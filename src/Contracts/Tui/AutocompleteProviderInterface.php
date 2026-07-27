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

namespace Milpa\Live\Contracts\Tui;

/**
 * Suggests completions for a text-input or editor field. The TUI analog
 * of pi-tui's `AutocompleteProvider` interface. A provider answers two
 * questions: "does the current text+cursor trigger a completion query?"
 * (via {@see shouldTrigger()}) and "what completions match the query?"
 * (via {@see suggestions()}). The renderer or key handler that owns
 * the field calls these to populate a `select-list` overlay showing
 * the matches.
 *
 * Implementations are expected to be cheap and synchronous — a provider
 * is consulted on every keystroke when a completion window is open,
 * so blocking I/O or expensive lookups should be offloaded by the
 * caller, not baked into the contract. Pure in-memory providers
 * (slash commands, a small file list) are the intended use case.
 */
interface AutocompleteProviderInterface
{
    /**
     * Whether the cursor at `$cursor` in `$text` should open a
     * completion window. The provider decides what triggers a query
     * — e.g. a slash command provider answers true when the cursor
     * follows a `/` not preceded by a non-whitespace char, a file
     * path provider answers true for `./`, `~/`, `../`, `@` prefixes.
     */
    public function shouldTrigger(string $text, int $cursor): bool;

    /**
     * Returns the completion matches for the query extracted from
     * `$text` at `$cursor`. Each suggestion is a `{value, label,
     * description?}` array shaped the same way as a `select-list`
     * item, so the caller can hand the result straight to a
     * `SelectListRenderer` overlay.
     *
     * @return array<int, array{value: string, label: string, description?: string}>
     */
    public function suggestions(string $text, int $cursor): array;
}
