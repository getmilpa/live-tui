<?php

declare(strict_types=1);

namespace Milpa\Live\Tui;

use Milpa\Live\Contracts\Rendering\ComponentRendererRegistryInterface;
use Milpa\Live\Contracts\Tui\FocusManagerInterface;
use Milpa\Live\Contracts\Tui\TerminalInterface;
use Milpa\Live\Contracts\Tui\TuiStateManagerInterface;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The component-driven loop: mounts Live components, dispatches keys into their
 * state, and re-renders the whole screen each tick. Simpler and more wasteful
 * than {@see RetainedTuiLoop}, which diffs instead of repainting.
 */
final class InteractiveTuiLoop
{
    /**
     * @var array<string, TuiComponentInstance>
     */
    private array $instances = [];

    private FocusManagerInterface $focus;

    private bool $running = true;

    private string $status = 'Ready';

    /**
     * @param array<int, TuiComponentInstance> $instances
     */
    public function __construct(
        private readonly ComponentRendererRegistryInterface $renderers,
        array $instances,
        private readonly ?TuiStateManagerInterface $stateManager = null,
        private int $width = 88,
        private readonly string $title = 'Milpa TUI',
    ) {
        foreach ($instances as $instance) {
            $this->instances[$instance->id] = $instance;
        }

        $this->focus = new FocusManager(array_keys($this->instances));
        $this->restoreSession();
    }

    /**
     * The id of the node holding focus, or null when nothing does.
     */
    public function focusedId(): ?string
    {
        return $this->focus->currentId();
    }

    public function running(): bool
    {
        return $this->running;
    }

    /**
     * The full screen as a string, ready to be written.
     */
    public function renderScreen(): string
    {
        $lines = [
            $this->header(),
        ];

        $renderer = $this->renderers->resolve(RenderTarget::TUI);
        if ($renderer === null) {
            throw new \RuntimeException('No renderer registered for the TUI render target.');
        }

        foreach ($this->instances as $id => $instance) {
            $state = $instance->mountedState();
            $rendered = $renderer->render($instance->component, new RenderRequest(
                context: $instance->context,
                props: $instance->props,
                state: $state,
                target: RenderTarget::TUI,
                options: [
                    'width' => $this->width,
                    'focused' => $id === $this->focus->currentId(),
                    'cursor' => $instance->cursor,
                ],
            ));

            $instance->state = $rendered->state ?? $state;
            $lines[] = $rendered->output;
        }

        $lines[] = $this->footer();

        return implode(PHP_EOL . PHP_EOL, $lines);
    }

    /**
     * Routes a key to the focused component, returning whether it was consumed.
     */
    public function dispatchKey(string $key): bool
    {
        $key = $this->normalizeKey($key);

        if ($key === 'tab') {
            $this->focusNext();
        } elseif ($key === 'shift-tab') {
            $this->focusPrevious();
        } elseif (in_array($key, ['up', 'k'], true)) {
            $this->moveOrFocus(-1);
        } elseif (in_array($key, ['down', 'j'], true)) {
            $this->moveOrFocus(1);
        } elseif ($key === 'enter') {
            $this->activateFocused();
        } elseif ($key === 'space') {
            if (!$this->typeIntoFocused($key)) {
                $this->activateFocused();
            }
        } elseif ($key === 'c') {
            $this->clearFocused();
        } elseif ($key === 'r') {
            $this->status = 'Refreshed';
        } elseif (in_array($key, ['q', 'escape'], true)) {
            $this->quit();
        } elseif (!$this->typeIntoFocused($key) && $key !== '') {
            $this->status = 'Unhandled key: ' . $key;
        }

        $this->saveSession();

        return $this->running;
    }

    /**
     * Bytes already polled from the terminal and not yet turned into keys.
     */
    private string $pendingChunk = '';

    /**
     * Runs the loop against a {@see TerminalInterface} instead of raw streams.
     *
     * Two things had to change to get here, and both are the cost of this loop
     * not being retained:
     *
     * 1. Key assembly reads from a CHUNK, not a stream. The byte-at-a-time
     *    reads become slices off `$pendingChunk`, keeping the same three-byte
     *    escape semantics this loop has always had.
     * 2. Painting moved OUT of the top of the loop. Polling means many ticks
     *    with no key, and this loop repaints in full — leaving the paint where
     *    it was would redraw the whole screen on every idle tick.
     *
     * @param int $idleMicroseconds How long to idle when a tick produced no key.
     */
    public function runOn(TerminalInterface $terminal, int $idleMicroseconds = 100000): void
    {
        $pushed = '';
        $terminal->start(
            static function (string $bytes) use (&$pushed): void {
                $pushed .= $bytes;
            },
            static function (): void {
            },
        );

        try {
            $this->paintTo($terminal);

            while ($this->running) {
                if ($this->pendingChunk === '') {
                    $this->pendingChunk = $pushed . $terminal->pollInput();
                    $pushed = '';
                }

                if ($this->pendingChunk === '') {
                    // This is what atEndOfInput() exists for: telling "nothing
                    // right now" apart from "nothing ever again". Without it a
                    // finite stream would spin here forever.
                    if ($terminal->atEndOfInput()) {
                        break;
                    }

                    if ($idleMicroseconds > 0) {
                        usleep($idleMicroseconds);
                    }

                    continue;
                }

                $key = $this->nextKeyFromChunk();
                if ($key === '') {
                    break;
                }

                $this->dispatchKey($key);
                $this->paintTo($terminal);
            }
        } finally {
            $terminal->stop();
        }
    }

    private function paintTo(TerminalInterface $terminal): void
    {
        $terminal->clearScreen();
        $terminal->write($this->renderScreen() . PHP_EOL);
    }

    /**
     * The same three-byte escape handling {@see self::readKey()} does, taken
     * off the pending chunk instead of off a stream.
     */
    private function nextKeyFromChunk(): string
    {
        if ($this->pendingChunk === '') {
            return '';
        }

        $char = $this->pendingChunk[0];
        $this->pendingChunk = substr($this->pendingChunk, 1);

        if ($char !== "\033") {
            return $char;
        }

        $sequence = $char;
        for ($i = 0; $i < 2 && $this->pendingChunk !== ''; $i++) {
            $sequence .= $this->pendingChunk[0];
            $this->pendingChunk = substr($this->pendingChunk, 1);
        }

        return $sequence;
    }

    /**
     * Runs the loop against the given input and output streams until it is asked
     * to stop.
     *
     * Kept alongside {@see self::runOn()} rather than delegating to it: over a
     * non-TTY this path deliberately emits no ANSI, and the terminal contract
     * has no way to say "this surface takes no escape sequences".
     *
     * @param resource|null $input
     * @param resource|null $output
     */
    public function run(mixed $input = null, mixed $output = null): void
    {
        $input ??= STDIN;
        $output ??= STDOUT;
        $isTty = function_exists('stream_isatty') && @stream_isatty($input);
        $stty = null;

        if ($isTty) {
            $stty = shell_exec('stty -g');
            shell_exec('stty -icanon -echo min 1 time 0');
        }

        try {
            while ($this->running) {
                fwrite($output, ($isTty ? "\033[2J\033[H" : '') . $this->renderScreen() . PHP_EOL);
                $key = $this->readKey($input);
                if ($key === '') {
                    break;
                }
                $this->dispatchKey($key);
            }
        } finally {
            if ($isTty && is_string($stty) && trim($stty) !== '') {
                shell_exec('stty ' . escapeshellarg(trim($stty)));
                fwrite($output, PHP_EOL);
            }
        }
    }

    /**
     * The loop's current session state, suitable for persisting.
     *
     * @return array<string, mixed>
     */
    public function sessionState(): array
    {
        $components = [];
        foreach ($this->instances as $id => $instance) {
            $state = $instance->mountedState();
            $components[$id] = [
                'cursor' => $instance->cursor,
                'state' => [
                    'componentId' => $state->componentId,
                    'componentName' => $state->componentName,
                    'version' => $state->version,
                    'data' => $state->data,
                    'meta' => $state->meta,
                ],
            ];
        }

        return [
            'focus' => $this->focus->currentId(),
            'components' => $components,
        ];
    }

    private function focusNext(): void
    {
        $this->focus->next();
        $this->status = 'Focus: ' . ($this->focus->currentId() ?? 'none');
    }

    private function focusPrevious(): void
    {
        $this->focus->previous();
        $this->status = 'Focus: ' . ($this->focus->currentId() ?? 'none');
    }

    private function moveOrFocus(int $delta): void
    {
        $current = $this->current();
        if ($current === null) {
            return;
        }

        $count = $this->choiceCount($current);
        if ($count <= 0) {
            $delta > 0 ? $this->focusNext() : $this->focusPrevious();

            return;
        }

        $current->cursor = max(0, min($count - 1, $current->cursor + $delta));
        $this->status = 'Cursor: ' . ($current->cursor + 1) . '/' . $count;
    }

    private function activateFocused(): void
    {
        $current = $this->current();
        if ($current === null) {
            return;
        }

        $state = $current->mountedState();
        $name = $current->componentName();

        if ($name === 'autocomplete') {
            $items = $this->arrayList($state->data['items'] ?? []);
            $item = $items[$current->cursor] ?? null;
            if (is_array($item)) {
                $this->apply($current, 'select', ['item' => $item]);
                $current->cursor = 0;
                $this->status = 'Selected: ' . $this->label($item);
                return;
            }
        }

        if ($name === 'data-table') {
            $rows = $this->arrayList($state->meta['rows'] ?? []);
            $row = $rows[$current->cursor] ?? null;
            if (is_array($row)) {
                $rowId = $this->rowId($row);
                $this->apply($current, 'toggle-row', ['rowId' => $rowId]);
                $this->status = 'Toggled row: ' . $rowId;
                return;
            }
        }

        if ($name === 'checkbox') {
            $this->apply($current, 'change', ['checked' => !(bool) ($state->data['checked'] ?? false)]);
            $this->status = 'Toggled checkbox';
            return;
        }

        if ($name === 'select') {
            $options = $this->options($state->meta['options'] ?? []);
            $option = $options[$current->cursor] ?? null;
            if (is_array($option) && !($option['disabled'] ?? false)) {
                $this->apply($current, 'change', ['value' => $option['value']]);
                $this->status = 'Selected: ' . $option['label'];
                return;
            }
        }

        $this->status = 'No activation action for ' . $name;
    }

    private function clearFocused(): void
    {
        $current = $this->current();
        if ($current === null) {
            return;
        }

        $name = $current->componentName();
        $action = match ($name) {
            'autocomplete' => 'clear',
            'data-table' => 'clear-selection',
            'input', 'textarea', 'select', 'checkbox' => 'reset',
            default => null,
        };

        if ($action === null) {
            $this->status = 'No clear action for ' . $name;
            return;
        }

        $this->apply($current, $action);
        $current->cursor = 0;
        $this->status = 'Cleared ' . $name;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function apply(TuiComponentInstance $instance, string $action, array $payload = []): void
    {
        $state = $instance->mountedState();
        $result = $instance->component->handle(new InteractionRequest(
            componentId: $state->componentId,
            componentName: $state->componentName,
            action: $action,
            state: $state,
            payload: $payload,
        ));

        $instance->state = $result->state;
        if ($result->errors !== []) {
            $this->status = implode('; ', array_map('strval', $result->errors));
        }
    }

    private function typeIntoFocused(string $key): bool
    {
        $current = $this->current();
        if ($current === null) {
            return false;
        }

        $name = $current->componentName();
        if (!in_array($name, ['autocomplete', 'input', 'textarea'], true)) {
            return false;
        }

        if ($key === 'backspace') {
            $state = $current->mountedState();
            $valueKey = $name === 'autocomplete' ? 'query' : 'value';
            $value = (string) ($state->data[$valueKey] ?? '');
            $this->setTypedValue($current, substr($value, 0, -1));
            return true;
        }

        $character = Key::printableCharacter($key);
        if ($character === null) {
            return false;
        }

        $state = $current->mountedState();
        $valueKey = $name === 'autocomplete' ? 'query' : 'value';
        $value = (string) ($state->data[$valueKey] ?? '');
        $this->setTypedValue($current, $value . $character);

        return true;
    }

    private function setTypedValue(TuiComponentInstance $instance, string $value): void
    {
        if ($instance->componentName() === 'autocomplete') {
            $this->apply($instance, 'search', ['query' => $value]);
            $instance->cursor = 0;
            $this->status = 'Search: ' . ($value !== '' ? $value : '(empty)');
            return;
        }

        $this->apply($instance, 'change', ['value' => $value]);
        $this->status = 'Changed: ' . ($value !== '' ? $value : '(empty)');
    }

    private function choiceCount(TuiComponentInstance $instance): int
    {
        $state = $instance->mountedState();

        return match ($instance->componentName()) {
            'autocomplete' => count($this->arrayList($state->data['items'] ?? [])),
            'data-table' => count($this->arrayList($state->meta['rows'] ?? [])),
            'select' => count($this->options($state->meta['options'] ?? [])),
            default => 0,
        };
    }

    private function current(): ?TuiComponentInstance
    {
        $id = $this->focus->currentId();

        return $id !== null ? ($this->instances[$id] ?? null) : null;
    }

    private function quit(): void
    {
        $this->running = false;
        $this->status = 'Quit';
    }

    private function restoreSession(): void
    {
        $saved = $this->stateManager?->loadState();
        if (!is_array($saved)) {
            return;
        }

        if (is_string($saved['focus'] ?? null)) {
            $this->focus->focus($saved['focus']);
        }

        $components = is_array($saved['components'] ?? null) ? $saved['components'] : [];
        foreach ($components as $id => $component) {
            if (!is_string($id) || !isset($this->instances[$id]) || !is_array($component)) {
                continue;
            }

            $this->instances[$id]->cursor = max(0, (int) ($component['cursor'] ?? 0));
            if (is_array($component['state'] ?? null)) {
                $state = $this->stateFromArray($component['state']);
                if ($state instanceof StateSnapshot) {
                    $this->instances[$id]->state = $state;
                }
            }
        }
    }

    private function saveSession(): void
    {
        $this->stateManager?->saveState($this->sessionState());
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function stateFromArray(array $raw): ?StateSnapshot
    {
        if (!is_string($raw['componentId'] ?? null) || !is_string($raw['componentName'] ?? null) || !is_string($raw['version'] ?? null)) {
            return null;
        }

        return new StateSnapshot(
            componentId: $raw['componentId'],
            componentName: $raw['componentName'],
            version: $raw['version'],
            data: is_array($raw['data'] ?? null) ? $raw['data'] : [],
            meta: is_array($raw['meta'] ?? null) ? $raw['meta'] : [],
        );
    }

    private function header(): string
    {
        $line = $this->fit($this->title . ' | Focus: ' . ($this->focus->currentId() ?? 'none'), $this->width);
        $rule = str_repeat('=', min($this->width, strlen($line)));

        return $line . PHP_EOL . $rule;
    }

    private function footer(): string
    {
        return $this->fit('Tab focus | Up/Down move | Enter activate | c clear | q quit | ' . $this->status, $this->width);
    }

    private function fit(string $text, int $width): string
    {
        if (strlen($text) <= $width) {
            return $text;
        }

        return substr($text, 0, max(1, $width - 1)) . '~';
    }

    private function normalizeKey(string $key): string
    {
        return match ($key) {
            "\033[A" => 'up',
            "\033[B" => 'down',
            "\033[Z" => 'shift-tab',
            "\033", "\e" => 'escape',
            "\t" => 'tab',
            "\n", "\r" => 'enter',
            ' ' => 'space',
            "\177", "\010" => 'backspace',
            default => strtolower($key),
        };
    }

    /**
     * @param resource $input
     */
    private function readKey(mixed $input): string
    {
        $char = fread($input, 1);
        if (!is_string($char) || $char === '') {
            return '';
        }

        if ($char !== "\033") {
            return $char;
        }

        $sequence = $char;
        $next = fread($input, 1);
        if (is_string($next)) {
            $sequence .= $next;
        }
        $next = fread($input, 1);
        if (is_string($next)) {
            $sequence .= $next;
        }

        return $sequence;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function arrayList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, 'is_array'));
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
            if (!is_array($option)) {
                continue;
            }

            $options[] = [
                'value' => (string) ($option['value'] ?? $key),
                'label' => (string) ($option['label'] ?? $option['value'] ?? $key),
                'disabled' => (bool) ($option['disabled'] ?? false),
            ];
        }

        return $options;
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

    /**
     * @param array<string, mixed> $item
     */
    private function label(array $item): string
    {
        return (string) ($item['label'] ?? $item['value'] ?? '');
    }
}
