<?php

namespace WikiAutomator\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;

class Uninstall extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Uninstall WikiAutomator: DROPS database tables and removes all data.' );
		$this->addOption( 'force', 'Skip confirmation prompt', false, false );
		$this->requireExtension( 'WikiAutomator' );
	}

	public function execute() {
		$this->output( "!!! WARNING !!!\n" );
		$this->output( "This script will PERMANENTLY DELETE the 'wa_tasks' table and all WikiAutomator data.\n" );
		$this->output( "This action cannot be undone.\n\n" );

		if ( !$this->hasOption( 'force' ) ) {
			$this->output( "Type 'DELETE' to continue: " );
			$confirm = $this->readInput();
			if ( trim( $confirm ) !== 'DELETE' ) {
				$this->output( "Aborted.\n" );
				return;
			}
		}

		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( \DB_PRIMARY );
		$tableName = $dbw->tableName( 'wa_tasks' );

		if ( $dbw->tableExists( 'wa_tasks' ) ) {
			$this->output( "Dropping table $tableName...\n" );
			try {
				$dbw->query( "DROP TABLE $tableName", __METHOD__ );
				$this->output( "✅ Table 'wa_tasks' dropped successfully.\n" );
				$this->output( "Now you can remove the 'WikiAutomator' folder from your extensions directory.\n" );
			} catch ( \Exception $e ) {
				$this->output( "❌ Error dropping table: " . $e->getMessage() . "\n" );
			}
		} else {
			$this->output( "⚠️ Table 'wa_tasks' does not exist. Nothing to do.\n" );
		}
	}
}
