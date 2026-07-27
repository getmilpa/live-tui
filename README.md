<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa Live TUI

> The **terminal surface** for `milpa/live` — a retained-mode runtime that diffs a virtual terminal buffer and repaints only what changed, its own ANSI painter, focus and shortcut management, and 23 node renderers.

[![CI](https://github.com/getmilpa/live-tui/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/live-tui/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/live-tui.svg)](https://packagist.org/packages/milpa/live-tui)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![Docs](https://img.shields.io/badge/docs-API%20reference-blue.svg)](https://getmilpa.github.io/live-tui/)

`milpa/live` defines the render-target-agnostic component lifecycle (mount / handle / render,
contracts, data sources). `milpa/live-tui` is what makes that lifecycle reachable from a terminal:
a node tree, a layout engine that resolves it into bounds, 23 renderers that paint those bounds,
and a loop that writes only the rows that actually changed.

It is the **sibling** of [`milpa/live-web`](https://github.com/getmilpa/live-web), not its
dependent. Both are transports of the same engine; neither requires the other.

## Install

```bash
composer require milpa/live-tui
```

## What it is

- **`RetainedTuiLoop`** — the retained-mode loop. Renders the tree into a `VirtualTerminalBuffer`,
  diffs it against the previous frame, and writes only the changed rows. An identical frame costs
  zero writes. It owns focus, key dispatch, session values, and the input buffer for the run.
- **`VirtualTerminalBuffer`** — a fixed grid of cells that frames composite into. Its `diff()` is
  the whole reason this is a retained runtime rather than a redrawn one.
- **`SimpleTuiLayoutEngine`** — resolves a `TuiNode` tree into absolute bounds plus a paint order,
  clipped to the viewport, so a renderer is handed the rectangle it may paint and never computes
  geometry itself.
- **23 node renderers** — `data-table`, `select-list`, `settings-list`, `editor`, `text-input`,
  `markdown`, `log-view`, `job-monitor`, `command-palette`, `progress-bar`, `loader`,
  `cancellable-loader`, `box`, `badge`, `divider`, `spacer`, `text`, `image`, `status-bar`,
  `milpa-logo`, plus the structural and fallback ones. Each is pure: node plus bounds in, lines out.
- **Editor-grade input** — `InputBuffer` (partial escape sequences held across reads), `Key`
  (modifier naming), `KeyMatcher`, `BracketedPaste`, `WordNavigation`, `KillRing`, `UndoStack`,
  and `Grapheme`/`TuiString` for cell-accurate width.
- **`TuiComponentRenderer`** — renders a Live component to the terminal, the counterpart of the
  HTML renderers in `milpa/live-web`. Same component definition, same state snapshot, different
  target: this is the seam that lets one component description serve both surfaces.

## Two rules the renderers keep

**Width is measured in cells, never bytes.** Everything goes through `TuiString`, which ignores
ANSI sequences and counts wide graphemes as the columns they really take. The buffer composites at
bounds *without clamping*, so a renderer that mismeasured would not produce a wrong string — it
would corrupt neighbouring cells.

**Dispatch is on shape, never on identity.** A renderer decides by the node's declared `type`; the
registry asks each renderer in turn and the first match wins. An unknown type resolves to `null`
rather than being guessed at. Nothing in this package knows which section of which application it
is drawing.

## Quick example

```php
use Milpa\Live\Tui\NodeRenderers\DataTableRenderer;
use Milpa\Live\ValueObjects\Tui\{TuiBounds, TuiNode, TuiRenderContext};

$frame = (new DataTableRenderer())->render(
    new TuiNode('routes', 'data-table', props: [
        // `columns` are declarations, not labels: a list of plain strings
        // renders nothing.
        'columns' => [
            ['key' => 'method', 'label' => 'method'],
            ['key' => 'path',   'label' => 'path'],
        ],
        'rows' => [
            ['method' => 'GET', 'path' => '/'],
            ['method' => 'PUT', 'path' => '/agency/api/account'],
        ],
    ]),
    new TuiRenderContext(new TuiBounds(0, 0, 48, 6)),
);

echo implode(PHP_EOL, $frame->lines);
```

And the retained core, which is what the loop is built on:

```php
use Milpa\Live\Tui\VirtualTerminalBuffer;

$previous = new VirtualTerminalBuffer(80, 24);
$current  = new VirtualTerminalBuffer(80, 24);
$current->writeFrame($bounds, $frame);

foreach ($current->diff($previous)->changes as $change) {
    // Only these rows ever reach the terminal.
    printf("\033[%d;1H%s", $change['row'] + 1, $change['line']);
}
```

## What's inside

| Namespace | What it provides |
|-----------|------------------|
| `Milpa\Live\Tui` | `RetainedTuiLoop`, `InteractiveTuiLoop`, `RetainedTuiRenderer`, `VirtualTerminalBuffer`, `SimpleTuiLayoutEngine`, `TuiNodeRendererRegistry`, `TuiAnsiPainter`, `StreamTerminal`, `FocusManager`, `TerminalTheme`, `TuiString`, `Grapheme`, `InputBuffer`, `Key`, `KeyMatcher` |
| `Milpa\Live\Tui\NodeRenderers` | The 23 renderers, plus `AbstractTuiNodeRenderer` with the shared box/fit/pad helpers |
| `Milpa\Live\Rendering` | `TuiComponentRenderer` — the Live-component-to-terminal renderer |
| `Milpa\Live\Contracts\Tui` | `TerminalInterface`, `VirtualTerminalBufferInterface`, `TuiLayoutEngineInterface`, `TuiNodeRendererInterface`, `TuiNodeRendererRegistryInterface`, `FocusManagerInterface`, `FocusableInterface`, `ShortcutRegistryInterface`, `TuiEventBusInterface`, `TuiStateManagerInterface`, `BackgroundJobManagerInterface`, `AutocompleteProviderInterface`, `TerminalThemeInterface` |
| `Milpa\Live\ValueObjects\Tui` | `TuiNode`, `TuiBounds`, `TuiFrame`, `TuiLayoutFrame`, `TuiBufferDiff`, `TuiRenderContext`, `TuiEvent`, `ShortcutBinding`, `BackgroundJob` |

Every public symbol carries a DocBlock — 79 types, 240 public methods, enforced by CI.

## Requirements

- PHP **≥ 8.3**
- [`milpa/core`](https://packagist.org/packages/milpa/core) **^0.6**
- [`milpa/live`](https://packagist.org/packages/milpa/live) **^0.1**

No terminal extension is required. `ext-pcntl` is used opportunistically for `SIGWINCH` resize
handling and its absence only costs you live resize.

## What this package does not claim yet

Stated plainly, because the package is `0.x` and the evidence behind it is specific:

- The **interactive loop has not been exercised against a real terminal** in CI. What the test
  suite covers is the buffer diff, the layout engine, the renderers, the registry, and the value
  objects' invariants — 27 tests. Keyboard handling, resize, and the run loop are verified by use,
  not by tests.
- `milpa/live` is pinned at **`^0.1`**, which is pre-1.0 on both sides.

## Documentation

**Full API reference: [getmilpa.github.io/live-tui](https://getmilpa.github.io/live-tui/)** —
generated straight from the source DocBlocks and dressed with the Milpa design system.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security
issues via [SECURITY.md](SECURITY.md), and note that this project follows a
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

[Apache-2.0](LICENSE) © Rodrigo Vicente - TeamX Agency.

---

Milpa is designed, built, and maintained by **[Rodrigo Vicente - TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=live-tui)**.
