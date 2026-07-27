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
 * Detects terminal capabilities from the `TERM` / `TERM_PROGRAM`
 * environment variables and the ` kitty keyboard protocol` negotiation
 * response. The PHP analog of pi-tui's capability detection in
 * `terminal.ts` / `terminal-image.ts`. Lets a TUI bootstrap decide,
 * without spawning subprocesses, whether to:
 *   - emit Kitty/iTerm2 inline-image escapes (`ImageRenderer`)
 *   - emit the Kitty keyboard protocol query (`KeyMatcher`)
 *   - emit OSC 11 background-color query (`TerminalColor`)
 *   - emit the synchronized-output mode toggles (`SynchronizedOutput`)
 *
 * Pure utility — no I/O. The caller reads env vars once and hands
 * them in via the constructor; {@see detect()} then returns an
 * immutable snapshot of what this terminal is known to support.
 */
final class TerminalCapabilities
{
    public const IMAGES_NONE = 'none';
    public const IMAGES_KITTY = 'kitty';
    public const IMAGES_ITERM2 = 'iterm2';

    public const SYNC_NONE = 'none';
    public const SYNC_2026 = '2026';

    public const KEYBOARD_LEGACY = 'legacy';
    public const KEYBOARD_KITTY = 'kitty';

    public function __construct(
        private readonly string $term = '',
        private readonly string $termProgram = '',
        private readonly string $colorTerm = '',
    ) {
    }

    /**
     * Builds a capabilities snapshot from the current `$_ENV` /
     * `getenv()` state. The caller usually wants this at bootstrap.
     */
    public static function fromEnvironment(): self
    {
        return new self(
            term: (string) getenv('TERM'),
            termProgram: (string) getenv('TERM_PROGRAM'),
            colorTerm: (string) getenv('COLORTERM'),
        );
    }

    /**
     * Returns the inline-image protocol this terminal is known to
     * support: {@see IMAGES_KITTY} for Kitty/Ghostty/WezTerm,
     * {@see IMAGES_ITERM2} for iTerm2, {@see IMAGES_NONE} otherwise.
     */
    public function imageProtocol(): string
    {
        $program = strtolower($this->termProgram);
        $term = strtolower($this->term);

        if (in_array($program, ['kitty', 'ghostty', 'wezterm'], true)) {
            return self::IMAGES_KITTY;
        }
        if ($program === 'iterm.app' || $program === 'iterm2') {
            return self::IMAGES_ITERM2;
        }
        if (str_contains($term, 'kitty') || str_contains($term, 'ghostty')) {
            return self::IMAGES_KITTY;
        }

        return self::IMAGES_NONE;
    }

    /**
     * Whether the terminal supports the Kitty keyboard protocol. The
     * caller still has to negotiate it at runtime by emitting the
     * query and reading the flags from the response; this is just
     * the static hint that the negotiation is worth doing.
     */
    public function supportsKittyKeyboard(): bool
    {
        $program = strtolower($this->termProgram);
        $term = strtolower($this->term);

        return in_array($program, ['kitty', 'ghostty', 'wezterm', 'konsole'], true)
            || str_contains($term, 'kitty')
            || str_contains($term, 'ghostty');
    }

    /**
     * Whether the terminal is known to support the synchronized-output
     * mode (CSI 2026). Most modern terminals do; the notable holdout
     * is the macOS Terminal.app, which ignores the escapes but still
     * works fine without atomic writes.
     */
    public function supportsSynchronizedOutput(): bool
    {
        $program = strtolower($this->termProgram);

        return $program !== 'apple_terminal';
    }

    /**
     * Whether the terminal is known to support OSC 11 background-color
     * queries. Used by {@see TerminalColor} to decide whether to emit
     * the query at startup. Conservative: returns true unless we know
     * for a fact the terminal does not answer (e.g. dumb terminals).
     */
    public function supportsOsc11(): bool
    {
        $term = strtolower($this->term);

        return $term !== '' && $term !== 'dumb';
    }

    /**
     * Whether the terminal is known to support 24-bit color (truecolor).
     * Matches the `COLORTERM=truecolor` convention xterm/Kitty/WezTerm
     * set, plus the known-truecolor terminal program names.
     */
    public function supportsTruecolor(): bool
    {
        $colorTerm = strtolower($this->colorTerm);
        if (str_contains($colorTerm, 'truecolor') || str_contains($colorTerm, '24bit')) {
            return true;
        }
        $program = strtolower($this->termProgram);

        return in_array($program, ['kitty', 'ghostty', 'wezterm', 'iterm.app', 'alacritty', 'windows terminal'], true);
    }
}
