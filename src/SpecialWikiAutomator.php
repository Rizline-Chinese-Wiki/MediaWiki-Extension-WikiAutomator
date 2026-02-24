<?php

namespace WikiAutomator;

use SpecialPage;
use HTMLForm;
use MediaWiki\MediaWikiServices;

class SpecialWikiAutomator extends SpecialPage {
	/** @var \Wikimedia\ObjectCache\WANObjectCache */
	private $cache;

	/** @var string */
	private $cacheKeyPrefix = 'wikiautomator';

	/** Cache TTL: 5 minutes */
	private const CACHE_TTL = 300;

	public function __construct() {
		parent::__construct( 'WikiAutomator', 'manage-automation' );
	}

	private function getCache() {
		if ( !$this->cache ) {
			$this->cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		}
		return $this->cache;
	}

	private function getTaskListCacheKey() {
		return $this->getCache()->makeKey( $this->cacheKeyPrefix, 'tasklist', 'v1' );
	}

	private function getTaskCacheKey( $taskId ) {
		return $this->getCache()->makeKey( $this->cacheKeyPrefix, 'task', $taskId );
	}

	private function invalidateTaskCache( $taskId = null ) {
		$cache = $this->getCache();
		// Always invalidate the list cache
		$cache->delete( $this->getTaskListCacheKey() );
		// If specific task, also invalidate its individual cache
		if ( $taskId ) {
			$cache->delete( $this->getTaskCacheKey( $taskId ) );
		}
	}

	public function execute( $par ) {
		$this->setHeaders();
		$this->checkPermissions();
		$out = $this->getOutput();

		if ( $par === 'help' ) { $this->showHelpPage(); return; }
		if ( $par === 'fixdb' ) { $this->handleFixDB(); return; }
		if ( $par === 'log' ) { $this->showLogPage(); return; }

		$args = explode( '/', $par ?: '' );
		$action = $args[0] ?? '';
		$id = isset($args[1]) ? (int)$args[1] : 0;

		// Preview is GET for viewing, POST for selective execution
		if ( $action === 'preview' && $id > 0 ) {
			if ( $this->getRequest()->wasPosted() ) {
				$this->handlePreviewExecute( $id );
			} else {
				$this->handlePreview( $id );
			}
			return;
		}

		// Undo: GET shows confirmation, POST executes
		if ( $action === 'undo' && $id > 0 ) {
			if ( $this->getRequest()->wasPosted() ) {
				$this->handleUndoExecute( $id );
			} else {
				$this->handleUndoConfirm( $id );
			}
			return;
		}

		if ( $this->getRequest()->wasPosted() ) {
			if ( $action === 'delete' && $id > 0 ) {
				$this->handleDelete( $id );
			} elseif ( $action === 'toggle' && $id > 0 ) {
				$this->handleToggle( $id );
			} elseif ( $action === 'run' && $id > 0 ) {
				$this->handleRunNow( $id );
			} elseif ( $action === 'edit' && $id > 0 ) {
				// Edit form submission is handled by HTMLForm, so this branch might be for AJAX or manual POST
				// But standard edit is GET to show form -> POST to saveTask
			}
		}

		if ( $action === 'edit' && $id > 0 ) {
			$this->showEditForm( $id );
			return; 
		}

		$out->addWikiMsg( 'wikiautomator-intro' );
		
		$helpLink = $this->getPageTitle( 'help' )->getFullURL();
		$fixLink = $this->getPageTitle( 'fixdb' )->getFullURL();
		$logLink = $this->getPageTitle( 'log' )->getFullURL();
		$toolbar = '<div style="float:right; margin-bottom:10px;">';
		$toolbar .= '<a href="' . $logLink . '" class="mw-ui-button mw-ui-quiet">' . $this->msg( 'wikiautomator-toolbar-log' )->escaped() . '</a> ';
		$toolbar .= '<a href="' . $helpLink . '" class="mw-ui-button mw-ui-quiet">' . $this->msg( 'wikiautomator-toolbar-help' )->escaped() . '</a>';
		$toolbar .= '</div><div style="clear:both;"></div>';
		$out->addHTML( $toolbar );

		$this->showCreateForm();
		
		$out->addHTML( '<hr><h2>' . $this->msg( 'wikiautomator-task-list' )->escaped() . '</h2>' );
		$this->showTasksTable();
	}

	private function getFormDescriptor( $defaults = null ) {
		$descriptor = [
			'TaskID' => [
				'type' => 'hidden',
				'default' => $defaults['task_id'] ?? 0
			],
			'TaskName' => [
				'type' => 'text',
				'label-message' => 'wikiautomator-task-name',
				'required' => true,
				'default' => $defaults['task_name'] ?? ''
			],
			'TriggerSectionHeader' => [
				'type' => 'info',
				'raw' => true,
				'default' => '<h4 style="margin-top:1em;margin-bottom:0.5em;border-bottom:1px solid #c8ccd1;padding-bottom:0.3em;">' . $this->msg( 'wikiautomator-section-header-trigger' )->escaped() . '</h4>'
			],
			'TriggerType' => [
				'type' => 'select',
				'label-message' => 'wikiautomator-trigger-mode-label',
				'options' => [
					$this->msg( 'wikiautomator-trigger-option-manual' )->text() => 'manual',
					$this->msg( 'wikiautomator-trigger-option-page-save' )->text() => 'page_save',
					$this->msg( 'wikiautomator-trigger-option-page-create' )->text() => 'page_create',
					$this->msg( 'wikiautomator-trigger-option-category-change' )->text() => 'category_change',
					$this->msg( 'wikiautomator-trigger-option-cron' )->text() => 'cron_custom',
					$this->msg( 'wikiautomator-trigger-option-scheduled' )->text() => 'scheduled',
				],
				'default' => $this->mapTriggerToOption($defaults),
				'id' => 'wa-trigger-type'
			],
			'CategoryName' => [
				'type' => 'text',
				'label-message' => 'wikiautomator-category-name-label',
				'help-message' => 'wikiautomator-category-name-help',
				'default' => $defaults['trigger_category'] ?? '',
				'cssclass' => 'wa-category-field'
			],
			'CategoryAction' => [
				'type' => 'select',
				'label-message' => 'wikiautomator-category-action-label',
				'options' => [
					$this->msg( 'wikiautomator-category-option-add' )->text() => 'add',
					$this->msg( 'wikiautomator-category-option-remove' )->text() => 'remove',
					$this->msg( 'wikiautomator-category-option-both' )->text() => 'both'
				],
				'default' => $defaults['category_action'] ?? 'add',
				'cssclass' => 'wa-category-field'
			],
			'CronInterval' => [
				'type' => 'int',
				'label-message' => 'wikiautomator-cron-interval-label',
				'help-message' => 'wikiautomator-cron-interval-help',
				'default' => $this->getCronIntervalMinutes($defaults),
				'min' => 5,
				'cssclass' => 'wa-cron-field'
			],
			'ScheduledTime' => [
				'type' => 'info',
				'raw' => true,
				'default' => $this->buildScheduledTimeInput($defaults),
				'label-message' => 'wikiautomator-scheduled-time-label',
				'help-message' => 'wikiautomator-scheduled-time-help-full',
				'cssclass' => 'wa-scheduled-field'
			],
			'ConditionTitle' => [
				'type' => 'text',
				'label-message' => 'wikiautomator-condition-title-label',
				'help-message' => 'wikiautomator-condition-title-help-text',
				'default' => $defaults['conditions']['title'] ?? '',
				'cssclass' => 'wa-page-condition'
			],
			'ConditionNS' => [
				'type' => 'info',
				'raw' => true,
				'label-message' => 'wikiautomator-condition-ns-label',
				'default' => $this->renderNamespaceCheckboxes( $defaults['conditions']['namespace'] ?? '' ),
				'cssclass' => 'wa-page-condition'
			],
			'ActionSectionHeader' => [
				'type' => 'info',
				'raw' => true,
				'default' => '<h4 style="margin-top:1.5em;margin-bottom:0.5em;border-bottom:1px solid #c8ccd1;padding-bottom:0.3em;">' . $this->msg( 'wikiautomator-section-header-actions' )->escaped() . '</h4>'
			],
			'TaskSteps' => [
				'type' => 'info',
				'raw' => true,
				'default' => '<div id="wa-steps-container"></div>
					<button type="button" id="wa-add-step" class="mw-ui-button mw-ui-progressive">' . $this->msg( 'wikiautomator-step-add' )->escaped() . '</button>
					<input type="hidden" name="wpTaskStepsJSON" id="wa-steps-json">'
			],
			'OptionsSectionHeader' => [
				'type' => 'info',
				'raw' => true,
				'default' => '<h4 style="margin-top:1.5em;margin-bottom:0.5em;border-bottom:1px solid #c8ccd1;padding-bottom:0.3em;">' . $this->msg( 'wikiautomator-section-header-options' )->escaped() . '</h4>'
			],
			'EditSummary' => [
				'type' => 'text',
				'label-message' => 'wikiautomator-edit-summary',
				'help-message' => 'wikiautomator-edit-summary-help',
				'default' => $defaults['edit_summary'] ?? ''
			],
			'BotEdit' => [
				'type' => 'check',
				'label-message' => 'wikiautomator-bot-edit',
				'help-message' => 'wikiautomator-bot-edit-help',
				'default' => (bool)($defaults['bot_edit'] ?? false)
			],
			'NotifyOwner' => [
				'type' => 'check',
				'label-message' => 'wikiautomator-notify-owner',
				'default' => $this->hasEmailAction($defaults['actions'] ?? [])
			]
		];

		$stepsData = $this->prepareStepsForJS( $defaults['actions'] ?? [] );
		$this->getOutput()->addJsConfigVars( 'wgWikiAutomatorSteps', $stepsData );

		// Pass searchable namespaces to JS for checkbox UI
		$searchEngineConfig = MediaWikiServices::getInstance()->getSearchEngineConfig();
		$namespaces = $searchEngineConfig->searchableNamespaces();
		$nsList = [];
		foreach ( $namespaces as $ns => $name ) {
			$name = str_replace( '_', ' ', $name );
			if ( $name === '' ) {
				$name = $this->msg( 'blanknamespace' )->text();
			}
			$nsList[] = [ 'id' => (int)$ns, 'name' => $name ];
		}
		$this->getOutput()->addJsConfigVars( 'wgWikiAutomatorNamespaces', $nsList );

		$this->getOutput()->addModules( 'ext.wikiautomator' );

		return $descriptor;
	}

	private function hasEmailAction( $actions ) {
		foreach($actions as $act) {
			if ( ($act['type']??'') === 'email_owner' ) return true;
		}
		return false;
	}

	private function prepareStepsForJS( $actions ) {
		$jsSteps = [];
		foreach ( $actions as $act ) {
			if ( ($act['type']??'') === 'step_execution' ) {
				$val = $act['value'];
				// Migrate legacy pipe format to object for replace/rename
				if ( $act['action'] === 'replace' ) {
					if ( is_string( $val ) ) {
						$parts = explode( '|', $val, 2 );
						$val = [
							'search' => $parts[0] ?? '',
							'replace' => $parts[1] ?? ''
						];
					} elseif ( !is_array( $val ) ) {
						$val = [ 'search' => '', 'replace' => '' ];
					}
				}
				// Migrate target format
				$targetType = $act['target_type'] ?? null;
				$targetPage = $act['target_page'] ?? '';
				if ( $targetType === null ) {
					// Legacy migration
					$oldTarget = $act['target'] ?? '';
					if ( $oldTarget === '__search__' ) {
						$targetType = 'search';
					} elseif ( $oldTarget === '' || $oldTarget === '{{PAGENAME}}' ) {
						$targetType = 'trigger';
					} else {
						$targetType = 'specific';
						$targetPage = $oldTarget;
					}
				}

				$step = [
					'target_type' => $targetType,
					'target_page' => $targetPage,
					'action' => $act['action'],
					'value' => $val,
					'match_mode' => $act['match_mode'] ?? 'literal'
				];
				if ( isset( $act['search_filters'] ) ) {
					$step['search_filters'] = $act['search_filters'];
				}
				if ( isset( $act['regex_flags'] ) ) {
					$step['regex_flags'] = $act['regex_flags'];
				}
				if ( !empty( $act['move_pages'] ) ) {
					$step['move_pages'] = true;
				}
				if ( isset( $act['search_term'] ) ) {
					$step['search_term'] = $act['search_term'];
				}
				$jsSteps[] = $step;
			}
		}
		return $jsSteps;
	}

	private function mapTriggerToOption($defaults) {
		if (!$defaults) return 'manual';
		$trigger = $defaults['trigger'] ?? 'manual';
		$interval = $defaults['cron_interval'] ?? 0;

		if ($trigger === 'manual') return 'manual';
		if ($trigger === 'page_create') return 'page_create';
		if ($trigger === 'category_change') return 'category_change';
		if ($trigger === 'scheduled') return 'scheduled';
		if ($trigger === 'cron') {
			return 'cron_custom';
		}
		return 'page_save';
	}

	/**
	 * Render namespace checkboxes as inline HTML
	 */
	private function renderNamespaceCheckboxes( $stored ) {
		$selected = $this->parseNamespaceDefaults( $stored );
		$searchEngineConfig = MediaWikiServices::getInstance()->getSearchEngineConfig();
		$namespaces = $searchEngineConfig->searchableNamespaces();

		$html = '<div style="display:flex;flex-wrap:wrap;gap:4px 14px;">';
		foreach ( $namespaces as $ns => $name ) {
			$name = str_replace( '_', ' ', $name );
			if ( $name === '' ) {
				$name = $this->msg( 'blanknamespace' )->text();
			}
			$checked = in_array( (int)$ns, $selected ) ? ' checked' : '';
			$html .= '<label style="display:inline;font-weight:normal;white-space:nowrap;cursor:pointer;">'
				. '<input type="checkbox" name="wpConditionNS[]" value="' . (int)$ns . '"' . $checked . '> '
				. htmlspecialchars( "$name ($ns)" )
				. '</label>';
		}
		$html .= '</div><p style="margin-top:4px;color:#72777d;font-size:0.9em;">' . $this->msg( 'wikiautomator-condition-ns-none-hint' )->escaped() . '</p>';
		return $html;
	}

	/**
	 * Parse namespace defaults from stored string to array of IDs
	 * @param string $stored Comma-separated namespace IDs or empty
	 * @return array Namespace ID integers
	 */
	private function parseNamespaceDefaults( $stored ) {
		if ( $stored === '' || $stored === null ) {
			return [];
		}
		// Could be comma-separated string from old format or already an array
		if ( is_array( $stored ) ) {
			return array_map( 'intval', $stored );
		}
		return array_map( 'intval', array_filter( explode( ',', $stored ), function( $v ) {
			return $v !== '' && is_numeric( trim( $v ) );
		} ) );
	}

	private function getCronIntervalMinutes($defaults) {
		if (!$defaults) return 60;
		$interval = $defaults['cron_interval'] ?? 0;
		if ($interval > 0) {
			return max(5, intval($interval / 60));
		}
		return 60;
	}

	private function formatScheduledTime($defaults) {
		if (!$defaults || empty($defaults['scheduled_time'])) return '';
		$ts = $defaults['scheduled_time'];
		// Convert TS_MW format to YYYY-MM-DD HH:MM:SS format
		return substr($ts, 0, 4) . '-' . substr($ts, 4, 2) . '-' . substr($ts, 6, 2) .
			' ' . substr($ts, 8, 2) . ':' . substr($ts, 10, 2) . ':' . substr($ts, 12, 2);
	}

	private function buildScheduledTimeInput($defaults) {
		$ts = $defaults['scheduled_time'] ?? '';
		$year = $ts ? substr($ts, 0, 4) : date('Y');
		$month = $ts ? substr($ts, 4, 2) : '';
		$day = $ts ? substr($ts, 6, 2) : '';
		$hour = $ts ? substr($ts, 8, 2) : '';
		$minute = $ts ? substr($ts, 10, 2) : '';
		$second = $ts ? substr($ts, 12, 2) : '00';

		// Get configured timezone
		$timezone = $GLOBALS['wgWikiAutomatorTimezone'] ?? 'Asia/Shanghai';

		$html = '<div class="wa-datetime-picker" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
		$html .= '<input type="number" name="wpScheduledYear" value="' . htmlspecialchars($year) . '" min="2024" max="2099" style="width:80px;" placeholder="' . $this->msg( 'wikiautomator-datetime-year' )->escaped() . '"> ' . $this->msg( 'wikiautomator-datetime-year' )->escaped();
		$html .= ' <input type="number" name="wpScheduledMonth" value="' . htmlspecialchars($month) . '" min="1" max="12" style="width:60px;" placeholder="' . $this->msg( 'wikiautomator-datetime-month' )->escaped() . '"> ' . $this->msg( 'wikiautomator-datetime-month' )->escaped();
		$html .= ' <input type="number" name="wpScheduledDay" value="' . htmlspecialchars($day) . '" min="1" max="31" style="width:60px;" placeholder="' . $this->msg( 'wikiautomator-datetime-day' )->escaped() . '"> ' . $this->msg( 'wikiautomator-datetime-day' )->escaped();
		$html .= ' <input type="number" name="wpScheduledHour" value="' . htmlspecialchars($hour) . '" min="0" max="23" style="width:60px;" placeholder="' . $this->msg( 'wikiautomator-datetime-hour' )->escaped() . '"> ' . $this->msg( 'wikiautomator-datetime-hour' )->escaped();
		$html .= ' <input type="number" name="wpScheduledMinute" value="' . htmlspecialchars($minute) . '" min="0" max="59" style="width:60px;" placeholder="' . $this->msg( 'wikiautomator-datetime-minute' )->escaped() . '"> ' . $this->msg( 'wikiautomator-datetime-minute' )->escaped();
		$html .= ' <input type="number" name="wpScheduledSecond" value="' . htmlspecialchars($second) . '" min="0" max="59" style="width:60px;" placeholder="' . $this->msg( 'wikiautomator-datetime-second' )->escaped() . '"> ' . $this->msg( 'wikiautomator-datetime-second' )->escaped();
		$html .= '<span style="color:#72777d;font-size:0.9em;">(' . htmlspecialchars($timezone) . ')</span>';
		$html .= '</div>';

		return $html;
	}

	private function showCreateForm() {
		$this->getOutput()->addHTML('<h3>' . $this->msg( 'wikiautomator-create-new-task' )->escaped() . '</h3>');
		$htmlForm = HTMLForm::factory( 'ooui', $this->getFormDescriptor(), $this->getContext() );
		$htmlForm->setMessagePrefix( 'wikiautomator' );
		$htmlForm->setSubmitTextMsg( 'wikiautomator-create-submit' );
		$htmlForm->setSubmitCallback( [ $this, 'saveTask' ] );
		$htmlForm->show();
	}

	private function showEditForm( $id ) {
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( \DB_REPLICA );
		$row = $dbr->selectRow( 'wa_tasks', '*', [ 'task_id' => $id ] );
		
		if ( !$row ) {
			$this->getOutput()->addHTML( '<div class="errorbox">' . $this->msg( 'wikiautomator-task-not-exist' )->escaped() . '</div>' );
			return;
		}
		
		if ( !$this->canModify($row) ) {
			$this->getOutput()->addHTML( '<div class="errorbox">' . $this->msg( 'wikiautomator-permission-denied' )->escaped() . '</div>' );
			return;
		}

		$defaults = [
			'task_id' => $row->task_id,
			'task_name' => $row->task_name,
			'trigger' => $row->task_trigger,
			'cron_interval' => $row->task_cron_interval,
			'conditions' => json_decode($row->task_conditions, true) ?? [],
			'actions' => json_decode($row->task_actions, true) ?? [],
			'use_regex' => $row->task_use_regex,
			'match_mode' => $row->task_match_mode ?? 'auto',
			'bot_edit' => $row->task_bot_edit ?? false,
			'trigger_category' => $row->task_trigger_category ?? '',
			'category_action' => $row->task_category_action ?? 'add',
			'scheduled_time' => $row->task_scheduled_time ?? '',
			'edit_summary' => $row->task_edit_summary ?? ''
		];

		$this->getOutput()->setPageTitle( $this->msg( 'wikiautomator-edit-task-title', $row->task_name )->text() );
		$this->getOutput()->addHTML( '<a href="' . $this->getPageTitle()->getFullURL() . '">' . $this->msg( 'wikiautomator-back-to-list' )->escaped() . '</a>' );

		$htmlForm = HTMLForm::factory( 'ooui', $this->getFormDescriptor($defaults), $this->getContext() );
		$htmlForm->setMessagePrefix( 'wikiautomator' );
		$htmlForm->setSubmitTextMsg( 'wikiautomator-edit-submit' );
		$htmlForm->setSubmitCallback( [ $this, 'saveTask' ] );
		$htmlForm->show();
	}

	public function saveTask( $data ) {
		$request = $this->getRequest();
		$jsonSteps = $request->getVal( 'wpTaskStepsJSON' );
		$steps = json_decode( $jsonSteps, true );

		if ( empty($steps) && !$data['NotifyOwner'] ) {
			return $this->msg( 'wikiautomator-error-no-steps' )->text();
		}

		$actions = [];
		if ( is_array($steps) ) {
			foreach ( $steps as $step ) {
				$targetType = $step['target_type'] ?? 'trigger';
				// Skip empty specific targets
				if ( $targetType === 'specific' && empty( $step['target_page'] ) ) continue;

				$actionData = [
					'type' => 'step_execution',
					'target_type' => $targetType,
					'target_page' => trim( $step['target_page'] ?? '' ),
					// Legacy compat: also write 'target' so old code paths still work
					'target' => $targetType === 'search' ? '__search__' : ( $targetType === 'specific' ? trim( $step['target_page'] ?? '' ) : '{{PAGENAME}}' ),
					'action' => $step['action'],
					'value' => $step['value'],
					'match_mode' => $step['match_mode'] ?? 'literal'
				];
				if ( isset( $step['search_filters'] ) ) {
					$actionData['search_filters'] = $step['search_filters'];
				}
				if ( isset( $step['regex_flags'] ) ) {
					$actionData['regex_flags'] = $step['regex_flags'];
				}
				if ( !empty( $step['move_pages'] ) ) {
					$actionData['move_pages'] = true;
				}
				if ( isset( $step['search_term'] ) && $step['search_term'] !== '' ) {
					$actionData['search_term'] = $step['search_term'];
				}
				$actions[] = $actionData;
			}
		}

		if ( $data['NotifyOwner'] ) {
			$actions[] = [ 'type' => 'email_owner' ];
		}

		// Determine trigger type and related fields
		$triggerType = $data['TriggerType'];
		$trigger = 'manual';
		$cronInterval = 0;
		$triggerCategory = '';
		$categoryAction = '';
		$scheduledTime = '';

		switch ($triggerType) {
			case 'manual':
				$trigger = 'manual';
				break;
			case 'page_save':
				$trigger = 'page_save';
				break;
			case 'page_create':
				$trigger = 'page_create';
				break;
			case 'category_change':
				$trigger = 'category_change';
				$triggerCategory = trim($data['CategoryName'] ?? '');
				$categoryAction = $data['CategoryAction'] ?? 'add';
				if (empty($triggerCategory)) {
					return $this->msg( 'wikiautomator-error-no-category' )->text();
				}
				break;
			case 'cron_custom':
				$trigger = 'cron';
				$intervalMinutes = (int)($data['CronInterval'] ?? 60);
				// Enforce minimum 5 minutes
				if ($intervalMinutes < 5) {
					return $this->msg( 'wikiautomator-error-min-interval' )->text();
				}
				$cronInterval = $intervalMinutes * 60; // Convert to seconds
				break;
			case 'scheduled':
				$trigger = 'scheduled';
				// Get individual time components from form
				$year = $request->getInt('wpScheduledYear');
				$month = $request->getInt('wpScheduledMonth');
				$day = $request->getInt('wpScheduledDay');
				$hour = $request->getInt('wpScheduledHour');
				$minute = $request->getInt('wpScheduledMinute');
				$second = $request->getInt('wpScheduledSecond');

				if (!$year || !$month || !$day) {
					return $this->msg( 'wikiautomator-error-no-scheduled-time' )->text();
				}

				// Validate date components form a valid date
				if ( !checkdate( $month, $day, $year ) ) {
					return $this->msg( 'wikiautomator-error-invalid-date' )->text();
				}

				// Validate time components
				if ( $hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59 ) {
					return $this->msg( 'wikiautomator-error-invalid-time' )->text();
				}

				// Get timezone from config, default to UTC+8 (Asia/Shanghai)
				$timezone = $GLOBALS['wgWikiAutomatorTimezone'] ?? 'Asia/Shanghai';
				try {
					$tz = new \DateTimeZone( $timezone );
				} catch ( \Exception $e ) {
					// Fallback to UTC if invalid timezone configured
					$tz = new \DateTimeZone( 'UTC' );
				}

				// Build datetime with proper timezone handling
				$dateStr = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
				try {
					$dt = new \DateTime( $dateStr, $tz );
					$parsed = $dt->getTimestamp();
				} catch ( \Exception $e ) {
					return $this->msg( 'wikiautomator-error-invalid-time-format' )->text();
				}

				if ($parsed === false) {
					return $this->msg( 'wikiautomator-error-invalid-time-format' )->text();
				}
				$scheduledTime = \wfTimestamp(\TS_MW, $parsed);
				// Validate: must be at least 5 minutes in the future
				$minTime = time() + 300;
				if (\wfTimestamp(\TS_UNIX, $scheduledTime) < $minTime) {
					return $this->msg( 'wikiautomator-error-scheduled-time-future' )->text();
				}
				break;
		}

		$conditions = [
			'title' => trim($data['ConditionTitle'])
		];

		// Read from raw request (info type fields don't pass through HTMLForm $data)
		$nsIds = \RequestContext::getMain()->getRequest()->getArray( 'wpConditionNS', [] );
		if ( !empty( $nsIds ) ) {
			$namespaceInfo = MediaWikiServices::getInstance()->getNamespaceInfo();
			$validNamespaces = $namespaceInfo->getCanonicalNamespaces();
			$validatedNs = [];
			foreach ( $nsIds as $nsId ) {
				$nsId = (int)$nsId;
				if ( !array_key_exists( $nsId, $validNamespaces ) ) {
					return $this->msg( 'wikiautomator-error-invalid-ns', $nsId )->text();
				}
				$validatedNs[] = $nsId;
			}
			$conditions['namespace'] = implode( ',', $validatedNs );
		}

		$dbData = [
			'task_name' => $data['TaskName'],
			'task_trigger' => $trigger,
			'task_conditions' => json_encode( $conditions ),
			'task_actions' => json_encode( $actions, JSON_UNESCAPED_UNICODE ),
			'task_use_regex' => 0,
			'task_match_mode' => 'literal',
			'task_bot_edit' => $data['BotEdit'] ? 1 : 0,
			'task_cron_interval' => $cronInterval,
			'task_trigger_category' => $triggerCategory,
			'task_category_action' => $categoryAction,
			'task_scheduled_time' => $scheduledTime,
			'task_edit_summary' => trim($data['EditSummary'] ?? ''),
			'task_target' => ''
		];

		$id = (int)($data['TaskID'] ?? 0);

		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( \DB_PRIMARY );
		$user = $this->getUser();
		$logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'WikiAutomator' );

		if ( $id > 0 ) {
			$row = $dbw->selectRow('wa_tasks', '*', ['task_id' => $id]);
			if ( !$row || !$this->canModify($row) ) return $this->msg( 'wikiautomator-error-permission-or-missing' )->text();

			$dbw->update( 'wa_tasks', $dbData, [ 'task_id' => $id ] );
			$this->invalidateTaskCache( $id );

			// Log the modification
			$logger->info( "Task {$id} ('{$data['TaskName']}') modified by user {$user->getName()}" );

			$this->getOutput()->redirect( $this->getPageTitle()->getFullURL() );
		} else {
			$dbData['task_owner_id'] = $user->getId();
			$dbData['task_owner_name'] = $user->getName();
			$dbData['task_created_at'] = \wfTimestampNow();
			$dbData['task_enabled'] = 1;

			$dbw->insert( 'wa_tasks', $dbData );
			$newId = $dbw->insertId();
			$this->invalidateTaskCache();

			// Log the creation
			$logger->info( "Task {$newId} ('{$data['TaskName']}') created by user {$user->getName()}" );

			return true;
		}

		return true;
	}

	private function canModify( $row ) {
		$user = $this->getUser();
		// Check for manage-automation permission (admins) to modify any task
		if ( $user->isAllowed( 'manage-automation' ) ) return true;
		// Task owner can modify their own tasks
		if ( $user->getId() == $row->task_owner_id ) return true;
		return false;
	}

	private function handleDelete( $id ) {
		// Check token
		if ( !$this->getUser()->matchEditToken( $this->getRequest()->getVal( 'token' ) ) ) {
			$this->getOutput()->addHTML( '<div class="errorbox">' . $this->msg( 'wikiautomator-session-expired' )->escaped() . '</div>' );
			return;
		}

		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( \DB_PRIMARY );
		$row = $dbw->selectRow( 'wa_tasks', '*', [ 'task_id' => $id ] );

		if ( $row && $this->canModify($row) ) {
			$dbw->delete( 'wa_tasks', [ 'task_id' => $id ] );
			$this->invalidateTaskCache( $id );

			// Log the deletion
			$logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'WikiAutomator' );
			$logger->info( "Task {$id} ('{$row->task_name}') deleted by user {$this->getUser()->getName()}" );

			$this->getOutput()->addHTML( '<div class="successbox">' . $this->msg( 'wikiautomator-task-deleted' )->escaped() . '</div>' );
		} else {
			$this->getOutput()->addHTML( '<div class="errorbox">' . $this->msg( 'wikiautomator-delete-failed' )->escaped() . '</div>' );
		}
	}

	private function handleToggle( $id ) {
		// Check token
		if ( !$this->getUser()->matchEditToken( $this->getRequest()->getVal( 'token' ) ) ) {
			$this->getOutput()->addHTML( '<div class="errorbox">' . $this->msg( 'wikiautomator-session-expired' )->escaped() . '</div>' );
			return;
		}

		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( \DB_PRIMARY );
		$row = $dbw->selectRow( 'wa_tasks', '*', [ 'task_id' => $id ] );

		if ( $row && $this->canModify($row) ) {
			$newState = $row->task_enabled ? 0 : 1;
			$dbw->update( 'wa_tasks', [ 'task_enabled' => $newState ], [ 'task_id' => $id ] );

			// Log the toggle
			$logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'WikiAutomator' );
			$stateText = $newState ? 'enabled' : 'disabled';
			$logger->info( "Task {$id} ('{$row->task_name}') {$stateText} by user {$this->getUser()->getName()}" );
			$this->invalidateTaskCache( $id );
			$this->getOutput()->redirect( $this->getPageTitle()->getFullURL() );
		}
	}

	/**
	 * Handle preview/dry-run for a task
	 * Shows which pages would be affected and highlights matches
	 */
	private function handlePreview( $id ) {
		$out = $this->getOutput();
		$out->enableOOUI();
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( \DB_REPLICA );
		$row = $dbr->selectRow( 'wa_tasks', '*', [ 'task_id' => $id ] );

		if ( !$row || !$this->canModify( $row ) ) {
			$out->addHTML( '<div class="errorbox">' . $this->msg( 'wikiautomator-task-not-exist-or-denied' )->escaped() . '</div>' );
			return;
		}

		$out->setPageTitle( $this->msg( 'wikiautomator-preview-task-title', $row->task_name )->text() );
		$out->addHTML( '<a href="' . $this->getPageTitle()->getFullURL() . '">' . $this->msg( 'wikiautomator-back-to-list' )->escaped() . '</a>' );

		// Simulated trigger page input
		$request = $this->getRequest();
		$simulatedTitle = $request->getText( 'trigger_page', '' );
		$previewUrl = $this->getPageTitle( 'preview/' . $id )->getFullURL();
		$out->addHTML(
			'<form method="get" action="' . htmlspecialchars( $previewUrl ) . '" style="margin:1em 0;padding:10px;border:1px solid #c8ccd1;border-radius:4px;">' .
			'<label><strong>' . $this->msg( 'wikiautomator-preview-trigger-page-label' )->escaped() . '</strong></label> ' .
			\MediaWiki\Html\Html::input( 'trigger_page', $simulatedTitle, 'text', [
				'size' => 40,
				'placeholder' => $this->msg( 'wikiautomator-preview-trigger-page-placeholder' )->text()
			] ) . ' ' .
			new \OOUI\ButtonInputWidget( [
				'type' => 'submit',
				'label' => $this->msg( 'wikiautomator-preview-refresh' )->text(),
				'flags' => [ 'progressive' ]
			] ) .
			'</form>'
		);

		$actions = json_decode( $row->task_actions, true );
		if ( !is_array( $actions ) ) {
			$out->addHTML( '<div class="errorbox">' . $this->msg( 'wikiautomator-task-config-error' )->escaped() . '</div>' );
			return;
		}

		$matchMode = $row->task_match_mode ?? 'auto';
		$useRegex = (bool)$row->task_use_regex;

		// Resolve trigger title: user input > condition title > main page
		$conditions = json_decode( $row->task_conditions, true ) ?? [];
		$triggerTitle = null;
		if ( $simulatedTitle !== '' ) {
			$triggerTitle = \MediaWiki\Title\Title::newFromText( $simulatedTitle );
		}
		if ( !$triggerTitle && !empty( $conditions['title'] ) ) {
			$triggerTitle = \MediaWiki\Title\Title::newFromText( $conditions['title'] );
		}
		if ( !$triggerTitle ) {
			$triggerTitle = \MediaWiki\Title\Title::newMainPage();
		}
		$out->addHTML( '<p style="color:#555;">' . $this->msg( 'wikiautomator-preview-current-trigger', htmlspecialchars( $triggerTitle->getPrefixedText() ) )->text() . '</p>' );

		// Create a temporary job instance for countMatches
		$tempJob = new \WikiAutomator\AutomationJob( $triggerTitle, [
			'task_id' => $row->task_id,
			'task_name' => $row->task_name,
			'actions' => $actions,
			'owner_id' => $row->task_owner_id,
			'use_regex' => $useRegex,
			'match_mode' => $matchMode,
			'edit_summary' => ''
		] );

		$hasResults = false;
		$totalMatches = 0;
		$rowIndex = 0;

		// Wrap in form for selective execution
		$previewUrl = $this->getPageTitle( 'preview/' . $id )->getFullURL();
		$out->addHTML( '<form method="post" action="' . htmlspecialchars( $previewUrl ) . '">' );
		$out->addHTML( \MediaWiki\Html\Html::hidden( 'token', $this->getUser()->getEditToken() ) );
		$out->addHTML( \MediaWiki\Html\Html::hidden( 'task_id', $id ) );

		foreach ( $actions as $index => $action ) {
			if ( ( $action['type'] ?? '' ) !== 'step_execution' ) continue;
			$actionType = $action['action'] ?? '';
			$value = $action['value'] ?? '';

			// --- Resolve target type (new format with legacy migration) ---
			$targetType = $action['target_type'] ?? null;
			$targetPage = $action['target_page'] ?? '';
			if ( $targetType === null ) {
				$oldTarget = $action['target'] ?? '';
				if ( $oldTarget === '__search__' ) {
					$targetType = 'search';
				} elseif ( $oldTarget === '' || $oldTarget === '{{PAGENAME}}' ) {
					$targetType = 'trigger';
				} else {
					$targetType = 'specific';
					$targetPage = $oldTarget;
				}
			}
			$targetPages = [];

			if ( $actionType === 'replace' ) {
				$search = '';
				$replace = '';
				if ( is_array( $value ) && isset( $value['search'], $value['replace'] ) ) {
					$search = $value['search'];
					$replace = $value['replace'];
				} elseif ( is_string( $value ) && strpos( $value, '|' ) !== false ) {
					$parts = explode( '|', $value, 2 );
					$search = $parts[0];
					$replace = $parts[1] ?? '';
				}
				if ( $search === '' ) continue;

				// Resolve effective mode
				$effectiveMode = $action['match_mode'] ?? $matchMode;
				if ( $effectiveMode === 'auto' ) {
					if ( $useRegex ) {
						$effectiveMode = 'regex';
					} elseif ( strpos( $search, '*' ) !== false ) {
						$effectiveMode = 'wildcard';
					} else {
						$effectiveMode = 'literal';
					}
				}

				// --- Replace preview ---
				// Show warnings
				$warnings = Search::getWarnings( $search, $replace, $effectiveMode );
				foreach ( $warnings as $warningKey ) {
					$out->addHTML( '<div class="wa-warning">' . $this->msg( $warningKey )->escaped() . '</div>' );
				}

				if ( $targetType === 'search' ) {
					$searchFilters = $action['search_filters'] ?? [];
					$searchLimit = isset( $searchFilters['limit'] ) && $searchFilters['limit'] > 0 ? (int)$searchFilters['limit'] : 500;
					$targetPages = Search::doSearchQuery(
						$search, $effectiveMode,
						$searchFilters['namespaces'] ?? [],
						$searchFilters['category'] ?? null,
						$searchFilters['prefix'] ?? null,
						$searchLimit
					);
				} elseif ( $targetType === 'specific' ) {
					$resolved = $this->resolvePreviewTitle( $triggerTitle, $targetPage );
					if ( $resolved ) $targetPages = [ $resolved ];
				} else {
					// trigger mode: use the trigger page itself
					$targetPages = [ $triggerTitle ];
				}

				$services = MediaWikiServices::getInstance();
				foreach ( $targetPages as $target ) {
					if ( !$target->exists() ) continue;
					$page = $services->getWikiPageFactory()->newFromTitle( $target );
					$contentObj = $page->getContent();
					if ( !( $contentObj instanceof \TextContent ) ) continue;
					$content = $contentObj->getText();

					$matches = $tempJob->countMatches( $search, $content, $effectiveMode, $action['regex_flags'] ?? [] );
					if ( $matches === 0 ) continue;
					$totalMatches += $matches;

					if ( !$hasResults ) {
						$out->addHTML( '<table class="wikitable wa-preview-table" style="width:100%;margin-top:1em;">' );
						$out->addHTML( '<tr><th><input type="checkbox" id="wa-select-all"></th><th>' . $this->msg( 'wikiautomator-preview-table-header-step' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-preview-table-header-action' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-preview-table-header-target' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-preview-table-header-detail' )->escaped() . '</th></tr>' );
						$hasResults = true;
					}

					$contextHtml = $this->extractContext( $content, $search, $effectiveMode, $action['regex_flags'] ?? [] );

					// Generate diff preview
					$diffHtml = $this->generateReplaceDiff( $content, $search, $replace, $effectiveMode, $action['regex_flags'] ?? [] );

					$out->addHTML( '<tr>' );
					$cbVal = htmlspecialchars( $index . ':' . $target->getPrefixedText() );
					$out->addHTML( '<td><input type="checkbox" name="selected_pages[]" value="' . $cbVal . '" checked></td>' );
					$out->addHTML( '<td>#' . ( $index + 1 ) . '</td>' );
					$out->addHTML( '<td>' . $this->msg( 'wikiautomator-preview-replace-matches', $matches )->escaped() . '</td>' );
					$out->addHTML( '<td>' . htmlspecialchars( $target->getPrefixedText() ) . '</td>' );
					$detailHtml = '<code>' . htmlspecialchars( $search ) . '</code> → <code>' . htmlspecialchars( $replace ) . '</code><br>' . $contextHtml;
					if ( $diffHtml !== '' ) {
						$detailHtml .= '<details style="margin-top:6px;"><summary style="cursor:pointer;color:#36c;">' . $this->msg( 'wikiautomator-preview-view-diff' )->escaped() . '</summary><div class="wa-diff-container">' . $diffHtml . '</div></details>';
					}
					$out->addHTML( '<td class="wa-preview-context">' . $detailHtml . '</td>' );
					$out->addHTML( '</tr>' );
				}

				// Move pages preview
				if ( !empty( $action['move_pages'] ) ) {
					$moveCandidates = [];
					foreach ( $targetPages as $tp ) {
						if ( $tp->exists() && strpos( $tp->getText(), $search ) !== false ) {
							$moveCandidates[] = $tp;
						}
					}
					foreach ( $moveCandidates as $mc ) {
						$oldT = $mc->getText();
						$newT = str_replace( $search, $replace, $oldT );
						if ( $newT === $oldT ) continue;
						$totalMatches++;
						if ( !$hasResults ) {
							$out->addHTML( '<table class="wikitable wa-preview-table" style="width:100%;margin-top:1em;">' );
							$out->addHTML( '<tr><th><input type="checkbox" id="wa-select-all"></th><th>' . $this->msg( 'wikiautomator-preview-table-header-step' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-preview-table-header-action' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-preview-table-header-target' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-preview-table-header-detail' )->escaped() . '</th></tr>' );
							$hasResults = true;
						}
						$out->addHTML( '<tr>' );
						$cbVal = htmlspecialchars( $index . ':move:' . $mc->getPrefixedText() );
						$out->addHTML( '<td><input type="checkbox" name="selected_pages[]" value="' . $cbVal . '" checked></td>' );
						$out->addHTML( '<td>#' . ( $index + 1 ) . '</td>' );
						$out->addHTML( '<td>' . $this->msg( 'wikiautomator-preview-title-replace' )->escaped() . '</td>' );
						$out->addHTML( '<td>' . htmlspecialchars( $mc->getPrefixedText() ) . '</td>' );
						$out->addHTML( '<td>' . htmlspecialchars( $oldT ) . ' → <strong>' . htmlspecialchars( $newT ) . '</strong></td>' );
						$out->addHTML( '</tr>' );
					}
				}
				continue;
			}

			// --- Append / Prepend / Overwrite preview ---
			$resolvedPages = [];
			if ( $targetType === 'search' ) {
				$searchFilters = $action['search_filters'] ?? [];
				$searchLimit = isset( $searchFilters['limit'] ) && $searchFilters['limit'] > 0 ? (int)$searchFilters['limit'] : 500;
				$searchStr = $action['search_term'] ?? '';
				if ( $searchStr !== '' ) {
					$resolvedPages = Search::doSearchQuery(
						$searchStr, 'literal',
						$searchFilters['namespaces'] ?? [],
						$searchFilters['category'] ?? null,
						$searchFilters['prefix'] ?? null,
						$searchLimit
					);
				}
			} elseif ( $targetType === 'specific' ) {
				$r = $this->resolvePreviewTitle( $triggerTitle, $targetPage );
				if ( $r ) $resolvedPages = [ $r ];
			} elseif ( $targetType === 'trigger' ) {
				$resolvedPages = [ $triggerTitle ];
			}
			if ( empty( $resolvedPages ) ) continue;

			$actionLabels = [
				'append' => $this->msg( 'wikiautomator-action-append' )->text(),
				'prepend' => $this->msg( 'wikiautomator-action-prepend' )->text(),
				'overwrite' => $this->msg( 'wikiautomator-action-overwrite' )->text()
			];
			$actionLabel = $actionLabels[$actionType] ?? $actionType;

			$valueStr = is_string( $value ) ? $value : json_encode( $value, JSON_UNESCAPED_UNICODE );
			$preview = mb_substr( $valueStr, 0, 200 );
			if ( mb_strlen( $valueStr ) > 200 ) $preview .= '...';

			foreach ( $resolvedPages as $resolved ) {
				$pageExists = $resolved->exists();
				$existsLabel = $pageExists ? $this->msg( 'wikiautomator-preview-page-exists' )->text() : $this->msg( 'wikiautomator-preview-page-will-create' )->text();
				$currentLen = 0;
				if ( $pageExists ) {
					$services = MediaWikiServices::getInstance();
					$page = $services->getWikiPageFactory()->newFromTitle( $resolved );
					$contentObj = $page->getContent();
					if ( $contentObj instanceof \TextContent ) {
						$currentLen = mb_strlen( $contentObj->getText() );
					}
				}

				if ( !$hasResults ) {
					$out->addHTML( '<table class="wikitable wa-preview-table" style="width:100%;margin-top:1em;">' );
					$out->addHTML( '<tr><th><input type="checkbox" id="wa-select-all"></th><th>' . $this->msg( 'wikiautomator-preview-table-header-step' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-preview-table-header-action' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-preview-table-header-target' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-preview-table-header-detail' )->escaped() . '</th></tr>' );
					$hasResults = true;
				}

				$detail = "<strong>$existsLabel</strong>";
				if ( $pageExists ) {
					$detail .= " (" . $this->msg( 'wikiautomator-preview-current-chars', $currentLen )->text() . ")";
				}
				$detail .= '<br><code>' . htmlspecialchars( $preview ) . '</code>';

				$out->addHTML( '<tr>' );
				$cbVal = htmlspecialchars( $index . ':' . $resolved->getPrefixedText() );
				$out->addHTML( '<td><input type="checkbox" name="selected_pages[]" value="' . $cbVal . '" checked></td>' );
				$out->addHTML( '<td>#' . ( $index + 1 ) . '</td>' );
				$out->addHTML( '<td>' . htmlspecialchars( $actionLabel ) . '</td>' );
				$out->addHTML( '<td>' . htmlspecialchars( $resolved->getPrefixedText() ) . '</td>' );
				$out->addHTML( '<td>' . $detail . '</td>' );
				$out->addHTML( '</tr>' );
			}
		}

		if ( $hasResults ) {
			$out->addHTML( '</table>' );
			if ( $totalMatches > 0 ) {
				$out->addHTML( '<p style="margin-top:0.5em;">' . $this->msg( 'wikiautomator-stats-total-matches', $totalMatches )->escaped() . '</p>' );
			}
			$out->addHTML(
				'<div style="margin-top:1em;">' .
				new \OOUI\ButtonInputWidget( [
					'type' => 'submit',
					'label' => $this->msg( 'wikiautomator-preview-execute-selected' )->text(),
					'flags' => [ 'primary', 'progressive' ]
				] ) .
				'</div>'
			);
		} else {
			$out->addHTML( '<div class="warningbox" style="margin-top:1em;">' . $this->msg( 'wikiautomator-preview-no-results' )->escaped() . '</div>' );
		}

		$out->addHTML( '</form>' );

		// Select all checkbox JS
		$out->addHTML( '<script>
			document.getElementById("wa-select-all")?.addEventListener("change", function() {
				var checked = this.checked;
				document.querySelectorAll("input[name=\'selected_pages[]\']").forEach(function(cb) {
					cb.checked = checked;
				});
			});
		</script>' );
	}

	/**
	 * Handle selective execution from preview page
	 */
	private function handlePreviewExecute( $id ) {
		$out = $this->getOutput();
		$request = $this->getRequest();

		if ( !$this->getUser()->matchEditToken( $request->getVal( 'token' ) ) ) {
			$out->addHTML( '<div class="errorbox">' . $this->msg( 'wikiautomator-session-expired' )->escaped() . '</div>' );
			return;
		}

		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( \DB_REPLICA );
		$row = $dbr->selectRow( 'wa_tasks', '*', [ 'task_id' => $id ] );

		if ( !$row || !$this->canModify( $row ) ) {
			$out->addHTML( '<div class="errorbox">' . $this->msg( 'wikiautomator-task-not-exist-or-denied' )->escaped() . '</div>' );
			return;
		}

		$selectedPages = $request->getArray( 'selected_pages', [] );
		if ( empty( $selectedPages ) ) {
			$out->addHTML( '<div class="warningbox">' . $this->msg( 'wikiautomator-preview-no-pages-selected' )->escaped() . '</div>' );
			$out->addHTML( '<a href="' . $this->getPageTitle( 'preview/' . $id )->getFullURL() . '">' . $this->msg( 'wikiautomator-back-to-preview' )->escaped() . '</a>' );
			return;
		}

		$actions = json_decode( $row->task_actions, true );
		$matchMode = $row->task_match_mode ?? 'auto';

		// Filter actions: attach selected page list per step
		$filteredActions = [];
		foreach ( $actions as $index => $action ) {
			if ( ( $action['type'] ?? '' ) !== 'step_execution' ) continue;
			$pageNames = [];
			foreach ( $selectedPages as $sel ) {
				if ( strpos( $sel, $index . ':' ) === 0 ) {
					$pageNames[] = substr( $sel, strlen( $index . ':' ) );
				}
			}
			if ( !empty( $pageNames ) ) {
				$action['_selected_pages'] = $pageNames;
				$filteredActions[] = $action;
			}
		}

		// Resolve trigger title
		$triggerTitle = \MediaWiki\Title\Title::newMainPage();
		$conditions = json_decode( $row->task_conditions, true ) ?? [];
		if ( !empty( $conditions['title'] ) ) {
			$t = \MediaWiki\Title\Title::newFromText( $conditions['title'] );
			if ( $t ) $triggerTitle = $t;
		}

		$job = new \WikiAutomator\AutomationJob( $triggerTitle, [
			'task_id' => $row->task_id,
			'task_name' => $row->task_name,
			'actions' => $filteredActions,
			'owner_id' => $row->task_owner_id,
			'use_regex' => (bool)$row->task_use_regex,
			'match_mode' => $matchMode,
			'bot_edit' => (bool)($row->task_bot_edit ?? false),
			'edit_summary' => $row->task_edit_summary ?? ''
		] );

		MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );

		$count = count( $selectedPages );
		$out->setPageTitle( $this->msg( 'wikiautomator-preview-submitted-title' )->text() );
		$out->addHTML( '<div class="successbox" style="margin:1em 0;">' . $this->msg( 'wikiautomator-preview-submitted', $row->task_name, $count )->escaped() . '</div>' );
		$out->addHTML( '<a href="' . $this->getPageTitle()->getFullURL() . '">' . $this->msg( 'wikiautomator-back-to-list' )->escaped() . '</a>' );
	}

	/**
	 * Show undo confirmation page
	 */
	private function handleUndoConfirm( $logId ) {
		$out = $this->getOutput();
		$out->enableOOUI();
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( \DB_REPLICA );
		$log = $dbr->selectRow( 'wa_logs', '*', [ 'log_id' => $logId ] );

		if ( !$log ) {
			$out->addHTML( '<div class="errorbox">' . $this->msg( 'wikiautomator-undo-log-not-exist' )->escaped() . '</div>' );
			return;
		}
		if ( !in_array( $log->log_status, [ 'success', 'partial' ] ) ) {
			$out->addHTML( '<div class="errorbox">' . $this->msg( 'wikiautomator-undo-cannot', $log->log_status )->escaped() . '</div>' );
			return;
		}

		$details = json_decode( $log->log_details, true ) ?: [];
		$successPages = array_filter( $details, function( $d ) {
			return ( $d['status'] ?? '' ) === 'success';
		} );

		$out->setPageTitle( $this->msg( 'wikiautomator-undo-title', $logId )->text() );
		$out->addHTML( '<a href="' . $this->getPageTitle( 'log' )->getFullURL() . '">' . $this->msg( 'wikiautomator-back-to-log' )->escaped() . '</a>' );

		$lang = $this->getLanguage();
		$ts = $lang->timeanddate( $log->log_timestamp, true );
		$out->addHTML( '<div class="warningbox" style="margin:1em 0;">' . $this->msg( 'wikiautomator-undo-warning' )->escaped() . '<br>' .
			'<strong>' . $this->msg( 'wikiautomator-undo-task-label' )->escaped() . '</strong> #' . (int)$log->log_task_id . ' ' . htmlspecialchars( $log->log_task_name ) . '<br>' .
			'<strong>' . $this->msg( 'wikiautomator-undo-time-label' )->escaped() . '</strong> ' . htmlspecialchars( $ts ) . '<br>' .
			'<strong>' . $this->msg( 'wikiautomator-undo-pages-label' )->escaped() . '</strong> ' . $this->msg( 'wikiautomator-undo-pages-count', count( $successPages ) )->escaped() . '</div>' );

		if ( empty( $successPages ) ) {
			$out->addHTML( '<div class="errorbox">' . $this->msg( 'wikiautomator-undo-no-pages' )->escaped() . '</div>' );
			return;
		}

		$out->addHTML( '<p>' . $this->msg( 'wikiautomator-undo-revert-intro' )->escaped() . '</p><ul>' );
		foreach ( $successPages as $p ) {
			$out->addHTML( '<li>' . htmlspecialchars( $p['page'] ?? '' ) . ' — ' . htmlspecialchars( $p['action'] ?? '' ) . '</li>' );
		}
		$out->addHTML( '</ul>' );

		$undoUrl = $this->getPageTitle( 'undo/' . $logId )->getFullURL();
		$out->addHTML(
			'<form method="post" action="' . htmlspecialchars( $undoUrl ) . '">' .
			\MediaWiki\Html\Html::hidden( 'token', $this->getUser()->getEditToken() ) .
			new \OOUI\ButtonInputWidget( [
				'type' => 'submit',
				'label' => $this->msg( 'wikiautomator-undo-confirm' )->text(),
				'flags' => [ 'primary', 'destructive' ]
			] ) .
			'</form>'
		);
	}

	/**
	 * Execute undo: revert pages to pre-WikiAutomator revision
	 */
	private function handleUndoExecute( $logId ) {
		$out = $this->getOutput();

		if ( !$this->getUser()->matchEditToken( $this->getRequest()->getVal( 'token' ) ) ) {
			$out->addHTML( '<div class="errorbox">' . $this->msg( 'wikiautomator-session-expired' )->escaped() . '</div>' );
			return;
		}

		$services = MediaWikiServices::getInstance();
		$dbr = $services->getDBLoadBalancer()->getConnection( \DB_REPLICA );
		$log = $dbr->selectRow( 'wa_logs', '*', [ 'log_id' => $logId ] );

		if ( !$log || !in_array( $log->log_status, [ 'success', 'partial' ] ) ) {
			$out->addHTML( '<div class="errorbox">' . $this->msg( 'wikiautomator-undo-cannot-record' )->escaped() . '</div>' );
			return;
		}

		$details = json_decode( $log->log_details, true ) ?: [];
		$successPages = array_filter( $details, function( $d ) {
			return ( $d['status'] ?? '' ) === 'success';
		} );

		$out->setPageTitle( $this->msg( 'wikiautomator-undo-result-title' )->text() );
		$results = [];
		$revisionStore = $services->getRevisionStore();
		$wikiPageFactory = $services->getWikiPageFactory();
		$userFactory = $services->getUserFactory();
		$changeTag = \WikiAutomator\Hooks::CHANGE_TAG;

		$editUser = $userFactory->newFromId( $this->getUser()->getId() );

		foreach ( $successPages as $pageInfo ) {
			$pageName = $pageInfo['page'] ?? '';
			if ( $pageName === '' ) continue;

			$title = \MediaWiki\Title\Title::newFromText( $pageName );
			if ( !$title || !$title->exists() ) {
				$results[] = [ 'page' => $pageName, 'status' => $this->msg( 'wikiautomator-undo-page-not-exist' )->text() ];
				continue;
			}

			// Find the WikiAutomator edit and the revision before it
			$page = $wikiPageFactory->newFromTitle( $title );
			$latestRev = $page->getRevisionRecord();
			if ( !$latestRev ) {
				$results[] = [ 'page' => $pageName, 'status' => $this->msg( 'wikiautomator-undo-no-revision' )->text() ];
				continue;
			}

			// Walk back revisions to find the WikiAutomator tagged edit
			$waRevId = null;
			$prevRevId = null;
			$rev = $latestRev;
			for ( $i = 0; $i < 20; $i++ ) {
				if ( !$rev ) break;
				$tags = $revisionStore->getRevisionById( $rev->getId() ) ?
					$dbr->selectFieldValues( 'change_tag', 'ct_tag_id',
						[ 'ct_rev_id' => $rev->getId() ], __METHOD__ ) : [];
				// Check by tag name
				$tagNames = [];
				if ( !empty( $tags ) ) {
					$tagRows = $dbr->select( 'change_tag_def', 'ctd_name',
						[ 'ctd_id' => $tags ], __METHOD__ );
					foreach ( $tagRows as $tr ) {
						$tagNames[] = $tr->ctd_name;
					}
				}
				if ( in_array( $changeTag, $tagNames ) ) {
					$waRevId = $rev->getId();
					$parentId = $rev->getParentId();
					if ( $parentId ) {
						$prevRevId = $parentId;
					}
					break;
				}
				$rev = $revisionStore->getPreviousRevision( $rev );
			}

			if ( !$waRevId || !$prevRevId ) {
				$results[] = [ 'page' => $pageName, 'status' => $this->msg( 'wikiautomator-undo-no-wa-edit' )->text() ];
				continue;
			}

			// Restore content from previous revision
			$prevRev = $revisionStore->getRevisionById( $prevRevId );
			if ( !$prevRev ) {
				$results[] = [ 'page' => $pageName, 'status' => $this->msg( 'wikiautomator-undo-no-old-revision' )->text() ];
				continue;
			}

			$oldContent = $prevRev->getContent( \MediaWiki\Revision\SlotRecord::MAIN );
			if ( !$oldContent ) {
				$results[] = [ 'page' => $pageName, 'status' => $this->msg( 'wikiautomator-undo-old-content-empty' )->text() ];
				continue;
			}

			try {
				$updater = $page->newPageUpdater( $editUser );
				$updater->setContent( \MediaWiki\Revision\SlotRecord::MAIN, $oldContent );
				$comment = \MediaWiki\CommentStore\CommentStoreComment::newUnsavedComment(
					$this->msg( 'wikiautomator-undo-summary', $logId, $prevRevId )->text()
				);
				$updater->saveRevision( $comment, EDIT_UPDATE );
				$status = $updater->getStatus();
				$results[] = [ 'page' => $pageName, 'status' => $status->isOK() ? $this->msg( 'wikiautomator-undo-reverted' )->text() : $this->msg( 'wikiautomator-status-failed' )->text() ];
			} catch ( \Throwable $e ) {
				$results[] = [ 'page' => $pageName, 'status' => $this->msg( 'wikiautomator-undo-error', $e->getMessage() )->text() ];
			}
		}

		// Mark log as undone
		$dbw = $services->getDBLoadBalancer()->getConnection( \DB_PRIMARY );
		$dbw->update( 'wa_logs', [ 'log_status' => 'undone' ], [ 'log_id' => $logId ], __METHOD__ );

		// Show results
		$out->addHTML( '<div class="successbox" style="margin:1em 0;">' . $this->msg( 'wikiautomator-undo-complete' )->escaped() . '</div>' );
		$out->addHTML( '<table class="wikitable" style="width:100%;">' );
		$out->addHTML( '<tr><th>' . $this->msg( 'wikiautomator-undo-result-page' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-undo-result-status' )->escaped() . '</th></tr>' );
		foreach ( $results as $r ) {
			$out->addHTML( '<tr><td>' . htmlspecialchars( $r['page'] ) . '</td><td>' . htmlspecialchars( $r['status'] ) . '</td></tr>' );
		}
		$out->addHTML( '</table>' );
		$out->addHTML( '<a href="' . $this->getPageTitle( 'log' )->getFullURL() . '">' . $this->msg( 'wikiautomator-back-to-log' )->escaped() . '</a>' );
	}

	/**
	 * Show execution log page
	 */
	private function showLogPage() {
		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'wikiautomator-log-title' )->text() );
		$out->addHTML( '<a href="' . $this->getPageTitle()->getFullURL() . '">' . $this->msg( 'wikiautomator-back-to-list' )->escaped() . '</a>' );

		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( \DB_REPLICA );

		// Filters
		$request = $this->getRequest();
		$filterTask = $request->getInt( 'task_id', 0 );
		$filterUser = $request->getText( 'user', '' );
		$filterStatus = $request->getText( 'status', '' );

		$logUrl = $this->getPageTitle( 'log' )->getFullURL();
		$out->addHTML(
			'<form method="get" action="' . htmlspecialchars( $logUrl ) . '" style="margin:1em 0;display:flex;gap:10px;align-items:end;flex-wrap:wrap;">' .
			'<div><label>' . $this->msg( 'wikiautomator-log-filter-task-id' )->escaped() . '</label><br>' . \MediaWiki\Html\Html::input( 'task_id', $filterTask ?: '', 'number', [ 'size' => 6, 'min' => 0 ] ) . '</div>' .
			'<div><label>' . $this->msg( 'wikiautomator-log-filter-username' )->escaped() . '</label><br>' . \MediaWiki\Html\Html::input( 'user', $filterUser, 'text', [ 'size' => 15 ] ) . '</div>' .
			'<div><label>' . $this->msg( 'wikiautomator-log-filter-status' )->escaped() . '</label><br>' . \MediaWiki\Html\Html::openElement( 'select', [ 'name' => 'status' ] ) .
			'<option value="">' . $this->msg( 'wikiautomator-log-filter-all' )->escaped() . '</option>' .
			'<option value="success"' . ( $filterStatus === 'success' ? ' selected' : '' ) . '>' . $this->msg( 'wikiautomator-log-filter-success' )->escaped() . '</option>' .
			'<option value="partial"' . ( $filterStatus === 'partial' ? ' selected' : '' ) . '>' . $this->msg( 'wikiautomator-log-filter-partial' )->escaped() . '</option>' .
			'<option value="failed"' . ( $filterStatus === 'failed' ? ' selected' : '' ) . '>' . $this->msg( 'wikiautomator-log-filter-failed' )->escaped() . '</option>' .
			'<option value="no_change"' . ( $filterStatus === 'no_change' ? ' selected' : '' ) . '>' . $this->msg( 'wikiautomator-log-filter-no-change' )->escaped() . '</option>' .
			'<option value="undone"' . ( $filterStatus === 'undone' ? ' selected' : '' ) . '>' . $this->msg( 'wikiautomator-log-filter-undone' )->escaped() . '</option>' .
			\MediaWiki\Html\Html::closeElement( 'select' ) . '</div>' .
			'<div><button type="submit" class="mw-ui-button">' . $this->msg( 'wikiautomator-log-filter-submit' )->escaped() . '</button></div>' .
			'</form>'
		);

		$conds = [];
		if ( $filterTask > 0 ) $conds['log_task_id'] = $filterTask;
		if ( $filterUser !== '' ) $conds['log_user_name'] = $filterUser;
		if ( $filterStatus !== '' ) $conds['log_status'] = $filterStatus;

		$res = $dbr->select(
			'wa_logs', '*', $conds, __METHOD__,
			[ 'ORDER BY' => 'log_timestamp DESC', 'LIMIT' => 100 ]
		);

		$rows = iterator_to_array( $res );
		if ( empty( $rows ) ) {
			$out->addHTML( '<div class="warningbox" style="margin-top:1em;">' . $this->msg( 'wikiautomator-log-no-records' )->escaped() . '</div>' );
			return;
		}

		$statusLabels = [
			'success' => '<span style="color:#14866d;">' . $this->msg( 'wikiautomator-status-success' )->escaped() . '</span>',
			'partial' => '<span style="color:#ac6600;">' . $this->msg( 'wikiautomator-status-partial' )->escaped() . '</span>',
			'failed' => '<span style="color:#d33;">' . $this->msg( 'wikiautomator-status-failed' )->escaped() . '</span>',
			'no_change' => '<span style="color:#72777d;">' . $this->msg( 'wikiautomator-status-no-change' )->escaped() . '</span>',
			'undone' => '<span style="color:#72777d;text-decoration:line-through;">' . $this->msg( 'wikiautomator-status-undone' )->escaped() . '</span>'
		];

		$out->addHTML( '<table class="wikitable" style="width:100%;margin-top:1em;">' );
		$out->addHTML( '<tr><th>' . $this->msg( 'wikiautomator-log-table-id' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-log-table-time' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-log-table-task' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-log-table-user' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-log-table-pages' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-log-table-matches' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-log-table-status' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-log-table-actions' )->escaped() . '</th></tr>' );

		$lang = $this->getLanguage();
		foreach ( $rows as $log ) {
			$ts = $lang->timeanddate( $log->log_timestamp, true );
			$status = $statusLabels[$log->log_status] ?? htmlspecialchars( $log->log_status );
			$userName = $log->log_user_name ?: 'WikiAutomatorBot';

			$ops = '';
			// Details expand (show affected pages)
			$details = json_decode( $log->log_details, true );
			$detailCount = is_array( $details ) ? count( $details ) : 0;
			if ( $detailCount > 0 ) {
				$detailId = 'wa-log-detail-' . $log->log_id;
				$ops .= '<a href="#" onclick="var e=document.getElementById(\'' . $detailId . '\');e.style.display=e.style.display===\'none\'?\'table-row\':\'none\';return false;">' . $this->msg( 'wikiautomator-log-details', $detailCount )->escaped() . '</a>';
			}
			// Undo button (only for success/partial)
			if ( in_array( $log->log_status, [ 'success', 'partial' ] ) ) {
				$undoUrl = $this->getPageTitle( 'undo/' . $log->log_id )->getFullURL();
				$ops .= ' <a href="' . htmlspecialchars( $undoUrl ) . '" style="color:#d33;">' . $this->msg( 'wikiautomator-log-undo' )->escaped() . '</a>';
			}

			$out->addHTML( '<tr>' );
			$out->addHTML( '<td>' . (int)$log->log_id . '</td>' );
			$out->addHTML( '<td>' . htmlspecialchars( $ts ) . '</td>' );
			$out->addHTML( '<td>#' . (int)$log->log_task_id . ' ' . htmlspecialchars( $log->log_task_name ) . '</td>' );
			$out->addHTML( '<td>' . htmlspecialchars( $userName ) . '</td>' );
			$out->addHTML( '<td>' . (int)$log->log_pages_affected . '</td>' );
			$out->addHTML( '<td>' . (int)$log->log_total_matches . '</td>' );
			$out->addHTML( '<td>' . $status . '</td>' );
			$out->addHTML( '<td>' . $ops . '</td>' );
			$out->addHTML( '</tr>' );

			// Expandable detail row
			if ( $detailCount > 0 ) {
				$detailHtml = '<table class="wikitable" style="width:100%;font-size:0.9em;">';
				$detailHtml .= '<tr><th>' . $this->msg( 'wikiautomator-log-detail-page' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-log-detail-action' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-log-detail-status' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-log-detail-matches' )->escaped() . '</th></tr>';
				foreach ( $details as $d ) {
					$detailHtml .= '<tr>';
					$detailHtml .= '<td>' . htmlspecialchars( $d['page'] ?? '' ) . '</td>';
					$detailHtml .= '<td>' . htmlspecialchars( $d['action'] ?? '' ) . '</td>';
					$detailHtml .= '<td>' . htmlspecialchars( $d['status'] ?? '' ) . '</td>';
					$detailHtml .= '<td>' . (int)( $d['match_count'] ?? 0 ) . '</td>';
					$detailHtml .= '</tr>';
				}
				$detailHtml .= '</table>';
				$out->addHTML( '<tr id="' . $detailId . '" style="display:none;"><td colspan="8">' . $detailHtml . '</td></tr>' );
			}
		}

		$out->addHTML( '</table>' );
	}

	/**
	 * Resolve title for preview (same as resolveTitle in AutomationJob)
	 */
	private function resolvePreviewTitle( $triggerTitle, $rule ) {
		if ( empty( $rule ) ) return $triggerTitle;
		$text = str_replace( '{{PAGENAME}}', $triggerTitle->getText(), $rule );
		$text = str_replace( '{{FULLPAGENAME}}', $triggerTitle->getPrefixedText(), $text );
		$title = \MediaWiki\Title\Title::newFromText( $text );
		return $title instanceof \MediaWiki\Title\Title ? $title : null;
	}

	/**
	 * Extract context snippets around matches with highlighting
	 * @param string $content Page content
	 * @param string $search Search string
	 * @param string $matchMode effective match mode
	 * @param array $regexFlags regex flags
	 * @param int $contextChars chars of context before/after match
	 * @return string HTML with highlighted matches
	 */
	private function extractContext( $content, $search, $matchMode, $regexFlags = [], $contextChars = 60 ) {
		$pattern = '';
		if ( $matchMode === 'wildcard' ) {
			// Reuse expandWildcards logic
			$escaped = preg_quote( $search, '/' );
			$escaped = str_replace( '\\*', '<<<WILDCARD>>>', $escaped );
			$escaped = str_replace( '\\{\\{<<<WILDCARD>>>\\}\\}', '\\{\\{[^{}]*(?:\\{\\{[^{}]*\\}\\}[^{}]*)*\\}\\}', $escaped );
			$escaped = str_replace( '\\[\\[<<<WILDCARD>>>\\]\\]', '\\[\\[[^\\]]+\\]\\]', $escaped );
			$escaped = str_replace( '\\<\\!\\-\\-<<<WILDCARD>>>\\-\\-\\>', '<!--[\\s\\S]*?-->', $escaped );
			$escaped = str_replace( '<<<WILDCARD>>>', '.*?', $escaped );
			$pattern = '/' . $escaped . '/su';
		} elseif ( $matchMode === 'regex' ) {
			$pattern = $search;
			if ( $pattern[0] !== '/' && $pattern[0] !== '#' && $pattern[0] !== '~' ) {
				$flags = 'u';
				if ( !empty( $regexFlags['i'] ) ) $flags .= 'i';
				if ( !empty( $regexFlags['m'] ) ) $flags .= 'm';
				if ( !empty( $regexFlags['s'] ) ) $flags .= 's';
				if ( !empty( $regexFlags['U'] ) ) $flags .= 'U';
				$pattern = '#' . str_replace( '#', '\#', $pattern ) . '#' . $flags;
			}
		} else {
			$pattern = '/' . preg_quote( $search, '/' ) . '/u';
		}

		$matches = [];
		$result = @preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE );
		if ( $result === false || $result === 0 ) {
			return '<em>' . $this->msg( 'wikiautomator-preview-no-match' )->escaped() . '</em>';
		}

		$snippets = [];
		$maxSnippets = 3;
		foreach ( $matches[0] as $i => $match ) {
			if ( $i >= $maxSnippets ) {
				$remaining = $result - $maxSnippets;
				$snippets[] = '<em>' . $this->msg( 'wikiautomator-preview-more-matches', $remaining )->escaped() . '</em>';
				break;
			}
			$matchText = $match[0];
			$offset = $match[1];

			$before = mb_substr( mb_strcut( $content, max( 0, $offset - $contextChars ), $contextChars ), 0 );
			$after = mb_substr( mb_strcut( $content, $offset + strlen( $matchText ), $contextChars ), 0 );

			// Replace newlines with visible marker
			$before = str_replace( "\n", '↵', $before );
			$matchDisplay = str_replace( "\n", '↵', $matchText );
			$after = str_replace( "\n", '↵', $after );

			$snippet = htmlspecialchars( $before )
				. '<span class="wa-preview-match">' . htmlspecialchars( $matchDisplay ) . '</span>'
				. htmlspecialchars( $after );
			$snippets[] = $snippet;
		}

		return implode( '<br>', $snippets );
	}

	/**
	 * Generate a simple inline diff showing before/after replacement
	 * Shows only changed lines to keep output compact
	 */
	private function generateReplaceDiff( $content, $search, $replace, $matchMode, $regexFlags = [] ) {
		// Perform the replacement to get new content
		$newContent = $content;
		if ( $matchMode === 'wildcard' ) {
			$escaped = preg_quote( $search, '/' );
			$escaped = str_replace( '\\*', '<<<WILDCARD>>>', $escaped );
			$escaped = str_replace( '\\{\\{<<<WILDCARD>>>\\}\\}', '\\{\\{[^{}]*(?:\\{\\{[^{}]*\\}\\}[^{}]*)*\\}\\}', $escaped );
			$escaped = str_replace( '\\[\\[<<<WILDCARD>>>\\]\\]', '\\[\\[[^\\]]+\\]\\]', $escaped );
			$escaped = str_replace( '\\<\\!\\-\\-<<<WILDCARD>>>\\-\\-\\>', '<!--[\\s\\S]*?-->', $escaped );
			$escaped = str_replace( '<<<WILDCARD>>>', '.*?', $escaped );
			$pattern = '/' . $escaped . '/su';
			$result = @preg_replace( $pattern, $replace, $content );
			if ( $result !== null ) $newContent = $result;
		} elseif ( $matchMode === 'regex' ) {
			$pattern = $search;
			if ( $pattern[0] !== '/' && $pattern[0] !== '#' && $pattern[0] !== '~' ) {
				$flags = 'u';
				if ( !empty( $regexFlags['i'] ) ) $flags .= 'i';
				if ( !empty( $regexFlags['m'] ) ) $flags .= 'm';
				if ( !empty( $regexFlags['s'] ) ) $flags .= 's';
				if ( !empty( $regexFlags['U'] ) ) $flags .= 'U';
				$pattern = '#' . str_replace( '#', '\#', $pattern ) . '#' . $flags;
			}
			$result = @preg_replace( $pattern, $replace, $content );
			if ( $result !== null ) $newContent = $result;
		} else {
			$newContent = str_replace( $search, $replace, $content );
		}

		if ( $newContent === $content ) return '';

		// Simple line-level diff
		$oldLines = explode( "\n", $content );
		$newLines = explode( "\n", $newContent );
		$maxLines = max( count( $oldLines ), count( $newLines ) );
		$diffLines = [];
		$maxDiffLines = 20;
		$diffCount = 0;

		for ( $i = 0; $i < $maxLines; $i++ ) {
			$old = $oldLines[$i] ?? '';
			$new = $newLines[$i] ?? '';
			if ( $old !== $new ) {
				if ( $diffCount >= $maxDiffLines ) {
					$remaining = 0;
					for ( $j = $i; $j < $maxLines; $j++ ) {
						if ( ( $oldLines[$j] ?? '' ) !== ( $newLines[$j] ?? '' ) ) $remaining++;
					}
					if ( $remaining > 0 ) {
						$diffLines[] = '<em>' . $this->msg( 'wikiautomator-preview-more-changes', $remaining )->escaped() . '</em>';
					}
					break;
				}
				$lineNum = $i + 1;
				$diffLines[] = '<div class="wa-diff-del">- L' . $lineNum . ': ' . htmlspecialchars( $old ) . '</div>';
				$diffLines[] = '<div class="wa-diff-add">+ L' . $lineNum . ': ' . htmlspecialchars( $new ) . '</div>';
				$diffCount++;
			}
		}

		return implode( '', $diffLines );
	}

	private function handleRunNow( $id ) {
		// Check token
		if ( !$this->getUser()->matchEditToken( $this->getRequest()->getVal( 'token' ) ) ) {
			$this->getOutput()->addHTML( '<div class="errorbox">' . $this->msg( 'wikiautomator-session-expired' )->escaped() . '</div>' );
			return;
		}

		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( \DB_REPLICA );
		$row = $dbr->selectRow( 'wa_tasks', '*', [ 'task_id' => $id ] );

		if ( !$row || !$this->canModify($row) ) {
			$this->getOutput()->addHTML( '<div class="errorbox">' . $this->msg( 'wikiautomator-run-permission-denied' )->escaped() . '</div>' );
			return;
		}

		// Create and run the job immediately
		$actions = json_decode( $row->task_actions, true );
		if ( json_last_error() !== JSON_ERROR_NONE || !is_array( $actions ) ) {
			$this->getOutput()->addHTML( '<div class="errorbox">' . $this->msg( 'wikiautomator-task-config-invalid-actions' )->escaped() . '</div>' );
			return;
		}

		$conditions = json_decode( $row->task_conditions, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$conditions = [];
		}

		// Use a dummy title for manual execution
		$targetTitle = null;
		if ( !empty($conditions['title']) ) {
			$targetTitle = \MediaWiki\Title\Title::newFromText( $conditions['title'] );
		}
		if ( !$targetTitle ) {
			$targetTitle = \MediaWiki\Title\Title::newMainPage();
		}

		$job = new \WikiAutomator\AutomationJob( $targetTitle, [
			'task_id' => $row->task_id,
			'task_name' => $row->task_name,
			'actions' => $actions,
			'owner_id' => $row->task_owner_id,
			'use_regex' => (bool)$row->task_use_regex,
			'match_mode' => $row->task_match_mode ?? 'auto',
			'bot_edit' => (bool)($row->task_bot_edit ?? false),
			'edit_summary' => $row->task_edit_summary ?? ''
		] );

		try {
			$result = $job->run();
			if ( $result ) {
				$this->getOutput()->addHTML( '<div class="successbox">' . $this->msg( 'wikiautomator-run-executed' )->escaped() . '</div>' );
			} else {
				$this->getOutput()->addHTML( '<div class="warningbox">' . $this->msg( 'wikiautomator-run-no-change' )->escaped() . '</div>' );
			}
		} catch ( \Exception $e ) {
			// Log the full error for debugging, but show generic message to user
			$logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'WikiAutomator' );
			$logger->error( "Task execution failed: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() );
			$this->getOutput()->addHTML( '<div class="errorbox">' . $this->msg( 'wikiautomator-run-failed' )->escaped() . '</div>' );
		}

		// Update last run time
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( \DB_PRIMARY );
		$dbw->update( 'wa_tasks', [ 'task_last_run' => \wfTimestampNow() ], [ 'task_id' => $id ] );
		$this->invalidateTaskCache( $id );
	}

	private function handleFixDB() {
		$out = $this->getOutput();
		if ( !$this->getUser()->isAllowed('manage-automation') ) {
			$out->addHTML( '<div class="errorbox">' . $this->msg( 'wikiautomator-fixdb-permission-denied' )->escaped() . '</div>' );
			return;
		}
		$out->setPageTitle( $this->msg( 'wikiautomator-fixdb-title' )->text() );
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( \DB_PRIMARY );
		$tableName = $dbw->tableName( 'wa_tasks' );

		// Whitelist of allowed field names and their definitions
		// These are hardcoded and safe - no user input involved
		$requiredFields = [
			'task_target' => 'BLOB',
			'task_owner_id' => 'INT',
			'task_owner_name' => 'VARBINARY(255)',
			'task_created_at' => 'VARBINARY(14)',
			'task_use_regex' => 'TINYINT',
			'task_match_mode' => 'VARBINARY(20) DEFAULT \'auto\'',
			'task_bot_edit' => 'TINYINT DEFAULT 0',
			'task_cron_interval' => 'INT',
			'task_last_run' => 'VARBINARY(14)',
			'task_trigger_category' => 'VARBINARY(255) DEFAULT \'\'',
			'task_category_action' => 'VARBINARY(20) DEFAULT \'\'',
			'task_scheduled_time' => 'VARBINARY(14) DEFAULT \'\'',
			'task_edit_summary' => 'VARBINARY(255) DEFAULT \'\''
		];

		// Allowed field name pattern (alphanumeric and underscore only)
		$fieldNamePattern = '/^[a-z_][a-z0-9_]*$/i';

		$logs = [];
		foreach ( $requiredFields as $field => $def ) {
			// Validate field name format for extra safety
			if ( !preg_match( $fieldNamePattern, $field ) ) {
				$logs[] = "Skipped invalid field name: " . htmlspecialchars( $field );
				continue;
			}

			if ( !$dbw->fieldExists( 'wa_tasks', $field ) ) {
				try {
					// Use addIdentifierQuotes for field name (MediaWiki 1.44 compatible)
					$quotedField = $dbw->addIdentifierQuotes( $field );
					$dbw->query( "ALTER TABLE $tableName ADD COLUMN $quotedField $def", __METHOD__ );
					$logs[] = "Added $field";
				} catch ( \Exception $e ) {
					$logs[] = "Failed to add $field";
				}
			}
		}
		if (empty($logs)) {
			$logs[] = "All fields already exist";
		}
		$out->addHTML( '<ul><li>' . implode( '</li><li>', $logs ) . '</li></ul>' );
		$out->addHTML( '<a href="' . $this->getPageTitle()->getFullURL() . '">' . $this->msg( 'wikiautomator-back-to-list' )->escaped() . '</a>' );
	}

	private function showTasksTable() {
		$out = $this->getOutput();
		$cache = $this->getCache();
		$cacheKey = $this->getTaskListCacheKey();
		$request = $this->getRequest();

		// Filter parameters
		$filterOwner = $request->getText( 'filter_owner', '' );
		$filterEnabled = $request->getText( 'filter_enabled', '' );
		$filterTrigger = $request->getText( 'filter_trigger', '' );
		$filterMode = $request->getText( 'filter_mode', '' );
		$hasFilters = ( $filterOwner !== '' || $filterEnabled !== '' || $filterTrigger !== '' || $filterMode !== '' );

		// Filter UI
		$baseUrl = $this->getPageTitle()->getFullURL();
		$currentUser = $this->getUser()->getName();
		$out->addHTML(
			'<form method="get" action="' . htmlspecialchars( $baseUrl ) . '" style="margin-bottom:1em;display:flex;gap:8px;align-items:end;flex-wrap:wrap;">' .
			'<div><label>' . $this->msg( 'wikiautomator-tasklist-filter-creator' )->escaped() . '</label><br><select name="filter_owner">' .
			'<option value="">' . $this->msg( 'wikiautomator-tasklist-filter-all' )->escaped() . '</option>' .
			'<option value="__me__"' . ( $filterOwner === '__me__' ? ' selected' : '' ) . '>' . $this->msg( 'wikiautomator-tasklist-filter-mine' )->escaped() . '</option>' .
			'</select></div>' .
			'<div><label>' . $this->msg( 'wikiautomator-tasklist-filter-status' )->escaped() . '</label><br><select name="filter_enabled">' .
			'<option value="">' . $this->msg( 'wikiautomator-tasklist-filter-all' )->escaped() . '</option>' .
			'<option value="1"' . ( $filterEnabled === '1' ? ' selected' : '' ) . '>' . $this->msg( 'wikiautomator-tasklist-filter-enabled' )->escaped() . '</option>' .
			'<option value="0"' . ( $filterEnabled === '0' ? ' selected' : '' ) . '>' . $this->msg( 'wikiautomator-tasklist-filter-disabled' )->escaped() . '</option>' .
			'</select></div>' .
			'<div><label>' . $this->msg( 'wikiautomator-tasklist-filter-trigger-type' )->escaped() . '</label><br><select name="filter_trigger">' .
			'<option value="">' . $this->msg( 'wikiautomator-tasklist-filter-all' )->escaped() . '</option>' .
			'<option value="page_save"' . ( $filterTrigger === 'page_save' ? ' selected' : '' ) . '>' . $this->msg( 'wikiautomator-tasklist-trigger-page-save' )->escaped() . '</option>' .
			'<option value="page_create"' . ( $filterTrigger === 'page_create' ? ' selected' : '' ) . '>' . $this->msg( 'wikiautomator-tasklist-trigger-page-create' )->escaped() . '</option>' .
			'<option value="category_change"' . ( $filterTrigger === 'category_change' ? ' selected' : '' ) . '>' . $this->msg( 'wikiautomator-tasklist-trigger-category-change' )->escaped() . '</option>' .
			'<option value="cron"' . ( $filterTrigger === 'cron' ? ' selected' : '' ) . '>' . $this->msg( 'wikiautomator-tasklist-trigger-cron' )->escaped() . '</option>' .
			'<option value="scheduled"' . ( $filterTrigger === 'scheduled' ? ' selected' : '' ) . '>' . $this->msg( 'wikiautomator-tasklist-trigger-scheduled-no-time' )->escaped() . '</option>' .
			'</select></div>' .
			'<div><label>' . $this->msg( 'wikiautomator-tasklist-filter-match-mode' )->escaped() . '</label><br><select name="filter_mode">' .
			'<option value="">' . $this->msg( 'wikiautomator-tasklist-filter-all' )->escaped() . '</option>' .
			'<option value="literal"' . ( $filterMode === 'literal' ? ' selected' : '' ) . '>' . $this->msg( 'wikiautomator-tasklist-filter-literal' )->escaped() . '</option>' .
			'<option value="wildcard"' . ( $filterMode === 'wildcard' ? ' selected' : '' ) . '>' . $this->msg( 'wikiautomator-tasklist-filter-wildcard' )->escaped() . '</option>' .
			'<option value="regex"' . ( $filterMode === 'regex' ? ' selected' : '' ) . '>' . $this->msg( 'wikiautomator-tasklist-filter-regex' )->escaped() . '</option>' .
			'</select></div>' .
			'<div><button type="submit" class="mw-ui-button mw-ui-progressive">' . $this->msg( 'wikiautomator-tasklist-filter-submit' )->escaped() . '</button>' .
			( $hasFilters ? ' <a href="' . htmlspecialchars( $baseUrl ) . '" class="mw-ui-button mw-ui-quiet">' . $this->msg( 'wikiautomator-tasklist-filter-clear' )->escaped() . '</a>' : '' ) .
			'</div></form>'
		);

		// Pagination parameters
		$limit = 100;
		$offset = $request->getInt( 'offset', 0 );

		// Build query conditions
		$conds = [];
		if ( $filterOwner === '__me__' ) {
			$conds['task_owner_name'] = $currentUser;
		}
		if ( $filterEnabled !== '' ) {
			$conds['task_enabled'] = (int)$filterEnabled;
		}
		if ( $filterTrigger !== '' ) {
			$conds['task_trigger'] = $filterTrigger;
		}
		if ( $filterMode !== '' ) {
			$conds['task_match_mode'] = $filterMode;
		}

		// Skip cache when filters are active
		$rows = ( $offset === 0 && !$hasFilters ) ? $cache->get( $cacheKey ) : false;

		if ( $rows === false ) {
			// Cache miss - fetch from database with pagination
			$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( \DB_REPLICA );
			$res = $dbr->select(
				'wa_tasks',
				'*',
				$conds,
				__METHOD__,
				[
					'ORDER BY' => 'task_id DESC',
					'LIMIT' => $limit + 1, // Fetch one extra to check if there are more
					'OFFSET' => $offset
				]
			);

			$rows = [];
			foreach ( $res as $row ) {
				$rows[] = (array)$row;
			}

			// Store in cache only for first page without filters
			if ( $offset === 0 && !$hasFilters && count( $rows ) <= $limit ) {
				$cache->set( $cacheKey, $rows, self::CACHE_TTL );
			}
		}

		// Check if there are more results
		$hasMore = count( $rows ) > $limit;
		if ( $hasMore ) {
			array_pop( $rows ); // Remove the extra row
		}

		$html = '<table class="wikitable sortable" style="width:100%;">';
		$html .= '<tr><th>' . $this->msg( 'wikiautomator-tasklist-table-id' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-tasklist-table-name' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-tasklist-table-trigger' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-tasklist-table-status' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-tasklist-table-lastrun' )->escaped() . '</th><th>' . $this->msg( 'wikiautomator-tasklist-table-actions' )->escaped() . '</th></tr>';

		foreach ( $rows as $rowArr ) {
			$row = (object)$rowArr;
			$editUrl = $this->getPageTitle( 'edit/' . $row->task_id )->getFullURL();
			$delUrl = $this->getPageTitle( 'delete/' . $row->task_id )->getFullURL();
			$toggleUrl = $this->getPageTitle( 'toggle/' . $row->task_id )->getFullURL();
			$runUrl = $this->getPageTitle( 'run/' . $row->task_id )->getFullURL();

			$statusIcon = $row->task_enabled ? $this->msg( 'wikiautomator-tasklist-enabled' )->text() : $this->msg( 'wikiautomator-tasklist-disabled' )->text();
			$statusBtnText = $row->task_enabled ? $this->msg( 'wikiautomator-tasklist-btn-pause' )->text() : $this->msg( 'wikiautomator-tasklist-btn-enable' )->text();
			$lastRun = $row->task_last_run ? $this->getLanguage()->timeanddate($row->task_last_run) : '-';

			// Translate trigger type
			$triggerText = $row->task_trigger;
			if ( $row->task_trigger === 'page_save' ) {
				$triggerText = $this->msg( 'wikiautomator-tasklist-trigger-page-save' )->text();
			} elseif ( $row->task_trigger === 'page_create' ) {
				$triggerText = $this->msg( 'wikiautomator-tasklist-trigger-page-create' )->text();
			} elseif ( $row->task_trigger === 'category_change' ) {
				$cat = $row->task_trigger_category ?? '';
				$action = $row->task_category_action ?? '';
				$actionText = $action === 'add' ? $this->msg( 'wikiautomator-tasklist-trigger-category-add' )->text() : ($action === 'remove' ? $this->msg( 'wikiautomator-tasklist-trigger-category-remove' )->text() : $this->msg( 'wikiautomator-tasklist-trigger-category-change' )->text());
				$triggerText = $this->msg( 'wikiautomator-tasklist-trigger-category', $actionText, $cat )->text();
			} elseif ( $row->task_trigger === 'cron' ) {
				$interval = $row->task_cron_interval ?? 0;
				if ( $interval >= 86400 ) {
					$days = round($interval / 86400, 1);
					$triggerText = $this->msg( 'wikiautomator-tasklist-trigger-cron-days', $days )->text();
				} elseif ( $interval >= 3600 ) {
					$hours = round($interval / 3600, 1);
					$triggerText = $this->msg( 'wikiautomator-tasklist-trigger-cron-hours', $hours )->text();
				} else {
					$mins = round($interval / 60);
					$triggerText = $this->msg( 'wikiautomator-tasklist-trigger-cron-minutes', $mins )->text();
				}
			} elseif ( $row->task_trigger === 'scheduled' ) {
				$scheduledTime = $row->task_scheduled_time ?? '';
				if ($scheduledTime) {
					$triggerText = $this->msg( 'wikiautomator-tasklist-trigger-scheduled', $this->getLanguage()->timeanddate($scheduledTime) )->text();
				} else {
					$triggerText = $this->msg( 'wikiautomator-tasklist-trigger-scheduled-no-time' )->text();
				}
			}

			// Actions
			$actions = [];
			// Edit (GET is fine for showing form)
			$actions[] = "<a href='$editUrl' class='mw-ui-button mw-ui-quiet mw-ui-progressive'>" . $this->msg( 'wikiautomator-tasklist-btn-edit' )->escaped() . "</a>";

			// Preview (GET, read-only)
			$previewUrl = $this->getPageTitle( 'preview/' . $row->task_id )->getFullURL();
			$actions[] = "<a href='$previewUrl' class='mw-ui-button mw-ui-quiet'>" . $this->msg( 'wikiautomator-tasklist-btn-preview' )->escaped() . "</a>";

			// Run Now (POST)
			$runForm = \MediaWiki\Html\Html::openElement( 'form', [ 'action' => $runUrl, 'method' => 'post', 'style' => 'display:inline;' ] ) .
				\MediaWiki\Html\Html::hidden( 'token', $this->getUser()->getEditToken() ) .
				\MediaWiki\Html\Html::submitButton( $this->msg( 'wikiautomator-tasklist-btn-run' )->text(), [ 'class' => 'mw-ui-button mw-ui-quiet' ] ) .
				\MediaWiki\Html\Html::closeElement( 'form' );
			$actions[] = $runForm;

			// Toggle (POST)
			$toggleForm = \MediaWiki\Html\Html::openElement( 'form', [ 'action' => $toggleUrl, 'method' => 'post', 'style' => 'display:inline;' ] ) .
				\MediaWiki\Html\Html::hidden( 'token', $this->getUser()->getEditToken() ) .
				\MediaWiki\Html\Html::submitButton( $statusBtnText, [ 'class' => 'mw-ui-button mw-ui-quiet' ] ) .
				\MediaWiki\Html\Html::closeElement( 'form' );
			$actions[] = $toggleForm;

			// Delete (POST)
			$delForm = \MediaWiki\Html\Html::openElement( 'form', [ 'action' => $delUrl, 'method' => 'post', 'style' => 'display:inline;' ] ) .
				\MediaWiki\Html\Html::hidden( 'token', $this->getUser()->getEditToken() ) .
				\MediaWiki\Html\Html::submitButton( $this->msg( 'wikiautomator-tasklist-btn-delete' )->text(), [ 'class' => 'mw-ui-button mw-ui-quiet mw-ui-destructive', 'onclick' => "return confirm('" . $this->msg( 'wikiautomator-tasklist-confirm-delete', $row->task_id )->escaped() . "');" ] ) .
				\MediaWiki\Html\Html::closeElement( 'form' );
			$actions[] = $delForm;
			
			$html .= "<tr>
				<td>{$row->task_id}</td>
				<td>" . htmlspecialchars($row->task_name) . "</td>
				<td>$triggerText</td>
				<td>$statusIcon</td>
				<td>$lastRun</td>
				<td>" . implode(' ', $actions) . "</td>
			</tr>";
		}
		$html .= '</table>';

		// Pagination navigation
		$paginationHtml = '<div class="wa-pagination" style="margin-top:10px;">';
		if ( $offset > 0 ) {
			$prevOffset = max( 0, $offset - $limit );
			$prevUrl = $this->getPageTitle()->getFullURL( [ 'offset' => $prevOffset ] );
			$paginationHtml .= "<a href='$prevUrl' class='mw-ui-button'>" . $this->msg( 'wikiautomator-tasklist-prev-page' )->escaped() . "</a> ";
		}
		if ( $hasMore ) {
			$nextOffset = $offset + $limit;
			$nextUrl = $this->getPageTitle()->getFullURL( [ 'offset' => $nextOffset ] );
			$paginationHtml .= "<a href='$nextUrl' class='mw-ui-button'>" . $this->msg( 'wikiautomator-tasklist-next-page' )->escaped() . "</a>";
		}
		$paginationHtml .= '</div>';

		$out->addHTML( $html . $paginationHtml );
	}

	private function showHelpPage() {
		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'wikiautomator-help-title' )->text() );
		$out->addHTML('<a href="' . $this->getPageTitle()->getFullURL() . '">' . $this->msg( 'wikiautomator-back-to-list' )->escaped() . '</a>');
		$out->addWikiTextAsInterface( $this->msg( 'wikiautomator-help-content' )->plain() );
	}
}
