/**
 * Cyberdeck gallery blocks: shared editor UI (no build step; uses wp.* globals).
 * Each block calls cyberdeckGallery.register( config ) from its own index.js.
 * The canvas preview is rendered by PHP (ServerSideRender), so what you see is what visitors get,
 * minus the click-to-play interactions.
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var Fragment = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var c = wp.components;
	var ServerSideRender = wp.serverSideRender;
	var apiFetch = wp.apiFetch;

	var KINDS = [
		[ 'image', 'Images' ],
		[ 'gif', 'Animated GIFs' ],
		[ 'video', 'Video' ],
		[ 'audio', 'Audio' ],
		[ 'model', '3D models (GLB / glTF)' ],
		[ 'interactive', 'Interactive / generative (HTML, zip)' ],
		[ 'other', 'Other (PDF, text, unknown)' ],
	];

	function options( pairs ) {
		return pairs.map( function ( p ) {
			return { value: p[ 0 ], label: p[ 1 ] };
		} );
	}

	var LAYOUTS = options( [
		[ 'grid', 'Grid' ],
		[ 'masonry', 'Masonry' ],
		[ 'mosaic', 'Mosaic wall (mixed tile sizes)' ],
		[ 'marquee', 'Marquee (scrolling strip)' ],
		[ 'carousel', 'Carousel' ],
		[ 'spotlight', 'Spotlight (rotating feature + thumbnails)' ],
		[ 'list', 'List with descriptions' ],
		[ 'feed', 'Feed (single column)' ],
		[ 'single', 'Single piece' ],
	] );

	function makeEdit( cfg ) {
	return function Edit( props ) {
		var attributes = props.attributes;
		var set = props.setAttributes;
		var layout = attributes.layout;

		var draftState = useState( attributes.address );
		var draft = draftState[ 0 ];
		var setDraft = draftState[ 1 ];
		var checkState = useState( null );
		var check = checkState[ 0 ];
		var setCheck = checkState[ 1 ];
		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];

		var commit = function () {
			if ( draft !== attributes.address ) {
				set( { address: draft.trim() } );
			}
		};

		var runCheck = function () {
			setBusy( true );
			setCheck( null );
			apiFetch( { path: '/cyberdeck/v1/gallery-check?provider=' + encodeURIComponent( cfg.provider ) + '&address=' + encodeURIComponent( draft.trim() ) } )
				.then( function ( report ) {
					setCheck( report );
					setBusy( false );
				} )
				.catch( function ( error ) {
					setCheck( { ok: false, message: error && error.message ? error.message : 'Request failed.' } );
					setBusy( false );
				} );
		};

		var toggleType = function ( slug, on ) {
			var next = attributes.types.filter( function ( t ) {
				return t !== slug;
			} );
			if ( on ) {
				next.push( slug );
			}
			set( { types: next } );
		};

		var addressField = function ( extra ) {
			return el( cfg.multi ? c.TextareaControl : c.TextControl, {
				label: cfg.multi ? 'Artists or collectors' : 'Artist or collector',
				help: cfg.addressHelp,
				value: draft,
				placeholder: cfg.placeholder,
				onChange: setDraft,
				onBlur: commit,
				onKeyDown: function ( event ) {
					if ( event.key === 'Enter' ) {
						commit();
					}
				},
				__nextHasNoMarginBottom: true,
				...( extra || {} ),
			} );
		};

		var checkReport = check
			? el(
					'div',
					{ className: 'cd-gallery-check' + ( check.ok ? '' : ' is-error' ) },
					el( 'strong', null, check.ok ? 'OK. ' : 'Problem. ' ),
					check.message,
					check.works_found !== null && check.works_found !== undefined
						? el( 'div', null, 'Works found in test query: ' + check.works_found )
						: null,
					check.fields_missing && check.fields_missing.length
						? el( 'div', null, 'Indexer lacks: ', el( 'code', null, check.fields_missing.join( ', ' ) ) )
						: null,
					check.native && check.native.contracts && check.native.contracts.length
						? el( 'div', null, 'Native contracts (' + check.native.source + '): ', el( 'code', null, check.native.contracts.join( ', ' ) ) )
						: null,
					check.endpoint ? el( 'div', null, 'Endpoint: ', el( 'code', null, check.endpoint ) ) : null
			  )
			: null;

		var inspector = el(
			InspectorControls,
			null,
			el(
				c.PanelBody,
				{ title: 'Source', initialOpen: true },
				addressField(),
				el( c.SelectControl, {
					label: 'Show',
					value: attributes.source,
					options: options( cfg.sources ),
					onChange: function ( v ) {
						set( { source: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				layout !== 'single'
					? el( c.RangeControl, {
							label: 'Number of works',
							value: attributes.limit,
							min: 1,
							max: 60,
							onChange: function ( v ) {
								set( { limit: v } );
							},
							__nextHasNoMarginBottom: true,
					  } )
					: el( c.TextControl, {
							label: 'Token ID (optional)',
							help: 'Leave empty to use the newest work.',
							value: attributes.tokenId,
							onChange: function ( v ) {
								set( { tokenId: v.replace( /\D/g, '' ) } );
							},
							__nextHasNoMarginBottom: true,
					  } ),
				el( c.SelectControl, {
					label: 'Order',
					value: attributes.order,
					options: options( [
						[ 'newest', 'Newest first' ],
						[ 'oldest', 'Oldest first' ],
						[ 'random', 'Random (each time the cache refreshes)' ],
					] ),
					onChange: function ( v ) {
						set( { order: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( 'div', { style: { marginTop: 16 } }, el( c.BaseControl.VisualLabel, null, 'Media types' ) ),
				KINDS.map( function ( k ) {
					return el( c.CheckboxControl, {
						key: k[ 0 ],
						label: k[ 1 ],
						checked: attributes.types.indexOf( k[ 0 ] ) > -1,
						onChange: function ( on ) {
							toggleType( k[ 0 ], on );
						},
						__nextHasNoMarginBottom: true,
					} );
				} ),
				el( c.SelectControl, {
					label: 'Which works',
					value: attributes.scope,
					options: options( [
						[ 'native', cfg.nativeLabel ],
						[ 'any', 'Any contract (everything on Tezos)' ],
					] ),
					help: cfg.nativeHelp,
					onChange: function ( v ) {
						set( { scope: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( c.TextareaControl, {
					label: attributes.source === 'collection' ? 'Collection contract(s)' : 'Only these contracts (optional)',
					help: 'One KT1… address per line (up to 10). Overrides the choice above.',
					value: attributes.contracts,
					rows: 2,
					placeholder: 'KT1…',
					onChange: function ( v ) {
						set( { contracts: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( c.TextareaControl, {
					label: 'Specific works (optional)',
					help: 'One per line, shown in this order, replacing the account lookup: ' + cfg.worksHelp,
					value: attributes.works,
					rows: 3,
					placeholder: cfg.worksPlaceholder,
					onChange: function ( v ) {
						set( { works: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el(
					c.Button,
					{ variant: 'secondary', onClick: runCheck, isBusy: busy, disabled: busy },
					'Check ' + cfg.pageLabel + ' connection'
				),
				checkReport
			),
			el(
				c.PanelBody,
				{ title: 'Layout', initialOpen: true },
				el( c.SelectControl, {
					label: 'Display as',
					value: layout,
					options: LAYOUTS,
					onChange: function ( v ) {
						set( { layout: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				[ 'grid', 'masonry', 'mosaic', 'carousel' ].indexOf( layout ) > -1
					? el( c.RangeControl, {
							label: 'Columns',
							value: attributes.columns,
							min: 1,
							max: 10,
							onChange: function ( v ) {
								set( { columns: v } );
							},
							__nextHasNoMarginBottom: true,
					  } )
					: null,
				layout === 'spotlight'
					? el( c.RangeControl, {
							label: 'Seconds per piece',
							value: attributes.interval,
							min: 2,
							max: 20,
							onChange: function ( v ) {
								set( { interval: v } );
							},
							help: 'Rotates through still images only; pauses on hover, off-screen, and for reduced motion.',
							__nextHasNoMarginBottom: true,
					  } )
					: null,
				el( c.ToggleControl, {
					label: 'Tilt toward the pointer on hover',
					checked: attributes.tilt,
					onChange: function ( v ) {
						set( { tilt: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				layout === 'marquee'
					? el(
							Fragment,
							null,
							el( c.RangeControl, {
								label: 'Item height (px)',
								value: attributes.itemSize,
								min: 80,
								max: 600,
								onChange: function ( v ) {
									set( { itemSize: v } );
								},
								__nextHasNoMarginBottom: true,
							} ),
							el( c.RangeControl, {
								label: 'Speed (px per second)',
								value: attributes.speed,
								min: 5,
								max: 400,
								onChange: function ( v ) {
									set( { speed: v } );
								},
								__nextHasNoMarginBottom: true,
							} ),
							el( c.SelectControl, {
								label: 'Direction',
								value: attributes.direction,
								options: options( [
									[ 'left', 'Right to left' ],
									[ 'right', 'Left to right' ],
								] ),
								onChange: function ( v ) {
									set( { direction: v } );
								},
								__nextHasNoMarginBottom: true,
							} ),
							el( c.ToggleControl, {
								label: 'Pause on hover or focus',
								checked: attributes.pauseOnHover,
								onChange: function ( v ) {
									set( { pauseOnHover: v } );
								},
								__nextHasNoMarginBottom: true,
							} )
					  )
					: null,
				el( c.RangeControl, {
					label: 'Gap (px)',
					value: attributes.gap,
					min: 0,
					max: 80,
					onChange: function ( v ) {
						set( { gap: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( c.SelectControl, {
					label: 'Thumbnail shape',
					value: attributes.aspect,
					options: options( [
						[ 'auto', 'Original proportions' ],
						[ '1/1', 'Square' ],
						[ '4/3', '4:3' ],
						[ '3/2', '3:2' ],
						[ '16/9', '16:9' ],
						[ '3/4', '3:4 portrait' ],
						[ '2/3', '2:3 portrait' ],
					] ),
					onChange: function ( v ) {
						set( { aspect: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				attributes.aspect !== 'auto'
					? el( c.SelectControl, {
							label: 'Image fit',
							value: attributes.fit,
							options: options( [
								[ 'cover', 'Fill (crop)' ],
								[ 'contain', 'Fit (letterbox)' ],
							] ),
							onChange: function ( v ) {
								set( { fit: v } );
							},
							__nextHasNoMarginBottom: true,
					  } )
					: null
			),
			el(
				c.PanelBody,
				{ title: 'Profile and captions', initialOpen: false },
				el( c.ToggleControl, {
					label: 'Show profile header',
					checked: attributes.showProfile,
					onChange: function ( v ) {
						set( { showProfile: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				attributes.showProfile
					? el(
							Fragment,
							null,
							el( c.TextControl, {
								label: 'Display name (optional)',
								help: 'Overrides the .tez name or shortened address.',
								value: attributes.profileName,
								onChange: function ( v ) {
									set( { profileName: v } );
								},
								__nextHasNoMarginBottom: true,
							} ),
							el( c.TextareaControl, {
								label: 'Short bio (optional)',
								value: attributes.profileBio,
								onChange: function ( v ) {
									set( { profileBio: v } );
								},
								__nextHasNoMarginBottom: true,
							} )
					  )
					: null,
				el( c.ToggleControl, {
					label: 'Show titles',
					checked: attributes.showTitle,
					onChange: function ( v ) {
						set( { showTitle: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( c.ToggleControl, {
					label: 'Show editions and date',
					checked: attributes.showMeta,
					onChange: function ( v ) {
						set( { showMeta: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( c.ToggleControl, {
					label: 'Show type badges (Video, 3D, ...)',
					checked: attributes.showBadges,
					onChange: function ( v ) {
						set( { showBadges: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( c.ToggleControl, {
					label: 'Show link to the ' + cfg.pageLabel + ' page',
					checked: attributes.showLink,
					onChange: function ( v ) {
						set( { showLink: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				cfg.features && cfg.features.credits
					? el( c.ToggleControl, {
							label: 'Show material credits',
							help: 'Names of the artists whose Mederu materials were used.',
							checked: attributes.showCredits,
							onChange: function ( v ) {
								set( { showCredits: v } );
							},
							__nextHasNoMarginBottom: true,
					  } )
					: null,
				cfg.features && cfg.features.price
					? el( c.ToggleControl, {
							label: 'Show lowest price',
							help: 'The lowest current listing price, shown as "from X". Refreshed with the cache.',
							checked: attributes.showPrice,
							onChange: function ( v ) {
								set( { showPrice: v } );
							},
							__nextHasNoMarginBottom: true,
					  } )
					: null,
				cfg.features && cfg.features.drop
					? el(
							Fragment,
							null,
							el( c.TextControl, {
								label: 'Mederu free-mint or gacha link (optional)',
								help: 'A mederu.art/free-mint/\u2026 or gacha.mederu.art link. Shown as a button; these mints run on Mederu\u2019s own site (free mints are gas-sponsored, gacha is a random mint), so they link out.',
								value: attributes.dropUrl,
								onChange: function ( v ) {
									set( { dropUrl: v.trim() } );
								},
								__nextHasNoMarginBottom: true,
							} ),
							attributes.dropUrl
								? el( c.TextControl, {
										label: 'Button text (optional)',
										value: attributes.dropLabel,
										onChange: function ( v ) {
											set( { dropLabel: v } );
										},
										__nextHasNoMarginBottom: true,
								  } )
								: null
					  )
					: null,
				cfg.features && cfg.features.flagged
					? el( c.ToggleControl, {
							label: 'Include works flagged by the marketplace',
							help: 'Off by default. Banned works are never shown.',
							checked: attributes.includeFlagged,
							onChange: function ( v ) {
								set( { includeFlagged: v } );
							},
							__nextHasNoMarginBottom: true,
					  } )
					: null
			),
			el(
				c.PanelBody,
				{ title: 'Playback and performance', initialOpen: false },
				el( c.SelectControl, {
					label: 'When a visitor clicks a work',
					value: attributes.interaction,
					options: options( [
						[ 'lightbox', 'Open it in a viewer overlay' ],
						[ 'inline', 'Play it in place' ],
						[ 'page', 'Go to its ' + cfg.pageLabel + ' page' ],
					] ),
					onChange: function ( v ) {
						set( { interaction: v } );
					},
					help: 'Nothing ever autoplays. Video, audio, GIF, 3D and interactive works show a still image until clicked.',
					__nextHasNoMarginBottom: true,
				} ),
				cfg.features && cfg.features.collect
					? el( c.ToggleControl, {
							label: 'Let visitors collect with their wallet',
							help: cfg.collectHelp + ' Needs "Wallet purchases" switched on under Settings > Tezos blocks. Clicking a work then opens the viewer overlay, where the live price and a Collect button appear.',
							checked: attributes.allowCollect,
							onChange: function ( v ) {
								set( { allowCollect: v } );
							},
							__nextHasNoMarginBottom: true,
					  } )
					: null,
				el( c.RangeControl, {
					label: 'Players allowed at once (whole page)',
					value: attributes.maxActive,
					min: 1,
					max: 4,
					onChange: function ( v ) {
						set( { maxActive: v } );
					},
					help: 'Starting another one closes the oldest.',
					__nextHasNoMarginBottom: true,
				} ),
				el( c.SelectControl, {
					label: 'IPFS gateway',
					value: attributes.gateway,
					options: options( [
						[ 'teia', 'Teia cache (cache.teia.rocks)' ],
						[ 'ipfs', 'ipfs.io' ],
						[ 'dweb', 'dweb.link' ],
						[ 'custom', 'Custom (Settings > Tezos blocks)' ],
					] ),
					onChange: function ( v ) {
						set( { gateway: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( c.RangeControl, {
					label: 'Cache (minutes)',
					value: attributes.cacheMinutes,
					min: 1,
					max: 1440,
					onChange: function ( v ) {
						set( { cacheMinutes: v } );
					},
					help: 'How long the work list is reused before asking the data source again.',
					__nextHasNoMarginBottom: true,
				} )
			)
		);

		var blockProps = useBlockProps();

		if ( ! attributes.address && ! attributes.works.trim() && ! ( attributes.source === 'collection' && attributes.contracts.trim() ) ) {
			return el(
				Fragment,
				null,
				inspector,
				el(
					'div',
					blockProps,
					el(
						c.Placeholder,
						{ icon: 'format-gallery', label: cfg.label, className: 'cd-gallery-placeholder', instructions: cfg.instructions },
						addressField( { label: 'Artist or collector', help: undefined } ),
						el( c.Button, { variant: 'primary', onClick: commit, disabled: ! draft.trim() }, 'Load works' )
					)
				)
			);
		}

		return el(
			Fragment,
			null,
			inspector,
			el(
				'div',
				blockProps,
				el( ServerSideRender, {
					block: cfg.name,
					attributes: attributes,
					EmptyResponsePlaceholder: function () {
						return el( c.Placeholder, { icon: 'format-gallery', label: cfg.label }, 'Nothing to show for these settings.' );
					},
					LoadingResponsePlaceholder: function () {
						return el( c.Placeholder, { icon: 'format-gallery', label: cfg.label }, el( c.Spinner ) );
					},
				} )
			)
		);
	};
	}

	window.cyberdeckGallery = {
		register: function ( cfg ) {
			registerBlockType( cfg.name, {
				edit: makeEdit( cfg ),
				save: function () {
					return null; // dynamic block, rendered in PHP
				},
			} );
		},
	};
} )( window.wp );
