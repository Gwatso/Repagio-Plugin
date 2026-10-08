/**
 * Repagio block editor sidebar.
 *
 * Plain JavaScript against the wp.* globals the editor already loads, so there
 * is no build step. Every request goes to the same admin-ajax endpoints the
 * dashboard uses; nothing here talks to the Repagio service directly.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.data || ! wp.element || ! wp.components || ! wp.editor ) {
		return;
	}

	var settings = window.repagioEditor || {};
	var i18n = settings.i18n || {};

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useState = wp.element.useState;
	var useSelect = wp.data.useSelect;
	var components = wp.components;

	var STORE = 'repagio/editor';
	var SIDEBAR = 'repagio-sidebar';
	var ICON = 'book-alt';

	var formats = settings.formats || [];
	var keywordFormats = settings.keywordFor || [];

	/*
	 * State lives in a store rather than in the component, because the
	 * component unmounts whenever the sidebar is closed. A generation takes
	 * the best part of a minute, and its result must still be there when the
	 * sidebar is opened again.
	 *
	 * The Repurpose panel starts closed, and its open state lives here too.
	 * Opening it is what fetches the account's quota from the service, so a
	 * sidebar pinned open costs no external request on an editor load.
	 */
	var DEFAULTS = {
		status: null,
		statusLoading: false,
		statusError: null,
		repurposeOpen: false,
		quota: null,
		quotaLoading: false,
		format: formats.length ? formats[ 0 ].value : '',
		tone: settings.defaultTone || 'professional',
		keyword: '',
		generating: false,
		result: null,
		error: null,
		upgrade: null
	};

	wp.data.register(
		wp.data.createReduxStore( STORE, {
			reducer: function ( state, action ) {
				if ( 'SET' === action.type ) {
					return Object.assign( {}, state, action.patch );
				}

				return state || DEFAULTS;
			},
			actions: {
				set: function ( patch ) {
					return { type: 'SET', patch: patch };
				}
			},
			selectors: {
				get: function ( state ) {
					return state;
				}
			}
		} )
	);

	/**
	 * Merges a change into the sidebar's state.
	 *
	 * @param {Object} patch Keys to replace.
	 * @return {void}
	 */
	function set( patch ) {
		wp.data.dispatch( STORE ).set( patch );
	}

	/**
	 * Reads the sidebar's current state.
	 *
	 * @return {Object} State.
	 */
	function get() {
		return wp.data.select( STORE ).get();
	}

	/**
	 * Substitutes %s, %1$s and %2$s into a localised string.
	 *
	 * @param {string} template Format string.
	 * @param {string} one      First replacement.
	 * @param {string} two      Second replacement.
	 * @return {string} The filled string.
	 */
	function format( template, one, two ) {
		return String( template || '' )
			.replace( '%1$s', one )
			.replace( '%2$s', two )
			.replace( '%s', one );
	}

	/**
	 * Formats a count the way the reader's locale writes numbers.
	 *
	 * @param {*} value Number to show.
	 * @return {string} Formatted number.
	 */
	function number( value ) {
		return Number( value || 0 ).toLocaleString();
	}

	/**
	 * Posts to admin-ajax and resolves with the parsed envelope.
	 *
	 * @param {string} action Ajax action name.
	 * @param {Object} fields Extra form fields.
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
	 * Counts status requests, so a slow reply cannot overwrite a newer one.
	 *
	 * @type {number}
	 */
	var statusRequest = 0;

	/**
	 * Fetches the post's score, history and source preview.
	 *
	 * Answered entirely by this site; it never reaches the Repagio service.
	 *
	 * @param {number} postId Post being edited.
	 * @return {void}
	 */
	function loadStatus( postId ) {
		var request = ++statusRequest;

		set( { statusLoading: true, statusError: null } );

		ajax( 'repagio_post_status', { post_id: postId } )
			.then( function ( payload ) {
				if ( request !== statusRequest ) {
					return;
				}

				if ( ! payload || ! payload.success ) {
					set( {
						statusLoading: false,
						statusError: ( payload && payload.data ) || {}
					} );
					return;
				}

				set( { statusLoading: false, status: payload.data } );
			} )
			.catch( function () {
				if ( request === statusRequest ) {
					set( { statusLoading: false, statusError: {} } );
				}
			} );
	}

	/**
	 * Fetches the account's plan and remaining generations, once per page.
	 *
	 * This is the sidebar's only route to the service short of generating,
	 * and it is only taken when someone opens the Repurpose panel. A failure
	 * is left silent: the form still works, and Generate reports any problem
	 * with the account itself.
	 *
	 * @return void
	 */
	function loadQuota() {
		var state = get();

		if ( ! settings.hasKey || state.quota || state.quotaLoading ) {
			return;
		}

		set( { quotaLoading: true } );

		ajax( 'repagio_quota', {} )
			.then( function ( payload ) {
				var patch = { quotaLoading: false };

				// A generation finishing first carries a fresher figure.
				if ( payload && payload.success && payload.data && ! get().quota ) {
					patch.quota = payload.data.quota || null;
				}

				set( patch );
			} )
			.catch( function () {
				set( { quotaLoading: false } );
			} );
	}

	/**
	 * Opens or closes the Repurpose panel, fetching the quota on first open.
	 *
	 * @return {void}
	 */
	function toggleRepurpose() {
		var open = ! get().repurposeOpen;

		set( { repurposeOpen: open } );

		if ( open ) {
			loadQuota();
		}
	}

	/**
	 * Requests one generation for the post with the chosen options.
	 *
	 * @param {number} postId Post being edited.
	 * @return {void}
	 */
	function generate( postId ) {
		var state = get();

		if ( state.generating ) {
			return;
		}

		set( { generating: true, error: null, upgrade: null, result: null } );

		ajax( 'repagio_generate', {
			post_id: postId,
			format: state.format,
			tone: state.tone,
			keyword: state.keyword
		} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					var failure = ( payload && payload.data ) || {};
					var patch = { generating: false };

					if ( failure.quota ) {
						patch.quota = failure.quota;
					}

					// A spent allowance has a specific next step, which depends
					// on the tier, so it gets its own panel rather than an error.
					if ( failure.upgrade ) {
						patch.upgrade = failure.upgrade;
					} else {
						patch.error = failure;
					}

					set( patch );
					return;
				}

				set( {
					generating: false,
					result: payload.data,
					quota: payload.data.quota || get().quota
				} );

				// The post now has a new conversion, which changes its history
				// and its score.
				loadStatus( postId );
			} )
			.catch( function () {
				set( { generating: false, error: {} } );
			} );
	}

	/**
	 * Whether the account is known to have no generations left.
	 *
	 * @param {Object|null} quota Quota payload.
	 * @return {boolean} True when the allowance is spent.
	 */
	function isSpent( quota ) {
		return !! ( quota && quota.known && ! quota.unlimited && ! quota.hasQuota );
	}

	/**
	 * A link that leaves the editor in a new tab when it points off this site.
	 *
	 * @param {string} url   Destination.
	 * @param {string} label Link text.
	 * @return {Object} Element.
	 */
	function actionLink( url, label ) {
		if ( 0 !== url.indexOf( window.location.origin ) ) {
			return el( components.ExternalLink, { href: url }, label );
		}

		return el( 'a', { href: url }, label );
	}

	/**
	 * An error notice, with the server's suggested next step when it has one.
	 *
	 * Messages are rendered as text, never markup, so nothing that comes back
	 * from the service can inject HTML into the editor.
	 *
	 * @param {Object} props Component props.
	 * @return {Object} Element.
	 */
	function ErrorNotice( props ) {
		var data = props.error || {};
		var children = [ el( 'p', { key: 'message' }, data.message || i18n.genericError ) ];

		if ( data.actionUrl && data.actionLabel ) {
			children.push(
				el( 'p', { key: 'action' }, actionLink( data.actionUrl, data.actionLabel ) )
			);
		}

		if ( props.onRetry ) {
			children.push(
				el(
					components.Button,
					{ key: 'retry', variant: 'secondary', onClick: props.onRetry },
					i18n.retry
				)
			);
		}

		return el(
			components.Notice,
			{ status: 'error', isDismissible: false, className: 'repagio-sidebar-notice' },
			children
		);
	}

	/**
	 * The post's score, why it scored that, and what it has been made into.
	 *
	 * @param {Object} props Component props.
	 * @return {Object} Element.
	 */
	function Opportunity( props ) {
		var status = props.status;
		var score = Math.max( 0, Math.min( 100, parseInt( status.score, 10 ) || 0 ) );
		var band = status.band || 'low';

		return el(
			Fragment,
			null,
			el(
				'div',
				{ className: 'repagio-sidebar-score' },
				el( 'span', { className: 'screen-reader-text' }, format( i18n.scoreLabel, String( score ) ) ),
				el(
					'span',
					{ className: 'repagio-score repagio-score--' + band, 'aria-hidden': 'true' },
					String( score )
				),
				el(
					'span',
					{ className: 'repagio-score-track repagio-score-track--' + band, 'aria-hidden': 'true' },
					// The width is this post's score, so it cannot live in the stylesheet.
					el( 'span', { className: 'repagio-score-fill', style: { width: score + '%' } } )
				)
			),
			el( 'p', { className: 'repagio-sidebar-reason' }, status.reason || '' ),
			status.converted && status.converted.length
				? el(
					'div',
					{ className: 'repagio-sidebar-history' },
					el( 'p', { className: 'repagio-sidebar-label' }, i18n.repurposedAs ),
					el(
						'ul',
						null,
						status.converted.map( function ( row ) {
							return el(
								'li',
								{ key: row.format },
								el( 'span', null, row.label ),
								row.date ? el( 'span', { className: 'repagio-sidebar-date' }, row.date ) : null
							);
						} )
					)
				)
				: null,
			settings.canManage
				? el( 'p', null, el( 'a', { href: settings.dashboardUrl }, i18n.openDashboard ) )
				: null
		);
	}

	/**
	 * What will be sent, so the choice to generate is an informed one.
	 *
	 * @param {Object} props Component props.
	 * @return {Object|null} Element.
	 */
	function SourceNote( props ) {
		var source = props.source;

		if ( ! source ) {
			return null;
		}

		if ( source.error ) {
			return el( ErrorNotice, { error: { message: source.error } } );
		}

		var words = format( i18n.wordsToSend, number( source.wordCount ) );

		if ( source.truncated ) {
			return el(
				'p',
				{ className: 'repagio-sidebar-note repagio-sidebar-note--warning' },
				words + ' ' + format( i18n.truncated, number( source.length ), number( source.originalLength ) )
			);
		}

		return el( 'p', { className: 'repagio-sidebar-note' }, words );
	}

	/**
	 * Format, tone and keyword, and the button that sends them.
	 *
	 * @param {Object} props Component props.
	 * @return {Object} Element.
	 */
	function GenerateForm( props ) {
		var state = props.state;
		var source = state.status ? state.status.source : null;
		var wantsKeyword = -1 !== keywordFormats.indexOf( state.format );
		var quota = state.quota;
		var ready = !! state.status && !! source && ! source.error;

		return el(
			'form',
			{
				className: 'repagio-sidebar-form',
				onSubmit: function ( event ) {
					event.preventDefault();

					if ( ready ) {
						generate( props.postId );
					}
				}
			},
			el( components.SelectControl, {
				label: i18n.format,
				value: state.format,
				options: formats,
				disabled: state.generating,
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				onChange: function ( value ) {
					var patch = { format: value };

					// A keyword only means anything for the formats that take one.
					if ( -1 === keywordFormats.indexOf( value ) ) {
						patch.keyword = '';
					}

					set( patch );
				}
			} ),
			el( components.SelectControl, {
				label: i18n.tone,
				value: state.tone,
				options: settings.tones || [],
				disabled: state.generating,
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				onChange: function ( value ) {
					set( { tone: value } );
				}
			} ),
			wantsKeyword
				? el( components.TextControl, {
					label: i18n.keyword,
					value: state.keyword,
					placeholder: i18n.keywordHint,
					autoComplete: 'off',
					disabled: state.generating,
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					onChange: function ( value ) {
						set( { keyword: value } );
					}
				} )
				: null,
			state.statusLoading && ! state.status
				? el( 'p', { className: 'repagio-sidebar-note' }, el( components.Spinner ), i18n.loading )
				: el( SourceNote, { source: source } ),
			quota && ! quota.unlimited && quota.phrase
				? el( 'p', { className: 'repagio-sidebar-note' }, quota.phrase )
				: null,
			el(
				components.Button,
				{
					type: 'submit',
					variant: 'primary',
					isBusy: state.generating,
					disabled: state.generating || ! ready,
					__next40pxDefaultSize: true
				},
				state.generating ? i18n.generating : i18n.generate
			),
			state.generating
				? el( 'p', { className: 'repagio-sidebar-note', role: 'status' }, i18n.generateWait )
				: null
		);
	}

	/**
	 * A finished generation, ready to copy.
	 *
	 * @param {Object} props Component props.
	 * @return {Object} Element.
	 */
	function Result( props ) {
		var result = props.result;
		var content = result.content || '';
		var copiedState = useState( false );
		var copied = copiedState[ 0 ];
		var setCopied = copiedState[ 1 ];
		var timer = useRef( null );

		useEffect( function () {
			return function () {
				window.clearTimeout( timer.current );
			};
		}, [] );

		var copyRef = wp.compose.useCopyToClipboard( content, function () {
			setCopied( true );
			window.clearTimeout( timer.current );
			timer.current = window.setTimeout( function () {
				setCopied( false );
			}, 2000 );
		} );

		return el(
			'div',
			{ className: 'repagio-sidebar-result' },
			el(
				'p',
				{ className: 'repagio-sidebar-note' },
				format( i18n.resultMeta, result.formatLabel || '', number( result.wordCount ) )
			),
			// Read-only text, so generated output can never become markup.
			el( components.TextareaControl, {
				label: i18n.result,
				value: content,
				readOnly: true,
				rows: 14,
				className: 'repagio-sidebar-output',
				__nextHasNoMarginBottom: true,
				onChange: function () {}
			} ),
			el(
				'div',
				{ className: 'repagio-sidebar-actions' },
				el(
					components.Button,
					{ variant: 'primary', ref: copyRef, __next40pxDefaultSize: true },
					copied ? i18n.copied : i18n.copy
				),
				el(
					components.Button,
					{
						variant: 'secondary',
						__next40pxDefaultSize: true,
						onClick: function () {
							set( { result: null, error: null } );
						}
					},
					i18n.again
				)
			)
		);
	}

	/**
	 * The tier-appropriate prompt shown once the allowance is spent.
	 *
	 * @param {Object} props Component props.
	 * @return {Object} Element.
	 */
	function Upgrade( props ) {
		var offer = props.offer || {};

		return el(
			'div',
			{ className: 'repagio-sidebar-upgrade' },
			offer.title ? el( 'p', { className: 'repagio-sidebar-upgrade-title' }, offer.title ) : null,
			offer.message ? el( 'p', null, offer.message ) : null,
			offer.note ? el( 'p', { className: 'repagio-sidebar-note' }, offer.note ) : null,
			// Pro and Agency get no button: there is nothing above them to sell.
			offer.url && offer.label
				? el(
					components.Button,
					{
						variant: 'primary',
						href: offer.url,
						target: '_blank',
						rel: 'noopener noreferrer',
						__next40pxDefaultSize: true
					},
					offer.label
				)
				: null
		);
	}

	/**
	 * The Repurpose panel: whichever of form, result or upgrade applies.
	 *
	 * @param {Object} props Component props.
	 * @return {Object} Element.
	 */
	function Repurpose( props ) {
		var state = props.state;

		if ( ! settings.hasKey ) {
			return el(
				Fragment,
				null,
				el( 'p', null, i18n.noKey ),
				settings.canManage
					? el(
						components.Button,
						{ variant: 'secondary', href: settings.settingsUrl, __next40pxDefaultSize: true },
						i18n.connect
					)
					: el( 'p', { className: 'repagio-sidebar-note' }, i18n.askAdmin )
			);
		}

		var body;

		if ( state.result ) {
			body = el( Result, { result: state.result } );
		} else if ( state.upgrade || ( isSpent( state.quota ) && ! state.generating ) ) {
			body = el( Upgrade, { offer: state.upgrade || state.quota.upgrade } );
		} else {
			body = el( GenerateForm, { state: state, postId: props.postId } );
		}

		return el(
			Fragment,
			null,
			props.dirty
				? el(
					components.Notice,
					{ status: 'warning', isDismissible: false, className: 'repagio-sidebar-notice' },
					i18n.unsaved
				)
				: null,
			state.error ? el( ErrorNotice, { error: state.error } ) : null,
			body
		);
	}

	/**
	 * The sidebar's content.
	 *
	 * @return {Object} Element.
	 */
	function SidebarBody() {
		var editor = useSelect( function ( select ) {
			var store = select( 'core/editor' );

			return {
				postId: store.getCurrentPostId(),
				// The saved status, not the edited one: Repagio reads what is
				// in the database, so an unsaved switch to Published is not
				// published yet.
				published: 'publish' === store.getCurrentPostAttribute( 'status' ),
				dirty: store.isEditedPostDirty(),
				saving: store.isSavingPost() && ! store.isAutosavingPost()
			};
		}, [] );

		var state = useSelect( function ( select ) {
			return select( STORE ).get();
		}, [] );

		var wasSaving = useRef( editor.saving );

		// Opening the sidebar, or the post becoming published, reads its status.
		useEffect(
			function () {
				if ( editor.postId && editor.published ) {
					loadStatus( editor.postId );
				}
			},
			[ editor.postId, editor.published ]
		);

		// A finished save may have changed the content, so read it again.
		useEffect(
			function () {
				if ( wasSaving.current && ! editor.saving && editor.published ) {
					loadStatus( editor.postId );
				}

				wasSaving.current = editor.saving;
			},
			[ editor.saving ]
		);

		if ( ! editor.published ) {
			return el(
				components.PanelBody,
				null,
				el( 'p', null, i18n.notPublished )
			);
		}

		var opportunity;

		if ( state.status ) {
			opportunity = el( Opportunity, { status: state.status } );
		} else if ( state.statusError ) {
			opportunity = el( ErrorNotice, {
				error: state.statusError,
				onRetry: function () {
					loadStatus( editor.postId );
				}
			} );
		} else {
			opportunity = el( 'p', { className: 'repagio-sidebar-note' }, el( components.Spinner ), i18n.loading );
		}

		return el(
			Fragment,
			null,
			el( components.PanelBody, { title: i18n.opportunity }, opportunity ),
			el(
				components.PanelBody,
				{ title: i18n.repurpose, opened: state.repurposeOpen, onToggle: toggleRepurpose },
				// A closed panel renders nothing, so its contents cannot
				// trigger anything until someone opens it.
				state.repurposeOpen
					? el( Repurpose, { state: state, postId: editor.postId, dirty: editor.dirty } )
					: null
			)
		);
	}

	wp.plugins.registerPlugin( 'repagio', {
		icon: ICON,
		render: function () {
			return el(
				Fragment,
				null,
				el( wp.editor.PluginSidebarMoreMenuItem, { target: SIDEBAR, icon: ICON }, i18n.title ),
				el(
					wp.editor.PluginSidebar,
					{ name: SIDEBAR, title: i18n.title, icon: ICON, className: 'repagio-sidebar' },
					el( SidebarBody )
				)
			);
		}
	} );
}( window.wp ) );
