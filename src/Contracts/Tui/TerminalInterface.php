<?php

declare(strict_types=1);

namespace Milpa\Live\Contracts\Tui;

/**
 * The terminal surface a TUI loop talks to. The PHP analog of pi-tui's
 * `Terminal` interface. A `RetainedTuiLoop` (or any other TUI runtime)
 * writes to, reads from, and controls a terminal through this seam —
 * so the same loop can drive a real STDIN/STDOUT terminal, an
 * in-memory test terminal, or a remote PTY-backed terminal without
 * the loop body branching on the concrete backend.
 *
 * Implementations are expected to be cheap to construct and to be
 * driven explicitly via {@see start()} / {@see stop()} — no implicit
 * I/O in the constructor. `columns()` / `rows()` reflect the current
 * size and may change between calls when the terminal is resized;
 * {@see start()}'s `$onResize` callback lets the loop react.
 */
interface TerminalInterface
{
    /**
     * Enters raw mode (or whatever the backend needs) and begins
     * delivering input bytes to `$onInput`. `$onResize` is called when
     * the terminal's columns/rows change so the loop can re-layout.
     *
     * @param callable(string): void $onInput
     * @param callable(): void       $onResize
     */
    public function start(callable $onInput, callable $onResize): void;

    /**
     * Restores the terminal to its pre-{@see start()} state (cooked
     * mode, visible cursor, etc.). Safe to call after {@see start()}
     * or when {@see start()} was never called.
     */
    public function stop(): void;

    /**
     * Writes `$data` to the terminal output stream. The caller is
     * responsible for framing; this method does no buffering.
     */
    public function write(string $data): void;

    /**
     * The terminal's current width in columns.
     */
    public function columns(): int;

    /**
     * The terminal's current height in rows.
     */
    public function rows(): int;

    /**
     * Moves the cursor by the given number of lines, negative being upwards.
     */
    public function moveBy(int $lines): void;

    /**
     * Hides the hardware cursor.
     */
    public function hideCursor(): void;

    /**
     * Shows the hardware cursor.
     */
    public function showCursor(): void;

    /**
     * Clears the line the cursor is on.
     */
    public function clearLine(): void;

    /**
     * Clears from the cursor to the end of the screen.
     */
    public function clearFromCursor(): void;

    /**
     * Clears the whole screen.
     */
    public function clearScreen(): void;

    /**
     * Sets the terminal window title.
     */
    public function setTitle(string $title): void;
}
