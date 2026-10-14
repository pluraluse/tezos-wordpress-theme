/** objkt Profile block: editor registration. The UI itself lives in assets/gallery/gallery-editor.js. */
window.cyberdeckGallery.register( {
	name: 'cyberdeck/objkt-profile',
	provider: 'objkt',
	label: 'objkt Profile',
	pageLabel: 'objkt',
	multi: true,
	placeholder: 'tz1… or name.tez (several allowed)',
	addressHelp: 'Tezos addresses or .tez names. Separate several with commas or new lines (up to 20). Not needed when showing a whole collection.',
	instructions: 'Show works from objkt.com. Paste one or more Tezos addresses or .tez names, or switch the source to a whole collection.',
	sources: [
		[ 'created', 'Works created by these accounts' ],
		[ 'collected', 'Works collected by these accounts' ],
		[ 'collection', 'Every work in one collection (enter its contract)' ],
	],
	nativeLabel: 'Only collections created on objkt (if listed in Settings)',
	nativeHelp: 'objkt is an open marketplace: unless you list objkt-created collections in Settings, this does not restrict anything.',
	worksHelp: 'an objkt link or KT1…:id',
	worksPlaceholder: 'https://objkt.com/tokens/KT1…/45 or KT1…:45',
	features: { credits: false, flagged: true, price: true, collect: true },
	collectHelp: 'Buys from objkt Marketplace v4 fixed-price listings (tez only) at objkt\u2019s own price.',
} );
