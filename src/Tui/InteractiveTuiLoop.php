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

        $this->keys = new InputBuffer();
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
     * The same assembler the retained loop uses. Its own three-byte version
     * turned a fragmented arrow into Escape, bracket, letter: it took the ESC,
     * found nothing after it in that chunk, and emitted the three bytes as
     * three keys. A terminal is free to split a sequence across reads, so an
     * assembler that only looks at the current chunk cannot be correct.
     */
    private readonly InputBuffer $keys;

    /**
     * When the buffer first reported a partial sequence, so a fragment still
     * arriving is not mistaken for an abandoned Escape.
     */
    private ?float $pendingSince = null;

    /**
     * Runs the loop against a {@see TerminalInterface} instead of raw streams.
     *
     * Two things are different from {@see self::run()}:
     *
     * 1. Key assembly goes through {@see InputBuffer}, the same one the
     *    retained loop uses, so a sequence split across reads is reassembled
     *    instead of emitted as its separate bytes.
     * 2. Painting moved OUT of the top of the loop. Polling means many ticks
     *    with no key, and this loop repaints in full — leaving the paint where
     *    it was would redraw the whole screen on every idle tick.
     *
     * @param int $idleMicroseconds          How long to idle when a tick produced no key.
     * @param int $escapeTimeoutMicroseconds How long a partial sequence may sit before it is
     *                                       emitted as-is. A terminal delivers the rest of a CSI
     *                                       in microseconds; a person leaves an Escape pending for
     *                                       as long as they like.
     */
    public function runOn(TerminalInterface $terminal, int $idleMicroseconds = 100000, int $escapeTimeoutMicroseconds = 50000): void
    {
        $pushed = '';
        $terminal->start(
            static function (string $bytes) use (&$pushed): void {
                $pushed .= $bytes;
            },
            function () use ($terminal): void {
                $this->resizeTo($terminal->columns());
            },
        );

        // Asked once at startup: the resize callback only fires on CHANGE, so a
        // loop that never asks renders at its constructed width forever.
        $this->resizeTo($terminal->columns());

        try {
            $this->paintTo($terminal);

            while ($this->running) {
                $chunk = $pushed . $terminal->pollInput();
                $pushed = '';
                $key = $chunk === '' ? '' : $this->keys->feed($chunk);

                if ($key === '') {
                    // This is what atEndOfInput() exists for: telling "nothing
                    // right now" apart from "nothing ever again". Without it a
                    // finite stream would spin here forever.
                    if ($this->keys->pending() !== '') {
                        $ahora = microtime(true);
                        $this->pendingSince ??= $ahora;

                        if (($ahora - $this->pendingSince) * 1_000_000 >= $escapeTimeoutMicroseconds) {
                            $this->pendingSince = null;
                            $key = $this->keys->flush();
                        }
                    } elseif ($terminal->atEndOfInput()) {
                        break;
                    }

                    if ($key === '') {
                        if ($idleMicroseconds > 0) {
                            usleep($idleMicroseconds);
                        }

                        continue;
                    }
                }

                $this->pendingSince = null;

                $this->dispatchKey($key);
                $this->paintTo($terminal);
            }
        } finally {
            $terminal->stop();
        }
    }

    /**
     * Adopts a new terminal width.
     *
     * There is no previous frame to forget here: this loop repaints in full on
     * every key, so the next paint is already whole. Height is not tracked —
     * what it renders is a flowing document, not a fixed grid.
     */
    public function resizeTo(int $width): void
    {
        $this->width = max(1, $width);
    }

    private function paintTo(TerminalInterface $terminal): void
    {
        $terminal->clearScreen();
        $terminal->write($this->renderScreen() . PHP_EOL);
    }


    /**
     * Runs the loop against the given input and output streams until it is asked
     * to stop.
     *
     * Kept alongside {@see self::runOn()} rather than delegating to it: over a
     * non-TTY this path deliberately emits no ANSI, and the terminal contract
     * has no way to say "this surface takes no escape sequences".
     *
     * It keeps its own reader, which assembles at most three bytes after an
     * ESC. Blocking one byte at a time makes fragmentation a non-issue here,
     * but a sequence longer than three bytes — a bracketed paste, for one —
     * still arrives in pieces. {@see self::runOn()} does not have that limit.
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
                $this->announce('Selected: ' . $this->label($item));
                return;
            }
        }

        if ($name === 'data-table') {
            $rows = $this->arrayList($state->meta['rows'] ?? []);
            $row = $rows[$current->cursor] ?? null;
            if (is_array($row)) {
                $rowId = $this->rowId($row);
                $this->apply($current, 'toggle-row', ['rowId' => $rowId]);
                $this->announce('Toggled row: ' . $rowId);
                return;
            }
        }

        if ($name === 'checkbox') {
            $this->apply($current, 'change', ['checked' => !(bool) ($state->data['checked'] ?? false)]);
            $this->announce('Toggled checkbox');
            return;
        }

        if ($name === 'select') {
            $options = $this->options($state->meta['options'] ?? []);
            $option = $options[$current->cursor] ?? null;
            if (is_array($option) && !($option['disabled'] ?? false)) {
                $this->apply($current, 'change', ['value' => $option['value']]);
                $this->announce('Selected: ' . $option['label']);
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
        $this->announce('Cleared ' . $name);
    }

    /**
     * The message the last applied action was rejected with, or null when it
     * was accepted. Cleared by every {@see self::apply()}, so it only ever
     * describes the action just applied.
     */
    private ?string $rejection = null;

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
        $this->rejection = $result->errors !== []
            ? implode('; ', array_map('strval', $result->errors))
            : null;
    }

    /**
     * Announces the outcome of an applied action.
     *
     * Writing the success message straight into `$this->status` is what made
     * the errors branch of {@see self::apply()} unreachable in practice: every
     * caller overwrote the rejection on the very next line, so a component
     * that refused an action still reported success to the person watching.
     * The rejection wins; the caller's message is what to say otherwise.
     */
    private function announce(string $success): void
    {
        $this->status = $this->rejection ?? $success;
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
            $this->announce('Search: ' . ($value !== '' ? $value : '(empty)'));
            return;
        }

        $this->apply($instance, 'change', ['value' => $value]);
        $this->announce('Changed: ' . ($value !== '' ? $value : '(empty)'));
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
