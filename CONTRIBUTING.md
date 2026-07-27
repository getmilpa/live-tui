# Contributing to Milpa Live TUI

Thanks for your interest in contributing! Milpa Live TUI is the terminal surface
for `milpa/live` — a retained-mode runtime that diffs a virtual terminal buffer
and repaints only what changed, its own ANSI painter, focus and shortcut
management, and the node renderers that draw Live components on a terminal. It
builds on `milpa/live` for the render-target-agnostic component lifecycle and on
`milpa/core` for attributes, interfaces, and events, with no product coupling.

It is the sibling of [`milpa/live-web`](https://github.com/getmilpa/live-web),
not its dependent: both are transports of the same engine, and neither requires
the other.

## Getting started

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse src
php tools/validate-docblocks.php
```

These run in CI on PHP 8.3 and 8.4 (alongside `composer validate --strict` and a
`php -l` syntax pass); run them locally before opening a PR.

## Guidelines

- **PHP >= 8.3**, with `declare(strict_types=1);` in every file.
- **Document every public symbol.** A public class/interface/enum/trait or public
  method without a DocBlock summary fails CI (`tools/validate-docblocks.php`).
  Trivial accessors and magic methods are exempt.
- **Respect the tier boundary.** Milpa Live TUI depends on `milpa/live` and
  `milpa/core`, never the reverse. Do not introduce a dependency on Doctrine, a
  concrete container, or any product/plugin code, and do not push live-tui
  concerns down into `milpa/live` or the core.
- **Treat the buffer diff as load-bearing.** `VirtualTerminalBuffer::diff()` is
  what makes the runtime retained rather than redrawn: everything on screen is a
  consequence of the cells it reports as changed. Changes there need tests that
  pin what the diff emits — and what it does *not* — not just that the frame
  looks right.
- **Renderers stay pure.** A node renderer takes a `TuiNode` plus its bounds and
  returns lines. It does not read the terminal, hold state between frames, or
  know which section of an application it is drawing. Dispatch on the shape of
  the data, never on an identifier.
- **[Conventional Commits](https://www.conventionalcommits.org/)** — releases and
  the CHANGELOG are generated automatically from commit messages. Use
  `feat:` / `fix:` / `docs:` / `chore:` etc.; a breaking change to a public
  interface or capability schema is a `feat!:` / `BREAKING CHANGE:` (bumps MINOR
  while the package is `0.x`, MAJOR once it reaches `1.0`).

## Code style

The whole Milpa family (`milpa/core`, `milpa/http`, `milpa/live`, `milpa/live-web`, `milpa/live-tui`,
`milpa/tool-runtime`) shares one coding standard, committed verbatim in every repo
as `.php-cs-fixer.dist.php` and enforced by CI. In short:

- **[PSR-12](https://www.php-fig.org/psr/psr-12/) base**: 4 spaces (never tabs);
  opening braces on the **next line** for classes and methods, on the **same line**
  for control structures; one statement per line.
- **Family deltas on top of PSR-12**: short array syntax (`[]`), one space around
  string concatenation (`$a . $b`), fully-multiline method arguments when split,
  no unused imports, aligned/separated/trimmed PHPDoc tags, trailing commas in
  multiline constructs.

Check and fix locally before pushing:

```bash
vendor/bin/php-cs-fixer fix --dry-run --diff   # what CI runs
vendor/bin/php-cs-fixer fix                    # apply
```

Do not tweak `.php-cs-fixer.dist.php` in one package alone — the standard changes
in lockstep across the family or not at all.

## Pull requests

Keep PRs focused, add tests for behavior changes, and make sure the four commands
above are green. A maintainer will review and, once merged to `main`,
release-please will handle versioning.

## License

By contributing, you agree that your contributions are licensed under the
[Apache License 2.0](LICENSE).

---

Milpa is developed and maintained by [TeamX Agency](https://teamx.agency).
