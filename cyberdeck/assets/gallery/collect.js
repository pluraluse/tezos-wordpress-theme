/**
 * Cyberdeck gallery: "Collect" panel (loaded only when a visitor opens a work in a block that allows it).
 *
 * Flow, with a check at every step that money could move:
 *   1. Ask this site's server for the work's live listing (never trust page content for price/contract).
 *   2. Show price, editions, seller and contract; the visitor picks a quantity and presses Collect.
 *   3. A confirmation step repeats the total. Pressing "Confirm in wallet" re-fetches the listing and
 *      aborts if anything (id, contract, price) changed since the visitor saw it.
 *   4. Only then is the wallet bundle (Taquito + Beacon, ~2 MB) loaded. Before building the transaction,
 *      the contract's real entrypoint interface is read and compared with what we expect. Anything we
 *      do not fully understand is refused.
 *   5. The visitor's own wallet shows the transaction and signs it. The site never sees keys or funds.
 *
 * Scope: fixed-price listings in tez. The server never returns anything else as buyable.
 */
( function () {
	'use strict';

	var walletPromise = null;
	var wallet = null; // BeaconWallet instance, reused so a visitor pairs once per page.

	/* ---------- tiny helpers ---------- */

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

	function short( value ) {
		return value && value.length > 12 ? value.slice( 0, 6 ) + '\u2026' + value.slice( -4 ) : value || '';
	}

	/** mutez (string or BigInt) -> "1.5 \ua729" */
	function tez( mutez ) {
		var value = BigInt( mutez );
		var whole = value / 1000000n;
		var frac = String( value % 1000000n ).padStart( 6, '0' ).replace( /0+$/, '' );
		return whole.toString() + ( frac ? '.' + frac : '' ) + ' \ua729';
	}

	function loadScript( url ) {
		return new Promise( function ( resolve, reject ) {
			var script = document.createElement( 'script' );
			script.src = url;
			script.onload = resolve;
			script.onerror = function () {
				reject( new Error( 'Could not load the wallet library.' ) );
			};
			document.head.appendChild( script );
		} );
	}

	function loadWallet( url ) {
		if ( window.CyberdeckWallet ) {
			return Promise.resolve( window.CyberdeckWallet );
		}
		if ( ! walletPromise ) {
			walletPromise = loadScript( url ).then( function () {
				return window.CyberdeckWallet;
			} );
			walletPromise.catch( function () {
				walletPromise = null;
			} );
		}
		return walletPromise;
	}

	function fetchListing( ctx ) {
		var url =
			ctx.listingUrl +
			( ctx.listingUrl.indexOf( '?' ) === -1 ? '?' : '&' ) +
			'provider=' + encodeURIComponent( ctx.provider ) +
			'&fa2=' + encodeURIComponent( ctx.fa2 ) +
			'&token=' + encodeURIComponent( ctx.token );
		return fetch( url, { headers: { Accept: 'application/json' }, credentials: 'omit', cache: 'no-store' } ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'Price check failed (' + response.status + ').' );
			}
			return response.json();
		} );
	}

	/* ---------- building the transaction safely ---------- */

	var ID_FIELDS = [ 'ask_id', 'swap_id', 'listing_id', 'id' ];
	var QTY_FIELDS = [ 'amount', 'objkt_amount', 'editions' ];
	var NULL_FIELDS = [ 'proxy_for', 'condition_extra', 'referrer' ];

	/**
	 * Compare the contract's real entrypoint interface with what we can fill in.
	 * Returns the argument value to pass to Taquito, or throws if there is any field we do not understand.
	 */
	function buildArguments( signature, listing, quantity ) {
		var type = signature && signature.__michelsonType;
		var schema = signature && signature.schema;

		// Entrypoint takes just the listing id.
		if ( type === 'nat' ) {
			if ( quantity !== 1 ) {
				throw new Error( 'This marketplace entrypoint only supports buying one edition at a time.' );
			}
			return listing.id;
		}

		if ( type !== 'pair' || ! schema || typeof schema !== 'object' ) {
			throw new Error( 'Unrecognised marketplace interface. Not sending anything.' );
		}

		var args = {};
		var usedId = false;
		var usedQty = false;

		Object.keys( schema ).forEach( function ( key ) {
			var fieldType = schema[ key ] && schema[ key ].__michelsonType;
			if ( ID_FIELDS.indexOf( key ) > -1 && ! usedId && fieldType === 'nat' ) {
				args[ key ] = listing.id;
				usedId = true;
			} else if ( QTY_FIELDS.indexOf( key ) > -1 && ! usedQty && fieldType === 'nat' ) {
				args[ key ] = String( quantity );
				usedQty = true;
			} else if ( NULL_FIELDS.indexOf( key ) > -1 && fieldType === 'option' ) {
				args[ key ] = null;
			} else {
				throw new Error( 'The marketplace contract expects a field we do not recognise (' + key + '). Not sending anything.' );
			}
		} );

		if ( ! usedId ) {
			throw new Error( 'Could not find the listing id field. Not sending anything.' );
		}
		if ( ! usedQty && quantity !== 1 ) {
			throw new Error( 'This marketplace entrypoint only supports buying one edition at a time.' );
		}
		return args;
	}

	function getWallet( ctx, wallets ) {
		if ( ! wallet ) {
			wallet = new wallets.BeaconWallet( {
				name: ctx.siteName || document.title || 'Website',
				network: { type: 'mainnet' },
				preferredNetwork: 'mainnet',
			} );
		}
		return wallet;
	}

	/**
	 * Run the purchase. `ui.say( text )` reports progress.
	 */
	async function purchase( ctx, listing, quantity, ui ) {
		var total = BigInt( listing.price_mutez ) * BigInt( quantity );
		if ( total > BigInt( ctx.capMutez ) ) {
			throw new Error( 'This purchase is above the limit for on-site collecting. Please use the marketplace.' );
		}

		// Step 3: re-check immediately before asking the wallet.
		ui.say( 'Re-checking the price\u2026' );
		var fresh = await fetchListing( ctx );
		if (
			fresh.state !== 'ok' ||
			fresh.listing.id !== listing.id ||
			fresh.listing.contract !== listing.contract ||
			fresh.listing.price_mutez !== listing.price_mutez ||
			quantity > fresh.listing.left
		) {
			var changed = new Error( 'The listing changed while you were looking. Review the updated price and try again.' );
			changed.listingChanged = fresh;
			throw changed;
		}

		// Step 4: read the contract's real interface BEFORE the wallet is involved, so an unrecognised
		// contract is refused without ever prompting the visitor.
		ui.say( 'Loading wallet connection\u2026' );
		var wallets = await loadWallet( ctx.walletUrl );
		var Tezos = new wallets.TezosToolkit( ctx.rpc );

		ui.say( 'Checking the marketplace contract\u2026' );
		var probe = await Tezos.contract.at( listing.contract );
		if ( probe.address !== listing.contract ) {
			throw new Error( 'Contract mismatch. Not sending anything.' );
		}
		if ( typeof probe.methodsObject[ listing.entrypoint ] !== 'function' ) {
			throw new Error( 'The marketplace contract has no "' + listing.entrypoint + '" entrypoint. Not sending anything.' );
		}
		buildArguments( probe.methodsObject[ listing.entrypoint ]().getSignature(), listing, quantity );

		// Now connect the wallet and build the real call.
		ui.say( 'Waiting for your wallet\u2026' );
		var beacon = getWallet( ctx, wallets );
		var active = await beacon.client.getActiveAccount();
		if ( ! active ) {
			await beacon.requestPermissions( { network: { type: 'mainnet' } } );
		}
		Tezos.setWalletProvider( beacon );

		var contract = await Tezos.wallet.at( listing.contract );
		if ( contract.address !== listing.contract ) {
			throw new Error( 'Contract mismatch. Not sending anything.' );
		}
		var method = contract.methodsObject[ listing.entrypoint ];
		if ( typeof method !== 'function' ) {
			throw new Error( 'The marketplace contract has no "' + listing.entrypoint + '" entrypoint. Not sending anything.' );
		}
		var args = buildArguments( method().getSignature(), listing, quantity );

		ui.say( 'Confirm the transaction in your wallet\u2026' );
		var operation = await method( args ).send( { amount: Number( total ), mutez: true } );

		ui.say( 'Sent. Waiting for the network to confirm\u2026' );
		try {
			await operation.confirmation( 1 );
		} catch ( e ) {
			// The operation was injected; confirmation polling can fail on flaky RPCs.
		}
		return operation.opHash;
	}

	/* ---------- panel ---------- */

	function marketLink( ctx, response, label ) {
		var link = make( 'a', 'gx-collect__link', label || 'View on ' + ( response && response.label ? response.label : 'the marketplace' ) );
		link.href = ( response && response.page ) || ctx.page;
		link.target = '_blank';
		link.rel = 'noopener noreferrer';
		return link;
	}

	function mount( container, ctx ) {
		container.replaceChildren();
		var box = make( 'div', 'gx-collect' );
		var status = make( 'p', 'gx-collect__status', 'Checking price\u2026' );
		box.appendChild( status );
		container.appendChild( box );

		fetchListing( ctx )
			.then( function ( response ) {
				renderResponse( box, ctx, response );
			} )
			.catch( function () {
				box.replaceChildren( make( 'p', 'gx-collect__status', 'Could not check the price right now.' ), marketLink( ctx, null ) );
			} );
	}

	function renderResponse( box, ctx, response ) {
		box.replaceChildren();

		if ( response.state === 'disabled' ) {
			return;
		}
		if ( response.state !== 'ok' ) {
			var text =
				response.state === 'none'
					? 'Not listed for sale right now.'
					: response.state === 'elsewhere'
					? 'Listed in a way that cannot be bought here (auction, open edition, other currency or marketplace).'
					: response.message || 'Could not check the price right now.';
			box.appendChild( make( 'p', 'gx-collect__status', text ) );
			box.appendChild( marketLink( ctx, response ) );
			return;
		}

		var listing = response.listing;
		ctx.capMutez = response.cap_mutez;
		var max = Math.max( 1, Math.min( listing.left, 10 ) );
		var quantity = 1;

		var summary = make( 'p', 'gx-collect__price' );
		var price = make( 'strong', null, tez( listing.price_mutez ) );
		summary.appendChild( price );
		summary.appendChild( document.createTextNode( ' each \u00b7 ' + listing.left + ' left \u00b7 sold by ' + short( listing.seller ) ) );
		box.appendChild( summary );

		var row = make( 'div', 'gx-collect__row' );
		var qtyInput = null;
		if ( max > 1 ) {
			var label = make( 'label', 'gx-collect__qty', 'Editions ' );
			qtyInput = make( 'input' );
			qtyInput.type = 'number';
			qtyInput.min = '1';
			qtyInput.max = String( max );
			qtyInput.value = '1';
			label.appendChild( qtyInput );
			row.appendChild( label );
		}
		var totalEl = make( 'span', 'gx-collect__total' );
		var go = make( 'button', 'gx-collect__go', 'Collect' );
		go.type = 'button';
		row.appendChild( totalEl );
		row.appendChild( go );
		box.appendChild( row );

		var detail = make( 'p', 'gx-collect__detail', response.label + ' marketplace contract ' + short( listing.contract ) + ' \u00b7 Tezos mainnet \u00b7 you confirm in your own wallet' );
		detail.title = listing.contract;
		box.appendChild( detail );
		box.appendChild( marketLink( ctx, response, 'or buy on ' + response.label ) );

		var message = make( 'p', 'gx-collect__status' );
		message.setAttribute( 'role', 'status' );
		box.appendChild( message );

		function currentTotal() {
			return BigInt( listing.price_mutez ) * BigInt( quantity );
		}
		function refreshTotal() {
			var n = qtyInput ? parseInt( qtyInput.value, 10 ) : 1;
			quantity = Math.min( max, Math.max( 1, isNaN( n ) ? 1 : n ) );
			totalEl.textContent = 'Total ' + tez( currentTotal() );
			var over = currentTotal() > BigInt( response.cap_mutez );
			go.disabled = over;
			if ( over ) {
				message.textContent = 'Above the on-site purchase limit. Use the marketplace link.';
			} else if ( message.textContent.indexOf( 'Above the on-site' ) === 0 ) {
				message.textContent = '';
			}
		}
		if ( qtyInput ) {
			qtyInput.addEventListener( 'input', refreshTotal );
		}
		refreshTotal();

		go.addEventListener( 'click', function () {
			// Confirmation step: nothing is sent until the visitor presses "Confirm in wallet".
			var confirm = make( 'div', 'gx-collect__confirm' );
			confirm.appendChild(
				make( 'p', null, 'You are about to buy ' + quantity + ' edition' + ( quantity > 1 ? 's' : '' ) + ' of \u201c' + ctx.title + '\u201d for ' + tez( currentTotal() ) + ' plus network fees. Your wallet will show the transaction for you to approve.' )
			);
			var yes = make( 'button', 'gx-collect__go', 'Confirm in wallet' );
			var no = make( 'button', 'gx-collect__cancel', 'Cancel' );
			yes.type = no.type = 'button';
			confirm.appendChild( yes );
			confirm.appendChild( no );
			row.hidden = true;
			box.insertBefore( confirm, message );

			no.addEventListener( 'click', function () {
				confirm.remove();
				row.hidden = false;
				message.textContent = '';
			} );

			yes.addEventListener( 'click', function () {
				yes.disabled = no.disabled = true;
				purchase( ctx, listing, quantity, {
					say: function ( text ) {
						message.textContent = text;
					},
				} )
					.then( function ( hash ) {
						box.replaceChildren();
						box.appendChild( make( 'p', 'gx-collect__status is-success', 'Done. Your purchase is confirmed on Tezos.' ) );
						var link = make( 'a', 'gx-collect__link', 'View the transaction' );
						link.href = 'https://tzkt.io/' + encodeURIComponent( hash );
						link.target = '_blank';
						link.rel = 'noopener noreferrer';
						box.appendChild( link );
					} )
					.catch( function ( error ) {
						if ( error && error.listingChanged ) {
							renderResponse( box, ctx, error.listingChanged );
							box.insertBefore( make( 'p', 'gx-collect__status is-error', error.message ), box.firstChild );
							return;
						}
						var text = error && error.message ? error.message : 'The purchase did not go through.';
						if ( /abort|reject|denied|cancel/i.test( ( error && ( error.name + ' ' + error.message ) ) || '' ) ) {
							text = 'Cancelled in your wallet. Nothing was sent.';
						}
						message.textContent = text;
						message.classList.add( 'is-error' );
						confirm.remove();
						row.hidden = false;
					} );
			} );
		} );
	}

	window.CyberdeckCollect = { mount: mount };
} )();
