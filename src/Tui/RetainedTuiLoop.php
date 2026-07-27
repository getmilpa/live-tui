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

use Milpa\Live\Contracts\Tui\FocusManagerInterface;
use Milpa\Live\Contracts\Tui\TerminalInterface;
use Milpa\Live\ValueObjects\Tui\TuiBufferDiff;
use Milpa\Live\ValueObjects\Tui\TuiNode;

/**
 * The retained-mode loop: renders the tree into a buffer, diffs it against the
 * previous one, and writes only the rows that changed. Owns focus, key
 * dispatch, session values and the input buffer for the run.
 */
final class RetainedTuiLoop
{
    private bool $running = true;

    /**
     * @var array<string, mixed>
     */
    private array $state = [];

    private FocusManagerInterface $focus;

    private string $initialFocusFallback;

    /**
     * Last frame painted by {@see self::run()}, used to compute
     * {@see VirtualTerminalBuffer::diff()} against the next frame so only
     * changed rows are written to the terminal. Reset to null whenever a
     * full repaint is required (loop start), so the very first frame is
     * always a full paint and every frame after it is diff-only.
     */
    private ?VirtualTerminalBuffer $previousBuffer = null;

    private readonly \Closure $rootFactory;

    private readonly ?\Closure $handleKey;

    private readonly ?\Closure $tick;

    private readonly SynchronizedOutput $sync;

    private readonly ?BracketedPaste $paste;

    /**
     * @param callable(self): TuiNode           $rootFactory
     * @param array<int, string>                $focusOrder
     * @param null|callable(string, self): bool $handleKey
     * @param null|callable(self): void         $tick
     * @param null|SynchronizedOutput           $sync        Synchronized-output wrapper for atomic writes; defaults to enabled.
     * @param null|BracketedPaste               $paste       Bracketed-paste detector; defaults to null (no paste detection).
     */
    public function __construct(
        private readonly RetainedTuiRenderer $renderer,
        callable $rootFactory,
        array $focusOrder,
        string $initialFocus,
        private int $width,
        private int $height,
        private readonly bool $ansi = true,
        ?callable $handleKey = null,
        ?callable $tick = null,
        private readonly ?TuiAnsiPainter $painter = null,
        ?SynchronizedOutput $sync = null,
        ?BracketedPaste $paste = null,
    ) {
        $this->rootFactory = \Closure::fromCallable($rootFactory);
        $this->handleKey = $handleKey !== null ? \Closure::fromCallable($handleKey) : null;
        $this->tick = $tick !== null ? \Closure::fromCallable($tick) : null;
        $this->sync = $sync ?? new SynchronizedOutput($ansi);
        $this->paste = $paste;
        $this->inputBuffer = new InputBuffer();
        $this->initialFocusFallback = $initialFocus;
        $this->focus = new FocusManager($focusOrder);
        if (in_array($initialFocus, $this->focus->ids(), true)) {
            $this->focus->focus($initialFocus);
        }
    }

    /**
     * The id currently holding focus, falling back to the initial one.
     */
    public function focusedId(): string
    {
        return $this->focus->currentId() ?? $this->initialFocusFallback;
    }

    /**
     * Moves focus to the given id.
     */
    public function focus(string $id): void
    {
        $this->focus->focus($id);
    }

    /**
     * Replaces the focus order, keeping the currently focused id if it survives.
     *
     * @param array<int, string> $focusOrder
     */
    public function setFocusOrder(array $focusOrder): void
    {
        $currentId = $this->focus->currentId();
        $this->focus = new FocusManager($focusOrder);
        if ($currentId !== null) {
            $this->focus->focus($currentId);
        }
    }

    /**
     * Moves focus to the next id in order.
     */
    public function focusNext(): void
    {
        $this->focus->next();
        $this->set('status', 'Focus: ' . $this->focusedId());
    }

    /**
     * Moves focus to the previous id in order.
     */
    public function focusPrevious(): void
    {
        $this->focus->previous();
        $this->set('status', 'Focus: ' . $this->focusedId());
    }

    /**
     * A value from the loop's session state, or the default when it is not set.
     */
    public function value(string $key, mixed $default = null): mixed
    {
        return $this->state[$key] ?? $default;
    }

    /**
     * Stores a value in the loop's session state.
     */
    public function set(string $key, mixed $value): void
    {
        $this->state[$key] = $value;
    }

    /**
     * The full screen as a string. Bypasses the diff, so this repaints everything —
     * it is for the first frame and for a forced redraw, not the steady state.
     */
    public function renderScreen(): string
    {
        return $this->paintLines($this->currentBuffer()->lines());
    }

    /**
     * Routes a key through the shortcut registry and the key handler, returning
     * whether it was consumed.
     */
    public function dispatchKey(string $key): bool
    {
        $key = $this->normalizeKey($key);
        if ($key === '') {
            return $this->running;
        }

        if (in_array($key, ['q', 'escape', 'ctrl+c'], true)) {
            $this->running = false;

            return false;
        }

        if ($key === 'tab') {
            $this->focusNext();

            return true;
        }

        if ($key === 'shift+tab') {
            $this->focusPrevious();

            return true;
        }

        if ($this->handleKey !== null && ($this->handleKey)($key, $this) === true) {
            return $this->running;
        }

        $this->set('status', 'Unhandled: ' . $key);

        return $this->running;
    }

    /**
     * Bytes pushed by a terminal's `$onInput` callback and not yet consumed.
     * Lets {@see self::runOn()} accept a push-style terminal without giving up
     * its own tick.
     */
    private string $pushedInput = '';

    /**
     * When the input buffer first reported a partial sequence, so a fragment
     * that is still arriving is not mistaken for an abandoned Escape.
     */
    private ?float $pendingSince = null;

    /**
     * Runs the loop against a {@see TerminalInterface} instead of raw streams.
     *
     * Same body as {@see self::run()} — tick, paint the diff, read a key,
     * dispatch — with every terminal effect expressed through the contract:
     * no `stty`, no `stream_isatty` gate, no escape sequences written by hand.
     * That is what makes the interactive path drivable by a fake terminal, and
     * therefore testable without one.
     *
     * @param int      $idleMicroseconds          How long to idle when a tick produced no key.
     *                                            Zero keeps a scripted run instantaneous.
     * @param int|null $maxTicks                  Stop after this many ticks. Null runs until the loop is
     *                                            asked to stop, which is what a session wants; a bounded
     *                                            run is what a test wants, because a loop that never sees
     *                                            its quit key hangs the suite instead of failing it.
     * @param int      $escapeTimeoutMicroseconds How long a partial sequence may sit before it is
     *                                            emitted as-is. A terminal delivers the rest of a CSI in
     *                                            microseconds; a person leaves an Escape pending for as long
     *                                            as they like. Flushing sooner destroys fragmented
     *                                            sequences; never flushing turns Escape into alt+<key>.
     */
    public function runOn(TerminalInterface $terminal, int $idleMicroseconds = 100000, ?int $maxTicks = null, int $escapeTimeoutMicroseconds = 50000): void
    {
        $this->previousBuffer = null;
        $this->pushedInput = '';

        $terminal->start(
            function (string $bytes): void {
                $this->pushedInput .= $bytes;
            },
            function () use ($terminal): void {
                $this->resizeTo($terminal->columns(), $terminal->rows());
            },
        );

        // A terminal reports its size on demand; the resize callback only fires
        // when it CHANGES. Without asking once at startup the loop would render
        // at whatever size it was constructed with until the user happened to
        // resize the window.
        $this->resizeTo($terminal->columns(), $terminal->rows());

        try {
            // Bracketed paste has to bracket the SESSION, not a frame: without
            // MODE_BEGIN the terminal replays a paste as individual keystrokes
            // and the detector never sees a paste at all.
            if ($this->paste !== null) {
                $terminal->write(BracketedPaste::MODE_BEGIN);
            }

            $terminal->clearScreen();
            $terminal->hideCursor();

            $ticks = 0;

            while ($this->running) {
                if ($maxTicks !== null && ++$ticks > $maxTicks) {
                    break;
                }

                $this->tick();

                $frame = $this->nextFrameBytes();
                if ($frame !== '') {
                    $terminal->write($frame);
                }

                $chunk = $this->pushedInput . $terminal->pollInput();
                $this->pushedInput = '';

                $key = $this->consumeChunk($chunk);
                if ($key !== '') {
                    $this->dispatchKey($key);

                    continue;
                }

                // Un ESC solo no puede distinguirse, en el instante en que
                // llega, del principio de una secuencia que viene fragmentada:
                // los dos son el mismo byte. Lo que los separa es el TIEMPO —
                // una terminal manda el resto en microsegundos, una persona
                // deja el Escape colgado indefinidamente— así que lo pendiente
                // se emite solo cuando lleva demasiado esperando.
                if ($this->inputBuffer->pending() !== '') {
                    $ahora = microtime(true);
                    $this->pendingSince ??= $ahora;

                    if (($ahora - $this->pendingSince) * 1_000_000 >= $escapeTimeoutMicroseconds) {
                        $this->pendingSince = null;
                        $this->dispatchKey($this->inputBuffer->flush());

                        continue;
                    }
                } else {
                    $this->pendingSince = null;
                }

                if ($idleMicroseconds > 0) {
                    usleep($idleMicroseconds);
                }
            }
        } finally {
            if ($this->paste !== null) {
                $terminal->write(BracketedPaste::MODE_END);
            }

            // No showCursor() here: TerminalInterface::stop() is documented to
            // restore the terminal to how it was found, cursor included.
            // Calling both emitted the escape twice.
            $terminal->stop();
        }
    }

    /**
     * Runs the loop against the given input and output streams, writing only the
     * rows each frame's diff reports as changed.
     *
     * @param resource|null $input
     * @param resource|null $output
     */
    public function run(mixed $input = null, mixed $output = null): void
    {
        $input ??= STDIN;
        $output ??= STDOUT;

        // Not a terminal (piped, redirected, a test): there is nothing to be
        // interactive with, so emit one frame and leave. This stays here
        // rather than in the contract because it is a fact about the STREAM,
        // and only this entry point has one.
        if (!function_exists('stream_isatty') || !@stream_isatty($input)) {
            fwrite($output, $this->renderScreen() . PHP_EOL);

            return;
        }

        $this->runOn(new StreamTerminal(null, $input, $output));
    }

    /**
     * Adopts a new terminal size.
     *
     * Dropping the previous buffer is not housekeeping, it is the point: the
     * diff walks the CURRENT buffer's rows, so after a shrink the rows that no
     * longer exist are never visited and their old contents stay on screen.
     * Forgetting the previous frame turns the next one into a full repaint,
     * which is the only correct answer to a resize.
     */
    public function resizeTo(int $width, int $height): void
    {
        if ($width === $this->width && $height === $this->height) {
            return;
        }

        $this->width = max(1, $width);
        $this->height = max(1, $height);
        $this->previousBuffer = null;
    }

    private function tick(): void
    {
        if ($this->tick !== null) {
            ($this->tick)($this);
        }
    }

    /**
     * Builds and lays out the current frame without painting it -- shared
     * by {@see self::renderScreen()} (always a full string) and
     * {@see self::paintFrame()} (which additionally diffs against the
     * previous frame so the terminal write only touches changed rows).
     */
    private function currentBuffer(): VirtualTerminalBuffer
    {
        $root = ($this->rootFactory)($this);
        if (!$root instanceof TuiNode) {
            throw new \RuntimeException('Retained TUI root factory must return a TuiNode.');
        }

        return $this->renderer->render($root, $this->width, $this->height, $this->focusedId());
    }

    /**
     * @param array<int, string> $lines
     */
    private function paintLines(array $lines): string
    {
        if ($this->ansi) {
            return ($this->painter ?? new TuiAnsiPainter())->paint($lines);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Writes one frame to $output. The first call after a (re)start does a
     * full clear-and-repaint (there is no previous frame to diff against);
     * every call after that computes {@see VirtualTerminalBuffer::diff()}
     * against the last painted frame and writes only the changed rows via
     * cursor-positioned ANSI patches -- if nothing changed, nothing is
     * written at all. This is what makes the "flicker-resistant redraws"
     * capability an actual runtime behavior instead of dead code.
     *
     * Public (not just used internally by {@see self::run()}) so the
     * diff-repaint behavior can be driven and observed directly -- e.g. by
     * a test harness scripting a state change over a non-TTY stream, where
     * {@see self::run()}'s own TTY detection would otherwise short-circuit
     * to a single one-shot render and never reach the interactive loop
     * body at all. This is the exact per-tick call {@see self::run()}
     * makes; nothing about it is TTY-specific.
     *
     * @param resource $output
     */
    public function paintFrame(mixed $output): void
    {
        $bytes = $this->nextFrameBytes();
        if ($bytes !== '') {
            fwrite($output, $bytes);
        }
    }

    /**
     * Advances one frame and returns the bytes the terminal should receive —
     * empty when nothing changed. This is where the retained property lives:
     * the first frame is a full paint, an identical frame costs nothing, and
     * any later frame carries only the rows the diff reported. Shared by
     * {@see self::paintFrame()} (stream) and {@see self::runOn()} (terminal
     * contract) so both paint from exactly the same computation.
     */
    public function nextFrameBytes(): string
    {
        $buffer = $this->currentBuffer();

        if ($this->previousBuffer === null) {
            $bytes = "\033[H" . $this->paintLines($buffer->lines());
            $bytes .= $this->positionHardwareCursor();
            $this->previousBuffer = $buffer;

            return $this->sync->wrap($bytes);
        }

        $diff = $buffer->diff($this->previousBuffer);
        $this->previousBuffer = $buffer;

        if ($diff->isEmpty()) {
            $cursor = $this->positionHardwareCursor();

            return $cursor === '' ? '' : $this->sync->wrap($cursor);
        }

        $bytes = $this->paintDiff($diff);
        $bytes .= $this->positionHardwareCursor();

        return $this->sync->wrap($bytes);
    }

    /**
     * Asks the renderer for the focused node's caret position (via
     * {@see RetainedTuiRenderer::caretPosition()}, which delegates to
     * {@see FocusableInterface}) and emits the cursor-position escape
     * pointing the hardware cursor at that cell. The marker APC cannot
     * live in the cell-addressed {@see VirtualTerminalBuffer} (it is
     * zero-width), so the position is derived from the renderer's own
     * frame — not from the painted output — which keeps the buffer and
     * the caret concern cleanly separated.
     */
    private function positionHardwareCursor(): string
    {
        if (!$this->ansi) {
            return '';
        }
        $root = ($this->rootFactory)($this);
        if (!$root instanceof TuiNode) {
            return '';
        }
        $pos = $this->renderer->caretPosition($root, $this->width, $this->height, $this->focusedId());
        if ($pos === null) {
            return '';
        }
        [$row, $col] = $pos;
        $row1 = $row + 1;
        $col1 = $col + 1;

        return "\x1b[{$row1};{$col1}H";
    }

    private function paintDiff(TuiBufferDiff $diff): string
    {
        if (!$this->ansi) {
            return $diff->renderAnsiPatch();
        }

        $painter = $this->painter ?? new TuiAnsiPainter();
        $painted = array_map(
            static fn (array $change): array => ['row' => $change['row'], 'line' => $painter->paint([$change['line']])],
            $diff->changes,
        );

        return (new TuiBufferDiff($painted))->renderAnsiPatch();
    }

    private readonly InputBuffer $inputBuffer;

    /**
     * Pending complete sequences already accepted from InputBuffer
     * but not yet returned by {@see readKey()}. When bracketed paste
     * is active, a paste window is stripped from this stream so the
     * byte-by-byte loop can still dispatch one "key" at a time outside
     * pastes while the detector consumes the whole paste as one event.
     */
    private string $pendingInput = '';

    /**
     * The whole of key assembly with no I/O in it: drains anything already
     * complete, then feeds the chunk through {@see InputBuffer} (which holds
     * partial escape sequences across reads and returns only complete ones)
     * and the paste detector. Split out of {@see readKey()} so the same
     * assembly can be driven from a stream or from a {@see TerminalInterface},
     * which is the only difference between the two.
     */
    private function consumeChunk(string $chunk): string
    {
        // Drain any pending complete sequences first.
        if ($this->pendingInput !== '') {
            $next = $this->shiftPending();
            if ($next !== '') {
                return $next;
            }
        }

        if ($chunk === '') {
            return '';
        }

        $completed = $this->inputBuffer->feed($chunk);
        if ($this->paste !== null) {
            $this->pendingInput = $this->paste->feed($completed);
        } else {
            $this->pendingInput = $completed;
        }

        return $this->shiftPending();
    }

    /**
     * Shifts one logical keypress off the front of {@see pendingInput}.
     * A bare byte is returned as-is; an ANSI escape sequence is read
     * up to its terminator (the InputBuffer already guaranteed the
     * pendingInput only holds complete sequences, so we just return
     * the whole escape run when the first char is ESC).
     */
    private function shiftPending(): string
    {
        if ($this->pendingInput === '') {
            return '';
        }
        if ($this->pendingInput[0] !== "\033") {
            // Non-escape: return one printable char (UTF-8 aware).
            $char = mb_substr($this->pendingInput, 0, 1, 'UTF-8');
            $this->pendingInput = mb_substr($this->pendingInput, 1, null, 'UTF-8');

            return $char;
        }
        // Escape sequence: return the whole run. InputBuffer guaranteed
        // it is complete, so we do not need to re-parse it.
        $next = strpos($this->pendingInput, "\033", 1);
        if ($next === false) {
            $sequence = $this->pendingInput;
            $this->pendingInput = '';
        } else {
            $sequence = substr($this->pendingInput, 0, $next);
            $this->pendingInput = substr($this->pendingInput, $next);
        }

        return $sequence;
    }

    /**
     * What key is this?
     *
     * Delegates to {@see KeyMatcher}, which is the package's own answer to that
     * question — 41 sequences against the eleven this loop used to know from a
     * private `match` of its own. A second table is a second table to keep in
     * sync, and it had already drifted: it knew Up and Down but not Left and
     * Right, so an application that navigates with the arrow keys never saw
     * half of them, and the loop reported the raw escape bytes as an unhandled
     * key instead.
     *
     * The one spelling that differs is folded here: this loop's vocabulary said
     * `shift-tab` while the rest of the package spells modifiers with a plus
     * (`ctrl+p`, `ctrl+c`). The plus wins; the dash was the odd one out.
     */
    private function normalizeKey(string $key): string
    {
        $key = KeyMatcher::normalize($key);

        return $key === 'shift-tab' ? 'shift+tab' : $key;
    }
}
