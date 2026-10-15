/** Teia Profile block: editor registration. The UI itself lives in assets/gallery/gallery-editor.js. */
window.cyberdeckGallery.register( {
	name: 'cyberdeck/teia-profile',
	provider: 'teia',
	label: 'Teia Profile',
	pageLabel: 'Teia',
	multi: false,
	placeholder: 'tz1… or name.tez',
	addressHelp: 'Tezos address (tz1…), a .tez name, or a teia.art/tz1… link.',
	instructions: 'Show an artist\u2019s Teia works. Paste a Tezos address, a .tez name, or a teia.art link.',
	sources: [
		[ 'created', 'Works created by this account' ],
		[ 'collected', 'Works collected by this account' ],
	],
	nativeLabel: 'Only works minted on Teia (Hic et Nunc / Teia OBJKTs)',
	nativeHelp: "The Teia contract is built in. Choose Any to include this account's tokens on other contracts.",
	worksHelp: 'a Teia link, a Hic et Nunc OBJKT number, or KT1…:id',
	worksPlaceholder: 'https://teia.art/objkt/123 or just 123',
	features: { collect: true },
	collectHelp: 'Buys from Teia\u2019s own swap contract at the swap price (fixed price, tez only). Works not swapped on Teia link to its page instead.',
} );
