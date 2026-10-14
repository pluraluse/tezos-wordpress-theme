/**
 * Generative Canvas block: editor UI (no build step). The canvas inside the editor is driven by the
 * same engine as the front end (assets/generative/generative.js), so the preview is live.
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var be = wp.blockEditor;
	var c = wp.components;

	var BUILTIN = [
		[ 'flow', 'Flow field' ],
		[ 'constellation', 'Constellation' ],
		[ 'truchet', 'Truchet tiles' ],
		[ 'waves', 'Wave lines' ],
		[ 'glitch', 'Glitch grid' ],
	];
	var isBuiltin = function ( slug ) {
		return BUILTIN.some( function ( b ) {
			return b[ 0 ] === slug;
		} );
	};
	var options = function ( pairs ) {
		return pairs.map( function ( p ) {
			return { value: p[ 0 ], label: p[ 1 ] };
		} );
	};

	function Edit( props ) {
		var a = props.attributes;
		var set = props.setAttributes;
		var fixed = a.mode === 'fixed';
		var minted = a.source === 'minted';

		var blockProps = be.useBlockProps( {
			className: 'cd-gen is-mode-' + a.mode,
			style: { '--cd-gen-min': a.minHeight + 'vh', '--cd-gen-dim': a.dim / 100 },
			'data-sketch': a.sketch,
			'data-seed': a.seed,
			'data-palette': a.palette,
			'data-density': String( a.density ),
			'data-speed': String( a.speed ),
			'data-interactive': a.interactive ? '1' : '0',
			'data-background': a.background,
			'data-fps': String( a.fps ),
			'data-mode': a.mode,
			'data-source': minted ? 'minted' : undefined,
		} );

		var innerProps = be.useInnerBlocksProps(
			{ className: 'cd-gen__content' },
			{
				template: [
					[ 'core/heading', { level: 1, content: 'Your headline over live generative art' } ],
					[ 'core/paragraph', { content: 'Edit this text, or replace it with any blocks. Change the sketch, seed and palette in the sidebar.' } ],
					[ 'core/buttons', {}, [ [ 'core/button', { text: 'Enter' } ] ] ],
				],
			}
		);

		var sketchValue = isBuiltin( a.sketch ) ? a.sketch : '__custom';

		var inspector = el(
			be.InspectorControls,
			null,
			el(
				c.PanelBody,
				{ title: 'Source', initialOpen: true },
				el( c.SelectControl, {
					label: 'Show',
					value: a.source,
					options: options( [
						[ 'sketch', 'A built-in generative sketch' ],
						[ 'minted', 'One of my minted generative works, live' ],
					] ),
					onChange: function ( v ) {
						set( { source: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				minted
					? el(
							Fragment,
							null,
							el( c.SelectControl, {
								label: 'Platform',
								value: a.mintFrom,
								options: options( [
									[ 'teia', 'Teia' ],
									[ 'objkt', 'objkt' ],
									[ 'mederu', 'Mederu' ],
								] ),
								onChange: function ( v ) {
									set( { mintFrom: v } );
								},
								__nextHasNoMarginBottom: true,
							} ),
							el( c.TextControl, {
								label: 'A specific work (optional)',
								help: 'A Teia or objkt link, or KT1…:id. Leave empty to pick from the artist below.',
								value: a.mintWork,
								onChange: function ( v ) {
									set( { mintWork: v } );
								},
								__nextHasNoMarginBottom: true,
							} ),
							el( c.TextControl, {
								label: 'Artist address',
								help: 'tz1… or name.tez. Only the artist\u2019s generative (interactive) works are considered.',
								value: a.mintAddress,
								onChange: function ( v ) {
									set( { mintAddress: v.trim() } );
								},
								__nextHasNoMarginBottom: true,
							} ),
							el( c.SelectControl, {
								label: 'Which one',
								value: a.mintPick,
								options: options( [
									[ 'seeded', 'Chosen by the seed below (stable)' ],
									[ 'newest', 'The newest one' ],
								] ),
								onChange: function ( v ) {
									set( { mintPick: v } );
								},
								__nextHasNoMarginBottom: true,
							} ),
							el( c.SelectControl, {
								label: 'Works from',
								value: a.mintScope,
								options: options( [
									[ 'native', 'Only the platform\u2019s own contracts' ],
									[ 'any', 'Any contract' ],
								] ),
								onChange: function ( v ) {
									set( { mintScope: v } );
								},
								__nextHasNoMarginBottom: true,
							} ),
							el( c.TextControl, {
								label: 'Seed',
								value: a.seed,
								onChange: function ( v ) {
									set( { seed: v.slice( 0, 64 ) } );
								},
								__nextHasNoMarginBottom: true,
							} ),
							el( c.ToggleControl, {
								label: 'Run the work live',
								help: 'Off = a still image only. When on, the work runs only while on screen, never with reduced motion, and only one live work runs per page.',
								checked: a.mintLive,
								onChange: function ( v ) {
									set( { mintLive: v } );
								},
								__nextHasNoMarginBottom: true,
							} ),
							el( c.ToggleControl, {
								label: 'Let visitors interact with it',
								checked: a.mintInteractive,
								onChange: function ( v ) {
									set( { mintInteractive: v } );
								},
								__nextHasNoMarginBottom: true,
							} ),
							el( c.ToggleControl, {
								label: 'Show a credit link to the work',
								checked: a.showCredit,
								onChange: function ( v ) {
									set( { showCredit: v } );
								},
								__nextHasNoMarginBottom: true,
							} )
					  )
					: null
			),
			minted ? null : el(
				c.PanelBody,
				{ title: 'Artwork', initialOpen: true },
				el( c.SelectControl, {
					label: 'Sketch',
					value: sketchValue,
					options: options( BUILTIN.concat( [ [ '__custom', 'Custom (registered in code)' ] ] ) ),
					onChange: function ( v ) {
						set( { sketch: v === '__custom' ? ( isBuiltin( a.sketch ) ? 'my-sketch' : a.sketch ) : v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				sketchValue === '__custom'
					? el( c.TextControl, {
							label: 'Custom sketch name',
							help: 'Letters, numbers and dashes. Register it with cyberdeckGenerative.register( name, factory ).',
							value: a.sketch,
							onChange: function ( v ) {
								set( { sketch: v.toLowerCase().replace( /[^a-z0-9-]/g, '' ).slice( 0, 40 ) } );
							},
							__nextHasNoMarginBottom: true,
					  } )
					: null,
				el( c.TextControl, {
					label: 'Seed',
					help: 'The same seed always draws the same artwork. Change it to get a different one.',
					value: a.seed,
					onChange: function ( v ) {
						set( { seed: v.slice( 0, 64 ) } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el(
					c.Button,
					{
						variant: 'secondary',
						onClick: function () {
							set( { seed: Math.random().toString( 36 ).slice( 2, 8 ) } );
						},
					},
					'Randomize seed'
				),
				el( c.SelectControl, {
					label: 'Colors',
					value: a.palette,
					options: options( [
						[ 'theme', 'Theme palette (4 colors)' ],
						[ 'duo', 'Two neons' ],
						[ 'primary', 'Primary only' ],
						[ 'mono', 'Text color only' ],
					] ),
					onChange: function ( v ) {
						set( { palette: v });
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( c.RangeControl, {
					label: 'Density',
					value: a.density,
					min: 1,
					max: 10,
					onChange: function ( v ) {
						set( { density: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( c.RangeControl, {
					label: 'Speed (0 = still image)',
					value: a.speed,
					min: 0,
					max: 5,
					step: 0.25,
					onChange: function ( v ) {
						set( { speed: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( c.ToggleControl, {
					label: 'Reacts to the pointer',
					checked: a.interactive,
					onChange: function ( v ) {
						set( { interactive: v } );
					},
					__nextHasNoMarginBottom: true,
				} )
			),
			el(
				c.PanelBody,
				{ title: 'Placement', initialOpen: true },
				el( c.SelectControl, {
					label: 'Use as',
					value: a.mode,
					options: options( [
						[ 'section', 'Section: art behind content' ],
						[ 'fixed', 'Site backdrop: fixed behind the whole page' ],
					] ),
					help: fixed ? 'Put this block in the header template part to cover every page.' : undefined,
					onChange: function ( v ) {
						set( { mode: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				! fixed
					? el( c.RangeControl, {
							label: 'Minimum height (% of screen)',
							value: a.minHeight,
							min: 20,
							max: 100,
							onChange: function ( v ) {
								set( { minHeight: v } );
							},
							__nextHasNoMarginBottom: true,
					  } )
					: null,
				el( c.RangeControl, {
					label: 'Darken for readability (%)',
					value: a.dim,
					min: 0,
					max: 80,
					onChange: function ( v ) {
						set( { dim: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( c.SelectControl, {
					label: 'Background',
					value: a.background,
					options: options( [
						[ 'void', 'Solid theme background' ],
						[ 'transparent', 'Transparent (page shows through)' ],
					] ),
					onChange: function ( v ) {
						set( { background: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( c.SelectControl, {
					label: 'Frame rate',
					value: String( a.fps ),
					options: options( [
						[ '30', '30 fps (lighter)' ],
						[ '60', '60 fps (smoother)' ],
					] ),
					onChange: function ( v ) {
						set( { fps: parseInt( v, 10 ) } );
					},
					help: 'Pieces pause off-screen and use a still image when the visitor prefers reduced motion.',
					__nextHasNoMarginBottom: true,
				} )
			)
		);

		return el(
			Fragment,
			null,
			inspector,
			el(
				'div',
				blockProps,
				minted
					? el( 'div', { className: 'cd-gen__frame' } )
					: el( 'canvas', { className: 'cd-gen__canvas', 'aria-hidden': 'true' } ),
				minted
					? el( 'p', { className: 'cd-gen__label' }, 'Live minted work \u00b7 ' + a.mintFrom + ' \u00b7 ' + ( a.mintWork || a.mintAddress || 'choose a source' ) + ' (shown on the site, not in the editor)' )
					: null,
				fixed
					? el( 'p', { className: 'cd-gen__label' }, 'Site backdrop · ' + a.sketch + ' · seed ' + a.seed )
					: el( 'div', innerProps )
			)
		);
	}

	registerBlockType( 'cyberdeck/generative', {
		edit: Edit,
		save: function () {
			return el( be.InnerBlocks.Content );
		},
	} );
} )( window.wp );
