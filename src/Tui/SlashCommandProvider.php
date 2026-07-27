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

use Milpa\Live\Contracts\Tui\AutocompleteProviderInterface;

/**
 * Slash-command autocomplete provider. Triggers when the cursor follows
 * a `/` that is either at the start of the text or follows whitespace —
 * so `/` inside a file path (`./foo/bar`) does NOT trigger command
 * completion. Suggestions are filtered and ranked via
 * {@see FuzzyMatcher::filter()} so typing `/h` ranks `help` and
 * `history` near the top.
 *
 * The provider is pure-in-memory: the caller hands the command table
 * at construction time and the provider never touches the filesystem.
 */
final class SlashCommandProvider implements AutocompleteProviderInterface
{
    /** @param array<int, array{name: string, description?: string}> $commands */
    public function __construct(
        private readonly array $commands,
    ) {
    }

    /**
     * Whether the text at the cursor begins a slash command.
     */
    public function shouldTrigger(string $text, int $cursor): bool
    {
        $before = mb_substr($text, 0, $cursor, 'UTF-8');
        $slashPos = mb_strrpos($before, '/', 0, 'UTF-8');
        if ($slashPos === false) {
            return false;
        }
        if ($slashPos > 0 && !preg_match('/\s/', mb_substr($before, $slashPos - 1, 1, 'UTF-8'))) {
            return false;
        }
        $token = mb_substr($before, $slashPos + 1, null, 'UTF-8');
        if ($token === '') {
            return true;
        }

        return !preg_match('/\s/', $token);
    }

    /**
     * The commands matching what has been typed after the slash.
     */
    public function suggestions(string $text, int $cursor): array
    {
        $before = mb_substr($text, 0, $cursor, 'UTF-8');
        $slashPos = mb_strrpos($before, '/', 0, 'UTF-8');
        if ($slashPos === false) {
            return [];
        }
        $query = mb_substr($before, $slashPos + 1, null, 'UTF-8');

        $filtered = FuzzyMatcher::filter(
            $this->commands,
            $query,
            static fn (array $c): string => $c['name'],
        );

        return array_map(static fn (array $c): array => [
            'value' => '/' . $c['name'],
            'label' => '/' . $c['name'],
            'description' => $c['description'] ?? '',
        ], $filtered);
    }
}
