/** Tezos Account block: editor UI (no build step). Preview is rendered by PHP. */
( function ( wp ) {
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var be = wp.blockEditor;
	var c = wp.components;
	var SSR = wp.serverSideRender;

	var opts = function ( pairs ) {
		return pairs.map( function ( p ) {
			return { value: p[ 0 ], label: p[ 1 ] };
		} );
	};

	function Edit( props ) {
		var a = props.attributes;
		var set = props.setAttributes;
		var draftState = useState( a.address );
		var draft = draftState[ 0 ];
		var setDraft = draftState[ 1 ];
		var commit = function () {
			if ( draft !== a.address ) {
				set( { address: draft.trim() } );
			}
		};
		var toggle = function ( key, label, help ) {
			return el( c.ToggleControl, {
				label: label,
				help: help,
				checked: a[ key ],
				onChange: function ( v ) {
					var next = {};
					next[ key ] = v;
					set( next );
				},
				__nextHasNoMarginBottom: true,
			} );
		};
		var addressField = el( c.TextControl, {
			label: 'Account',
			help: 'A tz1… / KT1… address or a .tez name.',
			value: draft,
			placeholder: 'tz1… or name.tez',
			onChange: setDraft,
			onBlur: commit,
			onKeyDown: function ( e ) {
				if ( e.key === 'Enter' ) {
					commit();
				}
			},
			__nextHasNoMarginBottom: true,
		} );

		var inspector = el(
			be.InspectorControls,
			null,
			el(
				c.PanelBody,
				{ title: 'Account', initialOpen: true },
				addressField,
				el( c.SelectControl, {
					label: 'Layout',
					value: a.layout,
					options: opts( [
						[ 'card', 'Card' ],
						[ 'stats', 'Stat tiles only' ],
						[ 'line', 'One line (for footers and bylines)' ],
					] ),
					onChange: function ( v ) {
						set( { layout: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( c.SelectControl, {
					label: 'Chain stats from',
					value: a.statsSource,
					options: opts( [
						[ 'tzkt', 'TzKT (default)' ],
						[ 'tzstats', 'TzStats (non-commercial use)' ],
						[ 'none', 'None (names only)' ],
					] ),
					onChange: function ( v ) {
						set( { statsSource: v } );
					},
					__nextHasNoMarginBottom: true,
				} )
			),
			el(
				c.PanelBody,
				{ title: 'What to show', initialOpen: true },
				toggle( 'showName', 'Primary .tez name' ),
				toggle( 'showBalance', 'Balance' ),
				toggle( 'showActivity', 'Transactions, first seen, last active' ),
				toggle( 'showTokens', 'Tokens held', 'TzKT only.' ),
				toggle( 'showDelegate', 'Delegate (baker)' ),
				toggle( 'showDomains', 'Owned Tezos Domains' ),
				a.showDomains
					? el( c.RangeControl, {
							label: 'Domains to list',
							value: a.domainsLimit,
							min: 1,
							max: 30,
							onChange: function ( v ) {
								set( { domainsLimit: v } );
							},
							__nextHasNoMarginBottom: true,
					  } )
					: null,
				toggle( 'showLinks', 'Explorer links (TzKT, TzStats, objkt)' )
			),
			el(
				c.PanelBody,
				{ title: 'Performance', initialOpen: false },
				el( c.RangeControl, {
					label: 'Cache (minutes)',
					value: a.cacheMinutes,
					min: 1,
					max: 1440,
					help: 'Results are reused this long; if a service is down the last good copy is shown.',
					onChange: function ( v ) {
						set( { cacheMinutes: v } );
					},
					__nextHasNoMarginBottom: true,
				} )
			)
		);

		var blockProps = be.useBlockProps();
		if ( ! a.address ) {
			return el(
				Fragment,
				null,
				inspector,
				el(
					'div',
					blockProps,
					el(
						c.Placeholder,
						{ icon: 'id-alt', label: 'Tezos Account', instructions: 'Show an account\u2019s public stats and Tezos Domains names.' },
						addressField,
						el( c.Button, { variant: 'primary', onClick: commit, disabled: ! draft.trim() }, 'Load account' )
					)
				)
			);
		}
		return el( Fragment, null, inspector, el( 'div', blockProps, el( SSR, { block: 'cyberdeck/tezos-account', attributes: a } ) ) );
	}

	wp.blocks.registerBlockType( 'cyberdeck/tezos-account', {
		edit: Edit,
		save: function () {
			return null;
		},
	} );
} )( window.wp );
