/**
 * Cyberdeck Generative Canvas: engine + built-in sketches (no dependencies).
 *
 * Every piece is deterministic for a given seed, so a seed is a "mint": the same seed always draws
 * the same artwork at the same size. Colors come from the theme's palette presets, so style variations
 * recolor the art too.
 *
 * Behaviour that keeps pages light:
 *  - runs only while on screen and while the tab is visible
 *  - 30 fps by default, device-pixel-ratio capped, and the canvas has a hard pixel budget
 *  - prefers-reduced-motion (or speed 0) renders one still frame instead of animating
 *
 * Extending: add your own sketch from any script that loads after this one:
 *
 *   cyberdeckGenerative.register( 'my-sketch', function ( env ) {
 *     // env: { ctx, canvas, w, h, rng, noise, palette, opts, pointer, helpers }
 *     return { draw: function ( t, dt ) { ... } };   // t = scaled seconds, dt = scaled frame time
 *     // optional: settle: N  = frames to pre-run for the still (reduced-motion) version; default 1
 *   } );
 *
 * then set the block's "Sketch" to "my-sketch" (Custom name). See README.
 */
( function () {
	'use strict';

	var reduceMotion = window.matchMedia ? window.matchMedia( '(prefers-reduced-motion: reduce)' ) : { matches: false };
	var registry = {};
	var instances = new WeakMap();
	var MAX_PIXELS = 2200000;

	/* ---------- seeded randomness ---------- */

	function xmur3( str ) {
		var h = 1779033703 ^ str.length;
		for ( var i = 0; i < str.length; i++ ) {
			h = Math.imul( h ^ str.charCodeAt( i ), 3432918353 );
			h = ( h << 13 ) | ( h >>> 19 );
		}
		h = Math.imul( h ^ ( h >>> 16 ), 2246822507 );
		h = Math.imul( h ^ ( h >>> 13 ), 3266489909 );
		return ( h ^ ( h >>> 16 ) ) >>> 0;
	}

	function makeRng( seed ) {
		var a = xmur3( String( seed ) );
		var rng = function () {
			a |= 0;
			a = ( a + 0x6d2b79f5 ) | 0;
			var t = Math.imul( a ^ ( a >>> 15 ), 1 | a );
			t = ( t + Math.imul( t ^ ( t >>> 7 ), 61 | t ) ) ^ t;
			return ( ( t ^ ( t >>> 14 ) ) >>> 0 ) / 4294967296;
		};
		rng.range = function ( min, max ) {
			return min + ( max - min ) * rng();
		};
		rng.int = function ( min, max ) {
			return Math.floor( rng.range( min, max + 1 ) );
		};
		rng.pick = function ( list ) {
			return list[ Math.floor( rng() * list.length ) ];
		};
		return rng;
	}

	/** Smooth 3D value noise in 0..1, seeded. */
	function makeNoise( rng ) {
		var perm = new Uint16Array( 512 );
		var base = [];
		var i;
		for ( i = 0; i < 256; i++ ) {
			base[ i ] = i;
		}
		for ( i = 255; i > 0; i-- ) {
			var j = Math.floor( rng() * ( i + 1 ) );
			var tmp = base[ i ];
			base[ i ] = base[ j ];
			base[ j ] = tmp;
		}
		for ( i = 0; i < 512; i++ ) {
			perm[ i ] = base[ i & 255 ];
		}
		var vals = new Float32Array( 256 );
		for ( i = 0; i < 256; i++ ) {
			vals[ i ] = rng();
		}
		var fade = function ( t ) {
			return t * t * ( 3 - 2 * t );
		};
		var lerp = function ( a, b, t ) {
			return a + ( b - a ) * t;
		};
		var at = function ( x, y, z ) {
			return vals[ perm[ perm[ perm[ x & 255 ] + ( y & 255 ) ] + ( z & 255 ) ] ];
		};
		return function ( x, y, z ) {
			var xi = Math.floor( x );
			var yi = Math.floor( y );
			var zi = Math.floor( z );
			var xf = fade( x - xi );
			var yf = fade( y - yi );
			var zf = fade( z - zi );
			return lerp(
				lerp( lerp( at( xi, yi, zi ), at( xi + 1, yi, zi ), xf ), lerp( at( xi, yi + 1, zi ), at( xi + 1, yi + 1, zi ), xf ), yf ),
				lerp( lerp( at( xi, yi, zi + 1 ), at( xi + 1, yi, zi + 1 ), xf ), lerp( at( xi, yi + 1, zi + 1 ), at( xi + 1, yi + 1, zi + 1 ), xf ), yf ),
				zf
			);
		};
	}

	/* ---------- colors ---------- */

	function clamp( value, min, max ) {
		return Math.max( min, Math.min( max, value ) );
	}

	function resolveColor( el, cssValue, fallback ) {
		var probe = document.createElement( 'span' );
		probe.style.color = cssValue;
		probe.style.display = 'none';
		el.appendChild( probe );
		var computed = window.getComputedStyle( probe ).color;
		el.removeChild( probe );
		var match = /rgba?\(([^)]+)\)/.exec( computed || '' );
		if ( ! match ) {
			return fallback;
		}
		var parts = match[ 1 ].split( /[ ,/]+/ ).filter( Boolean ).map( Number );
		return parts.length >= 3 && parts.every( function ( n ) { return ! isNaN( n ); } ) ? parts.slice( 0, 3 ) : fallback;
	}

	function getPalette( el, name ) {
		var defaults = {
			primary: [ 0, 229, 255 ],
			secondary: [ 255, 43, 214 ],
			accent: [ 182, 255, 59 ],
			violet: [ 143, 107, 255 ],
			ink: [ 213, 225, 242 ],
			void: [ 6, 7, 13 ],
			line: [ 31, 42, 71 ],
		};
		var P = {};
		Object.keys( defaults ).forEach( function ( key ) {
			P[ key ] = resolveColor( el, 'var(--wp--preset--color--' + key + ')', defaults[ key ] );
		} );
		var list;
		switch ( name ) {
			case 'primary':
				list = [ P.primary ];
				break;
			case 'duo':
				list = [ P.primary, P.secondary ];
				break;
			case 'mono':
				list = [ P.ink ];
				break;
			default:
				list = [ P.primary, P.secondary, P.accent, P.violet ];
		}
		return { list: list, bg: P.void, text: P.ink, line: P.line };
	}

	function rgba( color, alpha ) {
		return 'rgba(' + color[ 0 ] + ',' + color[ 1 ] + ',' + color[ 2 ] + ',' + alpha + ')';
	}

	/* ---------- drawing helpers handed to sketches ---------- */

	function makeHelpers( env ) {
		return {
			rgba: rgba,
			/** Paint the background (or clear, when the block is transparent). */
			clear: function () {
				if ( env.opts.background === 'transparent' ) {
					env.ctx.clearRect( 0, 0, env.w, env.h );
				} else {
					env.ctx.fillStyle = rgba( env.palette.bg, 1 );
					env.ctx.fillRect( 0, 0, env.w, env.h );
				}
			},
			/** Fade previous frames out (motion trails). */
			fade: function ( alpha ) {
				var ctx = env.ctx;
				if ( env.opts.background === 'transparent' ) {
					ctx.globalCompositeOperation = 'destination-out';
					ctx.fillStyle = 'rgba(0,0,0,' + alpha + ')';
					ctx.fillRect( 0, 0, env.w, env.h );
					ctx.globalCompositeOperation = 'source-over';
				} else {
					ctx.fillStyle = rgba( env.palette.bg, alpha );
					ctx.fillRect( 0, 0, env.w, env.h );
				}
			},
			areaScale: function () {
				return clamp( ( env.w * env.h ) / ( 1200 * 700 ), 0.25, 2.5 );
			},
		};
	}

	/* ---------- built-in sketches ---------- */

	registry.flow = function ( env ) {
		var ctx = env.ctx;
		var rng = env.rng;
		var pal = env.palette.list;
		var count = Math.round( clamp( ( 50 + env.opts.density * 55 ) * env.helpers.areaScale(), 40, 1100 ) );
		var scale = 0.0012 + env.opts.density * 0.00012;
		var particles = [];
		for ( var i = 0; i < count; i++ ) {
			particles.push( { x: rng() * env.w, y: rng() * env.h, c: Math.floor( rng() * pal.length ), age: rng() * 300 } );
		}
		env.helpers.clear();
		return {
			settle: 90,
			draw: function ( t, dt ) {
				env.helpers.fade( 0.055 );
				ctx.lineWidth = 1.2;
				var step = 60 * Math.max( dt, 0.001 ) * 1.6;
				var p = env.pointer;
				for ( var i = 0; i < particles.length; i++ ) {
					var q = particles[ i ];
					var angle = env.noise( q.x * scale, q.y * scale, t * 0.1 ) * Math.PI * 4;
					var vx = Math.cos( angle ) * step;
					var vy = Math.sin( angle ) * step;
					if ( p.active ) {
						var dx = q.x - p.x;
						var dy = q.y - p.y;
						var d2 = dx * dx + dy * dy;
						if ( d2 < 22500 && d2 > 1 ) {
							var f = ( 1 - d2 / 22500 ) * 3;
							vx += ( dx / Math.sqrt( d2 ) ) * f + ( -dy / Math.sqrt( d2 ) ) * f * 0.6;
							vy += ( dy / Math.sqrt( d2 ) ) * f + ( dx / Math.sqrt( d2 ) ) * f * 0.6;
						}
					}
					var nx = q.x + vx;
					var ny = q.y + vy;
					ctx.strokeStyle = rgba( pal[ q.c ], 0.6 );
					ctx.beginPath();
					ctx.moveTo( q.x, q.y );
					ctx.lineTo( nx, ny );
					ctx.stroke();
					q.x = nx;
					q.y = ny;
					q.age += 1;
					if ( q.x < -10 || q.x > env.w + 10 || q.y < -10 || q.y > env.h + 10 || q.age > 420 ) {
						q.x = rng() * env.w;
						q.y = rng() * env.h;
						q.age = 0;
					}
				}
			},
		};
	};

	registry.constellation = function ( env ) {
		var ctx = env.ctx;
		var rng = env.rng;
		var pal = env.palette.list;
		var count = Math.round( clamp( ( 22 + env.opts.density * 8 ) * env.helpers.areaScale(), 18, 170 ) );
		var reach = Math.min( env.w, env.h ) * ( 0.16 + env.opts.density * 0.012 );
		var pts = [];
		for ( var i = 0; i < count; i++ ) {
			pts.push( {
				x: rng() * env.w,
				y: rng() * env.h,
				vx: rng.range( -14, 14 ),
				vy: rng.range( -14, 14 ),
				r: rng.range( 1.1, 2.6 ),
				c: Math.floor( rng() * pal.length ),
			} );
		}
		return {
			draw: function ( t, dt ) {
				env.helpers.clear();
				var p = env.pointer;
				var i;
				var j;
				for ( i = 0; i < pts.length; i++ ) {
					var a = pts[ i ];
					a.x += a.vx * dt;
					a.y += a.vy * dt;
					if ( a.x < 0 || a.x > env.w ) {
						a.vx *= -1;
					}
					if ( a.y < 0 || a.y > env.h ) {
						a.vy *= -1;
					}
				}
				ctx.lineWidth = 1;
				for ( i = 0; i < pts.length; i++ ) {
					var u = pts[ i ];
					for ( j = i + 1; j < pts.length; j++ ) {
						var v = pts[ j ];
						var dx = u.x - v.x;
						var dy = u.y - v.y;
						var d = Math.sqrt( dx * dx + dy * dy );
						if ( d < reach ) {
							ctx.strokeStyle = rgba( pal[ u.c ], ( 1 - d / reach ) * 0.55 );
							ctx.beginPath();
							ctx.moveTo( u.x, u.y );
							ctx.lineTo( v.x, v.y );
							ctx.stroke();
						}
					}
					if ( p.active ) {
						var px = u.x - p.x;
						var py = u.y - p.y;
						var pd = Math.sqrt( px * px + py * py );
						if ( pd < reach * 1.6 ) {
							ctx.strokeStyle = rgba( env.palette.text, ( 1 - pd / ( reach * 1.6 ) ) * 0.7 );
							ctx.beginPath();
							ctx.moveTo( u.x, u.y );
							ctx.lineTo( p.x, p.y );
							ctx.stroke();
						}
					}
					ctx.fillStyle = rgba( pal[ u.c ], 0.9 );
					ctx.beginPath();
					ctx.arc( u.x, u.y, u.r, 0, Math.PI * 2 );
					ctx.fill();
				}
			},
		};
	};

	registry.truchet = function ( env ) {
		var ctx = env.ctx;
		var rng = env.rng;
		var pal = env.palette.list;
		var size = Math.round( clamp( 118 - env.opts.density * 9, 26, 118 ) );
		var cols = Math.ceil( env.w / size ) + 1;
		var rows = Math.ceil( env.h / size ) + 1;
		var tiles = [];
		for ( var i = 0; i < cols * rows; i++ ) {
			var o = rng() < 0.5 ? 0 : 1;
			tiles.push( { o: o, target: o, p: o, c: Math.floor( env.noise( ( i % cols ) * 0.35, Math.floor( i / cols ) * 0.35, 0 ) * pal.length ) % pal.length } );
		}
		var half = size / 2;
		var arcs = function ( x, y, orient, alpha, color ) {
			ctx.strokeStyle = rgba( color, alpha );
			ctx.beginPath();
			if ( orient === 0 ) {
				ctx.arc( x, y, half, 0, Math.PI / 2 );
				ctx.moveTo( x + size + half, y + size );
				ctx.arc( x + size, y + size, half, Math.PI, Math.PI * 1.5 );
			} else {
				ctx.arc( x + size, y, half, Math.PI / 2, Math.PI );
				ctx.moveTo( x + half, y + size );
				ctx.arc( x, y + size, half, Math.PI * 1.5, Math.PI * 2 );
			}
			ctx.stroke();
		};
		return {
			draw: function ( t, dt ) {
				env.helpers.clear();
				ctx.lineWidth = Math.max( 1.5, size * 0.1 );
				ctx.lineCap = 'round';
				var flips = Math.max( 1, Math.round( dt * 60 * ( tiles.length / 220 ) ) );
				for ( var f = 0; f < flips; f++ ) {
					var pick = tiles[ Math.floor( rng() * tiles.length ) ];
					pick.target = 1 - pick.target;
				}
				var pt = env.pointer;
				for ( var i = 0; i < tiles.length; i++ ) {
					var tile = tiles[ i ];
					var cx = ( i % cols ) * size;
					var cy = Math.floor( i / cols ) * size;
					if ( pt.active ) {
						var dx = cx + half - pt.x;
						var dy = cy + half - pt.y;
						if ( dx * dx + dy * dy < size * size * 2.2 ) {
							tile.target = ( ( cx + cy ) / size ) % 2 > 0.5 ? 1 : 0;
						}
					}
					tile.p += ( tile.target - tile.p ) * Math.min( 1, dt * 5 );
					var color = pal[ ( tile.c + Math.floor( t * 0.4 + ( cx + cy ) / ( size * 4 ) ) ) % pal.length ];
					arcs( cx, cy, 0, 0.85 * ( 1 - tile.p ), color );
					arcs( cx, cy, 1, 0.85 * tile.p, color );
				}
			},
		};
	};

	registry.waves = function ( env ) {
		var ctx = env.ctx;
		var rng = env.rng;
		var pal = env.palette.list;
		var lines = Math.round( clamp( 7 + env.opts.density * 3.4, 8, 42 ) );
		var rows = [];
		for ( var i = 0; i < lines; i++ ) {
			rows.push( {
				y: ( ( i + 0.5 ) / lines ) * env.h,
				amp: ( env.h / lines ) * rng.range( 0.9, 2.4 ),
				f1: rng.range( 0.004, 0.011 ),
				f2: rng.range( 0.011, 0.024 ),
				ph: rng() * Math.PI * 2,
				s1: rng.range( 0.4, 1.1 ) * ( rng() < 0.5 ? -1 : 1 ),
				s2: rng.range( 0.6, 1.6 ),
				c: i % pal.length,
			} );
		}
		return {
			draw: function ( t ) {
				env.helpers.clear();
				ctx.lineWidth = 1.3;
				var pt = env.pointer;
				for ( var i = 0; i < rows.length; i++ ) {
					var r = rows[ i ];
					ctx.strokeStyle = rgba( pal[ r.c ], 0.5 );
					ctx.beginPath();
					for ( var x = 0; x <= env.w + 8; x += 8 ) {
						var y = r.y + Math.sin( x * r.f1 + t * r.s1 + r.ph ) * r.amp + Math.sin( x * r.f2 - t * r.s2 ) * r.amp * 0.45;
						if ( pt.active ) {
							var gx = ( x - pt.x ) / 130;
							var gy = ( r.y - pt.y ) / 170;
							y -= Math.exp( -( gx * gx + gy * gy ) ) * 70;
						}
						if ( x === 0 ) {
							ctx.moveTo( x, y );
						} else {
							ctx.lineTo( x, y );
						}
					}
					ctx.stroke();
				}
			},
		};
	};

	registry.glitch = function ( env ) {
		var ctx = env.ctx;
		var rng = env.rng;
		var pal = env.palette.list;
		var cell = Math.round( clamp( 50 - env.opts.density * 3.6, 10, 50 ) );
		var cols = Math.ceil( env.w / cell );
		var rows = Math.ceil( env.h / cell );
		var cells = new Float32Array( cols * rows );
		var colors = new Uint8Array( cols * rows );
		for ( var i = 0; i < colors.length; i++ ) {
			colors[ i ] = Math.floor( rng() * pal.length );
		}
		env.helpers.clear();
		return {
			settle: 40,
			draw: function ( t, dt ) {
				env.helpers.fade( 0.2 );
				var sparks = Math.max( 1, Math.round( cells.length * 0.012 * ( 0.4 + env.opts.density / 8 ) ) );
				var i;
				for ( i = 0; i < sparks; i++ ) {
					var k = Math.floor( rng() * cells.length );
					cells[ k ] = 1;
					colors[ k ] = Math.floor( rng() * pal.length );
				}
				var pt = env.pointer;
				if ( pt.active ) {
					var pc = Math.floor( pt.x / cell );
					var pr = Math.floor( pt.y / cell );
					for ( var dy = -2; dy <= 2; dy++ ) {
						for ( var dx = -2; dx <= 2; dx++ ) {
							var cc = pc + dx;
							var rr = pr + dy;
							if ( cc >= 0 && cc < cols && rr >= 0 && rr < rows && rng() < 0.5 ) {
								cells[ rr * cols + cc ] = 1;
							}
						}
					}
				}
				for ( i = 0; i < cells.length; i++ ) {
					if ( cells[ i ] > 0.02 ) {
						ctx.fillStyle = rgba( pal[ colors[ i ] ], cells[ i ] * 0.8 );
						ctx.fillRect( ( i % cols ) * cell + 1, Math.floor( i / cols ) * cell + 1, cell - 2, cell - 2 );
						cells[ i ] *= Math.pow( 0.9, dt * 60 );
					}
				}
				// Occasional horizontal tear.
				if ( rng() < 0.05 * Math.max( dt * 60, 0.2 ) ) {
					var bandY = Math.floor( rng() * env.h );
					var bandH = Math.floor( rng.range( 6, 40 ) );
					var shift = Math.floor( rng.range( -30, 30 ) );
					var s = env.dpr;
					ctx.save();
					ctx.setTransform( 1, 0, 0, 1, 0, 0 );
					ctx.drawImage( env.canvas, 0, bandY * s, env.canvas.width, bandH * s, shift * s, bandY * s, env.canvas.width, bandH * s );
					ctx.restore();
				}
			},
		};
	};

	/* ---------- instance lifecycle ---------- */

	function readOptions( el ) {
		var d = el.dataset;
		var speed = parseFloat( d.speed );
		return {
			sketch: /^[a-z0-9-]{1,40}$/.test( d.sketch || '' ) ? d.sketch : 'flow',
			seed: d.seed || 'cyberdeck',
			palette: d.palette || 'theme',
			density: clamp( parseFloat( d.density ) || 5, 1, 10 ),
			speed: isNaN( speed ) ? 1 : clamp( speed, 0, 5 ),
			interactive: d.interactive !== '0',
			background: d.background === 'transparent' ? 'transparent' : 'void',
			fps: parseInt( d.fps, 10 ) === 60 ? 60 : 30,
			mode: d.mode === 'fixed' ? 'fixed' : 'section',
		};
	}

	function createInstance( el ) {
		var canvas = el.querySelector( '.cd-gen__canvas' );
		if ( ! canvas || ! canvas.getContext ) {
			return null;
		}
		var ctx = canvas.getContext( '2d' );
		if ( ! ctx ) {
			return null;
		}

		var state = {
			opts: readOptions( el ),
			sketch: null,
			running: false,
			visible: false,
			raf: 0,
			last: 0,
			t: 0,
			pointer: { x: -9999, y: -9999, active: false },
			resizeTimer: 0,
		};

		function animated() {
			return state.opts.speed > 0 && ! reduceMotion.matches;
		}

		function stop() {
			state.running = false;
			if ( state.raf ) {
				window.cancelAnimationFrame( state.raf );
				state.raf = 0;
			}
		}

		function frame( ts ) {
			if ( ! state.running ) {
				return;
			}
			if ( ! el.isConnected ) {
				stop();
				return;
			}
			state.raf = window.requestAnimationFrame( frame );
			var interval = 1000 / state.opts.fps;
			if ( ts - state.last < interval - 1 ) {
				return;
			}
			var dt = clamp( ( ts - state.last ) / 1000 || 0.016, 0.001, 0.05 ) * state.opts.speed;
			state.last = ts;
			state.t += dt;
			try {
				state.sketch.draw( state.t, dt );
			} catch ( e ) {
				stop();
			}
		}

		function update() {
			var shouldRun = state.sketch && animated() && state.visible && ! document.hidden;
			if ( shouldRun && ! state.running ) {
				state.running = true;
				state.last = 0;
				state.raf = window.requestAnimationFrame( frame );
			} else if ( ! shouldRun && state.running ) {
				stop();
			}
		}

		function build() {
			stop();
			state.opts = readOptions( el );
			var rect = el.getBoundingClientRect();
			var w = Math.round( rect.width );
			var h = Math.round( rect.height );
			if ( w < 2 || h < 2 ) {
				state.sketch = null;
				return;
			}
			var scale = Math.min( window.devicePixelRatio || 1, 2 );
			scale = Math.min( scale, Math.sqrt( MAX_PIXELS / ( w * h ) ) );
			canvas.width = Math.max( 1, Math.round( w * scale ) );
			canvas.height = Math.max( 1, Math.round( h * scale ) );
			ctx.setTransform( scale, 0, 0, scale, 0, 0 );

			var rng = makeRng( state.opts.seed + '|' + state.opts.sketch );
			var env = {
				ctx: ctx,
				canvas: canvas,
				w: w,
				h: h,
				dpr: scale,
				rng: rng,
				noise: makeNoise( rng ),
				palette: getPalette( el, state.opts.palette ),
				opts: state.opts,
				pointer: state.pointer,
			};
			env.helpers = makeHelpers( env );

			var factory = registry[ state.opts.sketch ] || registry.flow;
			try {
				state.sketch = factory( env );
			} catch ( e ) {
				state.sketch = null;
				return;
			}
			state.t = 0;

			if ( ! animated() ) {
				// Still image: let the piece "settle" for a couple of seconds of simulated time.
				try {
					// Trail-based sketches need many steps to build up; the rest only need one pose.
					var frames = state.sketch.settle || 1;
					for ( var i = 0; i < frames; i++ ) {
						state.sketch.draw( 1.5 + i / 30, 1 / 30 );
					}
				} catch ( e ) {
					state.sketch = null;
				}
			} else {
				try {
					state.sketch.draw( 0, 0.016 );
				} catch ( e ) {
					state.sketch = null;
				}
			}
			update();
		}

		/* observers */
		var io = null;
		if ( 'IntersectionObserver' in window ) {
			io = new IntersectionObserver( function ( entries ) {
				state.visible = entries[ entries.length - 1 ].isIntersecting;
				update();
			}, { rootMargin: '120px' } );
			io.observe( el );
		} else {
			state.visible = true;
		}

		var ro = null;
		if ( 'ResizeObserver' in window ) {
			ro = new ResizeObserver( function () {
				window.clearTimeout( state.resizeTimer );
				state.resizeTimer = window.setTimeout( build, 160 );
			} );
			ro.observe( el );
		}

		var mo = new MutationObserver( function () {
			window.clearTimeout( state.resizeTimer );
			state.resizeTimer = window.setTimeout( build, 60 );
		} );
		mo.observe( el, { attributes: true, attributeFilter: [ 'data-sketch', 'data-seed', 'data-palette', 'data-density', 'data-speed', 'data-background', 'data-fps', 'data-interactive' ] } );

		document.addEventListener( 'visibilitychange', update );
		if ( reduceMotion.addEventListener ) {
			reduceMotion.addEventListener( 'change', build );
		}

		// Pointer influence (section: over the block; fixed backdrop: anywhere on the page).
		var target = state.opts.mode === 'fixed' ? window : el;
		var onMove = function ( event ) {
			if ( ! state.opts.interactive ) {
				return;
			}
			var rect = el.getBoundingClientRect();
			state.pointer.x = event.clientX - rect.left;
			state.pointer.y = event.clientY - rect.top;
			state.pointer.active = true;
		};
		var onLeave = function () {
			state.pointer.active = false;
		};
		target.addEventListener( 'pointermove', onMove, { passive: true } );
		target.addEventListener( 'pointerleave', onLeave, { passive: true } );
		if ( target === window ) {
			document.addEventListener( 'pointerleave', onLeave );
		}

		build();

		return {
			rebuild: build,
			destroy: function () {
				stop();
				if ( io ) {
					io.disconnect();
				}
				if ( ro ) {
					ro.disconnect();
				}
				mo.disconnect();
				target.removeEventListener( 'pointermove', onMove );
				target.removeEventListener( 'pointerleave', onLeave );
				document.removeEventListener( 'visibilitychange', update );
			},
		};
	}

	/* ---------- minted works (the artist's own generative tokens, shown live) ---------- */

	var mintedInstances = [];
	var liveHolder = null;

	function refreshMinted() {
		mintedInstances.slice().forEach( function ( inst ) {
			inst.update();
		} );
	}

	/**
	 * A poster image is always present. The real work (an interactive HTML token in a sandboxed iframe)
	 * is mounted only while this block is on screen, the tab is visible, motion is allowed, and no other
	 * live work is on screen: at most ONE live embed per page, so a page can never pile up several
	 * full generative programs.
	 */
	function createMintedInstance( el ) {
		var frame = el.querySelector( '.cd-gen__frame' );
		var d = el.dataset;
		if ( ! frame || ! d.src ) {
			return null;
		}

		var state = { visible: false, iframe: null };
		var api = {};

		function embedUrl() {
			try {
				var url = new URL( d.src );
				if ( ! /\.[a-z0-9]{2,5}$/i.test( url.pathname ) && url.pathname.slice( -1 ) !== '/' ) {
					url.pathname += '/';
				}
				if ( d.creator ) {
					url.searchParams.set( 'creator', d.creator );
				}
				if ( d.token ) {
					url.searchParams.set( 'objkt', d.token );
				}
				return url.toString();
			} catch ( e ) {
				return d.src;
			}
		}

		function mount() {
			if ( state.iframe ) {
				return;
			}
			var f = document.createElement( 'iframe' );
			f.className = 'cd-gen__iframe';
			f.title = d.title || 'Generative artwork';
			f.setAttribute( 'sandbox', 'allow-scripts allow-same-origin' );
			f.setAttribute( 'aria-hidden', 'true' );
			f.tabIndex = -1;
			f.referrerPolicy = 'no-referrer';
			if ( d.interactive !== '1' ) {
				f.style.pointerEvents = 'none';
			}
			f.addEventListener( 'load', function () {
				frame.classList.add( 'is-live' );
			} );
			f.src = embedUrl();
			frame.appendChild( f );
			state.iframe = f;
		}

		function unmount() {
			if ( ! state.iframe ) {
				return;
			}
			state.iframe.src = 'about:blank';
			state.iframe.remove();
			state.iframe = null;
			frame.classList.remove( 'is-live' );
		}

		api.isVisible = function () {
			return state.visible;
		};
		api.update = function () {
			var wantLive = d.live !== '0' && ! reduceMotion.matches;
			var should = wantLive && state.visible && ! document.hidden;
			if ( should ) {
				if ( liveHolder && liveHolder !== api && liveHolder.isVisible() ) {
					return; // another live work already owns the page's single slot.
				}
				liveHolder = api;
				mount();
			} else {
				if ( liveHolder === api ) {
					liveHolder = null;
				}
				unmount();
			}
		};
		api.destroy = function () {
			state.visible = false;
			api.update();
			mintedInstances.splice( mintedInstances.indexOf( api ), 1 );
			if ( io ) {
				io.disconnect();
			}
		};

		var io = null;
		if ( 'IntersectionObserver' in window ) {
			io = new IntersectionObserver( function ( entries ) {
				state.visible = entries[ entries.length - 1 ].isIntersecting;
				api.update();
				refreshMinted(); // a released slot may let another work go live.
			} );
			io.observe( el );
		} else {
			state.visible = true;
		}

		mintedInstances.push( api );
		document.addEventListener( 'visibilitychange', api.update );
		if ( reduceMotion.addEventListener ) {
			reduceMotion.addEventListener( 'change', api.update );
		}
		api.update();
		return api;
	}

	function init( el ) {
		if ( instances.has( el ) ) {
			return;
		}
		var instance = el.dataset.source === 'minted' ? createMintedInstance( el ) : createInstance( el );
		if ( instance ) {
			instances.set( el, instance );
		}
	}

	function scan( root ) {
		( root || document ).querySelectorAll( '.cd-gen' ).forEach( init );
	}

	function boot() {
		scan( document );
		// Inside the block editor, blocks appear and disappear dynamically.
		if ( document.body && document.body.classList.contains( 'editor-styles-wrapper' ) ) {
			new MutationObserver( function () {
				scan( document );
			} ).observe( document.body, { childList: true, subtree: true } );
		}
	}

	window.cyberdeckGenerative = {
		register: function ( name, factory ) {
			if ( /^[a-z0-9-]{1,40}$/.test( name ) && typeof factory === 'function' ) {
				registry[ name ] = factory;
				document.querySelectorAll( '.cd-gen[data-sketch="' + name + '"]' ).forEach( function ( el ) {
					var existing = instances.get( el );
					if ( existing ) {
						existing.rebuild();
					} else {
						init( el );
					}
				} );
			}
		},
		sketches: function () {
			return Object.keys( registry );
		},
		makeRng: makeRng,
		makeNoise: makeNoise,
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
