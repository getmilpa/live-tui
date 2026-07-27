<?php

declare(strict_types=1);

namespace Milpa\Live\Tui;

use Milpa\Live\Contracts\Tui\AutocompleteProviderInterface;

/**
 * File-path autocomplete provider. Triggers when the cursor is inside a
 * token that starts with `./`, `~/`, `../`, or `@` (the same prefixes
 * pi-tui recognizes). The provider lists the files inside the directory
 * implied by the prefix and filters them via {@see FuzzyMatcher::filter()}
 * on the basename.
 *
 * Pure-in-memory: the caller supplies a `callable(string $dir): array`
 * directory lister so the provider itself does not call `opendir()` —
 * this keeps it pure and testable, and lets a real harness swap in a
 * cached or async-backed lister later. The lister returns an array of
 * `{name: string, isDir: bool}` entries; the provider shapes them into
 * `select-list` items with a trailing `/` for directories.
 */
final class FilePathProvider implements AutocompleteProviderInterface
{
    public const TRIGGER_PREFIXES = ['./', '~/', '../', '@'];

    /** @param \Closure(string): array<int, array{name: string, isDir: bool}> $lister */
    public function __construct(
        private readonly \Closure $lister,
    ) {
    }

    /**
     * Whether the text at the cursor begins a path worth completing.
     */
    public function shouldTrigger(string $text, int $cursor): bool
    {
        $token = $this->currentToken($text, $cursor);

        foreach (self::TRIGGER_PREFIXES as $prefix) {
            if (str_starts_with($token, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The paths matching the fragment at the cursor.
     */
    public function suggestions(string $text, int $cursor): array
    {
        $token = $this->currentToken($text, $cursor);
        $dir = $this->resolveDir($token);
        $filter = $this->filterPrefix($token);

        $entries = ($this->lister)($dir);
        if (!is_array($entries)) {
            return [];
        }
        $filtered = FuzzyMatcher::filter(
            $entries,
            $filter,
            static fn (array $e): string => $e['name'],
        );

        $out = [];
        foreach ($filtered as $entry) {
            $name = (string) ($entry['name'] ?? '');
            $isDir = (bool) ($entry['isDir'] ?? false);
            $value = $this->joinPath($token, $name) . ($isDir ? '/' : '');
            $out[] = [
                'value' => $value,
                'label' => $name . ($isDir ? '/' : ''),
                'description' => $isDir ? 'directory' : 'file',
            ];
        }

        return $out;
    }

    private function currentToken(string $text, int $cursor): string
    {
        $before = mb_substr($text, 0, $cursor, 'UTF-8');
        $spacePos = mb_strrpos($before, ' ', 0, 'UTF-8');
        $start = $spacePos === false ? 0 : $spacePos + 1;

        return mb_substr($text, $start, $cursor - $start, 'UTF-8');
    }

    private function resolveDir(string $token): string
    {
        foreach (self::TRIGGER_PREFIXES as $prefix) {
            if (str_starts_with($token, $prefix)) {
                if ($prefix === '@') {
                    return './';
                }
                if ($prefix === '~/') {
                    $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';

                    return $home . '/';
                }

                return $prefix;
            }
        }

        return './';
    }

    private function filterPrefix(string $token): string
    {
        foreach (self::TRIGGER_PREFIXES as $prefix) {
            if (str_starts_with($token, $prefix)) {
                $remainder = substr($token, strlen($prefix));
                $slashPos = strrpos($remainder, '/');
                if ($slashPos === false) {
                    return $remainder;
                }

                return substr($remainder, $slashPos + 1);
            }
        }

        return '';
    }

    private function joinPath(string $token, string $name): string
    {
        foreach (self::TRIGGER_PREFIXES as $prefix) {
            if (str_starts_with($token, $prefix)) {
                $remainder = substr($token, strlen($prefix));
                $slashPos = strrpos($remainder, '/');
                if ($slashPos === false) {
                    // The token is `prefix + filter`, with no sub-path.
                    // Replace the filter portion entirely with $name.
                    return $prefix . $name;
                }

                return $prefix . substr($remainder, 0, $slashPos + 1) . $name;
            }
        }

        return $name;
    }
}
