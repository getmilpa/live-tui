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

namespace Milpa\Live\Tests\Fixtures;

use Milpa\Live\Contracts\Tui\TerminalInterface;

/**
 * A terminal that exists only in memory: scripted keystrokes go in, every
 * write and lifecycle call is recorded, and nothing touches a real device.
 *
 * This is the whole point of {@see TerminalInterface}. If the interactive loop
 * can be driven by this, it can be driven in CI.
 */
final class FakeTerminal implements TerminalInterface
{
    /** @var list<string> */
    public array $writes = [];

    /** @var list<string> */
    public array $lifecycle = [];

    public bool $cursorVisible = true;

    /** @var list<string> */
    private array $scriptedInput;

    /** @var (callable(): void)|null */
    private $onResize = null;

    /**
     * @param list<string> $scriptedInput One entry is delivered per poll, in order.
     *                                    An empty entry models a tick with no key.
     */
    public function __construct(
        array $scriptedInput = [],
        private int $columns = 40,
        private int $rows = 6,
    ) {
        $this->scriptedInput = $scriptedInput;
    }

    /**
     * Records that the session started. The callback is kept unused on
     * purpose: this terminal is a pulling one, which is the case the contract
     * did not cover before {@see TerminalInterface::pollInput()} existed.
     */
    public function start(callable $onInput, callable $onResize): void
    {
        $this->onResize = $onResize;
        $this->lifecycle[] = 'start';
    }

    /**
     * Simulates the terminal being resized: changes what it reports and fires
     * the callback, exactly as a SIGWINCH would.
     */
    public function resizeTo(int $columns, int $rows): void
    {
        $this->columns = $columns;
        $this->rows = $rows;

        if ($this->onResize !== null) {
            ($this->onResize)();
        }
    }

    /**
     * Records that the session ended.
     */
    public function stop(): void
    {
        // The contract says stop() restores the terminal to how it was found,
        // cursor included. A fake that does not honour that would let the loop
        // get away with leaving the cursor hidden.
        $this->cursorVisible = true;
        $this->lifecycle[] = 'stop';
    }

    /**
     * Records the bytes instead of emitting them.
     */
    public function write(string $data): void
    {
        $this->writes[] = $data;
    }

    /**
     * The next scripted chunk, or `''` once the script runs out.
     */
    public function pollInput(): string
    {
        return array_shift($this->scriptedInput) ?? '';
    }

    /**
     * True once the script is exhausted.
     */
    public function atEndOfInput(): bool
    {
        return $this->scriptedInput === [];
    }

    /**
     * The fixed width this fake reports.
     */
    public function columns(): int
    {
        return $this->columns;
    }

    /**
     * The fixed height this fake reports.
     */
    public function rows(): int
    {
        return $this->rows;
    }

    /**
     * Records a relative cursor move.
     */
    public function moveBy(int $lines): void
    {
        $this->lifecycle[] = 'moveBy:' . $lines;
    }

    /**
     * Records that the cursor was hidden.
     */
    public function hideCursor(): void
    {
        $this->cursorVisible = false;
        $this->lifecycle[] = 'hideCursor';
    }

    /**
     * Records that the cursor was shown.
     */
    public function showCursor(): void
    {
        $this->cursorVisible = true;
        $this->lifecycle[] = 'showCursor';
    }

    /**
     * Records a single-line clear.
     */
    public function clearLine(): void
    {
        $this->lifecycle[] = 'clearLine';
    }

    /**
     * Records a clear from the cursor down.
     */
    public function clearFromCursor(): void
    {
        $this->lifecycle[] = 'clearFromCursor';
    }

    /**
     * Records a full-screen clear.
     */
    public function clearScreen(): void
    {
        $this->lifecycle[] = 'clearScreen';
    }

    /**
     * Records a title change.
     */
    public function setTitle(string $title): void
    {
        $this->lifecycle[] = 'setTitle:' . $title;
    }

    /**
     * Everything written so far, concatenated.
     */
    public function output(): string
    {
        return implode('', $this->writes);
    }
}
