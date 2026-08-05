# Changelog

## [0.7.0](https://github.com/getmilpa/live-tui/compare/v0.6.0...v0.7.0) (2026-08-05)


### Features

* **tui:** los renderers saben decir cuánto van a medir ([f1b04a1](https://github.com/getmilpa/live-tui/commit/f1b04a14466b51b330d5d37bb15fd717f7a93157))

## [0.6.0](https://github.com/getmilpa/live-tui/compare/v0.5.0...v0.6.0) (2026-08-04)


### Features

* **tui:** el pintor traduce el marcador de actor a color ([12c36c3](https://github.com/getmilpa/live-tui/commit/12c36c3212a392e60640397d68c38d06b75fa3ac))

## [0.5.0](https://github.com/getmilpa/live-tui/compare/v0.4.1...v0.5.0) (2026-08-02)


### Features

* a retained loop can forget what it believes is on screen ([52c5187](https://github.com/getmilpa/live-tui/commit/52c518747178ae3816d1178c5d4cc67defd711e9))

## [0.4.1](https://github.com/getmilpa/live-tui/compare/v0.4.0...v0.4.1) (2026-08-01)


### Bug Fixes

* **deps:** el pin de milpa/core acepta la linea 0.7 ([241a56a](https://github.com/getmilpa/live-tui/commit/241a56a4e3a6cab62cc1989151aa5948d59b45e6))

## [0.4.0](https://github.com/getmilpa/live-tui/compare/v0.3.1...v0.4.0) (2026-07-31)


### Features

* a screen that captures text can choose its own quit keys, and see the raw one ([562a590](https://github.com/getmilpa/live-tui/commit/562a590af08a15089c3dbd3f62787c636b212d76))

## [0.3.1](https://github.com/getmilpa/live-tui/compare/v0.3.0...v0.3.1) (2026-07-29)


### Bug Fixes

* accept milpa/live ^0.4, which this package already required and nobody could install ([d8e8e17](https://github.com/getmilpa/live-tui/commit/d8e8e171ff1ca24534f3e7f40aadf597df75787c))

## [0.3.0](https://github.com/getmilpa/live-tui/compare/v0.2.3...v0.3.0) (2026-07-28)


### Features

* accept milpa/live ^0.2 ([c802b76](https://github.com/getmilpa/live-tui/commit/c802b7642e2a3100d39adb34d23a6df4d5f1b19e))


### Bug Fixes

* Grapheme nunca usaba intl — los dos usos eran inalcanzables ([dfb636a](https://github.com/getmilpa/live-tui/commit/dfb636a9cd9350f83c41f881a66bb6970154ab2f))
* la columna del cursor se mide desde su propio renglón ([98f1ef4](https://github.com/getmilpa/live-tui/commit/98f1ef4ade38b13f6611c02b7d37609d2ea793ba))
* los loops preguntan a KeyMatcher qué tecla es, en vez de a una tabla propia ([cf02a72](https://github.com/getmilpa/live-tui/commit/cf02a72523ba66db9241231548d3d32a12798094))
* un componente que rechaza una acción ya no reporta éxito ([884a71e](https://github.com/getmilpa/live-tui/commit/884a71e77388f986d905cde926a6d77c44206a53))

## [0.2.3](https://github.com/getmilpa/live-tui/compare/v0.2.2...v0.2.3) (2026-07-27)


### Bug Fixes

* assemble escape sequences the way a terminal actually delivers them ([#8](https://github.com/getmilpa/live-tui/issues/8)) ([89555e4](https://github.com/getmilpa/live-tui/commit/89555e4f0db7de7542b7deaf0ceb804693f1bb20))

## [0.2.2](https://github.com/getmilpa/live-tui/compare/v0.2.1...v0.2.2) (2026-07-27)


### Bug Fixes

* adopt the terminal's size, at startup and on resize ([#6](https://github.com/getmilpa/live-tui/issues/6)) ([2c6fcce](https://github.com/getmilpa/live-tui/commit/2c6fcceab0139c4694421d1cdaa00ec9738e754f))

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
