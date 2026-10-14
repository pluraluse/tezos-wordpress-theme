# Cyberdeck

Dark, monospace block theme with a terminal look. Requires WordPress 6.6+ and PHP 7.4+.
No page builder or plugin needed: you edit everything visually in **Appearance > Editor**.

## What you can change visually

- **Styles panel (Appearance > Editor > Styles):** colors, typography, spacing, shadows, per-block styling.
  Five font families are bundled and self-hosted (JetBrains Mono, Space Mono, Share Tech Mono, VT323, system mono).
- **Style variations:** Matrix, Synthwave, Amber CRT, Ice (light). Switch under Styles > Browse styles.
  Every color is a semantic slot (`primary`, `secondary`, `accent`, `panel`, `line`, ...), so swapping a
  palette recolors glows, borders, buttons and gradients together.
- **Templates and parts:** header, footer, index, archive, search, 404, single, page, plus three custom
  page templates: **Blank Canvas** (no header/footer, full control), **Page, No Title** and **Generative Backdrop** (live generative art fixed behind the whole page).
- **Front page:** a generative hero, your page content, a generative divider, latest posts and a call to action, all editable in the Site Editor.
- **Patterns:** generative hero, generative showcase, generative divider, art spotlight, mosaic wall, marquee band, module grid, CTA banner, hero, collection zone.
- **Block styles** (select a block > Styles panel): Neon Panel, Terminal Window, Grid Backdrop, CRT Screen,
  Corner Brackets, Glitch / Prompt / Neon Glow headings, Typewriter and Code Comment paragraphs, Ghost
  button, Scanned images, Log Entry quotes, Chevron lists, Dashed separator.
- **Utility classes** (Advanced > Additional CSS class): `cd-glow`, `cd-glow-text`, `cd-flicker`, `cd-blink`,
  `cd-border`, `cd-border-neon`, `cd-uppercase`.

## Effect intensity

Scanlines and the background grid are subtle by default. Change them in Styles > Additional CSS:

```css
:root { --wp--custom--effects--scanline-opacity: 0; }  /* scanlines off */
:root { --wp--custom--effects--grid-strength: 0%; }    /* grid off */
```

The header is sticky. To turn that off, remove `.cd-site-header` from the header template part's CSS classes.

## Custom blocks

- **Teia Profile** (`cyberdeck/teia-profile`): in the inserter under "Cyberdeck: Tezos / Web3".
  Layouts: grid, masonry, mosaic wall, marquee, carousel, rotating spotlight, list, feed, single, plus optional pointer tilt. Shows a creator's works or a collector's holdings.
  Images, GIFs, video, audio, GLB/glTF and interactive HTML works are supported; anything animated shows a
  still poster until clicked. Configure endpoints and the IPFS gateway under **Settings > Tezos blocks**.
- **Which works each block shows:** every gallery block has a *Which works* choice. **Native** (the default) shows only works
  minted on that platform's own contract(s): Teia → the Hic et Nunc/Teia contract (built in), Mederu → the contracts you list under
  Settings > Tezos blocks. **Any contract** shows everything the account has on Tezos. You can also restrict to one or a few
  contracts, or list **specific works** (links or `KT1…:id`, one per line) to show exactly those, in that order.
- **Mederu Profile** (`cyberdeck/mederu-profile`): same layouts plus a single-column feed. Accepts several accounts
  at once, can show only works minted on Mederu (set the Studio contract under Settings), and can show material
  credits. Data comes from objkt's public API.
- **Generative Canvas** (`cyberdeck/generative`, in the Design category): live, seeded generative art behind your content, or
  a fixed backdrop for the whole site. Five built-in sketches (flow field, constellation, Truchet tiles, wave lines, glitch
  grid), colors from the theme, pauses off-screen, still image for reduced motion. It can also run **one of your own minted
  generative works live** (Teia, objkt or Mederu): a still poster first, the real work only while on screen, one live work per page. Add your own sketches in code; see
  `blocks/README.md`.
- **objkt Profile** (`cyberdeck/objkt-profile`): an artist's works, a collector's holdings, or a whole collection, with
  objkt's lowest price shown.
- **Collect with a wallet** (beta, off by default): see `blocks/README.md`.

## Files

```
theme.json            design tokens + editor capabilities
styles/               style variations
templates/, parts/    block templates and header/footer
patterns/             block patterns
assets/css/cyber.css  effects, block styles, utilities
assets/fonts/         self-hosted woff2 (latin subset, OFL)
functions.php         supports, assets, block styles, categories
inc/blocks.php        custom block loader
blocks/               reserved for objkt / Teia / Mederu blocks
```
