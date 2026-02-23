( function ( mw, $ ) {
	'use strict';

	/**
	 * WikiAutomator Dynamic Steps Editor
	 */
	var WikiAutomator = {
		steps: [],

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

			// Initial update, with a delayed retry for OOUI rendering
			self.updateFieldVisibility( currentValue );
			setTimeout( function() {
				self.updateFieldVisibility( $triggerSelect.val() );
			}, 500 );

			// Listen for changes on the trigger type select
			$triggerSelect.on( 'change', function() {
				var newValue = $triggerSelect.val();
				if ( newValue !== currentValue ) {
					currentValue = newValue;
					self.updateFieldVisibility( newValue );
				}
			} );

			// Also listen on the OOUI widget container for delegated changes
			$triggerSelect.closest( '.oo-ui-widget' ).on( 'change', 'select', function() {
				var newValue = $triggerSelect.val();
				if ( newValue !== currentValue ) {
					currentValue = newValue;
					self.updateFieldVisibility( newValue );
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
			$pageConditionFields.hide();

			// Show relevant fields based on trigger type
			switch ( triggerType ) {
				case 'manual':
					// No trigger conditions needed
					break;

				case 'page_save':
				case 'page_create':
					$pageConditionFields.show();
					break;

				case 'category_change':
					$categoryFields.show();
					// Show condition title but not namespace
					$( '.wa-page-condition' ).each( function() {
						var $field = $( this ).filter( '.oo-ui-fieldLayout' ).add( $( this ).closest( '.oo-ui-fieldLayout' ) );
						// Only show ConditionTitle, not ConditionNS
						var isNsField = $( this ).find( 'input[name="wpConditionNS[]"]' ).length > 0 ||
							$( this ).text().indexOf( mw.msg( 'wikiautomator-condition-ns-label' ) ) !== -1;
						if ( !isNsField ) {
							$field.show();
						}
					} );
					break;

				case 'cron_custom':
					$cronFields.show();
					break;

				case 'scheduled':
					$scheduledFields.show();
					break;
			}
		},

		addStep: function () {
			this.steps.push( {
				target_type: 'trigger',
				target_page: '',
				action: 'append',
				value: ''
			} );
			this.render();
		},

		removeStep: function ( index ) {
			if ( confirm( mw.msg( 'wikiautomator-confirm-delete-step' ) ) ) {
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
				$container.html( '<div class="wa-empty-state">' + mw.html.escape( mw.msg( 'wikiautomator-empty-steps' ) ) + '</div>' );
			} else {
				this.steps.forEach( function ( step, index ) {
					var $row = $( '<div>' ).addClass( 'wa-step-row' );

					var $header = $( '<div>' ).addClass( 'wa-step-header' ).text( mw.msg( 'wikiautomator-step-header', index + 1 ) );
					var $removeBtn = $( '<button>' )
						.addClass( 'mw-ui-button mw-ui-quiet mw-ui-destructive mw-ui-small' )
						.text( mw.msg( 'wikiautomator-step-delete' ) )
						.on( 'click', function(e) { e.preventDefault(); WikiAutomator.removeStep( index ); } );
					$header.append( $removeBtn );

					var $content = $( '<div>' ).addClass( 'wa-step-content' );

					// --- Target type dropdown ---
					var targetType = step.target_type || ( step.target === '__search__' ? 'search' : ( step.target === '{{PAGENAME}}' || step.target === '' ? 'trigger' : 'specific' ) );
					var $targetTypeGroup = this.createSelectGroup( mw.msg( 'wikiautomator-step-target-type' ), targetType, {
						'trigger': mw.msg( 'wikiautomator-step-target-trigger' ),
						'specific': mw.msg( 'wikiautomator-step-target-specific' ),
						'search': mw.msg( 'wikiautomator-step-target-search' )
					}, function(v) {
						WikiAutomator.updateStep( index, 'target_type', v );
						if ( v === 'specific' ) {
							$targetGroup.show();
							$filtersGroup.hide();
							$searchWarning.hide();
						} else if ( v === 'search' ) {
							$targetGroup.hide();
							$filtersGroup.show();
							if ( !WikiAutomator.steps[index].search_filters ) {
								WikiAutomator.updateStep( index, 'search_filters', { namespaces: [], category: '', prefix: '' } );
							}
							// Show warning if event trigger
							var tt = $( '#wa-trigger-type select, select[name="wpTriggerType"]' ).val();
							if ( tt === 'page_save' || tt === 'page_create' || tt === 'category_change' ) {
								$searchWarning.show();
							} else {
								$searchWarning.hide();
							}
						} else {
							$targetGroup.hide();
							$filtersGroup.hide();
							$searchWarning.hide();
						}
					} );

					// Target page input (for 'specific' mode)
					var $targetGroup = this.createInputGroup( mw.msg( 'wikiautomator-step-target-page' ), step.target_page || step.target || '', mw.msg( 'wikiautomator-step-target-page-placeholder' ), function(v) { WikiAutomator.updateStep(index, 'target_page', v); } );
					if ( targetType !== 'specific' ) $targetGroup.hide();

					// Search warning
					var $searchWarning = $( '<div>' ).addClass( 'wa-warning' ).text( mw.msg( 'wikiautomator-step-search-warning' ) );
					var triggerVal = $( '#wa-trigger-type select, select[name="wpTriggerType"]' ).val();
					if ( targetType !== 'search' || ( triggerVal !== 'page_save' && triggerVal !== 'page_create' && triggerVal !== 'category_change' ) ) {
						$searchWarning.hide();
					}

					// Search filters (for 'search' mode)
					var filters = step.search_filters || {};
					var $filtersGroup = $( '<div>' ).addClass( 'wa-search-filters' ).css( 'margin-left', '20px' );

					// Namespace checkboxes
					var selectedNs = filters.namespaces || [];
					var allNamespaces = mw.config.get( 'wgWikiAutomatorNamespaces' ) || [];
					var $nsGroup = $( '<div>' ).addClass( 'wa-input-group' );
					$nsGroup.append( $( '<label>' ).text( mw.msg( 'wikiautomator-step-search-ns-label' ) ) );
					var $nsCheckboxes = $( '<div>' ).css( { display: 'flex', 'flex-wrap': 'wrap', gap: '4px 14px' } );
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

					var $catInput = this.createInputGroup( mw.msg( 'wikiautomator-step-search-category' ), filters.category || '', mw.msg( 'wikiautomator-step-search-category-placeholder' ), function(v) {
						var sf = WikiAutomator.steps[index].search_filters || {};
						sf.category = v;
						WikiAutomator.updateStep( index, 'search_filters', sf );
					} );
					var $prefixInput = this.createInputGroup( mw.msg( 'wikiautomator-step-search-prefix' ), filters.prefix || '', mw.msg( 'wikiautomator-step-search-prefix-placeholder' ), function(v) {
						var sf = WikiAutomator.steps[index].search_filters || {};
						sf.prefix = v;
						WikiAutomator.updateStep( index, 'search_filters', sf );
					} );
					var $limitInput = this.createInputGroup( mw.msg( 'wikiautomator-step-search-limit' ), filters.limit || '', mw.msg( 'wikiautomator-step-search-limit-placeholder' ), function(v) {
						var sf = WikiAutomator.steps[index].search_filters || {};
						sf.limit = v ? parseInt( v, 10 ) : null;
						WikiAutomator.updateStep( index, 'search_filters', sf );
					} );
					// Search term input (for non-replace actions in search mode)
					var $searchTermInput = this.createInputGroup( mw.msg( 'wikiautomator-step-search-term' ), step.search_term || '', mw.msg( 'wikiautomator-step-search-term-placeholder' ), function(v) {
						WikiAutomator.updateStep( index, 'search_term', v );
					} );
					if ( step.action === 'replace' ) $searchTermInput.hide();
					$filtersGroup.append( $nsGroup, $catInput, $prefixInput, $limitInput, $searchTermInput );
					if ( targetType !== 'search' ) $filtersGroup.hide();

					// Action Select
					var $actionGroup = this.createSelectGroup( mw.msg( 'wikiautomator-step-action-type' ), step.action, {
						'append': mw.msg( 'wikiautomator-step-action-append' ),
						'prepend': mw.msg( 'wikiautomator-step-action-prepend' ),
						'overwrite': mw.msg( 'wikiautomator-step-action-overwrite' ),
						'replace': mw.msg( 'wikiautomator-step-action-replace' )
					}, function(v) {
						WikiAutomator.updateStep(index, 'action', v);
						// Toggle move pages option
						if ( v === 'replace' ) {
							$movePagesOption.show();
						} else {
							$movePagesOption.hide();
						}
						// Toggle search term input (hide for replace, show for others in search mode)
						if ( v === 'replace' ) {
							$searchTermInput.hide();
						} else {
							$searchTermInput.show();
						}
						// Toggle regex flags based on step match mode
						var stepMm = WikiAutomator.steps[index].match_mode || 'literal';
						if ( stepMm === 'regex' && v === 'replace' ) {
							$regexFlagsGroup.show();
						} else {
							$regexFlagsGroup.hide();
						}
						// Toggle match mode select
						if ( v === 'replace' ) {
							$matchModeGroup.show();
						} else {
							$matchModeGroup.hide();
						}
						// Toggle value inputs
						if ( v === 'replace' ) {
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

					// Match mode select (per step)
					var $matchModeGroup = this.createSelectGroup( mw.msg( 'wikiautomator-match-mode' ), step.match_mode || 'literal', {
						'literal': mw.msg( 'wikiautomator-match-mode-literal' ),
						'wildcard': mw.msg( 'wikiautomator-match-mode-wildcard' ),
						'regex': mw.msg( 'wikiautomator-match-mode-regex' )
					}, function(v) {
						WikiAutomator.updateStep( index, 'match_mode', v );
						// Toggle regex flags
						if ( v === 'regex' && WikiAutomator.steps[index].action === 'replace' ) {
							$regexFlagsGroup.show();
						} else {
							$regexFlagsGroup.hide();
						}
					} );
					// Value inputs: split into search+replace for replace, single textarea for others
					var val = step.value;
					var isReplaceType = ( step.action === 'replace' );

					// Only show match mode for replace
					if ( !isReplaceType ) $matchModeGroup.hide();

					// Single value textarea (for append/prepend/overwrite)
					var singleVal = '';
					if ( !isReplaceType ) {
						singleVal = ( typeof val === 'object' && val !== null ) ? '' : ( val || '' );
					}
					var $valueGroup = this.createInputGroup( mw.msg( 'wikiautomator-step-value' ), singleVal, mw.msg( 'wikiautomator-step-value-placeholder' ), function(v) { WikiAutomator.updateStep(index, 'value', v); }, true );

					// Dual inputs (for replace)
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
					var $searchGroup = this.createInputGroup( mw.msg( 'wikiautomator-step-search-value' ), searchVal, mw.msg( 'wikiautomator-step-search-value-placeholder' ), function(v) {
						var cur = WikiAutomator.steps[index].value;
						if ( typeof cur !== 'object' || cur === null ) cur = { search: '', replace: '' };
						cur.search = v;
						WikiAutomator.updateStep( index, 'value', cur );
					} );
					var $replaceGroup = this.createInputGroup( mw.msg( 'wikiautomator-step-replace-value' ), replaceVal, mw.msg( 'wikiautomator-step-replace-value-placeholder' ), function(v) {
						var cur = WikiAutomator.steps[index].value;
						if ( typeof cur !== 'object' || cur === null ) cur = { search: '', replace: '' };
						cur.replace = v;
						WikiAutomator.updateStep( index, 'value', cur );
					} );

					// Initialize value as object for replace
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
					$regexFlagsGroup.append( $( '<label>' ).text( mw.msg( 'wikiautomator-step-regex-flags' ) ) );
					var flagsDef = [
						{ key: 'i', label: mw.msg( 'wikiautomator-regex-flag-i' ) },
						{ key: 'm', label: mw.msg( 'wikiautomator-regex-flag-m' ) },
						{ key: 'U', label: mw.msg( 'wikiautomator-regex-flag-U' ) }
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
					var stepMatchMode = step.match_mode || 'literal';
					if ( stepMatchMode !== 'regex' || step.action !== 'replace' ) {
						$regexFlagsGroup.hide();
					}

					// Move pages option: also replace text in page titles
					var $movePagesOption = $( '<div>' ).addClass( 'wa-input-group' );
					var $movePagesCheck = $( '<input>' ).attr( 'type', 'checkbox' ).prop( 'checked', !!step.move_pages );
					$movePagesCheck.on( 'change', function() {
						WikiAutomator.updateStep( index, 'move_pages', $( this ).prop( 'checked' ) );
						if ( $( this ).prop( 'checked' ) ) {
							$redirectOption.show();
						} else {
							$redirectOption.hide();
						}
					} );
					$movePagesOption.append(
						$( '<label>' ).css( 'display', 'inline' ).append( $movePagesCheck, ' ' + mw.msg( 'wikiautomator-step-move-pages' ) )
					);

					// Create redirect option (shown when move_pages is checked)
					var $redirectOption = $( '<div>' ).css( 'margin-left', '20px' );
					var createRedirect = ( step.value && typeof step.value === 'object' ) ? ( step.value.create_redirect !== false ) : true;
					var $redirectCheck = $( '<input>' ).attr( 'type', 'checkbox' ).prop( 'checked', createRedirect );
					$redirectCheck.on( 'change', function() {
						var val = WikiAutomator.steps[index].value;
						if ( typeof val === 'object' && val !== null ) {
							val.create_redirect = $( this ).prop( 'checked' );
						}
						WikiAutomator.updateStep( index, 'value', val );
					} );
					$redirectOption.append(
						$( '<label>' ).css( { display: 'inline', 'font-weight': 'normal' } ).append( $redirectCheck, ' ' + mw.msg( 'wikiautomator-step-create-redirect' ) )
					);
					if ( !step.move_pages ) $redirectOption.hide();
					$movePagesOption.append( $redirectOption );

					if ( step.action !== 'replace' ) $movePagesOption.hide();

					$content.append( $targetTypeGroup, $targetGroup, $searchWarning, $filtersGroup, $actionGroup, $matchModeGroup, $valueGroup, $searchGroup, $replaceGroup, $regexFlagsGroup, $movePagesOption );
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
