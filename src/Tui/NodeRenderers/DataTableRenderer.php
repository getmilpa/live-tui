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
 * Tabular data view with headers, per-row cursor, vertical scrolling,
 * optional row selection, and per-row action hints. Implements the
 * table the spec COA §3.2 asks for (plugin list with columns Name /
 * Status / Contracts / Action) as a pure node renderer.
 *
 * Layout:
 *   ┌──────────────────────────────────────────────────────────────┐
 *   │  Name           │ Status    │ Contracts           │ Action     │  <- header row
 *   ├──────────────────────────────────────────────────────────────┤
 *   │  BlogEngine     │ ● active  │ IBlog, ISecurity   │ edit logs  │  <- cursor row
 *   │  EcommerceCore  │ ✕ error   │ IPayment, ICur     │ fix docs   │
 *   └──────────────────────────────────────────────────────────────┘
 *
 * Column widths are derived from the declared `columns` (each may set
 * `width` absolute or `flex` for proportional; default flex=1). The
 * cursor row is marked with `›`; selected rows with `✓`. Vertical
 * scrolling follows the cursor the same way as `SelectListRenderer`.
 *
 * Node props (all optional):
 *  - `columns` array  `{key, label, width?, flex?, align?}|.
 *  - `rows`    array  Each row is `{id?, <colKey>: value, …}`; `id` is used for selection.
 *  - `cursor`  int    Index into the (filtered) row list. Default: 0.
 *  - `filter`  string Substring filter (matches any cell). Default: ''.
 *  - `selected` array  Row ids marked as selected. Default: [].
 *  - `caption` string Table title shown in the top border. Default: ''.
 *  - `showHeader` bool Show the header row. Default: true.
 *  - `actions` array  Per-row action labels `{key, label}|; rendered in the last column. Default: [].
 *  - `focused` bool   Override focus. Default: context.focused().
 *  - `emptyText` string Message when no rows match. Default: 'No rows'.
 */
final class DataTableRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
{
    /**
     * True only for `data-table` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'data-table';
    }

    /**
     * Draws the header, the visible slice of rows and the cursor marker. Column
     * widths come from the declared `columns`; a row taller than the bounds
     * scrolls rather than overflowing.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $columns = $this->columns(is_array($node->props['columns'] ?? null) ? $node->props['columns'] : []);
        $rows = $this->filterRows(is_array($node->props['rows'] ?? null) ? $node->props['rows'] : [], $columns, (string) ($node->props['filter'] ?? ''));
        $cursor = max(0, min(max(0, count($rows) - 1), (int) ($node->props['cursor'] ?? 0)));
        $selected = is_array($node->props['selected'] ?? null) ? array_map('strval', $node->props['selected']) : [];
        $caption = (string) ($node->props['caption'] ?? '');
        $showHeader = (bool) ($node->props['showHeader'] ?? true);
        $emptyText = (string) ($node->props['emptyText'] ?? 'No rows');
        $actions = is_array($node->props['actions'] ?? null) ? $node->props['actions'] : [];
        $focused = (bool) ($node->props['focused'] ?? $context->focused($node));
        $width = $context->bounds->width;
        $height = $context->bounds->height;

        if ($columns === [] || $height < 1) {
            return $this->frame($width, $height, array_fill(0, $height, str_repeat(' ', $width)));
        }

        $colWidths = $this->allocateColumns($columns, $width, $actions !== []);
        $rowsOut = [];

        if ($caption !== '') {
            $rowsOut[] = $this->captionRow($caption, $width, $focused);
        }
        if ($showHeader) {
            $rowsOut[] = $this->headerRow($columns, $colWidths, $width);
            $rowsOut[] = str_repeat('─', $width);
        }

        if ($rows === []) {
            $rowsOut[] = TuiString::padEnd(TuiString::truncate($emptyText, $width), $width);
            while (count($rowsOut) < $height) {
                $rowsOut[] = str_repeat(' ', $width);
            }

            return $this->frame($width, $height, array_slice($rowsOut, 0, $height));
        }

        $bodyStart = count($rowsOut);
        $bodyHeight = $height - $bodyStart;
        $offset = $this->scrollOffset($cursor, $bodyHeight, count($rows));
        $visible = array_slice($rows, $offset, $bodyHeight, true);

        foreach ($visible as $index => $row) {
            $isCursor = $index === $cursor;
            $rowId = (string) ($row['id'] ?? '');
            $isSelected = $rowId !== '' && in_array($rowId, $selected, true);
            $rowsOut[] = $this->dataRow($columns, $colWidths, $row, $isCursor, $isSelected, $actions, $width);
        }

        while (count($rowsOut) < $height) {
            $rowsOut[] = str_repeat(' ', $width);
        }

        return $this->frame($width, $height, array_slice($rowsOut, 0, $height));
    }

    /**
     * @param array<int, array<string, mixed>> $raw
     *
     * @return array<int, array{key: string, label: string, width: int|null, flex: int, align: string}>
     */
    private function columns(array $raw): array
    {
        $out = [];
        foreach ($raw as $col) {
            if (!is_array($col)) {
                continue;
            }
            $out[] = [
                'key' => (string) ($col['key'] ?? ''),
                'label' => (string) ($col['label'] ?? $col['key'] ?? ''),
                'width' => isset($col['width']) && $col['width'] > 0 ? (int) $col['width'] : null,
                'flex' => max(1, (int) ($col['flex'] ?? 1)),
                'align' => (string) ($col['align'] ?? 'left'),
            ];
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     *
     * @return array<int, int>
     */
    private function allocateColumns(array $columns, int $totalWidth, bool $hasActions): array
    {
        $sepCount = count($columns) - 1 + ($hasActions ? 1 : 0);
        $markerCol = 2;
        $available = max(0, $totalWidth - $markerCol - ($sepCount * 3));
        $fixed = 0;
        $flex = 0;
        foreach ($columns as $col) {
            if ($col['width'] !== null) {
                $fixed += $col['width'];
            } else {
                $flex += $col['flex'];
            }
        }
        $actionWidth = $hasActions ? 12 : 0;
        $remaining = max(0, $available - $fixed - $actionWidth);

        $widths = [];
        foreach ($columns as $col) {
            $widths[] = $col['width'] ?? (int) floor($remaining * ($col['flex'] / max(1, $flex)));
        }

        return $widths;
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterRows(array $rows, array $columns, string $filter): array
    {
        if ($filter === '') {
            return array_values($rows);
        }
        $needle = mb_strtolower($filter, 'UTF-8');

        return array_values(array_filter($rows, static function (array $row) use ($columns, $needle): bool {
            $haystack = '';
            foreach ($columns as $col) {
                // The same rendering the cell gets, or the filter would search
                // text the user never sees: a false cell shows as "false" but
                // contributed '' here, so filtering for it found nothing.
                $haystack .= ' ' . self::cell($row[$col['key']] ?? null, array_key_exists($col['key'], $row));
            }

            return str_contains(mb_strtolower($haystack, 'UTF-8'), $needle);
        }));
    }

    private function scrollOffset(int $cursor, int $visible, int $total): int
    {
        if ($total <= $visible || $visible < 1) {
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

    private function captionRow(string $caption, int $width, bool $focused): string
    {
        $marker = $focused ? '› ' : '  ';
        $line = $marker . $caption;

        return TuiString::padEnd(TuiString::truncate($line, $width), $width);
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     * @param array<int, int>                  $colWidths
     */
    private function headerRow(array $columns, array $colWidths, int $width): string
    {
        $row = '  ';
        foreach ($columns as $index => $col) {
            $cell = TuiString::truncate($col['label'], $colWidths[$index] ?? 0);
            $row .= TuiString::padEnd($cell, $colWidths[$index] ?? 0) . ' │ ';
        }

        return TuiString::padEnd(TuiString::truncate(self::withoutTrailingSeparator($row), $width), $width);
    }

    /**
     * Strips the trailing column separator.
     *
     * `rtrim($row, ' │ ')` cannot do this: rtrim's second argument is a set of
     * BYTES, and `│` is three of them (E2 94 82). Any cell value ending in one
     * of those bytes gets eaten too — an em dash is E2 80 94, so rtrim ate its
     * final 94 and left a broken E2 80 behind, which took the row's visible
     * width to zero and blew its length past the frame.
     */
    private static function withoutTrailingSeparator(string $row): string
    {
        $separator = ' │ ';

        while (str_ends_with($row, $separator)) {
            $row = substr($row, 0, -strlen($separator));
        }

        return rtrim($row, ' ');
    }

    /**
     * Renders one cell without losing what it was.
     *
     * A plain `(string)` cast destroys information a data table exists to
     * show: PHP renders `false` as `''`, which is indistinguishable from
     * `null`, from an empty string and from a column the row does not carry.
     * Booleans and null get literal, language-free forms so the host — which
     * owns the wording — can still override by passing strings of its own.
     */
    private static function cell(mixed $value, bool $present): string
    {
        if (!$present) {
            return '';
        }

        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '—',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     * @param array<int, int>                  $colWidths
     * @param array<string, mixed>             $row
     * @param array<int, array<string, mixed>> $actions
     */
    private function dataRow(array $columns, array $colWidths, array $row, bool $isCursor, bool $isSelected, array $actions, int $width): string
    {
        $marker = $isCursor ? '› ' : '  ';
        $check = $isSelected ? '✓' : ' ';
        $rowOut = $marker . $check;

        foreach ($columns as $index => $col) {
            $value = self::cell($row[$col['key']] ?? null, array_key_exists($col['key'], $row));
            $cellWidth = $colWidths[$index] ?? 0;
            $cell = TuiString::truncate($value, $cellWidth);
            $rowOut .= TuiString::padEnd($cell, $cellWidth) . ' │ ';
        }

        if ($actions !== []) {
            $actionLabels = array_map(static fn (array $a): string => (string) ($a['label'] ?? ''), $actions);
            $rowOut .= implode(' ', $actionLabels);
        }

        return TuiString::padEnd(TuiString::truncate(self::withoutTrailingSeparator($rowOut), $width), $width);
    }
}
