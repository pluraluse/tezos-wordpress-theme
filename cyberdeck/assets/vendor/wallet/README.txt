cyberdeck-wallet.min.js: a single browser bundle of
  @taquito/taquito 25.0.0, @taquito/beacon-wallet 25.0.0 and @airgap/beacon-sdk 4.8.1 (all Apache-2.0 / MIT),
built with esbuild (entry: `window.CyberdeckWallet = { TezosToolkit, BeaconWallet }`).
It is self-hosted and loaded only when a visitor confirms a purchase. No third-party CDN is contacted by this
file; the Beacon SDK itself talks to wallet relay servers while pairing a wallet, as every Tezos dapp does.
Rebuild:  npm i @taquito/taquito @taquito/beacon-wallet esbuild
          esbuild entry.js --bundle --minify --format=iife --platform=browser --target=es2020 \
            --define:global=globalThis --define:process.env.NODE_ENV='"production"'
