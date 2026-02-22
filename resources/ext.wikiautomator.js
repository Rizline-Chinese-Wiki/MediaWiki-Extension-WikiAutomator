( function ( mw, $ ) {
	'use strict';

	/**
	 * WikiAutomator Dynamic Steps Editor
	 */
	var WikiAutomator = {
		steps: [],
		pollInterval: null,

		init: function () {
			// Load initial data
			var initialData = mw.config.get( 'wgWikiAutomatorSteps' ) || [];
			this.steps = initialData;
			this.render();

			// Bind events
			$( '#wa-add-step' ).on( 'click', this.addStep.bind( this ) );
			$( '#wa-steps-form' ).closest( 'form' ).on( 'submit', this.saveData.bind( this ) );

			// Initialize trigger type visibility
			this.initTriggerTypeVisibility();

			// Watch match mode changes to toggle regex flags visibility
			this.initMatchModeWatch();
		},

		/**
		 * Clean up resources when leaving the page
		 */
		destroy: function () {
			if ( this.pollInterval ) {
				clearInterval( this.pollInterval );
				this.pollInterval = null;
			}
			if ( this.matchModePollInterval ) {
				clearInterval( this.matchModePollInterval );
				this.matchModePollInterval = null;
			}
		},

		/**
		 * Watch match mode select to toggle regex flags visibility
		 */
		initMatchModeWatch: function () {
			var self = this;
			var $matchModeSelect = $( 'select[name="wpMatchMode"]' );
			if ( !$matchModeSelect.length ) return;

			var currentMode = $matchModeSelect.val();
			this.matchModePollInterval = setInterval( function() {
				var newMode = $matchModeSelect.val();
				if ( newMode !== currentMode ) {
					currentMode = newMode;
					// Toggle regex flags visibility
					if ( newMode === 'regex' ) {
						$( '.wa-regex-flags' ).show();
					} else {
						$( '.wa-regex-flags' ).hide();
					}
				}
			}, 200 );
		},

		/**
		 * Initialize dynamic visibility based on trigger type
		 */
		initTriggerTypeVisibility: function () {
			var self = this;

			// Find the select element inside the trigger type widget
			var $triggerSelect = $( '#wa-trigger-type select, select[name="wpTriggerType"]' );

			if ( !$triggerSelect.length ) {
				return;
			}

			// Store current value
			var currentValue = $triggerSelect.val();

			// Initial update - run multiple times to ensure it works after OOUI renders
			self.updateFieldVisibility( currentValue );
			setTimeout( function() {
				self.updateFieldVisibility( $triggerSelect.val() );
			}, 100 );
			setTimeout( function() {
				self.updateFieldVisibility( $triggerSelect.val() );
			}, 300 );
			setTimeout( function() {
				self.updateFieldVisibility( $triggerSelect.val() );
			}, 500 );

			// Poll for changes since OOUI doesn't fire native change events
			// Store reference so we can clear it later
			this.pollInterval = setInterval( function() {
				var newValue = $triggerSelect.val();
				if ( newValue !== currentValue ) {
					currentValue = newValue;
					self.updateFieldVisibility( newValue );
				}
			}, 200 );

			// Clean up interval when navigating away
			$( window ).on( 'beforeunload.wikiautomator', function() {
				self.destroy();
			} );

			// Also clean up on AJAX navigation (for single-page apps)
			mw.hook( 'wikipage.content' ).add( function() {
				// Only destroy if we're navigating away from this page
				if ( !$( '#wa-steps-container' ).length ) {
					self.destroy();
				}
			} );
		},

		/**
		 * Update field visibility based on selected trigger type
		 */
		updateFieldVisibility: function ( triggerType ) {
			// The wa-*-field class is directly on the oo-ui-fieldLayout div
			var $categoryFields = $( '.wa-category-field' ).filter( '.oo-ui-fieldLayout' ).add( $( '.wa-category-field' ).closest( '.oo-ui-fieldLayout' ) );
			var $cronFields = $( '.wa-cron-field' ).filter( '.oo-ui-fieldLayout' ).add( $( '.wa-cron-field' ).closest( '.oo-ui-fieldLayout' ) );
			var $scheduledFields = $( '.wa-scheduled-field' ).filter( '.oo-ui-fieldLayout' ).add( $( '.wa-scheduled-field' ).closest( '.oo-ui-fieldLayout' ) );
			var $pageConditionFields = $( '.wa-page-condition' ).filter( '.oo-ui-fieldLayout' ).add( $( '.wa-page-condition' ).closest( '.oo-ui-fieldLayout' ) );

			// Hide all conditional fields first
			$categoryFields.hide();
			$cronFields.hide();
			$scheduledFields.hide();

			// Show relevant fields based on trigger type
			switch ( triggerType ) {
				case 'page_save':
				case 'page_create':
					$pageConditionFields.show();
					break;

				case 'category_change':
					$categoryFields.show();
					$pageConditionFields.show();
					break;

				case 'cron_custom':
					$cronFields.show();
					$pageConditionFields.hide();
					break;

				case 'scheduled':
					$scheduledFields.show();
					$pageConditionFields.hide();
					break;
			}
		},

		addStep: function () {
			this.steps.push( {
				target: '',
				action: 'append',
				value: ''
			} );
			this.render();
		},

		removeStep: function ( index ) {
			// Use mw.msg for i18n, fallback to Chinese if message not defined
			var confirmMsg = mw.msg( 'wikiautomator-confirm-delete-step' );
			if ( confirmMsg === '[wikiautomator-confirm-delete-step]' ) {
				confirmMsg = '确定要删除这个步骤吗？';
			}
			if ( confirm( confirmMsg ) ) {
				this.steps.splice( index, 1 );
				this.render();
			}
		},

		updateStep: function ( index, field, value ) {
			this.steps[index][field] = value;
			// Auto-save to hidden field logic could go here, but we do it on submit or render
			this.updateHiddenField();
		},

		render: function () {
			var $container = $( '#wa-steps-container' );
			$container.empty();

			if ( this.steps.length === 0 ) {
				// Use mw.msg for i18n, fallback to Chinese if message not defined
				var emptyMsg = mw.msg( 'wikiautomator-empty-steps' );
				if ( emptyMsg === '[wikiautomator-empty-steps]' ) {
					emptyMsg = '暂无执行步骤。点击"添加步骤"开始配置。';
				}
				$container.html( '<div class="wa-empty-state">' + mw.html.escape( emptyMsg ) + '</div>' );
			} else {
				this.steps.forEach( function ( step, index ) {
					var $row = $( '<div>' ).addClass( 'wa-step-row' );

					var $header = $( '<div>' ).addClass( 'wa-step-header' ).text( '步骤 ' + (index + 1) );
					var $removeBtn = $( '<button>' )
						.addClass( 'mw-ui-button mw-ui-quiet mw-ui-destructive mw-ui-small' )
						.text( '删除' )
						.on( 'click', function(e) { e.preventDefault(); WikiAutomator.removeStep( index ); } );
					$header.append( $removeBtn );

					var $content = $( '<div>' ).addClass( 'wa-step-content' );

					// Search target toggle for replace action
					var useSearchTarget = step.target === '__search__';

					// Target Input (hidden when search mode is on)
					var $targetGroup = this.createInputGroup( '目标页面', useSearchTarget ? '' : step.target, '例如: Page2 或 {{PAGENAME}}', function(v) { WikiAutomator.updateStep(index, 'target', v); } );
					if ( useSearchTarget ) $targetGroup.hide();

					// Search target checkbox
					var $searchToggle = $( '<div>' ).addClass( 'wa-input-group' );
					var $searchCheck = $( '<input>' ).attr( 'type', 'checkbox' ).prop( 'checked', useSearchTarget );
					$searchToggle.append(
						$( '<label>' ).css( 'display', 'inline' ).append( $searchCheck, ' 搜索目标页面（按内容匹配）' )
					);

					// Search filters (shown when search mode is on)
					var filters = step.search_filters || {};
					var $filtersGroup = $( '<div>' ).addClass( 'wa-search-filters' ).css( 'margin-left', '20px' );

					// Namespace checkboxes
					var selectedNs = filters.namespaces || [];
					var allNamespaces = mw.config.get( 'wgWikiAutomatorNamespaces' ) || [];
					var $nsGroup = $( '<div>' ).addClass( 'wa-input-group' );
					$nsGroup.append( $( '<label>' ).text( '命名空间' ) );
					var $nsCheckboxes = $( '<div>' ).addClass( 'wa-ns-checkboxes' ).css( { display: 'flex', 'flex-wrap': 'wrap', gap: '4px 14px' } );
					allNamespaces.forEach( function( nsItem ) {
						var isChecked = selectedNs.indexOf( nsItem.id ) !== -1;
						var $cb = $( '<input>' ).attr( 'type', 'checkbox' ).prop( 'checked', isChecked ).data( 'ns-id', nsItem.id );
						$cb.on( 'change', function() {
							var sf = WikiAutomator.steps[index].search_filters || {};
							var current = sf.namespaces || [];
							var nsId = $( this ).data( 'ns-id' );
							if ( $( this ).prop( 'checked' ) ) {
								if ( current.indexOf( nsId ) === -1 ) current.push( nsId );
							} else {
								current = current.filter( function( n ) { return n !== nsId; } );
							}
							sf.namespaces = current;
							WikiAutomator.updateStep( index, 'search_filters', sf );
						} );
						$nsCheckboxes.append(
							$( '<label>' ).css( { display: 'inline', 'font-weight': 'normal', 'white-space': 'nowrap' } ).append( $cb, ' ' + nsItem.name )
						);
					} );
					$nsGroup.append( $nsCheckboxes );

					var $catInput = this.createInputGroup( '分类名称', filters.category || '', '可选', function(v) {
						var sf = WikiAutomator.steps[index].search_filters || {};
						sf.category = v;
						WikiAutomator.updateStep( index, 'search_filters', sf );
					} );
					var $prefixInput = this.createInputGroup( '标题前缀', filters.prefix || '', '可选', function(v) {
						var sf = WikiAutomator.steps[index].search_filters || {};
						sf.prefix = v;
						WikiAutomator.updateStep( index, 'search_filters', sf );
					} );
					var $limitInput = this.createInputGroup( '最大页面数', filters.limit || '', '默认500', function(v) {
						var sf = WikiAutomator.steps[index].search_filters || {};
						sf.limit = v ? parseInt( v, 10 ) : null;
						WikiAutomator.updateStep( index, 'search_filters', sf );
					} );
					$filtersGroup.append( $nsGroup, $catInput, $prefixInput, $limitInput );
					if ( !useSearchTarget ) $filtersGroup.hide();

					$searchCheck.on( 'change', function() {
						var checked = $( this ).prop( 'checked' );
						if ( checked ) {
							WikiAutomator.updateStep( index, 'target', '__search__' );
							if ( !WikiAutomator.steps[index].search_filters ) {
								WikiAutomator.updateStep( index, 'search_filters', { namespaces: [], category: '', prefix: '' } );
							}
							$targetGroup.hide();
							$filtersGroup.show();
						} else {
							WikiAutomator.updateStep( index, 'target', '' );
							$targetGroup.show();
							$filtersGroup.hide();
						}
					} );

					// Only show search toggle for replace/rename action
					if ( step.action !== 'replace' && step.action !== 'rename' ) {
						$searchToggle.hide();
						$filtersGroup.hide();
					}

					// Action Select
					var $actionGroup = this.createSelectGroup( '动作类型', step.action, {
						'append': '追加内容',
						'prepend': '前置内容',
						'overwrite': '覆写内容',
						'replace': '替换文本',
						'rename': '重命名页面'
					}, function(v) {
						WikiAutomator.updateStep(index, 'action', v);
						// Toggle search target visibility
						if ( v === 'replace' || v === 'rename' ) {
							$searchToggle.show();
						} else {
							$searchToggle.hide();
							$filtersGroup.hide();
							if ( WikiAutomator.steps[index].target === '__search__' ) {
								WikiAutomator.updateStep( index, 'target', '' );
								$targetGroup.show();
							}
						}
						// Toggle rename options
						if ( v === 'rename' ) {
							$renameOptions.show();
						} else {
							$renameOptions.hide();
						}
						// Toggle move pages option
						if ( v === 'replace' ) {
							$movePagesOption.show();
						} else {
							$movePagesOption.hide();
						}
						// Toggle regex flags
						var mm = $( 'select[name="wpMatchMode"]' ).val();
						if ( mm === 'regex' && ( v === 'replace' || v === 'rename' ) ) {
							$regexFlagsGroup.show();
						} else {
							$regexFlagsGroup.hide();
						}
						// Toggle value inputs
						if ( v === 'replace' || v === 'rename' ) {
							$valueGroup.hide();
							$searchGroup.show();
							$replaceGroup.show();
							// Convert value to object if needed
							var cur = WikiAutomator.steps[index].value;
							if ( typeof cur !== 'object' || cur === null ) {
								WikiAutomator.updateStep( index, 'value', { search: '', replace: '' } );
							}
						} else {
							$valueGroup.show();
							$searchGroup.hide();
							$replaceGroup.hide();
							// Convert value to string if needed
							var cur2 = WikiAutomator.steps[index].value;
							if ( typeof cur2 === 'object' && cur2 !== null ) {
								WikiAutomator.updateStep( index, 'value', '' );
							}
						}
					} );

					// Value inputs: split into search+replace for replace/rename, single textarea for others
					var val = step.value;
					var isReplaceType = ( step.action === 'replace' || step.action === 'rename' );

					// Single value textarea (for append/prepend/overwrite)
					var singleVal = '';
					if ( !isReplaceType ) {
						singleVal = ( typeof val === 'object' && val !== null ) ? '' : ( val || '' );
					}
					var $valueGroup = this.createInputGroup( '内容', singleVal, '要写入的内容', function(v) { WikiAutomator.updateStep(index, 'value', v); }, true );

					// Dual inputs (for replace/rename)
					var searchVal = '';
					var replaceVal = '';
					if ( typeof val === 'object' && val !== null ) {
						searchVal = val.search || '';
						replaceVal = val.replace || '';
					} else if ( typeof val === 'string' && isReplaceType ) {
						// Legacy pipe format migration
						var pipeIdx = val.indexOf( '|' );
						if ( pipeIdx !== -1 ) {
							searchVal = val.substring( 0, pipeIdx );
							replaceVal = val.substring( pipeIdx + 1 );
						}
					}
					var $searchGroup = this.createInputGroup( '查找内容', searchVal, '要查找的文本或模式', function(v) {
						var cur = WikiAutomator.steps[index].value;
						if ( typeof cur !== 'object' || cur === null ) cur = { search: '', replace: '' };
						cur.search = v;
						WikiAutomator.updateStep( index, 'value', cur );
					} );
					var $replaceGroup = this.createInputGroup( '替换为', replaceVal, '替换后的文本（留空则删除匹配内容）', function(v) {
						var cur = WikiAutomator.steps[index].value;
						if ( typeof cur !== 'object' || cur === null ) cur = { search: '', replace: '' };
						cur.replace = v;
						WikiAutomator.updateStep( index, 'value', cur );
					} );

					// Initialize value as object for replace/rename
					if ( isReplaceType && ( typeof val !== 'object' || val === null ) ) {
						WikiAutomator.updateStep( index, 'value', { search: searchVal, replace: replaceVal } );
					}

					// Show/hide based on action type
					if ( isReplaceType ) {
						$valueGroup.hide();
						$searchGroup.show();
						$replaceGroup.show();
					} else {
						$valueGroup.show();
						$searchGroup.hide();
						$replaceGroup.hide();
					}

					// Regex flags (shown only when match mode is regex and action is replace)
					var rFlags = step.regex_flags || {};
					var $regexFlagsGroup = $( '<div>' ).addClass( 'wa-input-group wa-regex-flags' );
					$regexFlagsGroup.append( $( '<label>' ).text( '正则标志' ) );
					var flagsDef = [
						{ key: 'i', label: '不区分大小写 (i)' },
						{ key: 'm', label: '多行模式 (m)' },
						{ key: 'U', label: '非贪婪模式 (U)' }
					];
					var $flagsRow = $( '<div>' ).css( { display: 'flex', gap: '15px', 'flex-wrap': 'wrap' } );
					flagsDef.forEach( function( f ) {
						var $cb = $( '<input>' ).attr( 'type', 'checkbox' ).prop( 'checked', !!rFlags[f.key] );
						$cb.on( 'change', function() {
							var rf = WikiAutomator.steps[index].regex_flags || {};
							rf[f.key] = $( this ).prop( 'checked' );
							WikiAutomator.updateStep( index, 'regex_flags', rf );
						} );
						$flagsRow.append( $( '<label>' ).css( { display: 'inline', 'font-weight': 'normal' } ).append( $cb, ' ' + f.label ) );
					} );
					$regexFlagsGroup.append( $flagsRow );

					// Determine visibility: check match mode select
					var $matchModeSelect = $( 'select[name="wpMatchMode"]' );
					var currentMatchMode = $matchModeSelect.length ? $matchModeSelect.val() : '';
					if ( currentMatchMode !== 'regex' || ( step.action !== 'replace' && step.action !== 'rename' ) ) {
						$regexFlagsGroup.hide();
					}

					// Rename option: create redirect checkbox
					var $renameOptions = $( '<div>' ).addClass( 'wa-input-group' );
					var createRedirect = ( step.value && typeof step.value === 'object' ) ? ( step.value.create_redirect !== false ) : true;
					var $redirectCheck = $( '<input>' ).attr( 'type', 'checkbox' ).prop( 'checked', createRedirect );
					$redirectCheck.on( 'change', function() {
						var val = WikiAutomator.steps[index].value;
						if ( typeof val === 'object' && val !== null ) {
							val.create_redirect = $( this ).prop( 'checked' );
						}
						WikiAutomator.updateStep( index, 'value', val );
					} );
					$renameOptions.append(
						$( '<label>' ).css( 'display', 'inline' ).append( $redirectCheck, ' 从旧标题创建重定向' )
					);
					if ( step.action !== 'rename' ) $renameOptions.hide();

					// Move pages option: also replace text in page titles
					var $movePagesOption = $( '<div>' ).addClass( 'wa-input-group' );
					var $movePagesCheck = $( '<input>' ).attr( 'type', 'checkbox' ).prop( 'checked', !!step.move_pages );
					$movePagesCheck.on( 'change', function() {
						WikiAutomator.updateStep( index, 'move_pages', $( this ).prop( 'checked' ) );
					} );
					$movePagesOption.append(
						$( '<label>' ).css( 'display', 'inline' ).append( $movePagesCheck, ' 替换页面标题内的文字（当可以时）' )
					);
					if ( step.action !== 'replace' ) $movePagesOption.hide();

					$content.append( $targetGroup, $searchToggle, $filtersGroup, $actionGroup, $valueGroup, $searchGroup, $replaceGroup, $regexFlagsGroup, $renameOptions, $movePagesOption );
					$row.append( $header, $content );
					$container.append( $row );
				}, this );
			}

			this.updateHiddenField();
		},

		createInputGroup: function ( label, value, placeholder, onChange, isTextarea ) {
			var $group = $( '<div>' ).addClass( 'wa-input-group' );
			$group.append( $( '<label>' ).text( label ) );

			var $input;
			if ( isTextarea ) {
				$input = $( '<textarea>' ).addClass( 'mw-ui-input' ).attr( 'rows', 3 ).val( value );
			} else {
				$input = $( '<input>' ).addClass( 'mw-ui-input' ).val( value );
			}

			if ( placeholder ) $input.attr( 'placeholder', placeholder );

			$input.on( 'input', function() { onChange( $(this).val() ); } );

			$group.append( $input );
			return $group;
		},

		createSelectGroup: function ( label, value, options, onChange ) {
			var $group = $( '<div>' ).addClass( 'wa-input-group' );
			$group.append( $( '<label>' ).text( label ) );

			var $select = $( '<select>' ).addClass( 'mw-ui-input' );
			$.each( options, function( k, v ) {
				var $opt = $( '<option>' ).val( k ).text( v );
				if ( k === value ) $opt.prop( 'selected', true );
				$select.append( $opt );
			} );

			$select.on( 'change', function() { onChange( $(this).val() ); } );

			$group.append( $select );
			return $group;
		},

		updateHiddenField: function () {
			$( '#wa-steps-json' ).val( JSON.stringify( this.steps ) );
		},

		saveData: function () {
			this.updateHiddenField();
			return true;
		}
	};

	$( function () {
		if ( $( '#wa-steps-container' ).length ) {
			WikiAutomator.init();
		}
	} );

}( mediaWiki, jQuery ) );
