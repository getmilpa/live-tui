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

use Milpa\Live\Contracts\Tui\TerminalInterface;

/**
 * Terminal backed by PHP input/output streams. The PHP analog of
 * pi-tui's `ProcessTerminal`. Defaults to `STDIN` / `STDOUT` and
 * uses `stty` to toggle raw mode, so it works on any POSIX terminal
 * the way the existing `RetainedTuiLoop::run()` does — but now behind
 * a contract the loop can swap out.
 *
 * A `StreamTerminal` owns no state beyond the streams it was given and
 * the saved `stty` settings; it is safe to construct one per loop
 * session and discard it on {@see stop()}.
 */
final class StreamTerminal implements TerminalInterface
{
    /** @var resource|null */
    private mixed $input;

    /** @var resource|null */
    private mixed $output;

    private ?string $savedStty = null;

    private bool $started = false;

    /** @var callable(string): void|null */
    private $inputHandler = null;

    private bool $resizeInstalled = false;

    /**
     * The signals that end a process by default: hangup, interrupt, quit, terminate. Numbers, not the `SIG*`
     * constants, because those exist only where pcntl does.
     */
    private const ENDING_SIGNALS = [1, 2, 3, 15];

    /**
     * What each ending signal did before {@see self::start()} took it, to hand it back on {@see self::stop()}.
     *
     * @var array<int, callable|int>
     */
    private array $previousHandlers = [];

    private bool $restoresAtShutdown = false;

    /**
     * @param resource|null $input  Stream the terminal reads input bytes from.
     * @param resource|null $output Stream the terminal writes ANSI output to.
     */
    public function __construct(
        private readonly ?string $title = null,
        mixed $input = null,
        mixed $output = null,
    ) {
        $this->input = $input;
        $this->output = $output;
    }

    /**
     * Puts the terminal in raw mode and begins delivering input and resize events.
     * Saves the previous terminal settings so {@see self::stop()} can restore them.
     */
    public function start(callable $onInput, callable $onResize): void
    {
        $this->input ??= STDIN;
        $this->output ??= STDOUT;
        $this->inputHandler = $onInput;
        $this->started = true;

        if (function_exists('stream_isatty') && @stream_isatty($this->input)) {
            $this->savedStty = shell_exec('stty -g');
            // `-isig`: Ctrl-C, Ctrl-\ and Ctrl-Z arrive as the keys they are instead of as signals. A screen
            // decides what Ctrl-C means — every screen of the family quits on it —, and quitting through the loop
            // is what restores the terminal. As a signal it killed the process between two frames and left the
            // terminal without echo and without lines (greenhouse evidence/1053, F2; decisions/0524).
            shell_exec('stty -icanon -echo -isig min 0 time 0');
            stream_set_blocking($this->input, false);
        }

        // Whatever ends the process after this — a fatal error, an exit() somewhere in the screen's code, a
        // signal from outside — hands the terminal back first. A shutdown function runs where `finally` does not.
        if (!$this->restoresAtShutdown) {
            register_shutdown_function(function (): void {
                $this->stop();
            });
            $this->restoresAtShutdown = true;
        }

        if ($this->title !== null) {
            $this->setTitle($this->title);
        }

        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGWINCH, static function () use ($onResize): void {
                $onResize();
            });
            $this->resizeInstalled = true;

            foreach (self::ENDING_SIGNALS as $signal) {
                $previous = pcntl_signal_get_handler($signal);
                // Ignored before (a `nohup`, a supervisor that owns the signal): it stays ignored.
                if ($previous === SIG_IGN) {
                    continue;
                }
                $this->previousHandlers[$signal] = $previous;
                pcntl_signal($signal, function (int $signal): void {
                    $this->endedBy($signal);
                });
            }
        }
    }

    /**
     * A signal that ends the process arrived while the terminal was raw: restore it, then let the signal do what
     * it did before — the handler the process already had, or the default death by that signal, so whoever waits
     * on this process still reads why it ended.
     */
    private function endedBy(int $signal): void
    {
        $previous = $this->previousHandlers[$signal] ?? SIG_DFL;
        $this->stop();
        if (is_callable($previous)) {
            $previous($signal, []);

            return;
        }
        pcntl_signal($signal, SIG_DFL);
        if (function_exists('posix_kill')) {
            posix_kill(getmypid(), $signal);
        }

        exit(128 + $signal);
    }

    /**
     * Restores the terminal to how it was found: previous settings, visible cursor,
     * default resize handling, and whatever the ending signals did before.
     */
    public function stop(): void
    {
        if (!$this->started) {
            return;
        }
        $this->showCursor();
        fwrite($this->output ?? STDOUT, "\033[0m" . PHP_EOL);
        if (is_string($this->savedStty) && trim($this->savedStty) !== '') {
            shell_exec('stty ' . escapeshellarg(trim($this->savedStty)));
            $this->savedStty = null;
        }
        // stop() undoes what start() did: if a SIGWINCH handler was installed,
        // hand the signal back to its default. Without this the handler
        // outlived the terminal that installed it.
        if ($this->resizeInstalled && function_exists('pcntl_signal')) {
            pcntl_signal(SIGWINCH, SIG_DFL);
            $this->resizeInstalled = false;
        }
        foreach ($this->previousHandlers as $signal => $previous) {
            pcntl_signal($signal, $previous);
        }
        $this->previousHandlers = [];
        $this->started = false;
        $this->inputHandler = null;
    }

    /**
     * Writes raw bytes to the output stream, unmodified.
     */
    public function write(string $data): void
    {
        $out = $this->output ?? STDOUT;
        fwrite($out, $data);
    }

    /**
     * The terminal's current width in columns.
     */
    public function columns(): int
    {
        return $this->terminalSize(0);
    }

    /**
     * The terminal's current height in rows.
     */
    public function rows(): int
    {
        return $this->terminalSize(1);
    }

    /**
     * Moves the cursor by the given number of lines, negative being upwards.
     */
    public function moveBy(int $lines): void
    {
        if ($lines === 0) {
            return;
        }
        $dir = $lines < 0 ? 'A' : 'B';
        $this->write("\x1b[" . abs($lines) . $dir);
    }

    /**
     * Hides the hardware cursor.
     */
    public function hideCursor(): void
    {
        $this->write("\x1b[?25l");
    }

    /**
     * Shows the hardware cursor.
     */
    public function showCursor(): void
    {
        $this->write("\x1b[?25h");
    }

    /**
     * Clears the line the cursor is on.
     */
    public function clearLine(): void
    {
        $this->write("\x1b[2K");
    }

    /**
     * Clears from the cursor to the end of the screen.
     */
    public function clearFromCursor(): void
    {
        $this->write("\x1b[J");
    }

    /**
     * Clears the whole screen.
     */
    public function clearScreen(): void
    {
        $this->write("\x1b[2J\x1b[H");
    }

    /**
     * Sets the terminal window title.
     */
    public function setTitle(string $title): void
    {
        $this->write("\x1b]0;" . $title . "\x07");
    }

    /**
     * Returns the current input byte chunk waiting on the input stream,
     * or `''` when nothing is pending. The loop polls this on each
     * tick; the method is intentionally non-blocking so a busy loop
     * stays responsive.
     */
    public function pollInput(): string
    {
        if ($this->input === null) {
            return '';
        }
        $chunk = fread($this->input, 4096);

        return is_string($chunk) ? $chunk : '';
    }

    /**
     * True once the input stream is drained. A real terminal never reaches
     * this: `feof()` on a TTY stays false for as long as the device is open.
     */
    public function atEndOfInput(): bool
    {
        return $this->input === null || feof($this->input);
    }

    /**
     * Feeds raw input bytes to the registered handler, used to drive the terminal
     * from a test or another source instead of the real stream.
     */
    public function dispatchInput(string $bytes): void
    {
        if ($this->inputHandler !== null) {
            ($this->inputHandler)($bytes);
        }
    }

    /**
     * @return int
     */
    private function terminalSize(int $dimension): int
    {
        if (function_exists('shell_exec')) {
            $cols = (int) shell_exec('tput cols 2>/dev/null');
            $rows = (int) shell_exec('tput lines 2>/dev/null');
            if ($dimension === 0 && $cols > 0) {
                return $cols;
            }
            if ($dimension === 1 && $rows > 0) {
                return $rows;
            }
        }

        return $dimension === 0 ? 80 : 24;
    }
}
