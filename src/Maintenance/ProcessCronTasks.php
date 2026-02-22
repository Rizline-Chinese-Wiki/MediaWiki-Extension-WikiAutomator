<?php

namespace WikiAutomator\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use WikiAutomator\AutomationJob;
use JobQueueGroup;

class ProcessCronTasks extends Maintenance {

	// Minimum interval: 5 minutes (300 seconds)
	const MIN_INTERVAL = 300;

	// Lock timeout in seconds
	const LOCK_TIMEOUT = 60;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'WikiAutomator: Process scheduled automation tasks (Cron and Scheduled)' );
		$this->requireExtension( 'WikiAutomator' );
	}

	public function execute() {
		$this->output( "Starting WikiAutomator cron check...\n" );

		$services = MediaWikiServices::getInstance();
		$dbw = $services->getDBLoadBalancer()->getConnection( \DB_PRIMARY );

		$cronCount = $this->processCronTasks( $dbw );
		$scheduledCount = $this->processScheduledTasks( $dbw );

		$total = $cronCount + $scheduledCount;
		$this->output( "Done. Triggered $total tasks (cron: $cronCount, scheduled: $scheduledCount).\n" );
	}

	/**
	 * Acquire a lock for a specific task to prevent race conditions
	 * @param \Wikimedia\Rdbms\IDatabase $dbw
	 * @param int $taskId
	 * @return bool True if lock acquired
	 */
	private function acquireTaskLock( $dbw, $taskId ) {
		$lockName = 'WikiAutomator_task_' . $taskId;
		// Use database-level lock for distributed locking
		return $dbw->lock( $lockName, __METHOD__, self::LOCK_TIMEOUT );
	}

	/**
	 * Release a task lock
	 * @param \Wikimedia\Rdbms\IDatabase $dbw
	 * @param int $taskId
	 */
	private function releaseTaskLock( $dbw, $taskId ) {
		$lockName = 'WikiAutomator_task_' . $taskId;
		$dbw->unlock( $lockName, __METHOD__ );
	}

	/**
	 * Process periodic cron tasks
	 * @param \Wikimedia\Rdbms\IDatabase $dbw
	 * @return int Number of tasks triggered
	 */
	private function processCronTasks( $dbw ) {
		$res = $dbw->select(
			'wa_tasks',
			'*',
			[
				'task_trigger' => 'cron',
				'task_enabled' => 1
			],
			__METHOD__
		);

		$now = time();
		$count = 0;

		foreach ( $res as $row ) {
			$interval = (int)$row->task_cron_interval;

			// Enforce minimum interval of 5 minutes (300 seconds)
			if ( $interval < self::MIN_INTERVAL ) {
				$this->output( "  Task ID {$row->task_id} skipped: interval ($interval) below minimum (" . self::MIN_INTERVAL . "s)\n" );
				continue;
			}

			$lastRun = \wfTimestamp( \TS_UNIX, $row->task_last_run );

			if ( !$row->task_last_run || ($now - $lastRun) >= $interval ) {

				// Try to acquire distributed lock first
				if ( !$this->acquireTaskLock( $dbw, $row->task_id ) ) {
					$this->output( "  Task ID {$row->task_id} skipped: could not acquire lock (another process running?)\n" );
					continue;
				}

				try {
					// Re-check the condition after acquiring lock (double-check locking)
					$freshRow = $dbw->selectRow(
						'wa_tasks',
						[ 'task_last_run', 'task_enabled' ],
						[ 'task_id' => $row->task_id ],
						__METHOD__
					);

					if ( !$freshRow || !$freshRow->task_enabled ) {
						$this->output( "  Task ID {$row->task_id} skipped: task disabled or deleted\n" );
						continue;
					}

					$freshLastRun = \wfTimestamp( \TS_UNIX, $freshRow->task_last_run );
					if ( $freshRow->task_last_run && ($now - $freshLastRun) < $interval ) {
						$this->output( "  Task ID {$row->task_id} skipped: already executed by another process\n" );
						continue;
					}

					$this->output( "  Triggering cron task ID {$row->task_id} ({$row->task_name})...\n" );

					// Update last_run timestamp
					$dbw->update(
						'wa_tasks',
						[ 'task_last_run' => \wfTimestampNow() ],
						[ 'task_id' => $row->task_id ],
						__METHOD__
					);

					$this->pushJob( $row, Title::newMainPage() );
					$count++;
				} finally {
					$this->releaseTaskLock( $dbw, $row->task_id );
				}
			}
		}

		return $count;
	}

	/**
	 * Process one-time scheduled tasks
	 * @param \Wikimedia\Rdbms\IDatabase $dbw
	 * @return int Number of tasks triggered
	 */
	private function processScheduledTasks( $dbw ) {
		$res = $dbw->select(
			'wa_tasks',
			'*',
			[
				'task_trigger' => 'scheduled',
				'task_enabled' => 1
			],
			__METHOD__
		);

		$now = time();
		$count = 0;

		foreach ( $res as $row ) {
			$scheduledTime = $row->task_scheduled_time;

			if ( empty( $scheduledTime ) ) {
				$this->output( "  Task ID {$row->task_id} skipped: no scheduled time set\n" );
				continue;
			}

			$scheduledUnix = \wfTimestamp( \TS_UNIX, $scheduledTime );

			// Check if scheduled time has passed
			if ( $now >= $scheduledUnix ) {
				// Try to acquire distributed lock first
				if ( !$this->acquireTaskLock( $dbw, $row->task_id ) ) {
					$this->output( "  Task ID {$row->task_id} skipped: could not acquire lock (another process running?)\n" );
					continue;
				}

				try {
					// Re-check the condition after acquiring lock
					$freshRow = $dbw->selectRow(
						'wa_tasks',
						[ 'task_enabled' ],
						[ 'task_id' => $row->task_id ],
						__METHOD__
					);

					if ( !$freshRow || !$freshRow->task_enabled ) {
						$this->output( "  Task ID {$row->task_id} skipped: already disabled or deleted\n" );
						continue;
					}

					$this->output( "  Triggering scheduled task ID {$row->task_id} ({$row->task_name})...\n" );

					// Update last_run and disable the task
					$dbw->update(
						'wa_tasks',
						[
							'task_last_run' => \wfTimestampNow(),
							'task_enabled' => 0  // Auto-disable after execution
						],
						[ 'task_id' => $row->task_id ],
						__METHOD__
					);

					$this->pushJob( $row, Title::newMainPage() );
					$count++;
					$this->output( "  Task ID {$row->task_id} executed and auto-disabled.\n" );
				} finally {
					$this->releaseTaskLock( $dbw, $row->task_id );
				}
			}
		}

		return $count;
	}

	private function pushJob( $row, $contextTitle ) {
		$actions = json_decode( $row->task_actions, true );
		if ( json_last_error() !== JSON_ERROR_NONE || !is_array( $actions ) ) {
			$this->output( "  Task ID {$row->task_id} skipped: invalid JSON in task_actions\n" );
			return;
		}

		$jobParams = [
			'task_id' => $row->task_id,
			'task_name' => $row->task_name,
			'trigger_page_id' => $contextTitle->getArticleID(),
			'actions' => $actions,
			'target_rule' => isset($row->task_target) ? $row->task_target : '',
			'owner_id' => isset($row->task_owner_id) ? $row->task_owner_id : 0,
			'use_regex' => isset($row->task_use_regex) ? (bool)$row->task_use_regex : false,
			'match_mode' => isset($row->task_match_mode) ? $row->task_match_mode : 'auto',
			'bot_edit' => isset($row->task_bot_edit) ? (bool)$row->task_bot_edit : false,
			'edit_summary' => isset($row->task_edit_summary) ? $row->task_edit_summary : ''
		];

		$job = new AutomationJob( $contextTitle, $jobParams );

		// Use MediaWikiServices for MW 1.39+
		$services = MediaWikiServices::getInstance();
		$services->getJobQueueGroup()->push( $job );
	}
}
