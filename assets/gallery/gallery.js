/**
 * Cyberdeck gallery blocks (Teia, Mederu, ...): shared front-end behavior (no dependencies).
 *
 * Design rule: nothing animated or heavy starts by itself.
 *  - Server markup contains still posters only.
 *  - A real <video>/<audio>/<model-viewer>/<iframe> is created only after a click.
 *  - At most `data-max-active` players exist at once, across the whole page; starting a
 *    new one tears down the oldest (back to its poster).
 *  - Inline video/3D/interactive players are torn down when scrolled out of view.
 *  - The 3D viewer library (~1 MB) is fetched the first time someone opens a GLB.
 */
( function () {
	'use strict';

	var reduceMotion = window.matchMedia ? window.matchMedia( '(prefers-reduced-motion: reduce)' ) : { matches: false };
	var active = [];
	var stageHandles = new WeakMap();
	var viewerPromise = null;
	var lightbox = null;
	var collectPromise = null;
	var offscreenObserver = null;

	if ( 'IntersectionObserver' in window ) {
		offscreenObserver = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( ! entry.isIntersecting ) {
					var handle = stageHandles.get( entry.target );
					if ( handle && ! handle.keepOffscreen ) {
						handle.destroy();
					}
				}
			} );
		} );
	}

	/* ---------- helpers ---------- */

	function make( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	function readItem( item, root ) {
		var d = item.dataset;
		return {
			kind: d.kind,
			src: d.src,
			mime: d.mime,
			poster: d.poster,
			title: d.title || '',
			page: d.page,
			label: root.dataset.providerLabel || 'source',
			token: d.token,
			fa2: d.fa2,
			creator: root.dataset.creator,
			viewer: root.dataset.viewer,
		};
	}

	function loadViewer( url ) {
		if ( window.customElements && window.customElements.get( 'model-viewer' ) ) {
			return Promise.resolve();
		}
		if ( ! url ) {
			return Promise.reject( new Error( 'No 3D viewer available' ) );
		}
		if ( ! viewerPromise ) {
			viewerPromise = new Promise( function ( resolve, reject ) {
				var script = document.createElement( 'script' );
				script.type = 'module';
				script.src = url;
				script.onload = function () {
					resolve();
				};
				script.onerror = function () {
					viewerPromise = null;
					reject( new Error( '3D viewer failed to load' ) );
				};
				document.head.appendChild( script );
			} );
		}
		return viewerPromise;
	}

	function interactiveUrl( data ) {
		try {
			var url = new URL( data.src );
			// Directory-style (zip) artifacts need a trailing slash before the query string.
			if ( ! /\.[a-z0-9]{2,5}$/i.test( url.pathname ) && url.pathname.slice( -1 ) !== '/' ) {
				url.pathname += '/';
			}
			if ( data.creator ) {
				url.searchParams.set( 'creator', data.creator );
			}
			if ( data.token ) {
				url.searchParams.set( 'objkt', data.token );
			}
			return url.toString();
		} catch ( e ) {
			return data.src;
		}
	}

	/* ---------- players ---------- */

	/**
	 * Build a player. Resolves { el, destroy, start? }. Nothing plays until the user does
	 * (or `start()` is called straight after a click on a poster's play button).
	 */
	function createPlayer( data ) {
		var wrap = make( 'div', 'gx-player is-' + data.kind );
		var media;

		switch ( data.kind ) {
			case 'video':
				media = document.createElement( 'video' );
				media.controls = true;
				media.playsInline = true;
				media.preload = 'metadata';
				if ( data.poster ) {
					media.poster = data.poster;
				}
				media.src = data.src;
				wrap.appendChild( media );
				return Promise.resolve( {
					el: wrap,
					start: function () {
						var p = media.play();
						if ( p && p.catch ) {
							p.catch( function () {} );
						}
					},
					destroy: function () {
						media.pause();
						media.removeAttribute( 'src' );
						media.load();
					},
				} );

			case 'audio':
				if ( data.poster ) {
					var cover = make( 'img', 'gx-cover' );
					cover.src = data.poster;
					cover.alt = '';
					wrap.appendChild( cover );
				}
				media = document.createElement( 'audio' );
				media.controls = true;
				media.preload = 'metadata';
				media.src = data.src;
				wrap.appendChild( media );
				return Promise.resolve( {
					el: wrap,
					start: function () {
						var p = media.play();
						if ( p && p.catch ) {
							p.catch( function () {} );
						}
					},
					destroy: function () {
						media.pause();
						media.removeAttribute( 'src' );
						media.load();
					},
				} );

			case 'interactive':
				media = document.createElement( 'iframe' );
				media.title = data.title;
				media.setAttribute( 'sandbox', 'allow-scripts allow-same-origin allow-pointer-lock allow-modals' );
				media.referrerPolicy = 'no-referrer';
				media.allowFullscreen = true;
				media.src = interactiveUrl( data );
				wrap.appendChild( media );
				return Promise.resolve( {
					el: wrap,
					destroy: function () {
						media.src = 'about:blank';
					},
				} );

			case 'model':
				return loadViewer( data.viewer ).then( function () {
					var mv = document.createElement( 'model-viewer' );
					mv.setAttribute( 'src', data.src );
					mv.setAttribute( 'alt', data.title );
					mv.setAttribute( 'camera-controls', '' );
					mv.setAttribute( 'touch-action', 'pan-y' );
					mv.setAttribute( 'shadow-intensity', '1' );
					mv.setAttribute( 'interaction-prompt', 'none' );
					// No "autoplay" attribute: embedded animations stay paused until the visitor asks.
					if ( data.poster ) {
						mv.setAttribute( 'poster', data.poster );
						mv.setAttribute( 'reveal', 'auto' );
					}
					wrap.appendChild( mv );

					mv.addEventListener( 'load', function () {
						if ( mv.availableAnimations && mv.availableAnimations.length ) {
							var toggle = make( 'button', 'gx-anim', 'Play animation' );
							toggle.type = 'button';
							toggle.addEventListener( 'click', function () {
								if ( mv.paused ) {
									mv.play();
									toggle.textContent = 'Pause animation';
								} else {
									mv.pause();
									toggle.textContent = 'Play animation';
								}
							} );
							wrap.appendChild( toggle );
						}
					} );

					return {
						el: wrap,
						destroy: function () {
							mv.removeAttribute( 'src' );
						},
					};
				} );

			default:
				// image, gif, anything with a displayable artifact
				media = document.createElement( 'img' );
				media.src = data.src;
				media.alt = data.title;
				media.decoding = 'async';
				media.referrerPolicy = 'no-referrer';
				wrap.appendChild( media );
				return Promise.resolve( {
					el: wrap,
					destroy: function () {
						media.removeAttribute( 'src' );
					},
				} );
		}
	}

	/* ---------- active-player registry ---------- */

	function refreshPlaying( root ) {
		if ( ! root ) {
			return;
		}
		root.classList.toggle(
			'is-playing',
			active.some( function ( h ) {
				return h.root === root;
			} )
		);
	}

	function register( handle ) {
		if ( handle.lightbox ) {
			// A lightbox is a deliberate, exclusive view: free every inline player first.
			active.slice().forEach( function ( h ) {
				if ( h !== handle ) {
					h.destroy();
				}
			} );
		}
		active.push( handle );

		var max = handle.root ? parseInt( handle.root.dataset.maxActive, 10 ) || 1 : 1;
		var inline = active.filter( function ( h ) {
			return ! h.lightbox;
		} );
		while ( ! handle.lightbox && inline.length > max ) {
			inline.shift().destroy();
		}
		refreshPlaying( handle.root );
	}

	function unregister( handle ) {
		var index = active.indexOf( handle );
		if ( index > -1 ) {
			active.splice( index, 1 );
		}
		refreshPlaying( handle.root );
	}

	/**
	 * Swap a stage's poster for a live player. destroy() puts the poster back.
	 */
	function mount( stage, data, options ) {
		var original = Array.prototype.slice.call( stage.childNodes );
		var handle = {
			stage: stage,
			root: options.root,
			lightbox: !! options.lightbox,
			keepOffscreen: !! options.lightbox || data.kind === 'audio',
			player: null,
			destroyed: false,
			destroy: function () {
				if ( handle.destroyed ) {
					return;
				}
				handle.destroyed = true;
				if ( handle.player ) {
					handle.player.destroy();
				}
				if ( offscreenObserver ) {
					offscreenObserver.unobserve( stage );
				}
				stageHandles.delete( stage );
				unregister( handle );
				stage.replaceChildren.apply( stage, original );
				stage.classList.remove( 'is-active', 'is-loading' );
				stage.style.minHeight = '';
			},
		};

		if ( ! handle.lightbox ) {
			// Keep the grid from jumping while the poster is swapped out.
			stage.style.minHeight = stage.getBoundingClientRect().height + 'px';
		}
		stage.classList.add( 'is-loading' );
		stageHandles.set( stage, handle );
		register( handle );

		createPlayer( data )
			.then( function ( player ) {
				if ( handle.destroyed ) {
					player.destroy();
					return;
				}
				handle.player = player;

				if ( ! handle.lightbox ) {
					var stop = make( 'button', 'gx-stop', '\u00d7' );
					stop.type = 'button';
					stop.setAttribute( 'aria-label', 'Close player and show the still image again' );
					player.el.appendChild( stop );
				}

				stage.classList.remove( 'is-loading' );
				stage.classList.add( 'is-active' );
				stage.replaceChildren( player.el );

				if ( options.play && player.start ) {
					player.start();
				}
				if ( offscreenObserver && ! handle.keepOffscreen ) {
					offscreenObserver.observe( stage );
				}
			} )
			.catch( function () {
				handle.destroy();
				var message = make( 'span', 'gx-error', 'Could not load this piece here. Try its page on the source site.' );
				stage.appendChild( message );
				window.setTimeout( function () {
					message.remove();
				}, 4000 );
			} );

		return handle;
	}

	/* ---------- lightbox ---------- */

	function getLightbox() {
		if ( lightbox ) {
			return lightbox;
		}
		var dialog = document.createElement( 'dialog' );
		dialog.className = 'gx-lightbox';
		dialog.setAttribute( 'aria-label', 'Artwork viewer' );
		dialog.innerHTML =
			'<div class="gx-lb-bar"><span class="gx-lb-title"></span>' +
			'<a class="gx-lb-link" target="_blank" rel="noopener noreferrer">View</a>' +
			'<button type="button" class="gx-lb-close" aria-label="Close">\u00d7</button></div>' +
			'<div class="gx-stage gx-lb-stage"></div>' +
			'<div class="gx-lb-collect"></div>';
		document.body.appendChild( dialog );

		lightbox = {
			dialog: dialog,
			stage: dialog.querySelector( '.gx-lb-stage' ),
			title: dialog.querySelector( '.gx-lb-title' ),
			link: dialog.querySelector( '.gx-lb-link' ),
			collect: dialog.querySelector( '.gx-lb-collect' ),
			handle: null,
		};

		dialog.querySelector( '.gx-lb-close' ).addEventListener( 'click', function () {
			dialog.close();
		} );
		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog ) {
				dialog.close();
			}
		} );
		dialog.addEventListener( 'close', function () {
			if ( lightbox.handle ) {
				lightbox.handle.destroy();
				lightbox.handle = null;
			}
			lightbox.stage.replaceChildren();
			lightbox.collect.replaceChildren();
			dialog.classList.remove( 'has-collect' );
		} );
		return lightbox;
	}

	/** The collect panel (and, later, the wallet) is only fetched when a block allows collecting. */
	function loadCollect( url ) {
		if ( window.CyberdeckCollect ) {
			return Promise.resolve();
		}
		if ( ! collectPromise ) {
			collectPromise = new Promise( function ( resolve, reject ) {
				var script = document.createElement( 'script' );
				script.src = url;
				script.onload = resolve;
				script.onerror = function () {
					collectPromise = null;
					reject( new Error( 'collect script failed' ) );
				};
				document.head.appendChild( script );
			} );
		}
		return collectPromise;
	}

	function openLightbox( data, root ) {
		var lb = getLightbox();
		if ( typeof lb.dialog.showModal !== 'function' ) {
			window.open( data.page, '_blank', 'noopener' );
			return;
		}
		lb.title.textContent = data.title;
		lb.link.href = data.page;
		lb.link.textContent = 'View on ' + data.label;
		lb.stage.replaceChildren();
		if ( ! lb.dialog.open ) {
			lb.dialog.showModal();
		}
		// Opened by the visitor, but still no autoplay: video/audio start paused.
		lb.handle = mount( lb.stage, data, { root: root, lightbox: true, play: false } );

		lb.collect.replaceChildren();
		lb.dialog.classList.remove( 'has-collect' );
		if ( root.dataset.collect === '1' && data.fa2 && data.token && root.dataset.collectJs ) {
			lb.dialog.classList.add( 'has-collect' );
			var wanted = data.token;
			loadCollect( root.dataset.collectJs )
				.then( function () {
					if ( lb.dialog.open && lb.title.textContent === data.title && wanted === data.token ) {
						window.CyberdeckCollect.mount( lb.collect, {
							provider: root.dataset.provider,
							fa2: data.fa2,
							token: data.token,
							title: data.title,
							page: data.page,
							listingUrl: root.dataset.listingUrl,
							rpc: root.dataset.rpc,
							walletUrl: root.dataset.walletJs,
							siteName: root.dataset.siteName,
							capMutez: '0',
						} );
					}
				} )
				.catch( function () {} );
		}
	}

	/* ---------- per-block setup ---------- */

	/**
	 * Vertical strips need an explicit height: exactly the first N items (plus gaps), re-measured as images load.
	 *
	 * @param {Element} root   Block root (data-visible = N).
	 * @param {Element} holder Element to give the height to.
	 * @param {Element} track  The .gx-items element.
	 */
	function fitVertical( root, holder, track ) {
		var count = parseInt( root.dataset.visible, 10 ) || 3;
		var items = Array.prototype.filter.call( track.children, function ( child ) {
			return child.classList.contains( 'gx-item' ) && ! child.hasAttribute( 'aria-hidden' );
		} );
		if ( ! items.length ) {
			return;
		}
		var n = Math.min( count, items.length );
		var gap = parseFloat( window.getComputedStyle( track ).rowGap ) || 0;
		var height = gap * ( n - 1 );
		for ( var i = 0; i < n; i++ ) {
			height += items[ i ].offsetHeight;
		}
		if ( height > 0 ) {
			holder.style.height = Math.round( height ) + 'px';
		}
	}

	function watchVertical( root, holder, track ) {
		var refit = function () {
			fitVertical( root, holder, track );
		};
		refit();
		track.querySelectorAll( 'img' ).forEach( function ( img ) {
			if ( ! img.complete ) {
				img.addEventListener( 'load', refit, { once: true } );
			}
		} );
		window.addEventListener( 'resize', refit );
		if ( 'ResizeObserver' in window ) {
			new ResizeObserver( refit ).observe( root );
		}
	}

	function initCarousel( root ) {
		var nav = root.querySelector( '.gx-nav' );
		var track = root.querySelector( '.gx-items' );
		if ( ! nav || ! track ) {
			return;
		}
		var vertical = root.classList.contains( 'is-dir-vertical' );
		if ( vertical ) {
			watchVertical( root, track, track );
		}
		nav.hidden = false;
		nav.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( 'button' );
			if ( ! button ) {
				return;
			}
			var direction = button.classList.contains( 'gx-next' ) ? 1 : -1;
			var behavior = reduceMotion.matches ? 'auto' : 'smooth';
			if ( vertical ) {
				track.scrollBy( { top: direction * track.clientHeight * 0.9, behavior: behavior } );
			} else {
				track.scrollBy( { left: direction * track.clientWidth * 0.9, behavior: behavior } );
			}
		} );
	}

	function initMarquee( root ) {
		var track = root.querySelector( '.gx-items' );
		var viewport = root.querySelector( '.gx-viewport' );
		var vertical = root.classList.contains( 'is-dir-vertical' );
		if ( ! track || track.children.length < 2 || reduceMotion.matches ) {
			if ( track && vertical ) {
				watchVertical( root, viewport, track ); // still a tidy, scrollable column
			}
			return; // stays a normal, scrollable strip
		}

		// Measure the visible window before duplicating the items.
		if ( vertical ) {
			watchVertical( root, viewport, track );
		}

		Array.prototype.slice.call( track.children ).forEach( function ( child ) {
			var clone = child.cloneNode( true );
			clone.setAttribute( 'aria-hidden', 'true' );
			clone.querySelectorAll( 'a, button' ).forEach( function ( focusable ) {
				focusable.tabIndex = -1;
			} );
			track.appendChild( clone );
		} );

		track.removeAttribute( 'tabindex' );
		track.removeAttribute( 'role' );
		root.classList.add( 'is-marquee-ready' );
		// left/up = default motion; right/down = reversed.
		root.classList.toggle( 'is-reverse', root.dataset.direction === 'right' || root.dataset.direction === 'down' );

		var speed = parseInt( root.dataset.speed, 10 ) || 40;
		var setDuration = function () {
			var distance = ( vertical ? track.scrollHeight : track.scrollWidth ) / 2;
			if ( distance > 0 ) {
				track.style.setProperty( '--gx-duration', Math.max( 8, distance / speed ) + 's' );
			}
		};
		setDuration();
		if ( 'ResizeObserver' in window ) {
			new ResizeObserver( setDuration ).observe( track );
		}
		if ( 'IntersectionObserver' in window ) {
			new IntersectionObserver( function ( entries ) {
				root.classList.toggle( 'is-offscreen', ! entries[ 0 ].isIntersecting );
			} ).observe( root );
		}
	}

	/**
	 * Spotlight: one large still image that rotates through the works, with the works themselves as a
	 * thumbnail strip. Only images crossfade here; real players still load on click, as everywhere else.
	 */
	function initSpotlight( root ) {
		var track = root.querySelector( '.gx-items' );
		var items = track ? Array.prototype.slice.call( track.querySelectorAll( '.gx-item' ) ) : [];
		if ( ! items.length ) {
			return;
		}

		var main = make( 'div', 'gx-spot' );
		var layers = [ make( 'img', 'gx-spot__img' ), make( 'img', 'gx-spot__img' ) ];
		layers.forEach( function ( img ) {
			img.alt = '';
			img.decoding = 'async';
			img.referrerPolicy = 'no-referrer';
		} );
		var caption = make( 'p', 'gx-spot__caption' );
		var badge = make( 'span', 'gx-badge gx-spot__badge' );
		main.appendChild( layers[ 0 ] );
		main.appendChild( layers[ 1 ] );
		main.appendChild( badge );
		main.appendChild( caption );
		main.setAttribute( 'role', 'button' );
		main.tabIndex = 0;
		root.insertBefore( main, root.querySelector( '.gx-viewport' ) );
		root.classList.add( 'is-spotlight-ready' );

		var index = -1;
		var front = 0;
		var timer = 0;
		var hovering = false;
		var visible = true;
		var kindLabels = { gif: 'GIF', video: 'Video', audio: 'Audio', model: '3D', interactive: 'Interactive', other: 'File' };

		function show( target, scrollThumb ) {
			target = ( target + items.length ) % items.length;
			if ( target === index ) {
				return;
			}
			index = target;
			var item = items[ index ];
			var poster = item.dataset.poster || ( item.dataset.kind === 'image' ? item.dataset.src : '' );
			var next = layers[ 1 - front ];
			var current = layers[ front ];
			if ( poster ) {
				next.onload = function () {
					next.classList.add( 'is-on' );
					current.classList.remove( 'is-on' );
					front = 1 - front;
				};
				next.src = poster;
			} else {
				current.classList.remove( 'is-on' );
				next.classList.remove( 'is-on' );
			}
			items.forEach( function ( other, i ) {
				other.classList.toggle( 'is-current', i === index );
			} );
			caption.textContent = item.dataset.title || '';
			badge.textContent = kindLabels[ item.dataset.kind ] || '';
			badge.hidden = ! kindLabels[ item.dataset.kind ];
			main.setAttribute( 'aria-label', 'Open \u201c' + ( item.dataset.title || 'artwork' ) + '\u201d' );
			if ( scrollThumb && track.scrollTo ) {
				track.scrollTo( { left: item.offsetLeft - track.clientWidth / 2 + item.offsetWidth / 2, behavior: reduceMotion.matches ? 'auto' : 'smooth' } );
			}
		}

		function stopTimer() {
			if ( timer ) {
				window.clearInterval( timer );
				timer = 0;
			}
		}
		function startTimer() {
			stopTimer();
			if ( items.length < 2 || reduceMotion.matches || hovering || ! visible || document.hidden ) {
				return;
			}
			var seconds = parseInt( root.dataset.interval, 10 ) || 5;
			timer = window.setInterval( function () {
				if ( ! document.querySelector( '.gx-lightbox[open]' ) ) {
					show( index + 1, true );
				}
			}, seconds * 1000 );
		}

		root.__spotSelect = function ( item ) {
			show( items.indexOf( item ), true );
			startTimer();
		};
		var open = function () {
			var link = items[ index ].querySelector( '.gx-open' );
			if ( link ) {
				root.__forceOpen = true;
				link.click();
				root.__forceOpen = false;
			}
		};
		main.addEventListener( 'click', open );
		main.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' || event.key === ' ' ) {
				event.preventDefault();
				open();
			}
		} );
		root.addEventListener( 'pointerenter', function () {
			hovering = true;
			stopTimer();
		} );
		root.addEventListener( 'pointerleave', function () {
			hovering = false;
			startTimer();
		} );
		root.addEventListener( 'focusin', function () {
			hovering = true;
			stopTimer();
		} );
		root.addEventListener( 'focusout', function () {
			hovering = false;
			startTimer();
		} );
		document.addEventListener( 'visibilitychange', startTimer );
		if ( 'IntersectionObserver' in window ) {
			new IntersectionObserver( function ( entries ) {
				visible = entries[ entries.length - 1 ].isIntersecting;
				startTimer();
			} ).observe( root );
		}

		show( 0, false );
		startTimer();
	}

	/** Pointer tilt + glare on still thumbnails (fine pointers only; never when motion is reduced). */
	function initTilt( root ) {
		if ( reduceMotion.matches || ! window.matchMedia || ! window.matchMedia( '(hover: hover) and (pointer: fine)' ).matches ) {
			return;
		}
		var reset = function ( stage ) {
			stage.style.removeProperty( '--gx-rx' );
			stage.style.removeProperty( '--gx-ry' );
		};
		root.addEventListener( 'pointermove', function ( event ) {
			var stage = event.target.closest && event.target.closest( '.gx-stage' );
			if ( ! stage || stage.classList.contains( 'is-active' ) || stage.classList.contains( 'gx-lb-stage' ) ) {
				return;
			}
			var rect = stage.getBoundingClientRect();
			var x = ( event.clientX - rect.left ) / rect.width - 0.5;
			var y = ( event.clientY - rect.top ) / rect.height - 0.5;
			stage.style.setProperty( '--gx-rx', ( -y * 9 ).toFixed( 2 ) + 'deg' );
			stage.style.setProperty( '--gx-ry', ( x * 11 ).toFixed( 2 ) + 'deg' );
			stage.style.setProperty( '--gx-gx', ( ( x + 0.5 ) * 100 ).toFixed( 1 ) + '%' );
			stage.style.setProperty( '--gx-gy', ( ( y + 0.5 ) * 100 ).toFixed( 1 ) + '%' );
		} );
		root.addEventListener( 'pointerout', function ( event ) {
			var stage = event.target.closest && event.target.closest( '.gx-stage' );
			if ( stage && ! stage.contains( event.relatedTarget ) ) {
				reset( stage );
			}
		} );
	}

	function init( root ) {
		if ( root.dataset.pageReady ) {
			return;
		}
		root.dataset.pageReady = '1';

		root.addEventListener( 'click', function ( event ) {
			var stop = event.target.closest( '.gx-stop' );
			if ( stop ) {
				var handle = stageHandles.get( stop.closest( '.gx-stage' ) );
				if ( handle ) {
					handle.destroy();
				}
				return;
			}

			var link = event.target.closest( '.gx-open' );
			if ( ! link || ! root.contains( link ) ) {
				return;
			}
			var mode = root.dataset.interaction;
			if ( mode === 'page' || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey ) {
				return; // normal link behaviour
			}
			var item = link.closest( '.gx-item' );
			if ( ! item ) {
				return;
			}
			event.preventDefault();

			if ( root.classList.contains( 'is-spotlight-ready' ) && ! root.__forceOpen ) {
				root.__spotSelect( item );
				return;
			}

			var data = readItem( item, root );
			if ( ! data.src ) {
				window.open( data.page, '_blank', 'noopener' );
				return;
			}
			if ( mode === 'lightbox' || data.kind === 'image' ) {
				openLightbox( data, root );
				return;
			}
			var stage = item.querySelector( '.gx-stage' );
			if ( stage.classList.contains( 'is-active' ) || stage.classList.contains( 'is-loading' ) ) {
				return;
			}
			// Clicking a poster's play button is an explicit "play".
			mount( stage, data, { root: root, play: true } );
		} );

		if ( root.dataset.layout === 'carousel' ) {
			initCarousel( root );
		}
		if ( root.dataset.layout === 'marquee' ) {
			initMarquee( root );
		}
		if ( root.dataset.layout === 'spotlight' ) {
			initSpotlight( root );
		}
		if ( root.dataset.tilt === '1' ) {
			initTilt( root );
		}
	}

	function boot() {
		document.querySelectorAll( '.cd-gallery' ).forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
