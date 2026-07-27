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

namespace Milpa\Live\Rendering;

use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Contracts\Tui\TerminalThemeInterface;
use Milpa\Live\Events\LiveEventEmitter;
use Milpa\Live\Rendering\ViewModel\DashboardViewModelFields;
use Milpa\Live\Tui\TerminalTheme;
use Milpa\Live\Tui\TuiString;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Renders a Live component to the terminal, the counterpart of the HTML
 * renderers in `milpa/live-web`. Same component definition, same state
 * snapshot, different target — this is the seam that lets one component
 * description serve both surfaces.
 */
final readonly class TuiComponentRenderer implements ComponentRendererInterface
{
    private TerminalThemeInterface $theme;

    /**
     * @var array<int, string>
     */
    private const SUPPORTED = [
        'autocomplete',
        'input',
        'textarea',
        'select',
        'checkbox',
        'dashboard-shell',
        'dashboard-sidebar',
        'dashboard-main',
        'dashboard-topbar',
        'dashboard-grid',
        'dashboard-panel',
        'dashboard-page-header',
        'dashboard-action-button',
        'dashboard-alert-list',
        'metric-card',
        'data-table',
    ];

    public function __construct(
        ?TerminalThemeInterface $theme = null,
        private ?MilpaEventDispatcherInterface $dispatcher = null,
    ) {
        $this->theme = $theme ?? new TerminalTheme();
    }

    /**
     * True only for {@see \Milpa\Live\ValueObjects\RenderTarget::TUI} — this renderer
     * produces terminal lines, not markup.
     */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::TUI;
    }

    /**
     * Renders one Live component to terminal lines, using the same component
     * definition and state snapshot the HTML renderers consume.
     */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $contract = $component::contract();
        if (!in_array($contract->name, self::SUPPORTED, true)) {
            throw new \InvalidArgumentException('TuiComponentRenderer does not support component: ' . $contract->name);
        }

        return LiveEventEmitter::withRendering(
            $this->dispatcher,
            $contract->name,
            $request,
            function () use ($component, $request, $contract): RenderResult {
                $state = $request->state ?? $component->mount($request->props, $request->context);
                $width = $this->width($request);
                $options = $request->options;

                $output = match ($contract->name) {
                    'autocomplete' => $this->autocomplete($state, $request->props, $width, $options),
                    'input', 'textarea', 'select', 'checkbox' => $this->field($contract->name, $state, $request->props, $width, $options),
                    'metric-card' => $this->metric($state, $request->props, $width, $options),
                    'data-table' => $this->dataTable($state, $request->props, $width, $options),
                    'dashboard-sidebar' => $this->sidebar($state, $request->props, $width, $options),
                    'dashboard-action-button' => $this->actionButton($state, $request->props, $width, $options),
                    'dashboard-alert-list' => $this->alertList($state, $request->props, $width, $options),
                    'dashboard-page-header', 'dashboard-topbar' => $this->header($state, $request->props, $width, $options),
                    'dashboard-shell', 'dashboard-main', 'dashboard-grid', 'dashboard-panel' => $this->container($contract->name, $state, $request->props, $width, $options),
                };

                return new RenderResult(
                    output: $output,
                    state: $state,
                    assets: [
                        'runtime' => 'terminal',
                        'keys' => ['up', 'down', 'enter', 'esc', 'tab'],
                    ],
                    format: RenderTarget::TUI,
                );
            },
        );
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $options
     */
    private function autocomplete(StateSnapshot $state, array $props, int $width, array $options): string
    {
        $label = $this->text($props['label'] ?? $state->meta['label'] ?? 'Autocomplete');
        $source = $this->text($props['source'] ?? $state->meta['source'] ?? '');
        $query = $this->text($state->data['query'] ?? '');
        $selected = $this->itemList($state->data['selected'] ?? []);
        $items = $this->itemList($state->data['items'] ?? $props['staticItems'] ?? []);
        $multiple = (bool) ($state->meta['multiple'] ?? false);

        $lines = [
            $this->theme->style($label, 'title') . ($source !== '' ? ' <' . $source . '>' : ''),
            'Mode: ' . ($multiple ? 'multiple' : 'single'),
            'Query: ' . ($query !== '' ? $query : '(empty)'),
        ];

        if ($selected !== []) {
            $lines[] = 'Selected: ' . implode(', ', array_map($this->label(...), $selected));
        }

        if (($state->data['error'] ?? null) !== null) {
            $lines[] = $this->theme->style('Error: ' . $this->text($state->data['error']), 'error');
        }

        if ($items !== []) {
            $lines[] = 'Suggestions:';
            $cursor = max(0, (int) ($options['cursor'] ?? 0));
            foreach ($items as $index => $item) {
                $marker = $this->focused($options) && $index === $cursor ? '>' : ' ';
                $lines[] = ' ' . $marker . ' ' . ($index + 1) . '. ' . $this->label($item) . $this->valueSuffix($item);
            }
        } elseif ((bool) ($state->data['open'] ?? false)) {
            $lines[] = $this->theme->style('No suggestions', 'muted');
        }

        $lines[] = $this->theme->style('Actions: search, select, remove, clear', 'muted');

        return $this->box('autocomplete', $lines, $width, $this->focused($options));
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $options
     */
    private function field(string $name, StateSnapshot $state, array $props, int $width, array $options): string
    {
        $label = $this->text($props['label'] ?? $state->meta['label'] ?? $state->meta['name'] ?? $state->componentId);
        $required = (bool) ($state->meta['required'] ?? false);
        $disabled = (bool) ($state->meta['disabled'] ?? false);
        $hint = $this->text($state->meta['hint'] ?? '');
        $error = $this->text($state->data['error'] ?? '');
        $value = $name === 'checkbox'
            ? ((bool) ($state->data['checked'] ?? false) ? $this->theme->symbol('selected') : $this->theme->symbol('unselected'))
            : $this->text($state->data['value'] ?? '');

        $title = $label . ($required ? ' *' : '');
        $lines = [];

        if ($name === 'checkbox') {
            $lines[] = '[' . $value . '] ' . $title;
        } elseif ($name === 'select') {
            $lines[] = $title . ': [' . ($value !== '' ? $value : '(none)') . ']';
            $cursor = max(0, (int) ($options['cursor'] ?? 0));
            $fieldOptions = $this->options($state->meta['options'] ?? []);
            foreach ($fieldOptions as $index => $option) {
                $selected = $option['value'] === $value;
                $cursorMarker = $this->focused($options) && $index === $cursor ? '>' : ' ';
                $marker = $selected ? '*' : $cursorMarker;
                $disabledSuffix = $option['disabled'] ? ' (disabled)' : '';
                $lines[] = ' ' . $marker . ' ' . $option['label'] . $disabledSuffix;
            }
        } elseif ($name === 'textarea') {
            $lines[] = $title . ':';
            $lines[] = $value !== '' ? $value : '(empty)';
        } else {
            $type = $this->text($state->meta['type'] ?? 'text');
            $lines[] = $title . ' [' . $type . ']: ' . ($value !== '' ? $value : '(empty)');
        }

        if ($hint !== '') {
            $lines[] = $this->theme->style($hint, 'muted');
        }

        if ($disabled) {
            $lines[] = $this->theme->style('Disabled', 'muted');
        }

        if ($error !== '') {
            $lines[] = $this->theme->style('Error: ' . $error, 'error');
        }

        return $this->box($name, $lines, $width, $this->focused($options));
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $options
     */
    private function metric(StateSnapshot $state, array $props, int $width, array $options): string
    {
        $title = $this->text(DashboardViewModelFields::string($state->meta, $props, 'title', 'Metric'));
        $value = $this->text($state->data['value'] ?? $props['value'] ?? '');
        $delta = $this->text($state->data['delta'] ?? $props['delta'] ?? '');
        $trend = $this->text($state->data['trend'] ?? $props['trend'] ?? 'neutral');
        $caption = $this->text(DashboardViewModelFields::string($state->meta, $props, 'caption'));

        $trendRole = match ($trend) {
            'up', 'positive', 'success' => 'success',
            'down', 'negative', 'error' => 'error',
            default => 'muted',
        };
        $trendSymbol = match ($trendRole) {
            'success' => $this->theme->symbol('trend-up'),
            'error' => $this->theme->symbol('trend-down'),
            default => '-',
        };

        $lines = [
            $this->theme->style($title, 'title'),
            trim($value . '  ' . ($delta !== '' ? $this->theme->style($trendSymbol . ' ' . $delta, $trendRole) : '')),
        ];

        if ($caption !== '') {
            $lines[] = $this->theme->style($caption, 'muted');
        }

        return $this->box('metric-card', $lines, $width, $this->focused($options));
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $options
     */
    private function dataTable(StateSnapshot $state, array $props, int $width, array $options): string
    {
        $caption = $this->text($state->meta['caption'] ?? $props['caption'] ?? 'Data table');
        $columns = $this->columns($state->meta['columns'] ?? []);
        $rows = $this->rows($state->meta['rows'] ?? []);
        $selectable = (bool) ($state->meta['selectable'] ?? false);
        $selected = $this->stringList($state->data['selectedRows'] ?? []);

        if ($columns === [] && $rows !== []) {
            foreach (array_keys($rows[0]) as $key) {
                if ($key !== 'id') {
                    $columns[] = ['key' => (string) $key, 'label' => ucfirst((string) $key), 'align' => 'left'];
                }
            }
        }

        $focused = $this->focused($options);
        $cursor = max(0, (int) ($options['cursor'] ?? 0));
        $headers = $focused ? [['key' => '__focus', 'label' => '', 'align' => 'left']] : [];
        $headers = $selectable ? [...$headers, ['key' => '__selected', 'label' => '', 'align' => 'left'], ...$columns] : [...$headers, ...$columns];
        $tableRows = [];
        foreach ($rows as $index => $row) {
            $rowId = $this->rowId($row);
            $cells = [];
            if ($focused) {
                $cells['__focus'] = $index === $cursor ? '>' : ' ';
            }
            if ($selectable) {
                $cells['__selected'] = '[' . (in_array($rowId, $selected, true) ? $this->theme->symbol('selected') : ' ') . ']';
            }

            foreach ($columns as $column) {
                $key = $column['key'];
                $cells[$key] = $this->text($row[$key] ?? '');
            }
            $tableRows[] = $cells;
        }

        $lines = [$this->theme->style($caption, 'title')];
        if ($headers === []) {
            $lines[] = $this->theme->style('No columns', 'muted');
        } else {
            array_push($lines, ...$this->table($headers, $tableRows, max(24, $width - 4)));
        }

        $sortBy = $this->text($state->data['sortBy'] ?? '');
        if ($sortBy !== '') {
            $lines[] = $this->theme->style('Sorted by ' . $sortBy . ' ' . $this->text($state->data['sortDirection'] ?? 'asc'), 'muted');
        }

        return $this->box('data-table', $lines, $width, $focused);
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $options
     */
    private function sidebar(StateSnapshot $state, array $props, int $width, array $options): string
    {
        $brand = $this->text(DashboardViewModelFields::string($state->meta, $props, 'brand', 'Milpa'));
        $active = $this->text(DashboardViewModelFields::string($state->meta, $props, 'active'));
        $items = DashboardViewModelFields::list($state->meta, $props, 'items');
        $lines = [$this->theme->style($brand, 'title')];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = $this->text($item['key'] ?? '');
            $label = $this->text($item['label'] ?? $key);
            $marker = $key === $active ? '>' : ' ';
            $lines[] = $marker . ' ' . $label;
        }

        $children = $this->children($props);
        if ($children !== []) {
            array_push($lines, '', ...$children);
        }

        return $this->box('sidebar', $lines, $width, $this->focused($options));
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $options
     */
    private function actionButton(StateSnapshot $state, array $props, int $width, array $options): string
    {
        $label = $this->text(DashboardViewModelFields::string($state->meta, $props, 'label', 'Action'));
        $variant = $this->text(DashboardViewModelFields::string($state->meta, $props, 'variant', 'ghost'));

        return $this->box('action-button', ['[' . $label . '] ' . $this->theme->style($variant, 'muted')], $width, $this->focused($options));
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $options
     */
    private function alertList(StateSnapshot $state, array $props, int $width, array $options): string
    {
        $items = DashboardViewModelFields::list($state->meta, $props, 'items');
        $lines = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $count = $this->text($item['count'] ?? '');
            $text = $this->text($item['text'] ?? '');
            $lines[] = $this->theme->symbol('warning') . ' ' . trim($count . ' ' . $text);
        }

        if ($lines === []) {
            $lines[] = $this->theme->style('No alerts', 'muted');
        }

        return $this->box('alerts', $lines, $width, $this->focused($options));
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $options
     */
    private function header(StateSnapshot $state, array $props, int $width, array $options): string
    {
        $title = $this->text(DashboardViewModelFields::string($state->meta, $props, 'title', $state->componentId));
        $eyebrow = $this->text(DashboardViewModelFields::string($state->meta, $props, 'eyebrow'));
        $description = $this->text(DashboardViewModelFields::string($state->meta, $props, 'description'));
        $lines = [];

        if ($eyebrow !== '') {
            $lines[] = $this->theme->style($eyebrow, 'accent');
        }
        $lines[] = $this->theme->style($title, 'title');
        if ($description !== '') {
            $lines[] = $description;
        }

        $children = $this->children($props);
        if ($children !== []) {
            array_push($lines, '', ...$children);
        }

        return $this->box('header', $lines, $width, $this->focused($options));
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $options
     */
    private function container(string $name, StateSnapshot $state, array $props, int $width, array $options): string
    {
        $title = $this->text(DashboardViewModelFields::string($state->meta, $props, 'title', $name));
        $description = $this->text(DashboardViewModelFields::string($state->meta, $props, 'description'));
        $lines = [];

        if ($description !== '') {
            $lines[] = $description;
        }

        $children = $this->children($props);
        if ($children !== []) {
            array_push($lines, ...$children);
        }

        if ($lines === []) {
            $lines[] = $this->theme->style('Ready', 'muted');
        }

        return $this->box($title !== '' ? $title : $name, $lines, $width, $this->focused($options));
    }

    /**
     * @param array<int, array{key: string, label: string, align: string}> $columns
     * @param array<int, array<string, string>>                            $rows
     *
     * @return array<int, string>
     */
    private function table(array $columns, array $rows, int $width): array
    {
        $count = max(1, count($columns));
        $available = max($count * 4, $width - (3 * $count + 1));
        $base = max(4, intdiv($available, $count));
        $widths = [];

        foreach ($columns as $column) {
            $widths[$column['key']] = min(24, max(4, $this->visibleLength($column['label']), $base));
        }

        while (array_sum($widths) + (3 * $count + 1) > $width) {
            $largestKey = array_keys($widths, max($widths), true)[0];
            if ($widths[$largestKey] <= 4) {
                break;
            }
            $widths[$largestKey]--;
        }

        $header = [];
        foreach ($columns as $column) {
            $header[] = $this->pad($this->fit($column['label'], $widths[$column['key']]), $widths[$column['key']]);
        }

        $lines = [
            '| ' . implode(' | ', $header) . ' |',
            '+-' . implode('-+-', array_map(static fn (int $size): string => str_repeat('-', $size), $widths)) . '-+',
        ];

        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $cells[] = $this->pad($this->fit($row[$column['key']] ?? '', $widths[$column['key']]), $widths[$column['key']]);
            }
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
        }

        if ($rows === []) {
            $lines[] = '| ' . $this->fit('No rows', max(4, $width - 4)) . ' |';
        }

        return $lines;
    }

    /**
     * @param array<int, string> $lines
     */
    private function box(string $title, array $lines, int $width, bool $focused = false): string
    {
        $inner = max(20, $width - 4);
        $border = '+' . str_repeat('-', $inner + 2) . '+';
        $title = $focused ? '> ' . $title : '  ' . $title;
        $output = [$border];
        $output[] = '| ' . $this->pad($this->fit($focused ? $this->theme->style($title, 'selected') : $title, $inner), $inner) . ' |';
        $output[] = $border;

        foreach ($this->wrapLines($lines, $inner) as $line) {
            $output[] = '| ' . $this->pad($line, $inner) . ' |';
        }

        $output[] = $border;

        return implode(PHP_EOL, $output);
    }

    /**
     * @param array<int, string> $lines
     *
     * @return array<int, string>
     */
    private function wrapLines(array $lines, int $width): array
    {
        $wrapped = [];
        foreach ($lines as $line) {
            $normalized = str_replace(["\r\n", "\r"], "\n", $line);
            foreach (explode("\n", $normalized) as $part) {
                if ($part === '') {
                    $wrapped[] = '';
                    continue;
                }

                if ($this->visibleLength($part) <= $width) {
                    $wrapped[] = $part;
                    continue;
                }

                $chunks = explode("\n", TuiString::wordwrap($this->stripAnsi($part), $width));
                array_push($wrapped, ...$chunks);
            }
        }

        return $wrapped;
    }

    private function fit(string $text, int $width): string
    {
        $plain = $this->stripAnsi($this->text($text));
        if ($this->visibleLength($plain) <= $width) {
            return $text;
        }

        return TuiString::truncate($plain, $width, '~');
    }

    private function pad(string $text, int $width): string
    {
        return $text . str_repeat(' ', max(0, $width - $this->visibleLength($text)));
    }

    /**
     * Mb-aware visible-column width, delegating to {@see TuiString} --
     * this used to be `strlen($this->stripAnsi($text))`, i.e. byte length,
     * which mis-measured (and therefore mis-padded/mis-wrapped) any
     * multibyte label. Kept as a thin wrapper rather than inlined so every
     * existing call site in this class keeps working unchanged.
     */
    private function visibleLength(string $text): int
    {
        return TuiString::visibleLength($text);
    }

    private function stripAnsi(string $text): string
    {
        return TuiString::stripAnsi($text);
    }

    private function width(RenderRequest $request): int
    {
        $width = (int) ($request->options['width'] ?? 80);

        return max(40, min(160, $width));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function focused(array $options): bool
    {
        return (bool) ($options['focused'] ?? false);
    }

    private function text(mixed $value): string
    {
        $text = strip_tags((string) $value);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param array<string, mixed> $props
     *
     * @return array<int, string>
     */
    private function children(array $props): array
    {
        $children = (string) ($props['childrenOutput'] ?? $props['childrenHtml'] ?? '');
        if (trim($children) === '') {
            return [];
        }

        $lines = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", strip_tags($children))) as $line) {
            $lines[] = rtrim($line);
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function label(array $item): string
    {
        return $this->text($item['label'] ?? $item['value'] ?? '');
    }

    /**
     * @param array<string, mixed> $item
     */
    private function valueSuffix(array $item): string
    {
        $value = $this->text($item['value'] ?? '');

        return $value !== '' && $value !== $this->label($item) ? ' [' . $value . ']' : '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function itemList(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * @return array<int, array{value: string, label: string, disabled: bool}>
     */
    private function options(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $options = [];
        foreach ($raw as $key => $option) {
            if (is_array($option)) {
                $options[] = [
                    'value' => $this->text($option['value'] ?? $key),
                    'label' => $this->text($option['label'] ?? $option['value'] ?? $key),
                    'disabled' => (bool) ($option['disabled'] ?? false),
                ];
            }
        }

        return $options;
    }

    /**
     * @return array<int, array{key: string, label: string, align: string}>
     */
    private function columns(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $columns = [];
        foreach ($raw as $key => $column) {
            if (is_array($column)) {
                $columns[] = [
                    'key' => $this->text($column['key'] ?? $key),
                    'label' => $this->text($column['label'] ?? $column['key'] ?? $key),
                    'align' => $this->text($column['align'] ?? 'left'),
                ];
            }
        }

        return $columns;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, 'is_array'));
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_map('strval', array_filter($raw, static fn (mixed $value): bool => $value !== null && $value !== '')));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowId(array $row): string
    {
        foreach (['id', 'key', 'value', 'name'] as $key) {
            if (isset($row[$key])) {
                return (string) $row[$key];
            }
        }

        return sha1(json_encode($row, JSON_THROW_ON_ERROR));
    }
}
