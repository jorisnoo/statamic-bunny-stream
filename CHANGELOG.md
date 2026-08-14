# Changelog

## [2.0.0](https://github.com/jorisnoo/statamic-bunny-stream/releases/tag/2.0.0) (2026-02-13)

## [2.3.0](https://github.com/jorisnoo/statamic-bunny-stream/releases/tag/v2.3.0) (2026-08-14)

### Features

- add bunny-stream:backup command ([5a986a4](https://github.com/jorisnoo/statamic-bunny-stream/commit/5a986a47cabfce76dba6c4205c7dc2524d531763))
- add chapter editor and video detail stack components ([4defe0e](https://github.com/jorisnoo/statamic-bunny-stream/commit/4defe0e273dabab344100ce21ab4408f00a93628))

### Bug Fixes

- show video title in collapsed repeater previews ([65136aa](https://github.com/jorisnoo/statamic-bunny-stream/commit/65136aa0782e1721b1b611e2f6e43eaab7210537))
- stop exposing Bunny API key to the browser and gate CP routes ([0de1dd9](https://github.com/jorisnoo/statamic-bunny-stream/commit/0de1dd9b9130eb6c117387b746440f08b1ca0f7b))

### Chores

- **deps:** minor update ([627831f](https://github.com/jorisnoo/statamic-bunny-stream/commit/627831f0ed51d6aad6fa9775749fba1cbaebdd3f))
- tidy metadata, add justfile, stop tracking package-lock.json ([83e0688](https://github.com/jorisnoo/statamic-bunny-stream/commit/83e0688d2038e66e255016c4d218313462940b5d))
- simplify dependabot auto-merge and add package-lock.json to gitignore ([ef11d45](https://github.com/jorisnoo/statamic-bunny-stream/commit/ef11d4532f9146b67847e698ca219f5b6022f272))
## [2.2.0](https://github.com/jorisnoo/statamic-bunny-stream/releases/tag/v2.2.0) (2026-03-30)

### Features

- add thumbnail cache bust functionality ([18eb944](https://github.com/jorisnoo/statamic-bunny-stream/commit/18eb944fa367493bcfc2c5c74bfb73d399e8b41b))
- add BunnyVideo class with token authentication support ([5981a0a](https://github.com/jorisnoo/statamic-bunny-stream/commit/5981a0a276c54ef78fd1f35c1ff3527f7fb82d77))

### Bug Fixes

- **thumbnail:** add Referer header to thumbnail requests ([67486f3](https://github.com/jorisnoo/statamic-bunny-stream/commit/67486f346ebcc601a14d319ea6aeec807bd9168b))
## [2.1.3](https://github.com/jorisnoo/statamic-bunny-stream/releases/tag/v2.1.3) (2026-03-27)

### Code Refactoring

- make cache operations explicit ([7a256e6](https://github.com/jorisnoo/statamic-bunny-stream/commit/7a256e642649650a04f0fc7cbdada3eed444bed2))

### Continuous Integration

- add dependabot configuration and auto-merge workflow ([2abca99](https://github.com/jorisnoo/statamic-bunny-stream/commit/2abca995fa0a0c19edc86a6477cfe3b4faa47ceb))
## [2.1.2](https://github.com/jorisnoo/statamic-bunny-stream/releases/tag/v2.1.2) (2026-03-04)

### Features

- proxy Bunny video list through CP route and prefill initial title in fieldtype ([7631c6e](https://github.com/jorisnoo/statamic-bunny-stream/commit/7631c6eeb8a5f0ecf4f5348128f8c53ae5ed5853))

### Build System

- compile frontend assets ([05bfba9](https://github.com/jorisnoo/statamic-bunny-stream/commit/05bfba96a99cab503517d248024672e5ffc49378))
- simplify vite config and fix asset commit workflow to use git add --force ([2758643](https://github.com/jorisnoo/statamic-bunny-stream/commit/2758643395d4866edf5a7e105cd573c39e7b9645))
- compile frontend assets ([03f9919](https://github.com/jorisnoo/statamic-bunny-stream/commit/03f9919e0b81f689aae645df9688a9c08209a810))
- compile frontend assets ([1369ff4](https://github.com/jorisnoo/statamic-bunny-stream/commit/1369ff42c41fb75a529b64c7ec80dc3343ef2688))
- upgrade deps ([b899aa0](https://github.com/jorisnoo/statamic-bunny-stream/commit/b899aa047248cd0685b0c731989de648dfad1be5))
- compile frontend assets ([9c20f49](https://github.com/jorisnoo/statamic-bunny-stream/commit/9c20f4968706e0838ef85bd6e10c2fc940a38161))
- compile frontend assets ([589e98e](https://github.com/jorisnoo/statamic-bunny-stream/commit/589e98e01d358d3439338f6c09ced5d444bf39c5))
- compile frontend assets ([5635fbe](https://github.com/jorisnoo/statamic-bunny-stream/commit/5635fbeb8f928e910e8c484a66695b24b5006a3a))

### Continuous Integration

- simplify asset commit workflow by using file_pattern instead of manual git add ([f431c04](https://github.com/jorisnoo/statamic-bunny-stream/commit/f431c04feff90e405bc62ee82523ccaec96693e4))
## [2.1.1](https://github.com/jorisnoo/statamic-bunny-stream/releases/tag/v2.1.1) (2026-03-04)

### Features

- proxy Bunny video list through CP route and prefill initial title in fieldtype ([7631c6e](https://github.com/jorisnoo/statamic-bunny-stream/commit/7631c6eeb8a5f0ecf4f5348128f8c53ae5ed5853))

### Build System

- compile frontend assets ([05bfba9](https://github.com/jorisnoo/statamic-bunny-stream/commit/05bfba96a99cab503517d248024672e5ffc49378))
- simplify vite config and fix asset commit workflow to use git add --force ([2758643](https://github.com/jorisnoo/statamic-bunny-stream/commit/2758643395d4866edf5a7e105cd573c39e7b9645))
- compile frontend assets ([03f9919](https://github.com/jorisnoo/statamic-bunny-stream/commit/03f9919e0b81f689aae645df9688a9c08209a810))
- compile frontend assets ([1369ff4](https://github.com/jorisnoo/statamic-bunny-stream/commit/1369ff42c41fb75a529b64c7ec80dc3343ef2688))
- upgrade deps ([b899aa0](https://github.com/jorisnoo/statamic-bunny-stream/commit/b899aa047248cd0685b0c731989de648dfad1be5))
- compile frontend assets ([9c20f49](https://github.com/jorisnoo/statamic-bunny-stream/commit/9c20f4968706e0838ef85bd6e10c2fc940a38161))
- compile frontend assets ([589e98e](https://github.com/jorisnoo/statamic-bunny-stream/commit/589e98e01d358d3439338f6c09ced5d444bf39c5))
- compile frontend assets ([5635fbe](https://github.com/jorisnoo/statamic-bunny-stream/commit/5635fbeb8f928e910e8c484a66695b24b5006a3a))

### Continuous Integration

- simplify asset commit workflow by using file_pattern instead of manual git add ([f431c04](https://github.com/jorisnoo/statamic-bunny-stream/commit/f431c04feff90e405bc62ee82523ccaec96693e4))
## [2.1.0](https://github.com/jorisnoo/statamic-bunny-stream/releases/tag/v2.1.0) (2026-03-01)

### Features

- auto-publish assets after addon installation with cache cleanup ([e5f9569](https://github.com/jorisnoo/statamic-bunny-stream/commit/e5f9569203134576db6155bd7ca2f6d3d3a1cd46))
- rename addon assets to bunny-stream, add cleanup command, and enable cache-busting hashes ([22dd7d7](https://github.com/jorisnoo/statamic-bunny-stream/commit/22dd7d77d1ea2332cd3c58144285820233e77cc1))
- add tailwind CSS prefix to avoid style conflicts and rebuild assets ([d266e8b](https://github.com/jorisnoo/statamic-bunny-stream/commit/d266e8b80ed1d742d0ebac5c1f31d49d4f327a29))
- add augment method to Bunny fieldtype to generate HLS playlist URLs ([8acf088](https://github.com/jorisnoo/statamic-bunny-stream/commit/8acf088ebd9e599d2c53c3ef9cf57f040c56a899))

### Bug Fixes

- rename addon from "Video Stream GDPR Compliant" to "Bunny Stream" in composer.json ([697761f](https://github.com/jorisnoo/statamic-bunny-stream/commit/697761f8d0c848d7eae7e8844536c347d0da6aa1))
- rename "Select Video" to "Select Media" in fieldtype placeholder and translations ([1556865](https://github.com/jorisnoo/statamic-bunny-stream/commit/1556865f2f4b7ff4135f40112f0ade822ad1bfae))
- correct encoding progress display in Bunny fieldtype and rebuild assets ([e52cf68](https://github.com/jorisnoo/statamic-bunny-stream/commit/e52cf688f42f17a8f85a9ee57fb901d7b16d36e0))
- simplify config publish tag from statamic-bunny-stream-config to bunny-stream-config ([5cf1e53](https://github.com/jorisnoo/statamic-bunny-stream/commit/5cf1e53de144a089c81463fd60f9beb551eb9f67))
- update Bunny fieldtype to use correct encoding progress calculation and rebuild assets ([58d94d5](https://github.com/jorisnoo/statamic-bunny-stream/commit/58d94d554f6e963b56b47d84a39d60cd9647e168))
- simplify Bunny fieldtype by removing unused data properties and rebuild assets ([60652ed](https://github.com/jorisnoo/statamic-bunny-stream/commit/60652ed6611d097bfe61098451356ff11be7c30a))
- simplify Bunny fieldtype component and rebuild assets ([392f266](https://github.com/jorisnoo/statamic-bunny-stream/commit/392f266243b394d257fd9260e1b0da9294a0eae1))

### Build System

- compile frontend assets ([e64188c](https://github.com/jorisnoo/statamic-bunny-stream/commit/e64188c25939c2a1a84c5832e658eda1735318d3))
### Breaking Changes

- Requires **Statamic 6** and **PHP 8.3+**
- Namespace changed from `Laborb` to `Noo`
- Removed public-facing frontend features (privacy overlay, consent management)
- Config namespace changed from `bunny` to `bunny-stream`
- Environment variables renamed to `BUNNY_STREAM_*` prefix
- Publish tag renamed to `statamic-bunny-stream-config`

### What's New

- Vue 3 components (migrated from Vue 2)
- New Vue-based overview page (replaces Blade template)
- Renamed VideoBrowser to MediaBrowser
- Improved video card thumbnail handling
- Updated build tooling (Vite)
- GitHub Actions workflow for auto-building frontend assets

### Housekeeping

- Removed unused dependencies (axios, js-sha256, tus-js-client)
- Simplified video components, removed unused icons and settings panel
