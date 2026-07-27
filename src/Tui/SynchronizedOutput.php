<?php

declare(strict_types=1);

namespace Milpa\Live\Tui;

/**
 * Wraps terminal writes in the synchronized-output escape sequence
 * (`\x1b[?2026h` ... `\x1b[?2026l`) so the terminal commits the whole
 * frame atomically instead of drawing it row-by-row. The TUI analog of
 * pi-tui's synchronized-output mode. Terminals that do not understand
 * the mode ignore the escapes, so wrapping is always safe; terminals
 * that do (modern xterm, Kitty, WezTerm, iTerm2, Alacritty, Windows
 * Terminal) stop intermediate paints until the closing escape arrives,
 * eliminating the flicker a row-by-row diff would otherwise introduce.
 *
 * This is a pure helper: callers hand it the bytes they were going to
 * write, and `wrap()` returns those same bytes framed by the mode
 * toggles. `RetainedTuiLoop` uses it on every `paintFrame()` /
 * `renderScreen()` write so the loop itself never has to know about
 * the sequence, and a non-TTY caller (tests, scripted harness) can
 * verify the wraps are present without changing the loop body.
 *
 * `enabled()` defaults to true; pass `false` to bypass the wrapping
 * (useful for tests that want to inspect raw diff escapes without the
 * mode toggles interleaved, or for terminals known not to support it).
 */
final class SynchronizedOutput
{
    public const MODE_BEGIN = "\x1b[?2026h";
    public const MODE_END = "\x1b[?2026l";

    public function __construct(
        private readonly bool $enabled = true,
    ) {
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Returns `$bytes` framed by the synchronized-output mode toggles
     * when enabled, or unchanged when disabled. The toggles are
     * emitted on every call so a caller can batch any number of frames
     * without tracking state.
     */
    public function wrap(string $bytes): string
    {
        if (!$this->enabled || $bytes === '') {
            return $bytes;
        }

        return self::MODE_BEGIN . $bytes . self::MODE_END;
    }
}
