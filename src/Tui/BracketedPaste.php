<?php

declare(strict_types=1);

namespace Milpa\Live\Tui;

use Milpa\Live\Contracts\Tui\TuiEventBusInterface;
use Milpa\Live\ValueObjects\Tui\TuiEvent;

/**
 * Detects bracketed-paste markers in the raw terminal input stream and
 * turns a paste into a single `paste.received` event on the
 * {@see TuiEventBusInterface}, instead of letting the loop dispatch the
 * pasted bytes one key at a time. The TUI analog of pi-tui's bracketed
 * paste handling.
 *
 * Bracketed paste mode is a terminal feature where pastes are wrapped
 * in `\x1b[200~` ... `\x1b[201~`; the application enables it (via the
 * DECSET 2004 escape) and the terminal emits the markers around any
 * pasted block, letting the app treat pastes specially (collapse to a
 * `[paste +N lines]` marker, route to a single field, validate, …)
 * instead of replaying the content as keystrokes.
 *
 * This class is a pure detector + event emitter: it does NOT enable
 * DECSET 2004 itself — that is the loop's job, because enabling the
 * mode belongs at TTY setup time alongside raw-mode toggles. The
 * detector only inspects each incoming byte chunk and, when it sees a
 * paste start marker, buffers everything until the matching end marker
 * arrives, then publishes the event. Anything outside a paste window
 * is returned to the caller as "passthrough" bytes so the loop can
 * dispatch them as normal keys.
 *
 * When `collapse` is true, the event payload carries a short marker
 * string (`[paste +N lines]`) instead of the raw content — matching
 * pi-tui's behavior for pastes longer than 10 lines, useful when the
 * TUI does not want to paste the content inline but show that a paste
 * happened and let the user expand it.
 */
final class BracketedPaste
{
    public const MODE_BEGIN = "\x1b[?2004h";
    public const MODE_END = "\x1b[?2004l";
    public const PASTE_BEGIN = "\x1b[200~";
    public const PASTE_END = "\x1b[201~";

    private bool $inPaste = false;

    private string $buffer = '';

    public function __construct(
        private readonly ?TuiEventBusInterface $events = null,
        private readonly bool $collapse = true,
        private readonly int $collapseThreshold = 10,
    ) {
    }

    /**
     * Feeds one chunk of raw terminal bytes to the detector. Returns the
     * slice of those bytes that should be dispatched as normal keys —
     * i.e. everything outside a paste window. Bytes inside a paste
     * window are buffered and emitted as a `paste.received` event once
     * the end marker arrives; they are NOT returned here.
     */
    public function feed(string $bytes): string
    {
        $passthrough = '';
        $i = 0;
        $len = strlen($bytes);

        while ($i < $len) {
            if ($this->inPaste) {
                $endPos = strpos($bytes, self::PASTE_END, $i);
                if ($endPos === false) {
                    $this->buffer .= substr($bytes, $i);
                    $i = $len;
                } else {
                    $this->buffer .= substr($bytes, $i, $endPos - $i);
                    $i = $endPos + strlen(self::PASTE_END);
                    $this->finishPaste();
                }
            } else {
                $beginPos = strpos($bytes, self::PASTE_BEGIN, $i);
                if ($beginPos === false) {
                    $passthrough .= substr($bytes, $i);
                    $i = $len;
                } else {
                    $passthrough .= substr($bytes, $i, $beginPos - $i);
                    $i = $beginPos + strlen(self::PASTE_BEGIN);
                    $this->inPaste = true;
                    $this->buffer = '';
                }
            }
        }

        return $passthrough;
    }

    public function isInPaste(): bool
    {
        return $this->inPaste;
    }

    /**
     * How many bytes of an in-flight paste are still buffered.
     */
    public function bufferedLength(): int
    {
        return strlen($this->buffer);
    }

    private function finishPaste(): void
    {
        $content = $this->buffer;
        $this->buffer = '';
        $this->inPaste = false;

        $lineCount = substr_count($content, "\n") + (str_ends_with($content, "\n") ? 0 : 1);
        $payload = [
            'content' => $content,
            'lineCount' => $lineCount,
            'collapsed' => false,
            'marker' => null,
        ];

        if ($this->collapse && $lineCount > $this->collapseThreshold) {
            $payload['collapsed'] = true;
            $payload['marker'] = sprintf('[paste +%d lines]', $lineCount);
            $payload['content'] = '';
        }

        $this->events?->publish(TuiEvent::now('paste.received', $payload, source: 'bracketed-paste'));
    }
}
