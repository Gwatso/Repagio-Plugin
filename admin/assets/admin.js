/**
 * Repagio admin behaviour.
 *
 * Loaded only on the plugin's own screens. Vanilla JS, no build step.
 */
( function () {
	'use strict';

	var settings = window.repagioAdmin || {};
	var i18n = settings.i18n || {};

	/**
	 * Builds a notice element. Text is set with textContent so that anything
	 * coming back from the API cannot inject markup into the admin.
	 *
	 * @param {string} message Message to show.
	 * @param {string} type    'success' or 'error'.
	 * @return {HTMLElement} The notice.
	 */
	function buildNotice( message, type ) {
		var notice = document.createElement( 'div' );
		var paragraph = document.createElement( 'p' );

		notice.className = 'repagio-notice repagio-notice--' + type;
		paragraph.textContent = message;
		notice.appendChild( paragraph );

		return notice;
	}

	/**
	 * Appends a label/value row to a notice.
	 *
	 * @param {HTMLElement} list  List element to append to.
	 * @param {string}      label Row label.
	 * @param {string}      value Row value.
	 * @return {void}
	 */
	function appendAccountRow( list, label, value ) {
		var item = document.createElement( 'li' );
		var labelEl = document.createElement( 'span' );
		var valueEl = document.createElement( 'strong' );

		labelEl.className = 'repagio-account-label';
		labelEl.textContent = label;
		valueEl.textContent = value;

		item.appendChild( labelEl );
		item.appendChild( valueEl );
		list.appendChild( item );
	}

	/**
	 * Renders the account summary underneath a success notice.
	 *
	 * @param {HTMLElement} notice  Notice to extend.
	 * @param {Object}      account Account summary from the server.
	 * @return {void}
	 */
	function appendAccount( notice, account ) {
		if ( ! account ) {
			return;
		}

		var list = document.createElement( 'ul' );
		var rows = 0;

		list.className = 'repagio-account';

		/**
		 * Adds a row when the value is actually present.
		 *
		 * @param {string} label Row label.
		 * @param {*}      value Row value.
		 * @return {void}
		 */
		function maybeRow( label, value ) {
			if ( null === value || undefined === value || '' === value ) {
				return;
			}

			appendAccountRow( list, label, String( value ) );
			rows++;
		}

		maybeRow( i18n.planLabel || 'Plan', account.plan );
		maybeRow( i18n.used || 'Conversions used', account.used );
		maybeRow( i18n.remaining || 'Conversions remaining', account.remaining );

		if ( rows ) {
			notice.appendChild( list );
		}
	}

	/**
	 * Appends the server's suggested follow-up link to a notice.
	 *
	 * @param {HTMLElement} notice Notice to extend.
	 * @param {Object}      data   Error payload.
	 * @return {void}
	 */
	function appendAction( notice, data ) {
		if ( ! data || ! data.actionUrl || ! data.actionLabel ) {
			return;
		}

		var paragraph = document.createElement( 'p' );
		var link = document.createElement( 'a' );

		link.href = data.actionUrl;
		link.textContent = data.actionLabel;
		link.className = 'button button-secondary';

		if ( 0 !== data.actionUrl.indexOf( window.location.origin ) ) {
			link.target = '_blank';
			link.rel = 'noopener noreferrer';
		}

		paragraph.appendChild( link );
		notice.appendChild( paragraph );
	}

	/**
	 * Wires the Test connection button.
	 *
	 * @return {void}
	 */
	function initConnectionTest() {
		var button = document.getElementById( 'repagio-test-connection' );
		var result = document.getElementById( 'repagio-test-result' );
		var spinner = document.getElementById( 'repagio-test-spinner' );

		if ( ! button || ! result ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var defaultLabel = button.getAttribute( 'data-default-label' ) || button.textContent;

			button.disabled = true;
			button.textContent = i18n.testing || 'Testing…';
			result.textContent = '';

			if ( spinner ) {
				spinner.classList.add( 'is-active' );
			}

			var body = new URLSearchParams();
			body.append( 'action', 'repagio_test_connection' );
			body.append( 'nonce', settings.nonce || '' );

			window
				.fetch( settings.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
					},
					body: body.toString()
				} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					var data = payload && payload.data ? payload.data : {};
					var message = data.message || i18n.genericError || 'Something went wrong.';

					if ( payload && payload.success ) {
						var notice = buildNotice( message, 'success' );
						appendAccount( notice, data.account );
						result.appendChild( notice );
						return;
					}

					var failure = buildNotice( message, 'error' );
					appendAction( failure, data );
					result.appendChild( failure );
				} )
				.catch( function () {
					result.appendChild(
						buildNotice( i18n.genericError || 'Something went wrong.', 'error' )
					);
				} )
				.then( function () {
					button.disabled = false;
					button.textContent = defaultLabel;

					if ( spinner ) {
						spinner.classList.remove( 'is-active' );
					}
				} );
		} );
	}

	/**
	 * Substitutes %1$s and %2$s into a localised string.
	 *
	 * @param {string} template Format string.
	 * @param {string} one      First replacement.
	 * @param {string} two      Second replacement.
	 * @return {string} The filled string.
	 */
	function format( template, one, two ) {
		return String( template )
			.replace( '%1$s', one )
			.replace( '%2$s', two );
	}

	/**
	 * Drives the batched archive scan on sites with large archives.
	 *
	 * Each request scans a slice of the archive and the server folds it into
	 * the cached partial result, so no single request runs long. Once the
	 * server reports the scan complete the page reloads into the dashboard.
	 *
	 * @return {void}
	 */
	function initBatchedScan() {
		var container = document.getElementById( 'repagio-scan-progress' );

		if ( ! container ) {
			return;
		}

		var fill = document.getElementById( 'repagio-progress-fill' );
		var status = document.getElementById( 'repagio-scan-status' );
		var bar = container.querySelector( '.repagio-progress-bar' );
		var total = parseInt( container.getAttribute( 'data-total' ), 10 ) || 0;
		var offset = parseInt( container.getAttribute( 'data-offset' ), 10 ) || 0;
		var restarts = 0;

		if ( total < 1 ) {
			return;
		}

		/**
		 * Paints the progress bar and its status line.
		 *
		 * @param {number} scanned Posts scanned so far.
		 * @param {number} known   Posts in total.
		 * @return {void}
		 */
		function paint( scanned, known ) {
			var percent = known > 0 ? Math.min( 100, Math.round( ( scanned / known ) * 100 ) ) : 0;

			if ( fill ) {
				fill.style.width = percent + '%';
			}

			if ( bar ) {
				bar.setAttribute( 'aria-valuemax', String( known ) );
				bar.setAttribute( 'aria-valuenow', String( scanned ) );
			}

			if ( status ) {
				status.textContent = format(
					i18n.scanProgress || 'Scanned %1$s of %2$s posts.',
					String( scanned ),
					String( known )
				);
			}
		}

		/**
		 * Shows a terminal error and stops the loop.
		 *
		 * @return {void}
		 */
		function fail() {
			if ( status ) {
				status.textContent = i18n.scanError || 'The scan could not finish.';
			}
		}

		/**
		 * Requests one batch, then either continues or reloads.
		 *
		 * @return {void}
		 */
		function step() {
			var body = new URLSearchParams();
			body.append( 'action', 'repagio_scan_batch' );
			body.append( 'nonce', settings.nonce || '' );
			body.append( 'offset', String( offset ) );

			window
				.fetch( settings.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
					},
					body: body.toString()
				} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					if ( ! payload || ! payload.success || ! payload.data ) {
						fail();
						return;
					}

					var data = payload.data;

					// The server rebuilds a lost partial result from zero. Allow
					// that a couple of times, then give up rather than loop.
					if ( data.restarted ) {
						restarts++;

						if ( restarts > 2 ) {
							fail();
							return;
						}
					}

					total = parseInt( data.total, 10 ) || total;
					offset = parseInt( data.next_offset, 10 ) || 0;

					paint( parseInt( data.scanned, 10 ) || 0, total );

					if ( data.complete ) {
						if ( status ) {
							status.textContent = i18n.scanDone || 'Scan complete.';
						}

						window.location.reload();
						return;
					}

					step();
				} )
				.catch( fail );
		}

		paint( offset, total );
		step();
	}

	/**
	 * Posts to admin-ajax and resolves with the parsed envelope.
	 *
	 * @param {string} action Ajax action name.
	 * @param {Object} fields  Extra form fields.
	 * @return {Promise<Object>} Resolves { success, data }.
	 */
	function ajax( action, fields ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', settings.nonce || '' );

		Object.keys( fields || {} ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );

		return window
			.fetch( settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
				},
				body: body.toString()
			} )
			.then( function ( response ) {
				return response.json();
			} );
	}

	/**
	 * Drives the Repurpose modal: the form, the generation, and the result.
	 *
	 * @return {void}
	 */
	function initRepurpose() {
		var modal = document.getElementById( 'repagio-modal' );
		var buttons = document.querySelectorAll( '.repagio-repurpose' );

		if ( ! modal || ! buttons.length ) {
			return;
		}

		var form = document.getElementById( 'repagio-generate-form' );
		var postField = document.getElementById( 'repagio-field-post' );
		var formatField = document.getElementById( 'repagio-field-format' );
		var toneField = document.getElementById( 'repagio-field-tone' );
		var keywordField = document.getElementById( 'repagio-field-keyword' );
		var keywordRow = document.getElementById( 'repagio-field-keyword-row' );
		var postLine = document.getElementById( 'repagio-modal-post' );
		var sourceNote = document.getElementById( 'repagio-source-note' );
		var notice = document.getElementById( 'repagio-modal-notice' );
		var generateButton = document.getElementById( 'repagio-generate-button' );
		var spinner = document.getElementById( 'repagio-generate-spinner' );
		var waitNote = document.getElementById( 'repagio-generate-wait' );
		var resultPanel = document.getElementById( 'repagio-result-panel' );
		var resultContent = document.getElementById( 'repagio-result-content' );
		var resultMeta = document.getElementById( 'repagio-result-meta' );
		var copyButton = document.getElementById( 'repagio-copy-button' );
		var againButton = document.getElementById( 'repagio-again-button' );
		var upgradePanel = document.getElementById( 'repagio-upgrade-panel' );
		var upgradeTitle = document.getElementById( 'repagio-upgrade-title' );
		var upgradeMessage = document.getElementById( 'repagio-upgrade-message' );
		var upgradeNote = document.getElementById( 'repagio-upgrade-note' );
		var upgradeButton = document.getElementById( 'repagio-upgrade-button' );
		var modalQuota = document.getElementById( 'repagio-modal-quota' );
		var keywordFormats = settings.keywordFor || [];
		var quota = settings.quota || {};
		var lastFocus = null;
		var busy = false;

		/**
		 * Reflects the current quota in the dashboard strip and the modal.
		 *
		 * @param {Object} next Fresh quota payload from the server.
		 * @return {void}
		 */
		function applyQuota( next ) {
			if ( ! next ) {
				return;
			}

			quota = next;

			var strip = document.querySelector( '.repagio-status' );

			if ( strip && next.phrase ) {
				var quotaEl = strip.querySelector( '.repagio-status-quota' );

				if ( quotaEl ) {
					quotaEl.textContent = next.phrase;
				}

				strip.className = 'repagio-status repagio-status--' + ( next.band || 'neutral' );
			}

			if ( modalQuota ) {
				modalQuota.textContent = next.unlimited ? '' : ( next.phrase || '' );
			}
		}

		/**
		 * Shows the tier-appropriate upgrade prompt in place of the form.
		 *
		 * @param {Object} offer Upgrade offer from the server.
		 * @return {void}
		 */
		function showUpgrade( offer ) {
			if ( ! upgradePanel || ! offer ) {
				return;
			}

			clearNotice();

			form.hidden = true;
			resultPanel.hidden = true;
			upgradePanel.hidden = false;

			upgradeTitle.textContent = offer.title || '';
			upgradeMessage.textContent = offer.message || '';
			upgradeNote.textContent = offer.note || '';
			upgradeNote.hidden = ! offer.note;

			// Pro and Agency get no upgrade button: there is nothing above them
			// to sell, and a limit there is a fault, not a prompt to spend.
			if ( offer.url && offer.label ) {
				upgradeButton.href = offer.url;
				upgradeButton.textContent = offer.label;
				upgradeButton.hidden = false;
			} else {
				upgradeButton.hidden = true;
				upgradeButton.removeAttribute( 'href' );
			}
		}

		/**
		 * Empties the notice area.
		 *
		 * @return {void}
		 */
		function clearNotice() {
			while ( notice.firstChild ) {
				notice.removeChild( notice.firstChild );
			}
		}

		/**
		 * Shows an error, with the follow-up link when the server offers one.
		 *
		 * @param {Object} data Error payload from the server.
		 * @return {void}
		 */
		function showError( data ) {
			clearNotice();

			var payload = data || {};
			var box = buildNotice(
				payload.message || i18n.genericError || 'Something went wrong.',
				'error'
			);

			if ( payload.actionUrl && payload.actionLabel ) {
				var paragraph = document.createElement( 'p' );
				var link = document.createElement( 'a' );

				link.href = payload.actionUrl;
				link.textContent = payload.actionLabel;
				link.className = 'button button-secondary';

				// Anything off this site opens in its own tab.
				if ( 0 !== payload.actionUrl.indexOf( window.location.origin ) ) {
					link.target = '_blank';
					link.rel = 'noopener noreferrer';
				}

				paragraph.appendChild( link );
				box.appendChild( paragraph );
			}

			notice.appendChild( box );
		}

		/**
		 * Shows or hides the keyword field for the chosen format.
		 *
		 * @return {void}
		 */
		function syncKeyword() {
			var wanted = -1 !== keywordFormats.indexOf( formatField.value );

			keywordRow.hidden = ! wanted;

			if ( ! wanted ) {
				keywordField.value = '';
			}
		}

		/**
		 * Puts the modal back to its form state.
		 *
		 * @return {void}
		 */
		function showForm() {
			resultPanel.hidden = true;

			if ( upgradePanel ) {
				upgradePanel.hidden = true;
			}

			form.hidden = false;
			clearNotice();
			syncKeyword();
		}

		/**
		 * Enables or disables the whole form while a request is in flight.
		 *
		 * @param {boolean} state Whether a request is running.
		 * @return {void}
		 */
		function setBusy( state ) {
			busy = state;

			[ formatField, toneField, keywordField, generateButton ].forEach( function ( el ) {
				el.disabled = state;
			} );

			generateButton.textContent = state
				? i18n.generating || 'Generating…'
				: generateButton.getAttribute( 'data-label' );

			waitNote.hidden = ! state;

			if ( state ) {
				spinner.classList.add( 'is-active' );
			} else {
				spinner.classList.remove( 'is-active' );
			}
		}

		/**
		 * Asks the server what would be sent, and reports it.
		 *
		 * @param {string} postId Post to describe.
		 * @return {void}
		 */
		function prepare( postId ) {
			sourceNote.textContent = i18n.preparing || 'Reading the post…';
			generateButton.disabled = true;

			ajax( 'repagio_prepare', { post_id: postId } )
				.then( function ( payload ) {
					// A later click may have replaced the post being shown.
					if ( postField.value !== postId ) {
						return;
					}

					if ( ! payload || ! payload.success ) {
						sourceNote.textContent = '';
						showError( payload ? payload.data : null );
						return;
					}

					var data = payload.data;
					var parts = [];

					parts.push(
						format(
							i18n.wordsToSend || '%1$s words will be sent to Repagio.',
							Number( data.wordCount ).toLocaleString(),
							''
						)
					);

					sourceNote.textContent = parts.join( ' ' );
					sourceNote.classList.remove( 'repagio-warning' );

					if ( data.truncated ) {
						sourceNote.textContent =
							parts.join( ' ' ) +
							' ' +
							format(
								i18n.truncated || '',
								Number( data.length ).toLocaleString(),
								Number( data.originalLength ).toLocaleString()
							);
						sourceNote.classList.add( 'repagio-warning' );
					}

					generateButton.disabled = false;
				} )
				.catch( function () {
					if ( postField.value !== postId ) {
						return;
					}

					sourceNote.textContent = '';
					showError( null );
				} );
		}

		/**
		 * Opens the modal for one post.
		 *
		 * @param {HTMLElement} button The Repurpose button that was clicked.
		 * @return {void}
		 */
		function open( button ) {
			lastFocus = button;

			postLine.textContent = button.getAttribute( 'data-post-title' ) || '';

			modal.hidden = false;
			document.body.classList.add( 'repagio-modal-open' );

			// Pre-flight: an allowance known to be spent is answered from the
			// page, without a round trip that could only say no.
			if ( quota.known && ! quota.unlimited && ! quota.hasQuota ) {
				showUpgrade( quota.upgrade );
				upgradeButton.focus();

				return;
			}

			form.reset();
			postField.value = button.getAttribute( 'data-post-id' ) || '';
			toneField.value = settings.defaultTone || 'professional';
			sourceNote.textContent = '';
			sourceNote.classList.remove( 'repagio-warning' );

			showForm();
			applyQuota( quota );
			setBusy( false );

			formatField.focus();
			prepare( postField.value );
		}

		/**
		 * Closes the modal, unless a generation is still running.
		 *
		 * @return {void}
		 */
		function close() {
			if ( busy ) {
				return;
			}

			modal.hidden = true;
			document.body.classList.remove( 'repagio-modal-open' );

			if ( lastFocus ) {
				lastFocus.focus();
			}
		}

		/**
		 * Renders a finished generation.
		 *
		 * @param {Object} data Success payload.
		 * @return {void}
		 */
		function showResult( data ) {
			clearNotice();

			form.hidden = true;
			resultPanel.hidden = false;

			// textContent, so generated output can never become markup.
			resultContent.textContent = data.content || '';

			resultMeta.textContent =
				( data.formatLabel || '' ) +
				' — ' +
				format(
					i18n.resultWords || '%1$s words generated.',
					Number( data.wordCount || 0 ).toLocaleString(),
					''
				);

			copyButton.textContent = i18n.copy || 'Copy to clipboard';
			resultContent.focus();
		}

		generateButton.setAttribute( 'data-label', generateButton.textContent.trim() );

		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', function () {
				open( button );
			} );
		} );

		formatField.addEventListener( 'change', syncKeyword );

		modal.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-repagio-close]' ) ) {
				event.preventDefault();
				close();
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! modal.hidden ) {
				close();
			}
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			if ( busy ) {
				return;
			}

			clearNotice();
			setBusy( true );

			ajax( 'repagio_generate', {
				post_id: postField.value,
				format: formatField.value,
				tone: toneField.value,
				keyword: keywordField.value
			} )
				.then( function ( payload ) {
					setBusy( false );

					if ( ! payload || ! payload.success ) {
						var failure = payload ? payload.data : null;

						if ( failure && failure.quota ) {
							applyQuota( failure.quota );
						}

						// A spent allowance is not an error to shrug at: it has
						// a specific next step, which depends on the tier.
						if ( failure && failure.upgrade ) {
							showUpgrade( failure.upgrade );
							return;
						}

						showError( failure );
						return;
					}

					if ( payload.data.quota ) {
						applyQuota( payload.data.quota );
					}

					showResult( payload.data );
				} )
				.catch( function () {
					setBusy( false );
					showError( null );
				} );
		} );

		againButton.addEventListener( 'click', function () {
			// The generation just made may have been the last one available.
			if ( quota.known && ! quota.unlimited && ! quota.hasQuota ) {
				showUpgrade( quota.upgrade );
				upgradeButton.focus();

				return;
			}

			showForm();
			applyQuota( quota );
			formatField.focus();
		} );

		copyButton.addEventListener( 'click', function () {
			var text = resultContent.textContent;

			/**
			 * Reports a successful copy on the button itself.
			 *
			 * @return {void}
			 */
			function copied() {
				copyButton.textContent = i18n.copied || 'Copied';

				window.setTimeout( function () {
					copyButton.textContent = i18n.copy || 'Copy to clipboard';
				}, 2000 );
			}

			if ( window.navigator.clipboard && window.navigator.clipboard.writeText ) {
				window.navigator.clipboard.writeText( text ).then( copied, function () {
					showError( { message: i18n.copyFailed } );
				} );

				return;
			}

			// Older browsers, and any page not served over a secure context.
			try {
				var selection = window.getSelection();
				var range = document.createRange();

				range.selectNodeContents( resultContent );
				selection.removeAllRanges();
				selection.addRange( range );

				if ( document.execCommand( 'copy' ) ) {
					copied();
				} else {
					showError( { message: i18n.copyFailed } );
				}

				selection.removeAllRanges();
			} catch ( e ) {
				showError( { message: i18n.copyFailed } );
			}
		} );
	}

	/**
	 * Wires up whichever Repagio screen is on show.
	 *
	 * @return {void}
	 */
	function init() {
		initConnectionTest();
		initBatchedScan();
		initRepurpose();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
