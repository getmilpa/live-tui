# Changelog

## [0.2.1](https://github.com/getmilpa/live-tui/compare/v0.2.0...v0.2.1) (2026-07-27)


### Bug Fixes

* keep every cell, every shape and every attribution header ([#4](https://github.com/getmilpa/live-tui/issues/4)) ([127097b](https://github.com/getmilpa/live-tui/commit/127097b34308732fee24b735c300e96073d3deb0))

## [0.2.0](https://github.com/getmilpa/live-tui/compare/v0.1.0...v0.2.0) (2026-07-27)


### ⚠ BREAKING CHANGES

* TerminalInterface gains pollInput() and atEndOfInput(). Existing signatures are unchanged, but any external implementor must add the two methods. There are none today — zero dependents on Packagist.

### Features

* drive both TUI loops through TerminalInterface ([#2](https://github.com/getmilpa/live-tui/issues/2)) ([a79249d](https://github.com/getmilpa/live-tui/commit/a79249d0524208faaf874822becc8f87b674a2f9))

## 0.1.0 (2026-07-27)


### Features

* milpa/live-tui — the terminal transport layer for Milpa Live ([cbcc8c5](https://github.com/getmilpa/live-tui/commit/cbcc8c5487f35e45ed626797a28f512959774e2f))


### Bug Fixes

* **docs:** map Milpa\Docs\ so the API reference can build ([86b8c8d](https://github.com/getmilpa/live-tui/commit/86b8c8d858961cf7c8b455f80c82b3d2e05b7bb0))


### Miscellaneous Chores

* release 0.1.0 ([3b917f8](https://github.com/getmilpa/live-tui/commit/3b917f8d47b535a08d8fa6951f668b46726789a4))
