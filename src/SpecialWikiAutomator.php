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
		$toolbar .= '<a href="' . $logLink . '" class="mw-ui-button mw-ui-quiet">执行日志</a> ';
		$toolbar .= '<a href="' . $helpLink . '" class="mw-ui-button mw-ui-quiet">使用手册</a>';
		$toolbar .= '</div><div style="clear:both;"></div>';
		$out->addHTML( $toolbar );

		$this->showCreateForm();
		
		$out->addHTML( '<hr><h2>任务列表</h2>' );
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
				'default' => '<h4 style="margin-top:1em;margin-bottom:0.5em;border-bottom:1px solid #c8ccd1;padding-bottom:0.3em;">1. 触发条件</h4>'
			],
			'TriggerType' => [
				'type' => 'select',
				'label' => '触发模式',
				'options' => [
					'事件触发: 当页面保存时' => 'page_save',
					'事件触发: 当新页面创建时' => 'page_create',
					'事件触发: 当页面分类变动时' => 'category_change',
					'定时触发: 周期执行' => 'cron_custom',
					'定时触发: 指定时间执行' => 'scheduled',
				],
				'default' => $this->mapTriggerToOption($defaults),
				'id' => 'wa-trigger-type'
			],
			'CategoryName' => [
				'type' => 'text',
				'label' => '监听分类名称',
				'help' => '填写分类名称（不含 Category: 前缀）',
				'default' => $defaults['trigger_category'] ?? '',
				'cssclass' => 'wa-category-field'
			],
			'CategoryAction' => [
				'type' => 'select',
				'label' => '监听动作',
				'options' => [
					'加入分类时' => 'add',
					'移除分类时' => 'remove',
					'两者都触发' => 'both'
				],
				'default' => $defaults['category_action'] ?? 'add',
				'cssclass' => 'wa-category-field'
			],
			'CronInterval' => [
				'type' => 'int',
				'label' => '执行间隔（分钟）',
				'help' => '最小间隔为5分钟',
				'default' => $this->getCronIntervalMinutes($defaults),
				'min' => 5,
				'cssclass' => 'wa-cron-field'
			],
			'ScheduledTime' => [
				'type' => 'info',
				'raw' => true,
				'default' => $this->buildScheduledTimeInput($defaults),
				'label' => '执行时间',
				'help' => '任务将在指定时间执行一次后自动禁用（至少5分钟后）。时区可通过 $wgWikiAutomatorTimezone 配置，默认为 Asia/Shanghai。',
				'cssclass' => 'wa-scheduled-field'
			],
			'ConditionTitle' => [
				'type' => 'text',
				'label' => '限制触发页面 (可选)',
				'help' => '留空表示监听所有页面。',
				'default' => $defaults['conditions']['title'] ?? '',
				'cssclass' => 'wa-page-condition'
			],
			'ConditionNS' => [
				'type' => 'multiselect',
				'label' => '限制命名空间 (可选)',
				'help' => '不勾选则监听所有命名空间。',
				'options' => $this->getNamespaceOptions(),
				'default' => $this->parseNamespaceDefaults( $defaults['conditions']['namespace'] ?? '' ),
				'cssclass' => 'wa-page-condition'
			],
			'ActionSectionHeader' => [
				'type' => 'info',
				'raw' => true,
				'default' => '<h4 style="margin-top:1.5em;margin-bottom:0.5em;border-bottom:1px solid #c8ccd1;padding-bottom:0.3em;">2. 执行步骤 (动态)</h4>'
			],
			'TaskSteps' => [
				'type' => 'info',
				'raw' => true,
				'default' => '<div id="wa-steps-container"></div>
					<button type="button" id="wa-add-step" class="mw-ui-button mw-ui-progressive">+ 添加步骤</button>
					<input type="hidden" name="wpTaskStepsJSON" id="wa-steps-json">'
			],
			'OptionsSectionHeader' => [
				'type' => 'info',
				'raw' => true,
				'default' => '<h4 style="margin-top:1.5em;margin-bottom:0.5em;border-bottom:1px solid #c8ccd1;padding-bottom:0.3em;">3. 选项</h4>'
			],
			'EditSummary' => [
				'type' => 'text',
				'label' => '自定义编辑摘要 (可选)',
				'help' => '留空则自动生成摘要（显示操作类型）。此摘要会显示在最近更改中。',
				'default' => $defaults['edit_summary'] ?? ''
			],
			'MatchMode' => [
				'type' => 'select',
				'label' => '匹配模式',
				'options' => [
					'纯文本匹配' => 'literal',
					'通配符匹配' => 'wildcard',
					'正则表达式' => 'regex',
				],
				'default' => $defaults['match_mode'] ?? 'literal'
			],
			'BotEdit' => [
				'type' => 'check',
				'label' => '标记为机器人编辑',
				'help' => '勾选后编辑将在最近更改中标记为机器人编辑（默认隐藏）。',
				'default' => (bool)($defaults['bot_edit'] ?? false)
			],
			'NotifyOwner' => [
				'type' => 'check',
				'label' => '执行后发邮件通知',
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
				if ( in_array( $act['action'], [ 'replace', 'rename' ] ) ) {
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
				$step = [
					'target' => $act['target'],
					'action' => $act['action'],
					'value' => $val
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
				$jsSteps[] = $step;
			}
		}
		return $jsSteps;
	}

	private function mapTriggerToOption($defaults) {
		if (!$defaults) return 'page_save';
		$trigger = $defaults['trigger'] ?? 'page_save';
		$interval = $defaults['cron_interval'] ?? 0;

		if ($trigger === 'page_create') return 'page_create';
		if ($trigger === 'category_change') return 'category_change';
		if ($trigger === 'scheduled') return 'scheduled';
		if ($trigger === 'cron') {
			return 'cron_custom';
		}
		return 'page_save';
	}

	/**
	 * Get namespace options for multiselect form field
	 * @return array label => value pairs
	 */
	private function getNamespaceOptions() {
		$searchEngineConfig = MediaWikiServices::getInstance()->getSearchEngineConfig();
		$namespaces = $searchEngineConfig->searchableNamespaces();
		$options = [];
		foreach ( $namespaces as $ns => $name ) {
			$name = str_replace( '_', ' ', $name );
			if ( $name === '' ) {
				$name = $this->msg( 'blanknamespace' )->text();
			}
			$options["$name ($ns)"] = (int)$ns;
		}
		return $options;
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
		$html .= '<input type="number" name="wpScheduledYear" value="' . htmlspecialchars($year) . '" min="2024" max="2099" style="width:80px;" placeholder="年"> 年';
		$html .= '<input type="number" name="wpScheduledMonth" value="' . htmlspecialchars($month) . '" min="1" max="12" style="width:60px;" placeholder="月"> 月';
		$html .= '<input type="number" name="wpScheduledDay" value="' . htmlspecialchars($day) . '" min="1" max="31" style="width:60px;" placeholder="日"> 日';
		$html .= '<input type="number" name="wpScheduledHour" value="' . htmlspecialchars($hour) . '" min="0" max="23" style="width:60px;" placeholder="时"> 时';
		$html .= '<input type="number" name="wpScheduledMinute" value="' . htmlspecialchars($minute) . '" min="0" max="59" style="width:60px;" placeholder="分"> 分';
		$html .= '<input type="number" name="wpScheduledSecond" value="' . htmlspecialchars($second) . '" min="0" max="59" style="width:60px;" placeholder="秒"> 秒';
		$html .= '<span style="color:#72777d;font-size:0.9em;">(' . htmlspecialchars($timezone) . ')</span>';
		$html .= '</div>';

		return $html;
	}

	private function showCreateForm() {
		$this->getOutput()->addHTML('<h3>创建新任务</h3>');
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
			$this->getOutput()->addHTML( '<div class="errorbox">任务不存在。</div>' );
			return;
		}
		
		if ( !$this->canModify($row) ) {
			$this->getOutput()->addHTML( '<div class="errorbox">权限不足。</div>' );
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

		$this->getOutput()->setPageTitle( '编辑任务: ' . htmlspecialchars($row->task_name) );
		$this->getOutput()->addHTML( '<a href="' . $this->getPageTitle()->getFullURL() . '">← 返回列表</a>' );

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
			return "错误：请至少配置一个执行步骤。";
		}

		$actions = [];
		if ( is_array($steps) ) {
			foreach ( $steps as $step ) {
				if ( empty($step['target']) ) continue;
				$actionData = [
					'type' => 'step_execution',
					'target' => trim($step['target']),
					'action' => $step['action'],
					'value' => $step['value']
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
				$actions[] = $actionData;
			}
		}

		if ( $data['NotifyOwner'] ) {
			$actions[] = [ 'type' => 'email_owner' ];
		}

		// Determine trigger type and related fields
		$triggerType = $data['TriggerType'];
		$trigger = 'page_save';
		$cronInterval = 0;
		$triggerCategory = '';
		$categoryAction = '';
		$scheduledTime = '';

		switch ($triggerType) {
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
					return "错误：分类变动触发模式必须指定监听的分类名称。";
				}
				break;
			case 'cron_custom':
				$trigger = 'cron';
				$intervalMinutes = (int)($data['CronInterval'] ?? 60);
				// Enforce minimum 5 minutes
				if ($intervalMinutes < 5) {
					return "错误：定时间隔最小为5分钟。";
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
					return "错误：指定时间执行模式必须设置执行时间（年月日必填）。";
				}

				// Validate date components form a valid date
				if ( !checkdate( $month, $day, $year ) ) {
					return "错误：无效的日期。请检查年月日是否正确。";
				}

				// Validate time components
				if ( $hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59 ) {
					return "错误：无效的时间。时(0-23)、分(0-59)、秒(0-59)。";
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
					return "错误：执行时间格式不正确。";
				}

				if ($parsed === false) {
					return "错误：执行时间格式不正确。";
				}
				$scheduledTime = \wfTimestamp(\TS_MW, $parsed);
				// Validate: must be at least 5 minutes in the future
				$minTime = time() + 300;
				if (\wfTimestamp(\TS_UNIX, $scheduledTime) < $minTime) {
					return "错误：执行时间必须至少在5分钟之后。";
				}
				break;
		}

		$conditions = [
			'title' => trim($data['ConditionTitle'])
		];

		if ( isset($data['ConditionNS']) && !empty($data['ConditionNS']) ) {
			$nsIds = is_array($data['ConditionNS']) ? $data['ConditionNS'] : [ $data['ConditionNS'] ];
			$namespaceInfo = MediaWikiServices::getInstance()->getNamespaceInfo();
			$validNamespaces = $namespaceInfo->getCanonicalNamespaces();
			$validatedNs = [];
			foreach ( $nsIds as $nsId ) {
				$nsId = (int)$nsId;
				if ( !array_key_exists( $nsId, $validNamespaces ) ) {
					return "错误：无效的命名空间 ID: $nsId";
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
			'task_use_regex' => ($data['MatchMode'] === 'regex') ? 1 : 0,
			'task_match_mode' => $data['MatchMode'],
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
			if ( !$row || !$this->canModify($row) ) return "权限不足或任务不存在";

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
			$this->getOutput()->addHTML( '<div class="errorbox">会话过期或非法请求。请重试。</div>' );
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

			$this->getOutput()->addHTML( '<div class="successbox">任务已删除。</div>' );
		} else {
			$this->getOutput()->addHTML( '<div class="errorbox">无法删除：权限不足或任务不存在。</div>' );
		}
	}

	private function handleToggle( $id ) {
		// Check token
		if ( !$this->getUser()->matchEditToken( $this->getRequest()->getVal( 'token' ) ) ) {
			$this->getOutput()->addHTML( '<div class="errorbox">会话过期或非法请求。请重试。</div>' );
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
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( \DB_REPLICA );
		$row = $dbr->selectRow( 'wa_tasks', '*', [ 'task_id' => $id ] );

		if ( !$row || !$this->canModify( $row ) ) {
			$out->addHTML( '<div class="errorbox">任务不存在或权限不足。</div>' );
			return;
		}

		$out->setPageTitle( '任务预览: ' . htmlspecialchars( $row->task_name ) );
		$out->addHTML( '<a href="' . $this->getPageTitle()->getFullURL() . '">← 返回列表</a>' );

		// Simulated trigger page input
		$request = $this->getRequest();
		$simulatedTitle = $request->getText( 'trigger_page', '' );
		$previewUrl = $this->getPageTitle( 'preview/' . $id )->getFullURL();
		$out->addHTML(
			'<form method="get" action="' . htmlspecialchars( $previewUrl ) . '" style="margin:1em 0;padding:10px;background:#f8f9fa;border:1px solid #c8ccd1;border-radius:4px;">' .
			'<label><strong>模拟触发页面：</strong></label> ' .
			\MediaWiki\Html\Html::input( 'trigger_page', $simulatedTitle, 'text', [
				'size' => 40,
				'placeholder' => '输入页面名以模拟触发（用于解析 {{PAGENAME}}）'
			] ) . ' ' .
			new \OOUI\ButtonInputWidget( [
				'type' => 'submit',
				'label' => '刷新预览',
				'flags' => [ 'progressive' ]
			] ) .
			'</form>'
		);

		$actions = json_decode( $row->task_actions, true );
		if ( !is_array( $actions ) ) {
			$out->addHTML( '<div class="errorbox">任务配置错误。</div>' );
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
		$out->addHTML( '<p style="color:#555;">当前触发页面: <strong>' . htmlspecialchars( $triggerTitle->getPrefixedText() ) . '</strong></p>' );

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

			// --- Resolve target pages ---
			$targetRule = $action['target'] ?? '';
			$targetPages = [];

			if ( $actionType === 'replace' || $actionType === 'rename' ) {
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
				$effectiveMode = $matchMode;
				if ( $effectiveMode === 'auto' ) {
					if ( $useRegex ) {
						$effectiveMode = 'regex';
					} elseif ( strpos( $search, '*' ) !== false ) {
						$effectiveMode = 'wildcard';
					} else {
						$effectiveMode = 'literal';
					}
				}

				// --- Rename preview ---
				if ( $actionType === 'rename' ) {
					if ( $targetRule === '__search__' ) {
						$searchFilters = $action['search_filters'] ?? [];
						$searchLimit = isset( $searchFilters['limit'] ) && $searchFilters['limit'] > 0 ? (int)$searchFilters['limit'] : 500;
						$targetPages = Search::doTitleSearchQuery(
							$search, 'literal',
							$searchFilters['namespaces'] ?? [],
							$searchLimit
						);
					} else {
						$resolved = $this->resolvePreviewTitle( $triggerTitle, $targetRule );
						if ( $resolved ) $targetPages = [ $resolved ];
					}

					foreach ( $targetPages as $target ) {
						if ( !$target->exists() ) continue;
						$oldTitle = $target->getText();
						$newTitle = str_replace( $search, $replace, $oldTitle );
						if ( $newTitle === $oldTitle ) continue;
						$totalMatches++;

						if ( !$hasResults ) {
							$out->addHTML( '<table class="wikitable wa-preview-table" style="width:100%;margin-top:1em;">' );
							$out->addHTML( '<tr><th><input type="checkbox" id="wa-select-all"></th><th>步骤</th><th>动作</th><th>目标页面</th><th>详情</th></tr>' );
							$hasResults = true;
						}

						$out->addHTML( '<tr>' );
						$cbVal = htmlspecialchars( $index . ':' . $target->getPrefixedText() );
						$out->addHTML( '<td><input type="checkbox" name="selected_pages[]" value="' . $cbVal . '" checked></td>' );
						$out->addHTML( '<td>#' . ( $index + 1 ) . '</td>' );
						$out->addHTML( '<td>重命名</td>' );
						$out->addHTML( '<td>' . htmlspecialchars( $target->getPrefixedText() ) . '</td>' );
						$out->addHTML( '<td>' . htmlspecialchars( $oldTitle ) . ' → <strong>' . htmlspecialchars( $newTitle ) . '</strong></td>' );
						$out->addHTML( '</tr>' );
					}
					continue;
				}

				// --- Replace preview ---
				// Show warnings
				$warnings = Search::getWarnings( $search, $replace, $effectiveMode );
				foreach ( $warnings as $warningKey ) {
					$out->addHTML( '<div class="wa-warning">' . $this->msg( $warningKey )->escaped() . '</div>' );
				}

				if ( $targetRule === '__search__' ) {
					$searchFilters = $action['search_filters'] ?? [];
					$searchLimit = isset( $searchFilters['limit'] ) && $searchFilters['limit'] > 0 ? (int)$searchFilters['limit'] : 500;
					$targetPages = Search::doSearchQuery(
						$search, $effectiveMode,
						$searchFilters['namespaces'] ?? [],
						$searchFilters['category'] ?? null,
						$searchFilters['prefix'] ?? null,
						$searchLimit
					);
				} else {
					$resolved = $this->resolvePreviewTitle( $triggerTitle, $targetRule );
					if ( $resolved ) $targetPages = [ $resolved ];
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
						$out->addHTML( '<tr><th><input type="checkbox" id="wa-select-all"></th><th>步骤</th><th>动作</th><th>目标页面</th><th>详情</th></tr>' );
						$hasResults = true;
					}

					$contextHtml = $this->extractContext( $content, $search, $effectiveMode, $action['regex_flags'] ?? [] );

					$out->addHTML( '<tr>' );
					$cbVal = htmlspecialchars( $index . ':' . $target->getPrefixedText() );
					$out->addHTML( '<td><input type="checkbox" name="selected_pages[]" value="' . $cbVal . '" checked></td>' );
					$out->addHTML( '<td>#' . ( $index + 1 ) . '</td>' );
					$out->addHTML( '<td>替换 (' . $matches . '处匹配)</td>' );
					$out->addHTML( '<td>' . htmlspecialchars( $target->getPrefixedText() ) . '</td>' );
					$out->addHTML( '<td class="wa-preview-context"><code>' . htmlspecialchars( $search ) . '</code> → <code>' . htmlspecialchars( $replace ) . '</code><br>' . $contextHtml . '</td>' );
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
							$out->addHTML( '<tr><th><input type="checkbox" id="wa-select-all"></th><th>步骤</th><th>动作</th><th>目标页面</th><th>详情</th></tr>' );
							$hasResults = true;
						}
						$out->addHTML( '<tr>' );
						$cbVal = htmlspecialchars( $index . ':move:' . $mc->getPrefixedText() );
						$out->addHTML( '<td><input type="checkbox" name="selected_pages[]" value="' . $cbVal . '" checked></td>' );
						$out->addHTML( '<td>#' . ( $index + 1 ) . '</td>' );
						$out->addHTML( '<td>标题替换</td>' );
						$out->addHTML( '<td>' . htmlspecialchars( $mc->getPrefixedText() ) . '</td>' );
						$out->addHTML( '<td>' . htmlspecialchars( $oldT ) . ' → <strong>' . htmlspecialchars( $newT ) . '</strong></td>' );
						$out->addHTML( '</tr>' );
					}
				}
				continue;
			}

			// --- Append / Prepend / Overwrite preview ---
			$resolved = $this->resolvePreviewTitle( $triggerTitle, $targetRule );
			if ( !$resolved ) continue;

			if ( !$hasResults ) {
				$out->addHTML( '<table class="wikitable wa-preview-table" style="width:100%;margin-top:1em;">' );
				$out->addHTML( '<tr><th><input type="checkbox" id="wa-select-all"></th><th>步骤</th><th>动作</th><th>目标页面</th><th>详情</th></tr>' );
				$hasResults = true;
			}

			$actionLabels = [
				'append' => '追加内容',
				'prepend' => '前置内容',
				'overwrite' => '覆写内容'
			];
			$actionLabel = $actionLabels[$actionType] ?? $actionType;

			$valueStr = is_string( $value ) ? $value : json_encode( $value, JSON_UNESCAPED_UNICODE );
			$preview = mb_substr( $valueStr, 0, 200 );
			if ( mb_strlen( $valueStr ) > 200 ) $preview .= '...';

			$pageExists = $resolved->exists();
			$existsLabel = $pageExists ? '已存在' : '将创建';
			$currentLen = 0;
			if ( $pageExists ) {
				$services = MediaWikiServices::getInstance();
				$page = $services->getWikiPageFactory()->newFromTitle( $resolved );
				$contentObj = $page->getContent();
				if ( $contentObj instanceof \TextContent ) {
					$currentLen = mb_strlen( $contentObj->getText() );
				}
			}

			$detail = "<strong>$existsLabel</strong>";
			if ( $pageExists ) {
				$detail .= " (当前 {$currentLen} 字符)";
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

		if ( $hasResults ) {
			$out->addHTML( '</table>' );
			if ( $totalMatches > 0 ) {
				$out->addHTML( '<p style="margin-top:0.5em;">总匹配数: <strong>' . $totalMatches . '</strong></p>' );
			}
			$out->addHTML(
				'<div style="margin-top:1em;">' .
				new \OOUI\ButtonInputWidget( [
					'type' => 'submit',
					'label' => '执行选中项',
					'flags' => [ 'primary', 'progressive' ]
				] ) .
				'</div>'
			);
		} else {
			$out->addHTML( '<div class="warningbox" style="margin-top:1em;">此任务没有执行步骤，或未找到匹配内容。</div>' );
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
			$out->addHTML( '<div class="errorbox">会话过期或非法请求。请重试。</div>' );
			return;
		}

		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( \DB_REPLICA );
		$row = $dbr->selectRow( 'wa_tasks', '*', [ 'task_id' => $id ] );

		if ( !$row || !$this->canModify( $row ) ) {
			$out->addHTML( '<div class="errorbox">任务不存在或权限不足。</div>' );
			return;
		}

		$selectedPages = $request->getArray( 'selected_pages', [] );
		if ( empty( $selectedPages ) ) {
			$out->addHTML( '<div class="warningbox">未选择任何页面。</div>' );
			$out->addHTML( '<a href="' . $this->getPageTitle( 'preview/' . $id )->getFullURL() . '">← 返回预览</a>' );
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
		$out->setPageTitle( '执行已提交' );
		$out->addHTML( '<div class="successbox" style="margin:1em 0;">已提交任务「' . htmlspecialchars( $row->task_name ) . '」，选中 ' . $count . ' 项。任务将在后台处理。</div>' );
		$out->addHTML( '<a href="' . $this->getPageTitle()->getFullURL() . '">← 返回列表</a>' );
	}

	/**
	 * Show execution log page
	 */
	private function showLogPage() {
		$out = $this->getOutput();
		$out->setPageTitle( 'WikiAutomator 执行日志' );
		$out->addHTML( '<a href="' . $this->getPageTitle()->getFullURL() . '">← 返回列表</a>' );

		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( \DB_REPLICA );

		// Filters
		$request = $this->getRequest();
		$filterTask = $request->getInt( 'task_id', 0 );
		$filterUser = $request->getText( 'user', '' );
		$filterStatus = $request->getText( 'status', '' );

		$logUrl = $this->getPageTitle( 'log' )->getFullURL();
		$out->addHTML(
			'<form method="get" action="' . htmlspecialchars( $logUrl ) . '" style="margin:1em 0;display:flex;gap:10px;align-items:end;flex-wrap:wrap;">' .
			'<div><label>任务ID</label><br>' . \MediaWiki\Html\Html::input( 'task_id', $filterTask ?: '', 'number', [ 'size' => 6, 'min' => 0 ] ) . '</div>' .
			'<div><label>用户名</label><br>' . \MediaWiki\Html\Html::input( 'user', $filterUser, 'text', [ 'size' => 15 ] ) . '</div>' .
			'<div><label>状态</label><br>' . \MediaWiki\Html\Html::openElement( 'select', [ 'name' => 'status' ] ) .
			'<option value="">全部</option>' .
			'<option value="success"' . ( $filterStatus === 'success' ? ' selected' : '' ) . '>成功</option>' .
			'<option value="partial"' . ( $filterStatus === 'partial' ? ' selected' : '' ) . '>部分成功</option>' .
			'<option value="failed"' . ( $filterStatus === 'failed' ? ' selected' : '' ) . '>失败</option>' .
			'<option value="no_change"' . ( $filterStatus === 'no_change' ? ' selected' : '' ) . '>无变更</option>' .
			'<option value="undone"' . ( $filterStatus === 'undone' ? ' selected' : '' ) . '>已撤销</option>' .
			\MediaWiki\Html\Html::closeElement( 'select' ) . '</div>' .
			'<div><button type="submit" class="mw-ui-button">筛选</button></div>' .
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
			$out->addHTML( '<div class="warningbox" style="margin-top:1em;">暂无执行日志。</div>' );
			return;
		}

		$statusLabels = [
			'success' => '<span style="color:#14866d;">成功</span>',
			'partial' => '<span style="color:#ac6600;">部分成功</span>',
			'failed' => '<span style="color:#d33;">失败</span>',
			'no_change' => '<span style="color:#72777d;">无变更</span>',
			'undone' => '<span style="color:#72777d;text-decoration:line-through;">已撤销</span>'
		];

		$out->addHTML( '<table class="wikitable" style="width:100%;margin-top:1em;">' );
		$out->addHTML( '<tr><th>ID</th><th>时间</th><th>任务</th><th>执行者</th><th>影响页面</th><th>匹配数</th><th>状态</th><th>操作</th></tr>' );

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
				$ops .= '<a href="#" onclick="var e=document.getElementById(\'' . $detailId . '\');e.style.display=e.style.display===\'none\'?\'table-row\':\'none\';return false;">详情(' . $detailCount . ')</a>';
			}
			// Undo button (only for success/partial)
			if ( in_array( $log->log_status, [ 'success', 'partial' ] ) ) {
				$undoUrl = $this->getPageTitle( 'undo/' . $log->log_id )->getFullURL();
				$ops .= ' <a href="' . htmlspecialchars( $undoUrl ) . '" style="color:#d33;">撤销</a>';
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
				$detailHtml .= '<tr><th>页面</th><th>操作</th><th>状态</th><th>匹配数</th></tr>';
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
				if ( !empty( $regexFlags['U'] ) ) $flags .= 'U';
				$pattern = '/' . str_replace( '/', '\/', $pattern ) . '/' . $flags;
			}
		} else {
			$pattern = '/' . preg_quote( $search, '/' ) . '/u';
		}

		$matches = [];
		$result = @preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE );
		if ( $result === false || $result === 0 ) {
			return '<em>无匹配</em>';
		}

		$snippets = [];
		$maxSnippets = 3;
		foreach ( $matches[0] as $i => $match ) {
			if ( $i >= $maxSnippets ) {
				$remaining = $result - $maxSnippets;
				$snippets[] = "<em>...还有 {$remaining} 处匹配</em>";
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

	private function handleRunNow( $id ) {
		// Check token
		if ( !$this->getUser()->matchEditToken( $this->getRequest()->getVal( 'token' ) ) ) {
			$this->getOutput()->addHTML( '<div class="errorbox">会话过期或非法请求。请重试。</div>' );
			return;
		}

		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( \DB_REPLICA );
		$row = $dbr->selectRow( 'wa_tasks', '*', [ 'task_id' => $id ] );

		if ( !$row || !$this->canModify($row) ) {
			$this->getOutput()->addHTML( '<div class="errorbox">无法执行：权限不足或任务不存在。</div>' );
			return;
		}

		// Create and run the job immediately
		$actions = json_decode( $row->task_actions, true );
		if ( json_last_error() !== JSON_ERROR_NONE || !is_array( $actions ) ) {
			$this->getOutput()->addHTML( '<div class="errorbox">任务配置错误：无效的动作数据。</div>' );
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
				$this->getOutput()->addHTML( '<div class="successbox">任务已执行。</div>' );
			} else {
				$this->getOutput()->addHTML( '<div class="warningbox">任务执行完成，但可能没有进行任何更改。</div>' );
			}
		} catch ( \Exception $e ) {
			// Log the full error for debugging, but show generic message to user
			$logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'WikiAutomator' );
			$logger->error( "Task execution failed: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() );
			$this->getOutput()->addHTML( '<div class="errorbox">执行失败，请查看日志获取详细信息。</div>' );
		}

		// Update last run time
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( \DB_PRIMARY );
		$dbw->update( 'wa_tasks', [ 'task_last_run' => \wfTimestampNow() ], [ 'task_id' => $id ] );
		$this->invalidateTaskCache( $id );
	}

	private function handleFixDB() {
		$out = $this->getOutput();
		if ( !$this->getUser()->isAllowed('manage-automation') ) {
			$out->addHTML( '<div class="errorbox">权限不足。</div>' );
			return;
		}
		$out->setPageTitle( '数据库结构修复' );
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
		$out->addHTML( '<a href="' . $this->getPageTitle()->getFullURL() . '">← 返回列表</a>' );
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
			'<div><label>创建者</label><br><select name="filter_owner">' .
			'<option value="">全部</option>' .
			'<option value="__me__"' . ( $filterOwner === '__me__' ? ' selected' : '' ) . '>我的任务</option>' .
			'</select></div>' .
			'<div><label>状态</label><br><select name="filter_enabled">' .
			'<option value="">全部</option>' .
			'<option value="1"' . ( $filterEnabled === '1' ? ' selected' : '' ) . '>启用</option>' .
			'<option value="0"' . ( $filterEnabled === '0' ? ' selected' : '' ) . '>禁用</option>' .
			'</select></div>' .
			'<div><label>触发类型</label><br><select name="filter_trigger">' .
			'<option value="">全部</option>' .
			'<option value="page_save"' . ( $filterTrigger === 'page_save' ? ' selected' : '' ) . '>页面保存</option>' .
			'<option value="page_create"' . ( $filterTrigger === 'page_create' ? ' selected' : '' ) . '>页面创建</option>' .
			'<option value="category_change"' . ( $filterTrigger === 'category_change' ? ' selected' : '' ) . '>分类变更</option>' .
			'<option value="cron"' . ( $filterTrigger === 'cron' ? ' selected' : '' ) . '>定时任务</option>' .
			'<option value="scheduled"' . ( $filterTrigger === 'scheduled' ? ' selected' : '' ) . '>计划任务</option>' .
			'</select></div>' .
			'<div><label>匹配模式</label><br><select name="filter_mode">' .
			'<option value="">全部</option>' .
			'<option value="literal"' . ( $filterMode === 'literal' ? ' selected' : '' ) . '>纯文本</option>' .
			'<option value="wildcard"' . ( $filterMode === 'wildcard' ? ' selected' : '' ) . '>通配符</option>' .
			'<option value="regex"' . ( $filterMode === 'regex' ? ' selected' : '' ) . '>正则</option>' .
			'</select></div>' .
			'<div><button type="submit" class="mw-ui-button mw-ui-progressive">筛选</button>' .
			( $hasFilters ? ' <a href="' . htmlspecialchars( $baseUrl ) . '" class="mw-ui-button mw-ui-quiet">清除</a>' : '' ) .
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
		$html .= '<tr><th>ID</th><th>任务名称</th><th>触发器</th><th>状态</th><th>最后运行</th><th>操作</th></tr>';

		foreach ( $rows as $rowArr ) {
			$row = (object)$rowArr;
			$editUrl = $this->getPageTitle( 'edit/' . $row->task_id )->getFullURL();
			$delUrl = $this->getPageTitle( 'delete/' . $row->task_id )->getFullURL();
			$toggleUrl = $this->getPageTitle( 'toggle/' . $row->task_id )->getFullURL();
			$runUrl = $this->getPageTitle( 'run/' . $row->task_id )->getFullURL();

			$statusIcon = $row->task_enabled ? '启用' : '禁用';
			$statusBtnText = $row->task_enabled ? '暂停' : '启用';
			$lastRun = $row->task_last_run ? $this->getLanguage()->timeanddate($row->task_last_run) : '-';

			// Translate trigger type
			$triggerText = $row->task_trigger;
			if ( $row->task_trigger === 'page_save' ) {
				$triggerText = '页面保存';
			} elseif ( $row->task_trigger === 'page_create' ) {
				$triggerText = '页面创建';
			} elseif ( $row->task_trigger === 'category_change' ) {
				$cat = $row->task_trigger_category ?? '';
				$action = $row->task_category_action ?? '';
				$actionText = $action === 'add' ? '加入' : ($action === 'remove' ? '移除' : '变动');
				$triggerText = "分类{$actionText}: {$cat}";
			} elseif ( $row->task_trigger === 'cron' ) {
				$interval = $row->task_cron_interval ?? 0;
				if ( $interval >= 86400 ) {
					$days = round($interval / 86400, 1);
					$triggerText = "定时 (每{$days}天)";
				} elseif ( $interval >= 3600 ) {
					$hours = round($interval / 3600, 1);
					$triggerText = "定时 (每{$hours}小时)";
				} else {
					$mins = round($interval / 60);
					$triggerText = "定时 (每{$mins}分钟)";
				}
			} elseif ( $row->task_trigger === 'scheduled' ) {
				$scheduledTime = $row->task_scheduled_time ?? '';
				if ($scheduledTime) {
					$triggerText = '指定时间: ' . $this->getLanguage()->timeanddate($scheduledTime);
				} else {
					$triggerText = '指定时间';
				}
			}

			// Actions
			$actions = [];
			// Edit (GET is fine for showing form)
			$actions[] = "<a href='$editUrl' class='mw-ui-button mw-ui-quiet mw-ui-progressive'>编辑</a>";

			// Preview (GET, read-only)
			$previewUrl = $this->getPageTitle( 'preview/' . $row->task_id )->getFullURL();
			$actions[] = "<a href='$previewUrl' class='mw-ui-button mw-ui-quiet'>预览</a>";

			// Run Now (POST)
			$runForm = \MediaWiki\Html\Html::openElement( 'form', [ 'action' => $runUrl, 'method' => 'post', 'style' => 'display:inline;' ] ) .
				\MediaWiki\Html\Html::hidden( 'token', $this->getUser()->getEditToken() ) .
				\MediaWiki\Html\Html::submitButton( '执行', [ 'class' => 'mw-ui-button mw-ui-quiet' ] ) .
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
				\MediaWiki\Html\Html::submitButton( '删除', [ 'class' => 'mw-ui-button mw-ui-quiet mw-ui-destructive', 'onclick' => "return confirm('确定要删除任务 #{$row->task_id} 吗？');" ] ) .
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
			$paginationHtml .= "<a href='$prevUrl' class='mw-ui-button'>← 上一页</a> ";
		}
		if ( $hasMore ) {
			$nextOffset = $offset + $limit;
			$nextUrl = $this->getPageTitle()->getFullURL( [ 'offset' => $nextOffset ] );
			$paginationHtml .= "<a href='$nextUrl' class='mw-ui-button'>下一页 →</a>";
		}
		$paginationHtml .= '</div>';

		$out->addHTML( $html . $paginationHtml );
	}

	private function showHelpPage() {
		$out = $this->getOutput();
		$out->setPageTitle('使用文档');
		$out->addHTML('<a href="' . $this->getPageTitle()->getFullURL() . '">← 返回列表</a>');
		$out->addWikiTextAsInterface( <<<'WIKITEXT'
== 快速入门 ==
本工具允许您创建自动化机器人，自动对 Wiki 页面进行维护。

=== 1. 触发模式 ===
* **页面保存时**: 监听特定页面的编辑动作。可以配合 **限制页面** 选项，例如只监听 Project:Log 的变更。
* **页面创建时**: 仅在新页面创建时触发，编辑现有页面不会触发。
* **分类变动时**: 当页面加入或移除指定分类时触发。可选择监听「加入」、「移除」或「两者」。
* **定时周期执行**: 按自定义间隔周期执行（最小5分钟）。
* **指定时间执行**: 一次性任务，在指定时间执行后自动禁用。

=== 2. 执行步骤 ===
您最多可以配置 5 个连续步骤。每个步骤可以针对不同页面。
* **目标页面**: 要修改的页面标题。支持变量 <code><nowiki>{{PAGENAME}}</nowiki></code> (仅限事件触发模式)。
* **动作类型**:
** **追加**: 在页面末尾添加内容。
** **覆写**: 清空原有内容并写入新内容。
** **替换**: 查找并替换文本。
*** 格式：<code>查找内容|替换内容</code>
*** 示例：<code>苹果|香蕉</code> (将苹果替换为香蕉)

== 通配符 (推荐) ==
通配符是简化版的模式匹配，专为 MediaWiki 语法设计，无需开启正则选项即可使用。

=== 基本语法 ===
使用 <code>*</code> 匹配任意内容。

{| class="wikitable"
! 通配符模式 !! 匹配内容 !! 示例
|-
| <code><nowiki>{{*}}</nowiki></code> || 任意模板 || 匹配 <code><nowiki>{{模板名}}</nowiki></code>、<code><nowiki>{{模板|参数}}</nowiki></code> 等
|-
| <code><nowiki>[[*]]</nowiki></code> || 任意链接 || 匹配 <code><nowiki>[[页面]]</nowiki></code>、<code><nowiki>[[页面|显示文字]]</nowiki></code> 等
|-
| <code><nowiki><!--*--></nowiki></code> || 任意注释 || 匹配 <code><nowiki><!-- 任何注释内容 --></nowiki></code>
|-
| <code>*</code> || 任意文本 || 匹配任意字符（非贪婪）
|}

=== 通配符示例 ===
{| class="wikitable"
! 查找 !! 替换 !! 效果
|-
| <code><nowiki>{{过时*}}</nowiki></code> || <code><nowiki>{{已更新}}</nowiki></code> || 将 <code><nowiki>{{过时}}</nowiki></code>、<code><nowiki>{{过时|原因}}</nowiki></code> 等替换为 <code><nowiki>{{已更新}}</nowiki></code>
|-
| <code><nowiki>{{删除*}}</nowiki></code> || （留空） || 删除所有删除相关模板
|-
| <code><nowiki>[[分类:*]]</nowiki></code> || （留空） || 删除所有分类
|-
| <code><nowiki><!--*--></nowiki></code> || （留空） || 删除所有 HTML 注释
|-
| <code><nowiki>{{stub*}}</nowiki></code> || <code><nowiki>{{小作品}}</nowiki></code> || 统一小作品模板名称
|}

== 正则表达式 (高级) ==
开启「正则表达式」选项后，查找内容将作为正则表达式处理，适合复杂的模式匹配。

=== 常用正则符号 ===
{| class="wikitable"
! 符号 !! 含义 !! 示例
|-
| <code>\d</code> || 数字 (0-9) || <code>\d+</code> 匹配一个或多个数字
|-
| <code>\s</code> || 空白字符 || <code>\s+</code> 匹配空格、换行等
|-
| <code>.</code> || 任意单个字符 || <code>a.c</code> 匹配 abc、aXc 等
|-
| <code>*</code> || 零个或多个 || <code>ab*c</code> 匹配 ac、abc、abbc 等
|-
| <code>+</code> || 一个或多个 || <code>ab+c</code> 匹配 abc、abbc 等
|-
| <code>?</code> || 零个或一个 / 非贪婪 || <code>.*?</code> 非贪婪匹配
|-
| <code>()</code> || 捕获组 || 用 <code>$1</code>、<code>$2</code> 引用
|-
| <code>\n</code> || 换行符 || 匹配换行
|}

=== 正则示例 ===
{| class="wikitable"
! 查找 !! 替换 !! 效果
|-
| <code>\d+</code> || <code>XXX</code> || 将所有数字替换为 XXX
|-
| <code>\n\n\n+</code> || <code>\n\n</code> || 将多个连续空行压缩为一个
|-
| <code>(\d{4})-(\d{2})-(\d{2})</code> || <code>$1年$2月$3日</code> || 日期格式转换：2025-01-31 → 2025年01月31日
|-
| <code>\{\{[Ss]tub\}\}</code> || <code><nowiki>{{小作品}}</nowiki></code> || 替换 stub/Stub 模板（需转义大括号）
|-
| <code><nowiki>\[\[分类:[^\]]+\]\]</nowiki></code> || （留空） || 删除所有分类标签
|}

=== 通配符 vs 正则 ===
{| class="wikitable"
! 特性 !! 通配符 !! 正则表达式
|-
| 学习难度 || 简单 || 较复杂
|-
| 选择方式 || 匹配模式选「通配符」 || 匹配模式选「正则表达式」
|-
| MediaWiki 语法支持 || 内置优化 || 需手动转义
|-
| 适用场景 || 模板、链接、注释等常见操作 || 复杂模式、捕获组替换、正则标志
|}

<strong>建议</strong>: 优先使用通配符，只有在需要捕获组、正则标志或复杂匹配时才使用正则。

=== 正则表达式基础教程 ===
正则表达式是用于匹配文本模式的工具。

==== 常用元字符 ====
{| class="wikitable"
! 符号 !! 含义 !! 示例
|-
| <code>.</code> || 匹配任意单个字符 || <code>a.c</code> 匹配 abc, a1c, a-c
|-
| <code>^</code> || 匹配开头 || <code>^Hello</code> 匹配以 Hello 开头的文本
|-
| <code>$</code> || 匹配结尾 || <code>end$</code> 匹配以 end 结尾的文本
|-
| <code>*</code> || 前一个字符出现 0 次或多次 || <code>ab*c</code> 匹配 ac, abc, abbc
|-
| <code>+</code> || 前一个字符出现 1 次或多次 || <code>ab+c</code> 匹配 abc, abbc，不匹配 ac
|-
| <code>?</code> || 前一个字符出现 0 次或 1 次 || <code>colou?r</code> 匹配 color 和 colour
|-
| <code>\</code> || 转义特殊字符 || <code>\.</code> 匹配实际的点号
|}

==== 字符类 ====
{| class="wikitable"
! 符号 !! 含义
|-
| <code>[abc]</code> || 匹配 a、b 或 c 中的任意一个
|-
| <code>[a-z]</code> || 匹配任意小写字母
|-
| <code>[A-Z]</code> || 匹配任意大写字母
|-
| <code>[0-9]</code> || 匹配任意数字
|-
| <code>[^abc]</code> || 匹配除了 a、b、c 以外的字符
|}

==== 预定义字符类 ====
{| class="wikitable"
! 符号 !! 含义
|-
| <code>\d</code> || 数字，等同于 <code>[0-9]</code>
|-
| <code>\w</code> || 单词字符，等同于 <code>[a-zA-Z0-9_]</code>
|-
| <code>\s</code> || 空白字符（空格、制表符、换行）
|-
| <code>\D</code> || 非数字
|-
| <code>\W</code> || 非单词字符
|-
| <code>\S</code> || 非空白字符
|}

==== 量词 ====
{| class="wikitable"
! 符号 !! 含义
|-
| <code>{n}</code> || 恰好 n 次
|-
| <code>{n,}</code> || 至少 n 次
|-
| <code>{n,m}</code> || n 到 m 次
|}

示例：<code>\d{3}</code> 匹配恰好 3 个数字，如 123

==== 分组与捕获 ====
{| class="wikitable"
! 符号 !! 含义
|-
| <code>(abc)</code> || 捕获组，匹配并记住 abc
|-
| <code>$1</code>, <code>$2</code> || 在替换时引用第 1、2 个捕获组
|-
| <code>(?:abc)</code> || 非捕获组，匹配但不记住
|}

==== 实用示例 ====
{| class="wikitable"
! 正则表达式 !! 用途
|-
| <code>\d+\.\d+\.\d+</code> || 匹配版本号，如 1.0.1, 10.25.300
|-
| <code>\w+@\w+\.\w+</code> || 匹配邮箱（简化版），如 test@example.com
|-
| <code>[\u4e00-\u9fa5]+</code> || 匹配连续中文字符
|-
| <code>^(\d)</code> || 匹配以数字开头的行，并捕获该数字
|}

== 安全限制 ==
* 定时任务最小间隔：5分钟
* 指定时间任务必须设置在至少5分钟之后
* 指定时间任务执行后会自动禁用（保留记录）

== 匹配模式 ==
替换操作现在支持三种显式匹配模式，通过「匹配模式」下拉框选择：

{| class="wikitable"
! 模式 !! 说明 !! 适用场景
|-
| '''纯文本''' || 精确匹配字面文本，使用 <code>str_replace</code> || 简单的文本替换
|-
| '''通配符''' || 使用 <code>*</code> 匹配任意内容，专为 MediaWiki 语法优化 || 模板、链接、注释等
|-
| '''正则表达式''' || 完整的 PCRE 正则支持，包括捕获组 || 复杂模式匹配
|-
| '''自动''' || 兼容旧任务：开启正则则用正则，含 <code>*</code> 则用通配符，否则纯文本 || 仅用于迁移旧任务
|}

<strong>建议</strong>：新任务请明确选择匹配模式，避免使用「自动」。

== 预览功能 ==
在任务列表中点击「预览」按钮，可以在不执行任务的情况下查看：
* 哪些页面会被影响
* 每个页面有多少处匹配
* 匹配内容的上下文（高亮显示）
* 空替换和替换内容已存在的警告提示

== 搜索目标页面 ==
替换步骤支持「搜索目标页面」模式。启用后，系统会在数据库中搜索包含匹配内容的页面，而不是手动指定目标页面。

可配置的过滤条件：
* '''命名空间'''：限制搜索范围（如 0=主条目，2=用户页）
* '''分类'''：仅搜索指定分类中的页面
* '''标题前缀'''：仅搜索标题以指定前缀开头的页面

== 页面重命名 ==
动作类型新增「重命名页面」，可以对页面标题进行查找替换：
* 格式与文本替换相同：<code>旧文本|新文本</code>
* 可选择是否从旧标题创建重定向
* 支持搜索目标页面模式批量重命名

== 正则标志选项 ==
选择「正则表达式」匹配模式后，替换步骤会显示额外的标志选项：
* '''不区分大小写 (i)'''：匹配时忽略大小写
* '''多行模式 (m)'''：<code>^</code> 和 <code>$</code> 匹配每行的开头和结尾
* '''非贪婪模式 (U)'''：量词默认非贪婪匹配

=== 捕获组引用 ===
正则模式下，替换内容中可以使用 <code>$1</code>、<code>$2</code> 等引用捕获组：

{| class="wikitable"
! 查找 !! 替换 !! 效果
|-
| <code>(\d{4})-(\d{2})-(\d{2})</code> || <code>$1年$2月$3日</code> || 2025-01-31 → 2025年01月31日
|-
| <code>(Category):(\w+)</code> || <code>分类:$2</code> || Category:Test → 分类:Test
|}
WIKITEXT
		);
	}
}
