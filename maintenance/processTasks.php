<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

class RunProcessCronTasks extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Wrapper to run ProcessCronTasks from command line' );
	}

	public function execute() {
		// Just a wrapper to load the class via Autoloader or require it manually if needed
		// In a real extension with proper autoloading, we could just use the class directly.
		// However, for direct execution, we often need to be careful about namespaces.
		
		$task = new \WikiAutomator\Maintenance\ProcessCronTasks();
		$task->execute();
	}
}

// Ensure the autoloader knows about our class if it's not registered in extension.json yet
// or if we are running this before extension registration fully kicks in (though Maintenance script usually loads LocalSettings)

$maintClass = 'WikiAutomator\Maintenance\ProcessCronTasks';
require_once RUN_MAINTENANCE_IF_MAIN;
