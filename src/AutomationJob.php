<?php

namespace WikiAutomator;

use Job;
use MediaWiki\Title\Title;
use MediaWiki\MediaWikiServices;

class AutomationJob extends Job {
	/** @var int Maximum regex execution time in seconds */
	private const REGEX_TIMEOUT = 5;

	/** @var int Maximum regex pattern length */
	private const MAX_PATTERN_LENGTH = 1000;

	/** @var array Dangerous regex patterns that could cause ReDoS */
	private static $dangerousPatterns = [
		'/\([^)]*[\+\*][^)]*\)[\+\*]/',       // (group with quantifier) followed by quantifier
		'/\([^)]*[\+\*][^)]*\)\{/',            // (group with quantifier) followed by {n,m}
		'/\([^)]*\{[^}]+\}[^)]*\)[\+\*\{]/',  // (group with {n,m}) followed by quantifier
	];

	/**
	 * Validate a regex pattern for safety
	 * @param string $pattern The regex pattern to validate
	 * @return bool|string True if valid, error message string if invalid
	 */
	private function validateRegexPattern( $pattern ) {
		// Check pattern length
		if ( strlen( $pattern ) > self::MAX_PATTERN_LENGTH ) {
			return 'Pattern too long (max ' . self::MAX_PATTERN_LENGTH . ' characters)';
		}

		// Check for dangerous patterns that could cause ReDoS
		foreach ( self::$dangerousPatterns as $dangerous ) {
			if ( preg_match( $dangerous, $pattern ) ) {
				return 'Pattern contains potentially dangerous nested quantifiers';
			}
		}

		// Test if pattern is valid by attempting a match on empty string
		$result = @preg_match( $pattern, '' );
		if ( $result === false ) {
			$error = preg_last_error();
			$errorMessages = [
				PREG_NO_ERROR => 'Unknown error',
				PREG_INTERNAL_ERROR => 'Internal PCRE error',
				PREG_BACKTRACK_LIMIT_ERROR => 'Backtrack limit exhausted',
				PREG_RECURSION_LIMIT_ERROR => 'Recursion limit exhausted',
				PREG_BAD_UTF8_ERROR => 'Malformed UTF-8 data',
				PREG_BAD_UTF8_OFFSET_ERROR => 'Bad UTF-8 offset',
			];
			if ( defined( 'PREG_JIT_STACKLIMIT_ERROR' ) ) {
				$errorMessages[PREG_JIT_STACKLIMIT_ERROR] = 'JIT stack limit exhausted';
			}
			return $errorMessages[$error] ?? 'Invalid regex pattern';
		}

		return true;
	}

	/**
	 * Safely execute preg_replace with timeout protection
	 * @param string $pattern Regex pattern
	 * @param string $replacement Replacement string
	 * @param string $subject Subject string
	 * @param \Psr\Log\LoggerInterface $logger Logger instance
	 * @return string|null Result or null on error
	 */
	private function safeRegexReplace( $pattern, $replacement, $subject, $logger ) {
		// Validate pattern first
		$validation = $this->validateRegexPattern( $pattern );
		if ( $validation !== true ) {
			$logger->error( "Task {$this->params['task_id']}: Regex validation failed: $validation. Pattern: $pattern" );
			return null;
		}

		// Set PCRE limits for this operation
		$oldBacktrackLimit = ini_get( 'pcre.backtrack_limit' );
		$oldRecursionLimit = ini_get( 'pcre.recursion_limit' );

		// Use conservative limits to prevent ReDoS
		ini_set( 'pcre.backtrack_limit', 100000 );
		ini_set( 'pcre.recursion_limit', 10000 );

		$result = @preg_replace( $pattern, $replacement, $subject );

		// Restore original limits
		ini_set( 'pcre.backtrack_limit', $oldBacktrackLimit );
		ini_set( 'pcre.recursion_limit', $oldRecursionLimit );

		if ( $result === null ) {
			$errorCode = preg_last_error();
			$logger->error( "Task {$this->params['task_id']}: Regex execution failed with error code $errorCode. Pattern: $pattern" );
			return null;
		}

		return $result;
	}

	public function __construct( $title, array $params ) {
		parent::__construct( 'WikiAutomatorJob', $title, $params );
	}

	public function run() {
		$logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'WikiAutomator' );
		$taskId = $this->params['task_id'];
		$logger->info( "Starting execution for Task ID $taskId" );

		// Context: The page that triggered this task
		$triggerTitle = $this->title;
		$actions = $this->params['actions'];
		$ownerId = $this->params['owner_id'];
		$useRegex = isset($this->params['use_regex']) ? $this->params['use_regex'] : false;
		$matchMode = $this->params['match_mode'] ?? 'auto';

		// Default target rule (legacy compatibility)
		$defaultTargetRule = isset($this->params['target_rule']) ? $this->params['target_rule'] : '';

		// Track edited pages for email notification
		$editedPages = [];
		$shouldSendEmail = false;

		// Iterate over all actions
		foreach ( $actions as $index => $action ) {
			$type = $action['type'] ?? '';
			$value = $action['value'] ?? '';

			$logger->info( "Task $taskId: Processing action #$index (Type: $type)" );

			// === Branch A: Structured Step Mode (V5.0) ===
			if ( $type === 'step_execution' ) {
				$actionType = $action['action'] ?? 'append';
				$regexFlags = $action['regex_flags'] ?? [];
				$targetRule = $action['target'] ?? '';

				// Determine target pages
				$targets = [];
				if ( $targetRule === '__search__' ) {
					// Dynamic search mode: find pages via database query
					$searchFilters = $action['search_filters'] ?? [];
					$searchValue = $action['value'] ?? '';
					$searchStr = '';
					if ( is_array( $searchValue ) && isset( $searchValue['search'] ) ) {
						$searchStr = $searchValue['search'];
					}
					if ( $searchStr !== '' ) {
						$effectiveMode = $matchMode;
						if ( $effectiveMode === 'auto' ) {
							if ( $useRegex ) {
								$effectiveMode = 'regex';
							} elseif ( $this->hasWildcards( $searchStr ) ) {
								$effectiveMode = 'wildcard';
							} else {
								$effectiveMode = 'literal';
							}
						}
						$searchLimit = isset( $searchFilters['limit'] ) && $searchFilters['limit'] > 0 ? (int)$searchFilters['limit'] : 500;
						$targets = Search::doSearchQuery(
							$searchStr,
							$effectiveMode,
							$searchFilters['namespaces'] ?? [],
							$searchFilters['category'] ?? null,
							$searchFilters['prefix'] ?? null,
							$searchLimit
						);
						$logger->info( "Task $taskId: Search found " . count( $targets ) . " target pages" );
					}
				} else {
					$resolved = $this->resolveTitle( $triggerTitle, $targetRule );
					if ( $resolved ) {
						$targets = [ $resolved ];
					}
				}

				// Filter targets by selected pages (from preview selective execution)
				if ( isset( $action['_selected_pages'] ) ) {
					$allowed = $action['_selected_pages'];
					$targets = array_filter( $targets, function( $t ) use ( $allowed ) {
						foreach ( $allowed as $a ) {
							// Match page name directly, or with "move:" prefix stripped
							$pageName = preg_replace( '/^move:/', '', $a );
							if ( $t->getPrefixedText() === $pageName ) return true;
						}
						return false;
					} );
					$targets = array_values( $targets );
				}

				foreach ( $targets as $target ) {
					$logger->info( "Task $taskId: Processing target '" . $target->getPrefixedText() . "'" );
					try {
						$result = $this->performEdit( $target, $actionType, $action['value'] ?? '', $matchMode, $useRegex, $regexFlags );
						$editedPages[] = [
							'page' => $target->getPrefixedText(),
							'action' => $this->getActionDescription( $actionType ),
							'status' => $result['changed'] ? '成功' : '无变更',
							'match_count' => $result['match_count']
						];
					} catch ( \Throwable $e ) {
						$editedPages[] = [
							'page' => $target->getPrefixedText(),
							'action' => $this->getActionDescription( $actionType ),
							'status' => '失败: ' . $e->getMessage()
						];
						$logger->error( "Task $taskId: performEdit threw exception: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() );
					}
				}

				// Move pages: also replace text in page titles when possible
				if ( !empty( $action['move_pages'] ) && $actionType === 'replace' ) {
					$searchValue = $action['value'] ?? '';
					$searchStr = '';
					$replaceStr = '';
					if ( is_array( $searchValue ) && isset( $searchValue['search'], $searchValue['replace'] ) ) {
						$searchStr = $searchValue['search'];
						$replaceStr = $searchValue['replace'];
					}
					if ( $searchStr !== '' ) {
						// Find pages with matching titles
						$moveCandidates = [];
						foreach ( $targets as $target ) {
							if ( strpos( $target->getText(), $searchStr ) !== false ) {
								$moveCandidates[] = $target;
							}
						}
						// Also search for additional title matches if using search mode
						if ( $targetRule === '__search__' ) {
							$searchFilters = $action['search_filters'] ?? [];
							$titleMatches = Search::doTitleSearchQuery(
								$searchStr, 'literal',
								$searchFilters['namespaces'] ?? [],
								500
							);
							foreach ( $titleMatches as $tm ) {
								// Avoid duplicates
								$dominated = false;
								foreach ( $moveCandidates as $mc ) {
									if ( $mc->equals( $tm ) ) { $dominated = true; break; }
								}
								if ( !$dominated ) $moveCandidates[] = $tm;
							}
						}

						// Filter move candidates by selected pages
						if ( isset( $action['_selected_pages'] ) ) {
							$allowed = $action['_selected_pages'];
							$moveCandidates = array_filter( $moveCandidates, function( $c ) use ( $allowed ) {
								return in_array( 'move:' . $c->getPrefixedText(), $allowed );
							} );
							$moveCandidates = array_values( $moveCandidates );
						}

						$services = MediaWikiServices::getInstance();
						$userFactory = $services->getUserFactory();
						$ownerId = $this->params['owner_id'] ?? 0;
						$moveUser = null;
						if ( $ownerId > 0 ) {
							$moveUser = $userFactory->newFromId( $ownerId );
							if ( !$moveUser || !$moveUser->getId() ) $moveUser = null;
						}
						if ( !$moveUser ) {
							$moveUser = $userFactory->newSystemUser( 'WikiAutomatorBot', [ 'steal' => true ] );
						}

						foreach ( $moveCandidates as $candidate ) {
							if ( !$candidate->exists() ) continue;
							$oldText = $candidate->getText();
							$newText = str_replace( $searchStr, $replaceStr, $oldText );
							if ( $newText === $oldText ) continue;

							$newTitle = Title::makeTitleSafe( $candidate->getNamespace(), $newText );
							if ( !$newTitle ) {
								$logger->warning( "Task $taskId: Invalid new title '$newText' for move" );
								continue;
							}

							try {
								$movePage = $services->getMovePageFactory()->newMovePage( $candidate, $newTitle );
								$moveStatus = $movePage->isValidMove();
								if ( !$moveStatus->isOK() ) {
									$logger->warning( "Task $taskId: Cannot move " . $candidate->getPrefixedText() . ": " . $moveStatus->getMessage()->text() );
									$editedPages[] = [
										'page' => $candidate->getPrefixedText(),
										'action' => '重命名',
										'status' => '不可移动',
										'match_count' => 0
									];
									continue;
								}
								$reason = $this->buildEditSummary( 'rename', $searchValue, $matchMode );
								$status = $movePage->move( $moveUser, $reason, true );
								$editedPages[] = [
									'page' => $candidate->getPrefixedText(),
									'action' => '重命名 → ' . $newTitle->getPrefixedText(),
									'status' => $status->isOK() ? '成功' : '失败',
									'match_count' => 1
								];
								if ( $status->isOK() ) {
									$logger->info( "Task $taskId: Moved " . $candidate->getPrefixedText() . " to " . $newTitle->getPrefixedText() );
								}
							} catch ( \Throwable $e ) {
								$editedPages[] = [
									'page' => $candidate->getPrefixedText(),
									'action' => '重命名',
									'status' => '失败: ' . $e->getMessage(),
									'match_count' => 0
								];
								$logger->error( "Task $taskId: Move failed: " . $e->getMessage() );
							}
						}
					}
				}

				if ( empty( $targets ) && $targetRule !== '__search__' ) {
					$logger->warning( "Task $taskId: Failed to resolve target '$targetRule'" );
				}
				continue;
			}

			// === Branch B: Advanced Script Mode (Multi-Target) ===
			if ( $type === 'advanced_script' ) {
				$scriptResults = $this->runScript( $value, $triggerTitle, $matchMode, $useRegex );
				$editedPages = array_merge( $editedPages, $scriptResults );
				continue;
			}

			// === Branch C: Email Notification (mark for later) ===
			if ( $type === 'email_owner' ) {
				$shouldSendEmail = true;
				continue;
			}

			// === Branch D: Simple Single Page Mode ===
			$targetTitle = $this->resolveTitle( $triggerTitle, $defaultTargetRule );
			if ( !$targetTitle ) continue;
			$result = $this->performEdit( $targetTitle, $type, $value, $matchMode, $useRegex );
			$editedPages[] = [
				'page' => $targetTitle->getPrefixedText(),
				'action' => $this->getActionDescription( $type ),
				'status' => $result['changed'] ? '成功' : '无变更',
				'match_count' => $result['match_count']
			];
		}

		// Send email notification at the end with all collected info
		if ( $shouldSendEmail ) {
			$reportTarget = $defaultTargetRule ? $this->resolveTitle($triggerTitle, $defaultTargetRule) : $triggerTitle;
			$this->sendNotificationEmail( $ownerId, $reportTarget ? $reportTarget->getPrefixedText() : 'Unknown', $editedPages );
		}

		// Write execution log
		$this->writeExecutionLog( $editedPages );

		return true;
	}

	/**
	 * Get human-readable action description
	 */
	private function getActionDescription( $action ) {
		switch ( $action ) {
			case 'overwrite': return '覆写内容';
			case 'append': return '追加内容';
			case 'prepend': return '前置内容';
			case 'replace': return '替换文本';
			case 'rename': return '重命名页面';
			default: return $action;
		}
	}

	/**
	 * Parse and execute multi-line script
	 * Syntax: Target | Action | Value
	 * @return array Results of each edit
	 */
	private function runScript( $script, $triggerTitle, $matchMode, $useRegex ) {
		$logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'WikiAutomator' );
		$results = [];
		$lines = explode( "\n", $script );
		foreach ( $lines as $line ) {
			$line = trim($line);
			if ( empty($line) || $line[0] === '#' ) continue;

			$parts = explode( '|', $line, 3 );
			if ( count($parts) < 3 ) continue;

			$targetRule = trim($parts[0]);
			$actionType = trim($parts[1]);
			$actionValue = trim($parts[2]);

			$targetTitle = $this->resolveTitle( $triggerTitle, $targetRule );
			if ( !$targetTitle ) continue;

			try {
				$result = $this->performEdit( $targetTitle, $actionType, $actionValue, $matchMode, $useRegex );
				$results[] = [
					'page' => $targetTitle->getPrefixedText(),
					'action' => $this->getActionDescription( $actionType ),
					'status' => $result['changed'] ? '成功' : '无变更',
					'match_count' => $result['match_count']
				];
			} catch ( \Throwable $e ) {
				$results[] = [
					'page' => $targetTitle->getPrefixedText(),
					'action' => $this->getActionDescription( $actionType ),
					'status' => '失败: ' . $e->getMessage()
				];
			}
		}
		return $results;
	}

	/**
	 * Build a descriptive edit summary for Recent Changes
	 */
	private function buildEditSummary( $type, $value, $matchMode ) {
		$taskId = $this->params['task_id'];
		$customSummary = $this->params['edit_summary'] ?? '';

		$actionDesc = '';
		switch ( $type ) {
			case 'overwrite':
				$actionDesc = '覆写内容';
				break;
			case 'append':
				$preview = mb_substr( $value, 0, 30 );
				if ( mb_strlen( $value ) > 30 ) $preview .= '...';
				$actionDesc = '追加内容: "' . $preview . '"';
				break;
			case 'prepend':
				$preview = mb_substr( $value, 0, 30 );
				if ( mb_strlen( $value ) > 30 ) $preview .= '...';
				$actionDesc = '前置内容: "' . $preview . '"';
				break;
			case 'replace':
				$search = '';
				$replace = '';
				if ( is_array($value) && isset($value['search'], $value['replace']) ) {
					$search = $value['search'];
					$replace = $value['replace'];
				} elseif ( is_string($value) && strpos($value, '|') !== false ) {
					$parts = explode( '|', $value, 2 );
					$search = $parts[0];
					$replace = $parts[1] ?? '';
				}
				$searchPreview = mb_substr( $search, 0, 20 );
				$replacePreview = mb_substr( $replace, 0, 20 );
				if ( mb_strlen( $search ) > 20 ) $searchPreview .= '...';
				if ( mb_strlen( $replace ) > 20 ) $replacePreview .= '...';
				$modeNote = '';
				if ( $matchMode === 'regex' ) {
					$modeNote = ' (正则)';
				} elseif ( $matchMode === 'wildcard' ) {
					$modeNote = ' (通配符)';
				}
				$actionDesc = "替换{$modeNote}: \"{$searchPreview}\" → \"{$replacePreview}\"";
				break;
			case 'rename':
				$search = '';
				$replace = '';
				if ( is_array($value) && isset($value['search'], $value['replace']) ) {
					$search = $value['search'];
					$replace = $value['replace'];
				}
				$actionDesc = "重命名: \"{$search}\" → \"{$replace}\"";
				break;
			default:
				$actionDesc = $type;
		}

		// Build summary: custom summary takes priority
		if ( !empty($customSummary) ) {
			$summary = "WikiAutomator Action (Task #{$taskId}): {$customSummary}";
		} else {
			$summary = "WikiAutomator Action (Task #{$taskId}): {$actionDesc}";
		}

		return $summary;
	}

	/**
	 * Core Edit Logic: Load -> Modify -> Save
	 * @param Title $targetTitle Target page
	 * @param string $type Action type (overwrite/append/prepend/replace/rename)
	 * @param mixed $value Action value
	 * @param string $matchMode Match mode: auto/literal/wildcard/regex
	 * @param bool $useRegex Legacy regex flag (used when matchMode=auto)
	 * @param array $regexFlags Optional regex flags: ['i'=>bool, 'm'=>bool, 'U'=>bool]
	 * @return array ['changed' => bool, 'match_count' => int]
	 */
	private function performEdit( $targetTitle, $type, $value, $matchMode = 'auto', $useRegex = false, $regexFlags = [] ) {
		$logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'WikiAutomator' );
		$logger->info( "Task {$this->params['task_id']}: performEdit called for " . $targetTitle->getPrefixedText() . " (action: $type, matchMode: $matchMode)" );
		$matchCount = 0;

		try {
			$services = MediaWikiServices::getInstance();

			// Use new API for MediaWiki 1.40+
			$wikiPageFactory = $services->getWikiPageFactory();
			$page = $wikiPageFactory->newFromTitle( $targetTitle );

			if ( $targetTitle->exists() ) {
				$contentObj = $page->getContent();
				if ( $contentObj instanceof \TextContent ) {
					$content = $contentObj->getText();
				} elseif ( $contentObj ) {
					// For non-text content models, try to serialize
					$content = $contentObj->serialize();
				} else {
					$content = "";
				}
			} else {
				$content = "";
			}

			$logger->info( "Task {$this->params['task_id']}: Current content length: " . strlen($content) );

			$originalContent = $content;
			$contentChanged = false;

			// --- Action Processor ---
			if ( $type === 'overwrite' ) {
				if ( $content !== $value ) {
					$content = $value;
					$contentChanged = true;
				}
				$logger->info( "Task {$this->params['task_id']}: Overwrite mode, new content length: " . strlen($content) );
			}
			elseif ( $type === 'append' ) {
				$content .= "\n\n" . $value;
				$contentChanged = true;
				$logger->info( "Task {$this->params['task_id']}: Append mode, new content length: " . strlen($content) );
			}
			elseif ( $type === 'prepend' ) {
				$content = $value . "\n\n" . $content;
				$contentChanged = true;
				$logger->info( "Task {$this->params['task_id']}: Prepend mode, new content length: " . strlen($content) );
			}
			elseif ( $type === 'replace' ) {
				$search = '';
				$replace = '';

				if ( is_array($value) && isset($value['search'], $value['replace']) ) {
					$search = $value['search'];
					$replace = $value['replace'];
				} elseif ( is_string($value) && strpos($value, '|') !== false ) {
					// Legacy pipe format fallback
					$parts = explode( '|', $value, 2 );
					$search = $parts[0];
					$replace = $parts[1] ?? '';
				}

				$logger->info( "Task {$this->params['task_id']}: Replace mode, search: '$search', matchMode: $matchMode" );

				if ( $search !== '' ) {
					// Resolve effective match mode (backward compat for 'auto')
					$effectiveMode = $matchMode;
					if ( $effectiveMode === 'auto' ) {
						if ( $useRegex ) {
							$effectiveMode = 'regex';
						} elseif ( $this->hasWildcards( $search ) ) {
							$effectiveMode = 'wildcard';
						} else {
							$effectiveMode = 'literal';
						}
					}

					// Count matches before replacing
					$matchCount = $this->countMatches( $search, $content, $effectiveMode, $regexFlags );
					$logger->info( "Task {$this->params['task_id']}: Found $matchCount matches" );

					if ( $effectiveMode === 'wildcard' ) {
						$regexPattern = $this->expandWildcards( $search );
						$logger->info( "Task {$this->params['task_id']}: Wildcard pattern expanded to: $regexPattern" );
						$newContent = $this->safeRegexReplace( $regexPattern, $replace, $content, $logger );

						if ( $newContent === null ) {
							$logger->error( "Wildcard regex error for task {$this->params['task_id']}. Pattern: $regexPattern" );
						} elseif ( $newContent !== $content ) {
							$content = $newContent;
							$contentChanged = true;
						}
					} elseif ( $effectiveMode === 'regex' ) {
						$search = $this->normalizeRegexPattern( $search, $regexFlags );

						$newContent = $this->safeRegexReplace( $search, $replace, $content, $logger );

						if ( $newContent === null ) {
							$logger->error( "Regex error for task {$this->params['task_id']}. Pattern: $search" );
						} elseif ( $newContent !== $content ) {
							$content = $newContent;
							$contentChanged = true;
						}
					} else {
						$newContent = str_replace( $search, $replace, $content );
						if ( $newContent !== $originalContent ) {
							$content = $newContent;
							$contentChanged = true;
						}
					}
				}
			}
			elseif ( $type === 'rename' ) {
				// Page title replacement (rename)
				$search = '';
				$replace = '';
				$createRedirect = true;

				if ( is_array($value) && isset($value['search'], $value['replace']) ) {
					$search = $value['search'];
					$replace = $value['replace'];
					$createRedirect = $value['create_redirect'] ?? true;
				}

				if ( $search !== '' && $targetTitle->exists() ) {
					$oldTitleText = $targetTitle->getText();
					$newTitleText = str_replace( $search, $replace, $oldTitleText );

					if ( $newTitleText !== $oldTitleText ) {
						$newTitle = Title::makeTitleSafe( $targetTitle->getNamespace(), $newTitleText );
						if ( $newTitle ) {
							$matchCount = 1;
							$ownerId = $this->params['owner_id'] ?? 0;
							$userFactory = $services->getUserFactory();
							$editUser = null;
							if ( $ownerId > 0 ) {
								$editUser = $userFactory->newFromId( $ownerId );
								if ( !$editUser || !$editUser->getId() ) {
									$editUser = null;
								}
							}
							if ( !$editUser ) {
								$editUser = $userFactory->newSystemUser( 'WikiAutomatorBot', [ 'steal' => true ] );
							}

							if ( $editUser ) {
								$movePage = $services->getMovePageFactory()->newMovePage( $targetTitle, $newTitle );
								$reason = $this->buildEditSummary( $type, $value, $matchMode );
								$status = $movePage->move( $editUser, $reason, $createRedirect );
								if ( $status->isOK() ) {
									$logger->info( "Task {$this->params['task_id']}: Renamed " . $targetTitle->getPrefixedText() . " to " . $newTitle->getPrefixedText() );
									return [ 'changed' => true, 'match_count' => $matchCount ];
								} else {
									$logger->error( "Task {$this->params['task_id']}: Rename failed: " . $status->getMessage()->text() );
									return [ 'changed' => false, 'match_count' => $matchCount ];
								}
							}
						} else {
							$logger->error( "Task {$this->params['task_id']}: Invalid new title: $newTitleText" );
						}
					}
				}
				return [ 'changed' => false, 'match_count' => 0 ];
			}

			$logger->info( "Task {$this->params['task_id']}: contentChanged = " . ($contentChanged ? 'true' : 'false') );

			// --- Save ---
			if ( $contentChanged ) {
				$ownerId = $this->params['owner_id'] ?? 0;
				$editUser = null;

				// Use UserFactory for MediaWiki 1.40+
				$userFactory = $services->getUserFactory();

				if ( $ownerId > 0 ) {
					$editUser = $userFactory->newFromId( $ownerId );
					if ( $editUser && $editUser->getId() ) {
						$logger->info( "Task {$this->params['task_id']}: Using owner '{$editUser->getName()}' for edit" );
					} else {
						$editUser = null;
					}
				}

				if ( !$editUser ) {
					$editUser = $userFactory->newSystemUser( 'WikiAutomatorBot', [ 'steal' => true ] );
					if ( !$editUser ) {
						$logger->error( "Failed to create system user WikiAutomatorBot" );
						return [ 'changed' => false, 'match_count' => $matchCount ];
					}
					$logger->info( "Task {$this->params['task_id']}: Using system user WikiAutomatorBot" );
				}

				// Check permissions
				$permissionManager = $services->getPermissionManager();
				if ( !$permissionManager->userCan( 'edit', $editUser, $targetTitle ) ) {
					$logger->warning( "Task {$this->params['task_id']}: User '{$editUser->getName()}' cannot edit " . $targetTitle->getPrefixedText() );
					return [ 'changed' => false, 'match_count' => $matchCount ];
				}

				// Set edit flags
				$flags = 0;
				if ( !$targetTitle->exists() ) {
					$flags |= EDIT_NEW;
				} else {
					$flags |= EDIT_UPDATE;
				}

				// Mark as bot edit if configured
				$botEdit = $this->params['bot_edit'] ?? false;
				if ( $botEdit ) {
					$flags |= EDIT_FORCE_BOT;
				}

				$logger->info( "Task {$this->params['task_id']}: Saving page..." );

				// Use PageUpdater for MediaWiki 1.40+
				$updater = $page->newPageUpdater( $editUser );

				// Set MAIN slot content (already modified above)
				$contentHandlerFactory = $services->getContentHandlerFactory();
				$contentHandler = $contentHandlerFactory->getContentHandler( CONTENT_MODEL_WIKITEXT );
				$newContentObj = $contentHandler->makeContent( $content, $targetTitle );
				$updater->setContent( \MediaWiki\Revision\SlotRecord::MAIN, $newContentObj );

				// Multi-slot support: for replace action, also process non-MAIN slots
				if ( $type === 'replace' && $targetTitle->exists() ) {
					$revRecord = $page->getRevisionRecord();
					if ( $revRecord ) {
						$slotRoles = $revRecord->getSlotRoles();
						foreach ( $slotRoles as $role ) {
							if ( $role === \MediaWiki\Revision\SlotRecord::MAIN ) continue;
							$slotContent = $revRecord->getContent( $role );
							if ( !( $slotContent instanceof \TextContent ) ) continue;

							$slotText = $slotContent->getText();
							$newSlotText = $slotText;

							// Apply same replace logic as MAIN slot
							if ( isset( $effectiveMode ) && $search !== '' ) {
								if ( $effectiveMode === 'wildcard' ) {
									$regexPattern = $this->expandWildcards( $search );
									$result = $this->safeRegexReplace( $regexPattern, $replace, $slotText, $logger );
									if ( $result !== null ) $newSlotText = $result;
								} elseif ( $effectiveMode === 'regex' ) {
									$normalizedSearch = $this->normalizeRegexPattern( $search, $regexFlags );
									$result = $this->safeRegexReplace( $normalizedSearch, $replace, $slotText, $logger );
									if ( $result !== null ) $newSlotText = $result;
								} else {
									$newSlotText = str_replace( $search, $replace, $slotText );
								}
							}

							if ( $newSlotText !== $slotText ) {
								$slotHandler = $contentHandlerFactory->getContentHandler( $slotContent->getModel() );
								$newSlotObj = $slotHandler->makeContent( $newSlotText, $targetTitle );
								$updater->setContent( $role, $newSlotObj );
								$logger->info( "Task {$this->params['task_id']}: Also modified slot '$role'" );
							}
						}
					}
				}

				// Add change tag for tracking in RecentChanges
				$updater->addTag( \WikiAutomator\Hooks::CHANGE_TAG );

				$comment = \MediaWiki\CommentStore\CommentStoreComment::newUnsavedComment(
					$this->buildEditSummary( $type, $value, $matchMode )
				);

				$updater->saveRevision( $comment, $flags );
				$status = $updater->getStatus();

				if ( $status->isOK() ) {
					$logger->info( "Task {$this->params['task_id']}: Successfully edited " . $targetTitle->getPrefixedText() );
					return [ 'changed' => true, 'match_count' => $matchCount ];
				} else {
					$logger->error( "Task {$this->params['task_id']}: Edit failed for " . $targetTitle->getPrefixedText() . ". Reason: " . $status->getMessage()->text() );
					return [ 'changed' => false, 'match_count' => $matchCount ];
				}
			} else {
				$logger->info( "Task {$this->params['task_id']}: No content change needed for " . $targetTitle->getPrefixedText() );
				return [ 'changed' => false, 'match_count' => $matchCount ];
			}
		} catch ( \Throwable $e ) {
			$logger->error( "Error processing task {$this->params['task_id']}: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() );
			throw $e;
		}
	}

	private function resolveTitle( $triggerTitle, $rule ) {
		if ( empty($rule) ) return $triggerTitle;
		$text = str_replace( '{{PAGENAME}}', $triggerTitle->getText(), $rule );
		$text = str_replace( '{{FULLPAGENAME}}', $triggerTitle->getPrefixedText(), $text );
		$title = Title::newFromText( $text );
		// Return null if title is invalid, caller should handle this
		return $title instanceof Title ? $title : null;
	}

	/**
	 * Normalize a regex pattern: add delimiters if missing, ensure 'u' flag,
	 * and apply user-selected regex flags.
	 * @param string $pattern The search pattern
	 * @param array $regexFlags Optional flags: ['i'=>bool, 'm'=>bool, 'U'=>bool]
	 * @return string Normalized regex pattern with delimiters and flags
	 */
	private function normalizeRegexPattern( $pattern, $regexFlags = [] ) {
		if ( $pattern[0] !== '/' && $pattern[0] !== '#' && $pattern[0] !== '~' ) {
			// No delimiter provided: build flags from scratch
			$flags = 'u';
			if ( !empty( $regexFlags['i'] ) ) $flags .= 'i';
			if ( !empty( $regexFlags['m'] ) ) $flags .= 'm';
			if ( !empty( $regexFlags['U'] ) ) $flags .= 'U';
			$pattern = '/' . str_replace( '/', '\/', $pattern ) . '/' . $flags;
		} else {
			// User provided delimiters: ensure 'u' flag is present
			$delimiter = $pattern[0];
			$lastDelimPos = strrpos( $pattern, $delimiter, 1 );
			if ( $lastDelimPos !== false ) {
				$existingFlags = substr( $pattern, $lastDelimPos + 1 );
				if ( strpos( $existingFlags, 'u' ) === false ) {
					$pattern .= 'u';
				}
			}
		}
		return $pattern;
	}

	/**
	 * Expand wildcards to regex patterns for MediaWiki syntax
	 * @param string $pattern The pattern with wildcards
	 * @return string Regex pattern
	 */
	private function expandWildcards( $pattern ) {
		// Escape regex special characters except *
		$escaped = preg_quote( $pattern, '/' );
		// Restore * and convert to regex
		$escaped = str_replace( '\\*', '<<<WILDCARD>>>', $escaped );

		// Handle specific MediaWiki patterns
		// {{*}} - any template
		$escaped = str_replace( '\\{\\{<<<WILDCARD>>>\\}\\}', '\\{\\{[^{}]*(?:\\{\\{[^{}]*\\}\\}[^{}]*)*\\}\\}', $escaped );
		// [[*]] - any link
		$escaped = str_replace( '\\[\\[<<<WILDCARD>>>\\]\\]', '\\[\\[[^\\]]+\\]\\]', $escaped );
		// <!--*--> - any comment
		$escaped = str_replace( '\\<\\!\\-\\-<<<WILDCARD>>>\\-\\-\\>', '<!--[\\s\\S]*?-->', $escaped );

		// Generic * becomes non-greedy match
		$escaped = str_replace( '<<<WILDCARD>>>', '.*?', $escaped );

		return '/' . $escaped . '/su';
	}

	/**
	 * Check if pattern contains wildcards
	 */
	private function hasWildcards( $pattern ) {
		return strpos( $pattern, '*' ) !== false;
	}

	/**
	 * Count the number of matches for a search pattern in content
	 * @param string $search Search string
	 * @param string $content Page content
	 * @param string $matchMode Match mode: literal/wildcard/regex
	 * @param array $regexFlags Optional regex flags
	 * @return int Number of matches
	 */
	public function countMatches( $search, $content, $matchMode, $regexFlags = [] ) {
		if ( $search === '' || $content === '' ) {
			return 0;
		}

		if ( $matchMode === 'wildcard' ) {
			$pattern = $this->expandWildcards( $search );
			$result = @preg_match_all( $pattern, $content );
			return $result !== false ? $result : 0;
		} elseif ( $matchMode === 'regex' ) {
			$pattern = $this->normalizeRegexPattern( $search, $regexFlags );
			$result = @preg_match_all( $pattern, $content );
			return $result !== false ? $result : 0;
		} else {
			return substr_count( $content, $search );
		}
	}

	/**
	 * Write execution log to wa_logs table
	 */
	private function writeExecutionLog( $editedPages ) {
		$logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'WikiAutomator' );
		try {
			$services = MediaWikiServices::getInstance();
			$dbw = $services->getDBLoadBalancer()->getConnection( \DB_PRIMARY );

			$taskId = $this->params['task_id'];
			$taskName = $this->params['task_name'] ?? '';
			$ownerId = $this->params['owner_id'] ?? 0;
			$ownerName = '';
			if ( $ownerId > 0 ) {
				$user = $services->getUserFactory()->newFromId( $ownerId );
				if ( $user ) $ownerName = $user->getName();
			}

			$pagesAffected = 0;
			$totalMatches = 0;
			$hasFailure = false;
			foreach ( $editedPages as $p ) {
				if ( ( $p['status'] ?? '' ) === '成功' ) $pagesAffected++;
				if ( strpos( $p['status'] ?? '', '失败' ) !== false ) $hasFailure = true;
				$totalMatches += $p['match_count'] ?? 0;
			}

			$status = 'success';
			if ( $hasFailure && $pagesAffected > 0 ) $status = 'partial';
			elseif ( $hasFailure ) $status = 'failed';
			elseif ( $pagesAffected === 0 ) $status = 'no_change';

			$dbw->insert( 'wa_logs', [
				'log_task_id' => $taskId,
				'log_task_name' => $taskName,
				'log_user_id' => $ownerId,
				'log_user_name' => $ownerName,
				'log_timestamp' => wfTimestampNow(),
				'log_action' => 'execute',
				'log_pages_affected' => $pagesAffected,
				'log_total_matches' => $totalMatches,
				'log_status' => $status,
				'log_details' => json_encode( $editedPages, JSON_UNESCAPED_UNICODE )
			], __METHOD__ );
		} catch ( \Throwable $e ) {
			$logger->error( "Failed to write execution log: " . $e->getMessage() );
		}
	}

	private function sendNotificationEmail( $userId, $targetInfo, $editedPages = [] ) {
		$logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'WikiAutomator' );

		try {
			$services = MediaWikiServices::getInstance();
			$userFactory = $services->getUserFactory();
			$user = $userFactory->newFromId( $userId );

			if ( !$user || $user->isAnon() || !$user->getEmail() ) {
				$logger->info( "Task {$this->params['task_id']}: Cannot send email - user invalid or no email" );
				return;
			}

			// Validate email address format
			$email = $user->getEmail();
			if ( !\Sanitizer::validateEmail( $email ) ) {
				$logger->warning( "Task {$this->params['task_id']}: Invalid email address for user {$user->getName()}" );
				return;
			}

			// Get site name from global config
			$siteName = $GLOBALS['wgSitename'] ?? 'MediaWiki';
			$serverUrl = $GLOBALS['wgServer'] ?? '';
			$scriptPath = $GLOBALS['wgScriptPath'] ?? '';
			$baseUrl = $serverUrl . $scriptPath;

			$taskId = $this->params['task_id'];
			// Sanitize task name to prevent header injection (remove newlines)
			$taskName = str_replace( [ "\r", "\n" ], ' ', $this->params['task_name'] ?? '' );
			$triggerTitle = $this->title ? str_replace( [ "\r", "\n" ], ' ', $this->title->getPrefixedText() ) : '';

			// Sanitize user name for email header
			$sanitizedUserName = str_replace( [ "\r", "\n" ], ' ', $user->getName() );

			$to = new \MailAddress( $email, $sanitizedUserName );
			$from = new \MailAddress( $GLOBALS['wgPasswordSender'], $siteName );

			// Build detailed subject (sanitize to prevent header injection)
			$subject = str_replace( [ "\r", "\n" ], ' ', "[{$siteName}] WikiAutomator 任务执行报告 - Task #{$taskId}" );

			// Build detailed body
			$body = "您好 {$sanitizedUserName}，\n\n";
			$body .= "您在 {$siteName} 上的 WikiAutomator 任务已执行完成。\n\n";
			$body .= "========== 任务信息 ==========\n";
			$body .= "任务 ID: #{$taskId}\n";
			if ( $taskName ) {
				$body .= "任务名称: {$taskName}\n";
			}
			$body .= "触发页面: {$triggerTitle}\n";
			$body .= "执行时间: " . date('Y-m-d H:i:s') . "\n";

			if ( !empty($editedPages) ) {
				$body .= "\n========== 修改的页面 ==========\n";
				$totalMatches = 0;
				foreach ( $editedPages as $pageInfo ) {
					// Sanitize page info to prevent any injection
					$pageName = str_replace( [ "\r", "\n" ], ' ', $pageInfo['page'] ?? '' );
					$action = str_replace( [ "\r", "\n" ], ' ', $pageInfo['action'] ?? '' );
					$status = str_replace( [ "\r", "\n" ], ' ', $pageInfo['status'] ?? '' );
					$pageMatches = $pageInfo['match_count'] ?? 0;
					$totalMatches += $pageMatches;
					$body .= "- {$pageName}\n";
					$body .= "  操作: {$action}\n";
					$body .= "  状态: {$status}\n";
					if ( $pageMatches > 0 ) {
						$body .= "  匹配数: {$pageMatches}\n";
					}
					if ( $pageName && $baseUrl ) {
						$pageUrl = $baseUrl . '/index.php?title=' . urlencode(str_replace(' ', '_', $pageName));
						$body .= "  链接: {$pageUrl}\n";
					}
				}
				$body .= "\n总匹配数: {$totalMatches}\n";
			}

			$body .= "\n========== 相关链接 ==========\n";
			if ( $baseUrl ) {
				$body .= "任务管理: {$baseUrl}/index.php?title=Special:WikiAutomator\n";
				if ( $triggerTitle ) {
					$triggerUrl = $baseUrl . '/index.php?title=' . urlencode(str_replace(' ', '_', $triggerTitle));
					$body .= "触发页面: {$triggerUrl}\n";
				}
			}

			$body .= "\n--\n";
			$body .= "此邮件由 {$siteName} 的 WikiAutomator 扩展自动发送。\n";
			$body .= "如需修改任务设置，请访问 Special:WikiAutomator 页面。\n";

			$status = \UserMailer::send( $to, $from, $subject, $body );
			if ( $status->isOK() ) {
				$logger->info( "Task {$this->params['task_id']}: Email sent to {$user->getName()}" );
			} else {
				$logger->error( "Task {$this->params['task_id']}: Failed to send email: " . $status->getMessage()->text() );
			}
		} catch ( \Throwable $e ) {
			$logger->error( "Task {$this->params['task_id']}: Failed to send email: " . $e->getMessage() );
		}
	}
}
