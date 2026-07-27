<?php

declare(strict_types=1);

namespace Milpa\Live\Tui;

/**
 * Buffers raw stdin bytes and emits complete terminal sequences. The
 * PHP port of pi-tui's `StdinBuffer`. Necessary because stdin reads
 * can return partial chunks — a mouse event `\x1b[<35;20;5m` might
 * arrive as three reads, and a CSI sequence `\x1b[A` might share a
 * chunk with the next keypress. Without buffering, partial escape
 * sequences get misinterpreted as regular keypresses.
 *
 * The buffer accumulates bytes in `feed()` and returns any
 * *completed* sequences. A bare ESC that is not followed by anything
 * more is held for one more feed — it might be the start of a longer
 * escape sequence, or a bare Escape key. The caller should call
 * `flush()` on idle to emit a pending bare ESC as a complete key.
 *
 * Recognized sequence families (mirrors pi-tui):
 *   - CSI: `\x1b[...` (arrow keys, mouse, F-keys, …)
 *   - OSC: `\x1b]...\x07` or `\x1b]...\x1b\\` (title, color, …)
 *   - DCS: `\x1bP...\x1b\\` (kitty graphics, …)
 *   - APC: `\x1b_...\x1b\\` (kitty image responses, …)
 *   - SS3: `\x1bO?` (F1-F4, …)
 *   - Meta: `\x1b<char>` (Alt+key)
 *   - Bracketed paste: `\x1b[200~...\x1b[201~` (passed through intact
 *     so `BracketedPaste` can detect it downstream)
 */
final class InputBuffer
{
    public const ESC = "\x1b";

    private string $buffer = '';

    /**
     * Feeds raw bytes and returns any completed sequences. The
     * returned string contains zero or more complete sequences
     * concatenated in arrival order; anything that is still incomplete
     * is held in the internal buffer for the next call.
     */
    public function feed(string $bytes): string
    {
        if ($bytes === '') {
            return $this->flushMaybe();
        }
        $this->buffer .= $bytes;
        $output = '';
        // `consume()` already returns null once the buffer drains, so checking
        // the buffer again in the loop condition was redundant.
        while (($result = $this->consume()) !== null) {
            $output .= $result;
        }

        return $output;
    }

    /**
     * Emits any pending bare ESC as a complete key. Call on idle —
     * e.g. when the read loop times out with no new input — so a
     * lone Escape is not held forever waiting for a non-coming
     * continuation.
     */
    public function flush(): string
    {
        if ($this->buffer === '') {
            return '';
        }
        $out = $this->buffer;
        $this->buffer = '';

        return $out;
    }

    public function pending(): string
    {
        return $this->buffer;
    }

    public function clear(): void
    {
        $this->buffer = '';
    }

    private function flushMaybe(): string
    {
        return '';
    }

    /**
     * Consumes one complete sequence from the front of the buffer.
     * Returns the consumed bytes, or `null` when the buffer holds an
     * incomplete sequence that needs more input.
     */
    private function consume(): ?string
    {
        if ($this->buffer === '') {
            return null;
        }
        if ($this->buffer[0] !== self::ESC) {
            $pos = strpos($this->buffer, self::ESC);
            if ($pos === false) {
                $out = $this->buffer;
                $this->buffer = '';

                return $out;
            }
            $out = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos);

            return $out;
        }
        if (strlen($this->buffer) === 1) {
            return null;
        }
        $second = $this->buffer[1];
        if ($second === '[') {
            return $this->consumeCsi();
        }
        if ($second === ']') {
            return $this->consumeOsc();
        }
        if ($second === 'P') {
            return $this->consumeStringTerminated('P');
        }
        if ($second === '_') {
            return $this->consumeStringTerminated('_');
        }
        if ($second === 'O') {
            return strlen($this->buffer) >= 3 ? $this->take(3) : null;
        }
        return $this->take(2);
    }

    private function consumeCsi(): ?string
    {
        $len = strlen($this->buffer);
        for ($i = 2; $i < $len; $i++) {
            $ch = $this->buffer[$i];
            $code = ord($ch);
            if ($code >= 0x40 && $code <= 0x7E) {
                return $this->take($i + 1);
            }
        }

        return null;
    }

    private function consumeOsc(): ?string
    {
        $belPos = strpos($this->buffer, "\x07", 2);
        $stPos = strpos($this->buffer, self::ESC . '\\', 2);
        if ($belPos === false && $stPos === false) {
            return null;
        }
        if ($belPos === false) {
            return $this->take($stPos + 2);
        }
        if ($stPos === false) {
            return $this->take($belPos + 1);
        }
        return $belPos < $stPos ? $this->take($belPos + 1) : $this->take($stPos + 2);
    }

    private function consumeStringTerminated(string $prefix): ?string
    {
        $stPos = strpos($this->buffer, self::ESC . '\\', 2);
        if ($stPos === false) {
            return null;
        }

        return $this->take($stPos + 2);
    }

    private function take(int $length): string
    {
        $out = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $out;
    }
}
