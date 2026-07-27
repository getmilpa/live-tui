<?php

declare(strict_types=1);

namespace Milpa\Live\Tui;

use Milpa\Live\Contracts\Tui\AutocompleteProviderInterface;

/**
 * Combined autocomplete provider for slash commands and file paths.
 * The PHP analog of pi-tui's `CombinedAutocompleteProvider`. Delegates
 * to a {@see SlashCommandProvider} when the cursor follows a `/`
 * not preceded by a non-whitespace char, and to a {@see FilePathProvider}
 * when the cursor is inside a `./`, `~/`, `../`, or `@`-prefixed token.
 * When neither applies, answers no completions.
 *
 * Both sub-providers rank matches via {@see FuzzyMatcher::filter()} so
 * the user can type loose substrings and still see the right entries
 * float to the top. The combined provider does NOT merge results from
 * both sub-providers — at most one fires per cursor position, mirroring
 * pi-tui's "type `/` for commands, Tab for files" UX.
 */
final class CombinedAutocompleteProvider implements AutocompleteProviderInterface
{
    public function __construct(
        private readonly SlashCommandProvider $commands,
        private readonly FilePathProvider $files,
    ) {
    }

    /**
     * Whether any of the combined providers wants to complete at this cursor.
     */
    public function shouldTrigger(string $text, int $cursor): bool
    {
        return $this->commands->shouldTrigger($text, $cursor)
            || $this->files->shouldTrigger($text, $cursor);
    }

    /**
     * The suggestions of every provider that triggers, in provider order.
     */
    public function suggestions(string $text, int $cursor): array
    {
        if ($this->commands->shouldTrigger($text, $cursor)) {
            return $this->commands->suggestions($text, $cursor);
        }
        if ($this->files->shouldTrigger($text, $cursor)) {
            return $this->files->suggestions($text, $cursor);
        }

        return [];
    }

    /**
     * Replaces the complete token around the cursor with a selected value.
     *
     * @return array{text: string, cursor: int}
     */
    public function acceptSuggestion(string $text, int $cursor, string $value): array
    {
        $before = mb_substr($text, 0, $cursor, 'UTF-8');
        $spaceBefore = mb_strrpos($before, ' ', 0, 'UTF-8');
        $tokenStart = $spaceBefore === false ? 0 : $spaceBefore + 1;

        $afterCursor = mb_substr($text, $cursor, null, 'UTF-8');
        $spaceAfter = mb_strpos($afterCursor, ' ', 0, 'UTF-8');
        $tail = $spaceAfter === false
            ? ''
            : ltrim(mb_substr($afterCursor, $spaceAfter + 1, null, 'UTF-8'));

        $insert = rtrim($value) . ' ';
        $prefix = mb_substr($text, 0, $tokenStart, 'UTF-8');

        return [
            'text' => $prefix . $insert . $tail,
            'cursor' => $tokenStart + mb_strlen($insert, 'UTF-8'),
        ];
    }
}
