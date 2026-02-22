<?php

namespace WikiAutomator;

use DatabaseUpdater;
use MediaWiki\Title\Title;
use MediaWiki\MediaWikiServices;

class Hooks {

	/** @var string Tag name for WikiAutomator edits */
	public const CHANGE_TAG = 'WikiAutomator';

	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$sqlDir = __DIR__ . '/../sql';

		// Create table if not exists
		$updater->addExtensionTable(
			'wa_tasks',
			$sqlDir . '/wa_tasks.sql'
		);

		// Add new columns for existing installations
		$updater->addExtensionField(
			'wa_tasks',
			'task_trigger_category',
			$sqlDir . '/patch-task_trigger_category.sql'
		);
		$updater->addExtensionField(
			'wa_tasks',
			'task_category_action',
			$sqlDir . '/patch-task_category_action.sql'
		);
		$updater->addExtensionField(
			'wa_tasks',
			'task_scheduled_time',
			$sqlDir . '/patch-task_scheduled_time.sql'
		);
		$updater->addExtensionField(
			'wa_tasks',
			'task_edit_summary',
			$sqlDir . '/patch-task_edit_summary.sql'
		);
		$updater->addExtensionField(
			'wa_tasks',
			'task_target',
			$sqlDir . '/patch-task_target.sql'
		);
		$updater->addExtensionField(
			'wa_tasks',
			'task_match_mode',
			$sqlDir . '/patch-task_match_mode.sql'
		);
		$updater->addExtensionField(
			'wa_tasks',
			'task_bot_edit',
			$sqlDir . '/patch-task_bot_edit.sql'
		);
		$updater->addExtensionTable(
			'wa_logs',
			$sqlDir . '/patch-wa_logs.sql'
		);
	}

	/**
	 * Register the WikiAutomator change tag
	 * Hook: ListDefinedTags
	 */
	public static function onListDefinedTags( &$tags ) {
		$tags[] = self::CHANGE_TAG;
	}

	/**
	 * Mark the WikiAutomator tag as active
	 * Hook: ChangeTagsListActive
	 */
	public static function onChangeTagsListActive( &$tags ) {
		$tags[] = self::CHANGE_TAG;
	}

	public static function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		$logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'WikiAutomator' );

		if ( strpos( $summary, 'WikiAutomator Action' ) !== false ) {
			return;
		}

		$services = MediaWikiServices::getInstance();
		$dbr = $services->getDBLoadBalancer()->getConnection( \DB_REPLICA );

		$title = $wikiPage->getTitle();
		$titleText = $title->getPrefixedText();
		$ns = $title->getNamespace();

		// Check if this is a new page creation
		$isNewPage = ( $flags & EDIT_NEW ) !== 0;

		$logger->debug( "Checking triggers for page save: $titleText (NS: $ns, isNew: " . ($isNewPage ? 'yes' : 'no') . ")" );

		// Query for both page_save and page_create triggers
		$triggerTypes = [ 'page_save' ];
		if ( $isNewPage ) {
			$triggerTypes[] = 'page_create';
		}

		$res = $dbr->select(
			'wa_tasks',
			'*',
			[ 'task_trigger' => $triggerTypes, 'task_enabled' => 1 ],
			__METHOD__
		);

		foreach ( $res as $row ) {
			// For page_create trigger, only fire on new pages
			if ( $row->task_trigger === 'page_create' && !$isNewPage ) {
				continue;
			}

			$conditions = json_decode( $row->task_conditions, true );
			if ( json_last_error() !== JSON_ERROR_NONE || !is_array( $conditions ) ) {
				$logger->error( "Task {$row->task_id}: Invalid JSON in task_conditions" );
				continue;
			}

			// Check Namespace
			if ( isset( $conditions['namespace'] ) && $conditions['namespace'] !== '' ) {
				$allowedNs = array_map( 'intval', explode( ',', (string)$conditions['namespace'] ) );
				if ( !in_array( $ns, $allowedNs, true ) ) {
					$logger->debug( "Task {$row->task_id} skipped: NS mismatch ($ns not in " . implode(',', $allowedNs) . ")" );
					continue;
				}
			}

			// Check Title
			if ( !empty($conditions['title']) && $titleText !== $conditions['title'] ) {
				$logger->debug( "Task {$row->task_id} skipped: Title mismatch ($titleText != {$conditions['title']})" );
				continue;
			}

			$logger->info( "Task {$row->task_id} ({$row->task_trigger}) triggered for $titleText" );
			self::pushJob( $row, $title );
		}
	}

	/**
	 * Hook: CategoryAfterPageAdded
	 * Triggered when a page is added to a category
	 */
	public static function onCategoryAfterPageAdded( $category, $wikiPage ) {
		self::handleCategoryChange( $category, $wikiPage, 'add' );
	}

	/**
	 * Hook: CategoryAfterPageRemoved
	 * Triggered when a page is removed from a category
	 */
	public static function onCategoryAfterPageRemoved( $category, $wikiPage, $id ) {
		self::handleCategoryChange( $category, $wikiPage, 'remove' );
	}

	/**
	 * Handle category change events
	 * @param \Category $category The category object
	 * @param \WikiPage $wikiPage The page being added/removed
	 * @param string $action 'add' or 'remove'
	 */
	private static function handleCategoryChange( $category, $wikiPage, $action ) {
		$logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'WikiAutomator' );

		$categoryName = $category->getName();
		$title = $wikiPage->getTitle();
		$titleText = $title->getPrefixedText();
		$ns = $title->getNamespace();

		$logger->debug( "Category change: $titleText {$action}ed category '$categoryName'" );

		$services = MediaWikiServices::getInstance();
		$dbr = $services->getDBLoadBalancer()->getConnection( \DB_REPLICA );

		$res = $dbr->select(
			'wa_tasks',
			'*',
			[
				'task_trigger' => 'category_change',
				'task_enabled' => 1,
				'task_trigger_category' => $categoryName
			],
			__METHOD__
		);

		foreach ( $res as $row ) {
			// Check if action matches the configured category action
			$categoryAction = $row->task_category_action;
			if ( $categoryAction !== 'both' && $categoryAction !== $action ) {
				$logger->debug( "Task {$row->task_id} skipped: action mismatch ($action != $categoryAction)" );
				continue;
			}

			$conditions = json_decode( $row->task_conditions, true );
			if ( json_last_error() !== JSON_ERROR_NONE || !is_array( $conditions ) ) {
				$logger->error( "Task {$row->task_id}: Invalid JSON in task_conditions" );
				continue;
			}

			// Check Namespace
			if ( isset( $conditions['namespace'] ) && $conditions['namespace'] !== '' ) {
				$allowedNs = array_map( 'intval', explode( ',', (string)$conditions['namespace'] ) );
				if ( !in_array( $ns, $allowedNs, true ) ) {
					$logger->debug( "Task {$row->task_id} skipped: NS mismatch ($ns not in " . implode(',', $allowedNs) . ")" );
					continue;
				}
			}

			// Check Title (optional filter)
			if ( !empty($conditions['title']) && $titleText !== $conditions['title'] ) {
				$logger->debug( "Task {$row->task_id} skipped: Title mismatch ($titleText != {$conditions['title']})" );
				continue;
			}

			$logger->info( "Task {$row->task_id} (category_change) triggered for $titleText ($action category '$categoryName')" );
			self::pushJob( $row, $title );
		}
	}

	private static function pushJob( $row, $contextTitle ) {
		$logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'WikiAutomator' );
		$logger->info( "pushJob called for Task {$row->task_id}" );

		try {
			$actions = json_decode( $row->task_actions, true );
			if ( json_last_error() !== JSON_ERROR_NONE || !is_array( $actions ) ) {
				$logger->error( "Task {$row->task_id}: Invalid JSON in task_actions" );
				return;
			}
			$logger->info( "Task {$row->task_id} actions decoded: " . count($actions) . " actions" );

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

			$logger->info( "Task {$row->task_id} params built, creating job..." );
			$job = new AutomationJob( $contextTitle, $jobParams );
			$logger->info( "Task {$row->task_id} job created successfully" );

			// Check for Force Sync configuration (use global variable directly)
			$forceSync = $GLOBALS['wgWikiAutomatorForceSync'] ?? false;
			$logger->info( "ForceSync value: " . ($forceSync ? 'true' : 'false') );

			if ( $forceSync ) {
				$logger->info( "Force Sync enabled. Running task {$row->task_id} immediately." );
				$result = $job->run();
				$logger->info( "Task {$row->task_id} sync execution completed. Result: " . ($result ? 'success' : 'failed') );
			} else {
				$logger->info( "Pushing job to queue for Task {$row->task_id}..." );
				$jobQueueGroup = MediaWikiServices::getInstance()->getJobQueueGroup();
				$jobQueueGroup->push( $job );
				$logger->info( "Job pushed to queue for Task {$row->task_id}. Job Type: " . $job->getType() );
			}
		} catch ( \Throwable $e ) {
			$logger->error( "pushJob failed for Task {$row->task_id}: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() );
		}
	}
}
