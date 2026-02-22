<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

class RunUninstall extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Wrapper to run Uninstall from command line' );
	}

	public function execute() {
		$task = new \WikiAutomator\Maintenance\Uninstall();
		$task->execute();
	}
}

$maintClass = 'WikiAutomator\Maintenance\Uninstall';
require_once RUN_MAINTENANCE_IF_MAIN;
