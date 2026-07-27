<?php

declare(strict_types=1);

namespace Milpa\Live\Tui;

/**
 * Fuzzy matching utilities. The PHP port of pi-tui's `fuzzy.ts`. A query
 * matches a text when all of its characters appear in order (not
 * necessarily consecutive). A lower score is a better match. The score
 * rewards consecutive matches and word-boundary hits, penalizes gaps,
 * and breaks ties toward earlier matches — so typing `mtch` ranks
 * `match` above `mat_tooth` even though both match.
 *
 * `fuzzyFilter()` takes a list of items, a query, and a text extractor,
 * and returns the items whose text matches every whitespace/slash-
 * separated token of the query, sorted best-first. This is what a
 * `SelectList` filter, a command palette, or an autocomplete dropdown
 * uses to rank results.
 *
 * The secondary "swap digits/letters" fallback from pi-tui is preserved
 * so `4u` still matches `for you` (alpha-numeric swap). Pure utility,
 * no dependencies — safe to call from any renderer or test.
 */
final class FuzzyMatcher
{
    /**
     * Whether `$query` fuzzy-matches `$text` and, if so, how good the
     * match is. Lower score = better. Returns `{matches: false, score: 0}`
     * when there is no match.
     *
     * @return array{matches: bool, score: float}
     */
    public static function match(string $query, string $text): array
    {
        $queryLower = mb_strtolower($query, 'UTF-8');
        $textLower = mb_strtolower($text, 'UTF-8');

        $primary = self::matchQuery($queryLower, $textLower);
        if ($primary['matches']) {
            return $primary;
        }

        if (preg_match('/^([a-z]+)([0-9]+)$/', $queryLower, $m)) {
            $swapped = $m[2] . $m[1];
            $swappedMatch = self::matchQuery($swapped, $textLower);
            if ($swappedMatch['matches']) {
                return ['matches' => true, 'score' => $swappedMatch['score'] + 5];
            }
        } elseif (preg_match('/^([0-9]+)([a-z]+)$/', $queryLower, $m)) {
            $swapped = $m[2] . $m[1];
            $swappedMatch = self::matchQuery($swapped, $textLower);
            if ($swappedMatch['matches']) {
                return ['matches' => true, 'score' => $swappedMatch['score'] + 5];
            }
        }

        return $primary;
    }

    /**
     * The items whose text fuzzily matches the query, best match first.
     *
     * @param array<int, mixed>       $items
     * @param callable(mixed): string $getText
     *
     * @return array<int, mixed>
     */
    public static function filter(array $items, string $query, callable $getText): array
    {
        $query = trim($query);
        if ($query === '') {
            return array_values($items);
        }
        $tokens = preg_split('/[\s\/]+/u', $query) ?: [$query];
        $tokens = array_values(array_filter($tokens, static fn (string $t): bool => $t !== ''));
        if ($tokens === []) {
            return array_values($items);
        }

        $results = [];
        foreach ($items as $item) {
            $text = $getText($item);
            $totalScore = 0.0;
            $allMatch = true;
            foreach ($tokens as $token) {
                $m = self::match($token, $text);
                if ($m['matches']) {
                    $totalScore += $m['score'];
                } else {
                    $allMatch = false;
                    break;
                }
            }
            if ($allMatch) {
                $results[] = ['item' => $item, 'score' => $totalScore];
            }
        }

        usort($results, static fn (array $a, array $b): int => $a['score'] <=> $b['score']);

        return array_map(static fn (array $r): mixed => $r['item'], $results);
    }

    /**
     * @return array{matches: bool, score: float}
     */
    private static function matchQuery(string $query, string $text): array
    {
        if ($query === '') {
            return ['matches' => true, 'score' => 0.0];
        }
        if (mb_strlen($query, 'UTF-8') > mb_strlen($text, 'UTF-8')) {
            return ['matches' => false, 'score' => 0.0];
        }

        $queryChars = mb_str_split($query, 1, 'UTF-8');
        $textChars = mb_str_split($text, 1, 'UTF-8');
        $queryIndex = 0;
        $score = 0.0;
        $lastMatchIndex = -1;
        $consecutiveMatches = 0;

        for ($i = 0, $n = count($textChars); $i < $n && $queryIndex < count($queryChars); $i++) {
            if ($textChars[$i] === $queryChars[$queryIndex]) {
                $isWordBoundary = $i === 0 || preg_match('/[\s\-_\/:]/', $textChars[$i - 1]) === 1;
                if ($lastMatchIndex === $i - 1) {
                    $consecutiveMatches++;
                    $score -= $consecutiveMatches * 5;
                } else {
                    $consecutiveMatches = 0;
                    if ($lastMatchIndex >= 0) {
                        $score += ($i - $lastMatchIndex - 1) * 2;
                    }
                }
                if ($isWordBoundary) {
                    $score -= 10;
                }
                $score += $i * 0.1;
                $lastMatchIndex = $i;
                $queryIndex++;
            }
        }

        if ($queryIndex < count($queryChars)) {
            return ['matches' => false, 'score' => 0.0];
        }
        if ($query === $text) {
            $score -= 100;
        }

        return ['matches' => true, 'score' => $score];
    }
}
