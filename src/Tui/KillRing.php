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

/**
 * Ring buffer for Emacs-style kill/yank operations. The PHP port of
 * pi-tui's `KillRing`. Tracks killed (deleted) text entries so the
 * user can yank (paste) them back. Consecutive kills can accumulate
 * into a single entry — backward deletion prepends, forward deletion
 * appends — matching the Emacs behavior a `Ctrl+W` / `Ctrl+U` / `Alt+D`
 * key handler chain expects.
 *
 * Pure state holder — not a renderer, not a contract. A `text-input`
 * or `editor` key handler owns one instance and feeds it as the user
 * deletes text; the yank handler reads from it. The ring is bounded
 * only by usage; in practice a few entries are enough.
 */
final class KillRing
{
    /** @var array<int, string> */
    private array $ring = [];

    /**
     * Adds killed text to the ring. When `accumulate` is true and the
     * ring is non-empty, merges with the most recent entry instead of
     * pushing a new one — prepending for backward deletion, appending
     * for forward deletion. Empty text is a no-op.
     *
     * @param array{prepend: bool, accumulate?: bool} $opts
     */
    public function push(string $text, array $opts): void
    {
        if ($text === '') {
            return;
        }
        $accumulate = $opts['accumulate'] ?? false;
        $prepend = $opts['prepend'];
        if ($accumulate && $this->ring !== []) {
            $last = array_pop($this->ring);
            $this->ring[] = $prepend ? $text . $last : $last . $text;
        } else {
            $this->ring[] = $text;
        }
    }

    /**
     * Returns the most recent entry without modifying the ring, or
     * `null` when empty.
     */
    public function peek(): ?string
    {
        return $this->ring === [] ? null : $this->ring[count($this->ring) - 1];
    }

    /**
     * Rotates the last entry to the front so the next {@see peek()}
     * returns the previous kill — the "yank-pop" cycle. A no-op when
     * the ring holds fewer than two entries.
     */
    public function rotate(): void
    {
        if (count($this->ring) > 1) {
            $last = array_pop($this->ring);
            array_unshift($this->ring, $last);
        }
    }

    /**
     * How many entries the ring currently holds.
     */
    public function length(): int
    {
        return count($this->ring);
    }

    public function clear(): void
    {
        $this->ring = [];
    }
}
