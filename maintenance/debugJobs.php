<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

class RunJobsWrapper extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Manually run pending jobs in the queue.' );
	}

	public function execute() {
		$jobQueueGroup = \MediaWiki\MediaWikiServices::getInstance()->getJobQueueGroup();
		$jobs = $jobQueueGroup->getQueueSizes();
		
		$this->output( "Current Job Queue Status:\n" );
		if ( empty($jobs) ) {
			$this->output( "  Queue is empty.\n" );
		} else {
			foreach ( $jobs as $type => $count ) {
				$this->output( "  - $type: $count\n" );
			}
		}

		$this->output( "\nAttempting to run a WikiAutomatorJob manually...\n" );
		
		$job = $jobQueueGroup->pop( 'WikiAutomatorJob' );
		if ( $job ) {
			$this->output( "Found job! Executing...\n" );
			$status = $job->run();
			if ( $status ) {
				$this->output( "✅ Job executed successfully.\n" );
			} else {
				$this->output( "❌ Job execution failed.\n" );
				if ( $job->getLastError() ) {
					$this->output( "Error: " . $job->getLastError() . "\n" );
				}
			}
		} else {
			$this->output( "No WikiAutomatorJob found in queue.\n" );
		}
	}
}

$maintClass = 'RunJobsWrapper';
require_once RUN_MAINTENANCE_IF_MAIN;
