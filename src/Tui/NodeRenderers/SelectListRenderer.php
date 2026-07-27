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
 * Interactive selection list with keyboard navigation, filtering, and
 * vertical scrolling. The TUI analog of pi-tui's `SelectList`. Pure
 * renderer: the caller owns the `cursor`, `filter`, and `selected` state
 * and mutates them from a `RetainedTuiLoop` key handler or a live
 * component — this renderer only paints the current snapshot.
 *
 * Each item is `{value, label, description?}`. The list shows up to
 * `maxVisible` rows; when the cursor moves outside the visible window
 * the renderer scrolls to keep it centered. A `filter` prop hides items
 * whose label/value/description do not contain the substring (case-
 * insensitive). The selected item is marked with `›` (or `>` if the
 * theme does not supply that symbol), unselected with ` `.
 *
 * Node props (all optional):
 *  - `items`        array  List of `{value, label, description?}|.
 *  - `cursor`       int    Index into the *filtered* list. Default: 0.
 *  - `filter`       string Filter substring. Default: '' (no filter).
 *  - `maxVisible`   int    Rows to show before scrolling. Default: node height.
 *  - `selected`     array  Values marked as selected (multi-select). Default: [].
 *  - `emptyText`    string Message when the filtered list is empty. Default: 'No matches'.
 *  - `showFilter`   bool   Show the filter row at the top. Default: true.
 *  - `focused`      bool   Override focus. Default: context.focused().
 */
final class SelectListRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
{
    /**
     * True only for `select-list` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'select-list';
    }

    /**
     * Draws the filtered options with the cursor marker, scrolling the window to
     * keep the cursor visible.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $items = $this->filterItems(is_array($node->props['items'] ?? null) ? $node->props['items'] : [], (string) ($node->props['filter'] ?? ''));
        $cursor = max(0, min(max(0, count($items) - 1), (int) ($node->props['cursor'] ?? 0)));
        $selected = is_array($node->props['selected'] ?? null) ? array_map('strval', $node->props['selected']) : [];
        $showFilter = (bool) ($node->props['showFilter'] ?? true);
        $emptyText = (string) ($node->props['emptyText'] ?? 'No matches');
        $focused = (bool) ($node->props['focused'] ?? $context->focused($node));
        $width = $context->bounds->width;
        $height = $context->bounds->height;

        $rows = [];
        $headerRows = 0;
        if ($showFilter) {
            $filterText = (string) ($node->props['filter'] ?? '');
            $rows[] = $this->filterRow($filterText, $width, $focused);
            $headerRows = 1;
        }

        $listHeight = $height - $headerRows;
        $maxVisible = (int) ($node->props['maxVisible'] ?? $listHeight);
        $maxVisible = max(1, min($listHeight, $maxVisible));

        if ($items === []) {
            $rows[] = TuiString::padEnd(TuiString::truncate($emptyText, $width), $width);
            while (count($rows) < $height) {
                $rows[] = str_repeat(' ', $width);
            }

            return $this->frame($width, $height, $rows);
        }

        $offset = $this->scrollOffset($cursor, $maxVisible, count($items));
        $visible = array_slice($items, $offset, $maxVisible, true);

        foreach ($visible as $index => $item) {
            $isCursor = $index === $cursor;
            $isSelected = in_array((string) ($item['value'] ?? ''), $selected, true);
            $rows[] = $this->itemRow($item, $isCursor, $isSelected, $width, $focused);
        }

        while (count($rows) < $height) {
            $rows[] = str_repeat(' ', $width);
        }

        return $this->frame($width, $height, array_slice($rows, 0, $height));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterItems(array $items, string $filter): array
    {
        if ($filter === '') {
            return array_values($items);
        }
        $needle = mb_strtolower($filter, 'UTF-8');

        return array_values(array_filter($items, static function (array $item) use ($needle): bool {
            $haystack = mb_strtolower(
                (string) ($item['label'] ?? '') . ' ' . (string) ($item['value'] ?? '') . ' ' . (string) ($item['description'] ?? ''),
                'UTF-8',
            );

            return str_contains($haystack, $needle);
        }));
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

    /**
     * @param array<string, mixed> $item
     */
    private function itemRow(array $item, bool $isCursor, bool $isSelected, int $width, bool $focused): string
    {
        $marker = $isCursor ? '› ' : '  ';
        $check = $isSelected ? '✓ ' : '  ';
        $label = (string) ($item['label'] ?? $item['value'] ?? '');
        $description = (string) ($item['description'] ?? '');
        $labelWidth = $width - 4 - ($description !== '' ? 1 + TuiString::visibleLength(TuiString::truncate($description, max(0, $width - 4 - TuiString::visibleLength($label) - 1))) : 0);
        $labelPart = TuiString::truncate($label, max(1, $labelWidth));
        $row = $marker . $check . $labelPart;
        if ($description !== '') {
            $remaining = $width - TuiString::visibleLength($row);
            if ($remaining > 1) {
                $row .= ' ' . TuiString::truncate($description, $remaining - 1);
            }
        }

        return TuiString::padEnd(TuiString::truncate($row, $width), $width);
    }

    private function filterRow(string $filter, int $width, bool $focused): string
    {
        $prefix = $focused ? '› /' : '  /';
        $text = $filter !== '' ? $filter : '';
        $prompt = $filter === '' ? 'filter…' : '';
        $content = $text !== '' ? $text : $prompt;
        $row = $prefix . $content;
        $row = TuiString::truncate($row, $width);

        return TuiString::padEnd($row, $width);
    }
}
