/** Mederu Profile block: editor registration. The UI itself lives in assets/gallery/gallery-editor.js. */
window.cyberdeckGallery.register( {
	name: 'cyberdeck/mederu-profile',
	provider: 'mederu',
	label: 'Mederu Profile',
	pageLabel: 'objkt',
	multi: true,
	placeholder: 'tz1… or name.tez or mederu.art/user/tz1…',
	addressHelp: 'Tezos addresses, .tez names or mederu.art/user/… links. Separate several with commas or new lines (up to 20).',
	instructions: 'Show works from mederu.art artists. Paste one or more Tezos addresses, .tez names, or mederu.art profile links.',
	sources: [
		[ 'created', 'Works created by these accounts (all of Tezos)' ],
		[ 'collected', 'Works collected by these accounts' ],
	],
	nativeLabel: 'Only works minted on Mederu',
	nativeHelp: 'Uses the Mederu contracts you list under Settings > Tezos blocks.',
	worksHelp: 'an objkt link or KT1…:id',
	worksPlaceholder: 'https://objkt.com/tokens/KT1…/45 or KT1…:45',
	features: { credits: true, flagged: true, price: true, collect: true },
	collectHelp: 'Mederu purchases go through objkt, so this buys from objkt Marketplace v4 fixed-price listings (tez only).',
} );
