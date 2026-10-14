# Custom blocks

| Block | Status |
| --- | --- |
| `cyberdeck/teia-profile` | built |
| `cyberdeck/mederu-profile` | built |
| `cyberdeck/objkt-profile` | built |
| `cyberdeck/generative` | built |

Category for all of them: `cyberdeck-web3` ("Cyberdeck: Tezos / Web3" in the inserter).

## How they fit together

The blocks share one gallery framework; each provider only supplies data.

- `inc/gallery/` shared: media kinds, IPFS gateways, markup builder (`render.php`), settings page, GraphQL transport, asset handles
- `assets/gallery/` shared front end (`gallery.js`, `gallery.css`) and editor UI (`gallery-editor.js`)
- `inc/teia/client.php` Teia: TezTok indexer queries + provider definition
- `inc/objkt/client.php` objkt GraphQL queries + objkt provider (also used by Mederu)
- `inc/mederu/provider.php` Mederu provider (profile links, minted-on-Mederu filter, material credits)
- `inc/gallery/collect.php` + `assets/gallery/collect.js` + `assets/vendor/wallet/` on-site collecting (below)
- `blocks/<name>/` per block: `block.json`, `render.php`, `index.js` (a few lines of config)

A new marketplace block is one data file that returns a provider array (see the doc comment at the top of
`inc/gallery/render.php`) plus a copied block folder.

## Loading

`inc/blocks.php` registers every `blocks/<name>/block.json` (no build needed) and anything compiled into
`blocks/build/` (`npm install && npm run build`, sources in `blocks/src/<name>/`).

Note: blocks registered by a theme disappear if the theme is switched. To keep them across themes, move
`blocks/`, `assets/gallery/`, `assets/vendor/`, `inc/gallery/`, `inc/teia/`, `inc/objkt/`, `inc/mederu/` and `inc/blocks.php`
into a small plugin.

## Data sources

- Teia: Teia's TezTok indexer (default `https://teztok.teia.rocks/v1/graphql`).
- Mederu: mederu.art has no documented public API, and its works are ordinary FA2 tokens, so the block reads
  them from objkt's public GraphQL API (`https://data.objkt.com/v3/graphql`). Profile links go to
  `mederu.art/user/<address>`; token links go to objkt.
- Both are changeable under Settings > Tezos blocks. The block sidebar's "Check connection" button reports
  reachability and which fields the API exposes.

## Collecting (beta, off by default)

Each block can offer a Collect button in its viewer overlay. Turn it on under Settings > Tezos blocks
("Wallet purchases"), then enable "Let visitors collect with their wallet" per block.

- Marketplace per block: objkt and Mederu buy from objkt Marketplace v4 (`fulfill_ask`); Teia buys from Teia's own
  swap contract (`collect`). Mederu is routed through objkt because Mederu's own docs say purchases go through objkt.
- Only fixed-price listings paid in tez. Auctions, open editions, offers and token-priced listings link to the
  marketplace instead. Contracts are allow-listed in `cyberdeck_collect_adapters()` (filter: `cyberdeck_collect_adapters`).
- The server fetches the live listing (`GET /wp-json/cyberdeck/v1/listing`); block settings cannot change contract,
  entrypoint or price. A per-purchase cap (default 50 tez) is enforced server- and browser-side.
- The browser re-fetches the listing right before the wallet prompt and aborts if id, contract or price changed.
  Before the wallet is involved it reads the contract's real entrypoint interface and refuses anything it does not
  fully recognise.
- The wallet (Taquito + Beacon, one self-hosted 2 MB file) only loads after the visitor confirms.

## Generative Canvas: adding your own sketches

The engine is `assets/generative/generative.js` (seeded RNG, value noise, lifecycle). From any script that loads after it
(a small plugin, or enqueue one from your child theme):

```js
cyberdeckGenerative.register( 'rings', function ( env ) {
	// env: ctx, canvas, w, h (CSS px), rng (seeded), noise (seeded 3D), palette { list, bg, text, line },
	//      opts { density, speed, ... }, pointer { x, y, active }, helpers { clear, fade, rgba, areaScale }
	return {
		settle: 1, // optional: frames to pre-run for the still (reduced-motion) version
		draw: function ( t, dt ) {
			env.helpers.clear();
			// ... draw with env.ctx, using env.rng / env.noise so the same seed gives the same art
		},
	};
} );
```

Then pick Sketch > Custom in the block and type `rings`. The engine handles seeding, sizing, pausing off-screen, reduced
motion, and the editor preview. The `cyberdeck_generative_sketches` filter lists names in PHP if you want them elsewhere.

## Scoping which works a block shows

Shared by all gallery blocks (`inc/gallery/scope.php`):

- `scope`: `native` (default) or `any`. Native = the provider's own contracts (`native` callable in the provider array):
  Teia returns the Hic et Nunc/Teia OBJKT contract; Mederu returns the contracts in Settings (`mederu_contracts`, and the older
  `mederu_studio_contract`); objkt returns the optional list in Settings (`objkt_native_contracts`), because objkt is an open
  marketplace with no single native contract.
- `contracts`: one or more KT1 addresses (up to 10). Overrides `scope`.
- `works`: exact works, one per line, shown in that order. Accepts `KT1…:id`, `KT1…/id`, objkt and teia.art links, and a bare id
  (Teia: means a Hic et Nunc OBJKT). Replaces the account lookup. Works objkt flags stay hidden unless "include flagged" is on.

## Generative Canvas: minted source

`source: minted` picks one of an account's interactive (HTML/generative) tokens through the same provider code, then shows its
poster and, only while visible and when motion is allowed, runs it in a sandboxed iframe. Exactly one live work per page is
allowed; others stay as posters. If nothing can be resolved, visitors see the built-in sketch and editors see why.
