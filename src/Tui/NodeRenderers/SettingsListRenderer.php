<?php

/**
 * This file is part of Milpa Live TUI — the terminal transport layer (retained-mode runtime, ANSI painting, node rendering) of the Milpa PHP framework live component system.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/live-tui
 */

declare(strict_types=1);

namespace Milpa\Live\Tui\NodeRenderers;

use Milpa\Live\Contracts\Tui\TuiNodeRendererInterface;
use Milpa\Live\Tui\TuiString;
use Milpa\Live\ValueObjects\Tui\TuiFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;

/**
 * Settings panel with value cycling, submenus, and a description footer.
 * The TUI analog of pi-tui's `SettingsList`. Pure renderer: the caller
 * owns `cursor`, the per-item `currentValue`, and which submenu (if any)
 * is open; this renderer only paints the snapshot. Cycling values and
 * opening submenus is the caller's job (key handler / live component),
 * mirroring how every other renderer in this namespace is a pure
 * node-in/frame-out mapping.
 *
 * Layout:
 *   › Theme          dark
 *     Model          gpt-4
 *     Verbose        on
 *
 *   Theme: color scheme used for the dashboard.
 *   ⏎ change · esc cancel
 *
 * Each setting is `{id, label, currentValue, values?, description?}`.
 * When `values` is non-empty, the value side shows the current option
 * (and the caller cycles on Enter/Space). When `submenu` is set, the
 * caller is responsible for swapping in a subtree — this renderer just
 * shows the current value with a `›` marker to hint it opens something.
 *
 * Node props (all optional):
 *  - `settings`   array  List of setting items.
 *  - `cursor`     int    Index of the highlighted setting. Default: 0.
 *  - `maxVisible` int    Rows to show before scrolling. Default: node height.
 *  - `showHint`   bool   Show the footer hint row. Default: true.
 *  - `showDescription` bool  Show the selected item's description. Default: true.
 *  - `hint`       string Override the hint text. Default: '⏎ change · esc cancel'.
 *  - `emptyText`  string Message when the list is empty. Default: 'No settings'.
 *  - `focused`    bool   Override focus. Default: context.focused().
 */
final class SettingsListRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
{
    /**
     * True only for `settings-list` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'settings-list';
    }

    /**
     * Draws the settings rows with their current values and the description of
     * the highlighted row underneath.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $settings = is_array($node->props['settings'] ?? null) ? $node->props['settings'] : [];
        $cursor = max(0, min(max(0, count($settings) - 1), (int) ($node->props['cursor'] ?? 0)));
        $showHint = (bool) ($node->props['showHint'] ?? true);
        $showDescription = (bool) ($node->props['showDescription'] ?? true);
        $hint = (string) ($node->props['hint'] ?? '⏎ change · esc cancel');
        $emptyText = (string) ($node->props['emptyText'] ?? 'No settings');
        $focused = (bool) ($node->props['focused'] ?? $context->focused($node));
        $width = $context->bounds->width;
        $height = $context->bounds->height;

        if ($settings === []) {
            $rows = [TuiString::padEnd(TuiString::truncate($emptyText, $width), $width)];
            while (count($rows) < $height) {
                $rows[] = str_repeat(' ', $width);
            }

            return $this->frame($width, $height, array_slice($rows, 0, $height));
        }

        $maxLabelWidth = min(30, max(1, ...array_map(
            static fn (array $s): int => TuiString::visibleLength((string) ($s['label'] ?? '')),
            $settings,
        )));

        $description = $showDescription ? (string) ($settings[$cursor]['description'] ?? '') : '';
        $descLines = $description !== '' ? explode("\n", TuiString::wordwrap($description, max(1, $width - 2))) : [];
        $footerRows = ($showHint ? 1 : 0) + ($description !== '' ? 1 + count($descLines) : 0);
        $listHeight = max(1, $height - $footerRows);
        $maxVisible = (int) ($node->props['maxVisible'] ?? $listHeight);
        $maxVisible = max(1, min($listHeight, $maxVisible));

        $offset = $this->scrollOffset($cursor, $maxVisible, count($settings));
        $visible = array_slice($settings, $offset, $maxVisible, true);

        $rows = [];
        foreach ($visible as $index => $setting) {
            $isCursor = $index === $cursor;
            $rows[] = $this->settingRow($setting, $isCursor, $maxLabelWidth, $width, $focused);
        }
        while (count($rows) < $listHeight) {
            $rows[] = str_repeat(' ', $width);
        }

        if ($description !== '') {
            $rows[] = '';
            foreach ($descLines as $descLine) {
                $rows[] = TuiString::padEnd('  ' . TuiString::truncate($descLine, max(1, $width - 2)), $width);
            }
        }

        if ($showHint) {
            $rows[] = TuiString::padEnd(TuiString::truncate($hint, $width), $width);
        }

        while (count($rows) < $height) {
            $rows[] = str_repeat(' ', $width);
        }

        return $this->frame($width, $height, array_slice($rows, 0, $height));
    }

    /**
     * @param array<string, mixed> $setting
     */
    private function settingRow(array $setting, bool $isCursor, int $maxLabelWidth, int $width, bool $focused): string
    {
        $marker = $isCursor ? '› ' : '  ';
        $label = (string) ($setting['label'] ?? '');
        $value = (string) ($setting['currentValue'] ?? '');
        $hasSubmenu = isset($setting['submenu']) && is_callable($setting['submenu']);
        $hasValues = isset($setting['values']) && is_array($setting['values']) && $setting['values'] !== [];

        $labelPart = TuiString::padEnd(TuiString::truncate($label, $maxLabelWidth), $maxLabelWidth);
        $separator = '  ';
        $used = TuiString::visibleLength($marker) + $maxLabelWidth + TuiString::visibleLength($separator);
        $valueWidth = max(1, $width - $used - 1);
        $valuePart = TuiString::truncate($value, $valueWidth);
        if ($hasSubmenu && TuiString::visibleLength($valuePart) < $valueWidth) {
            $valuePart .= ' ›';
        } elseif ($hasValues && TuiString::visibleLength($valuePart) < $valueWidth) {
            $valuePart .= ' ⋯';
        }

        $row = $marker . $labelPart . $separator . $valuePart;

        return TuiString::padEnd(TuiString::truncate($row, $width), $width);
    }

    private function scrollOffset(int $cursor, int $visible, int $total): int
    {
        if ($total <= $visible) {
            return 0;
        }
        $half = (int) floor($visible / 2);
        $offset = $cursor - $half;
        if ($offset < 0) {
            return 0;
        }
        if ($offset > $total - $visible) {
            return $total - $visible;
        }

        return $offset;
    }
}
