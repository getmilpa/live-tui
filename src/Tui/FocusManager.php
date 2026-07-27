<?php

declare(strict_types=1);

namespace Milpa\Live\Tui;

use Milpa\Live\Contracts\Tui\FocusManagerInterface;

/**
 * The default {@see FocusManagerInterface} implementation: modulo-wrapped
 * rotation through a deduplicated, non-empty-string-only id list.
 * `$index` is clamped into range at construction and on every rotation,
 * so it can never point outside {@see ids()} even if constructed with an
 * out-of-range starting index.
 */
final class FocusManager implements FocusManagerInterface
{
    /**
     * @param array<int, string> $ids   The focusable ids to rotate through. Deduplicated and stripped of
     *                                  empty strings before use.
     * @param int                $index Starting position into `$ids`, clamped into range.
     */
    public function __construct(
        private array $ids = [],
        private int $index = 0,
    ) {
        $this->ids = array_values(array_filter(array_unique($ids), static fn (string $id): bool => $id !== ''));
        $this->index = $this->normalize($index);
    }

    /**
     * The id currently holding focus, or null when nothing does.
     */
    public function currentId(): ?string
    {
        if ($this->ids === []) {
            return null;
        }

        return $this->ids[$this->index] ?? $this->ids[0];
    }

    /**
     * Moves focus to this id, if it is part of the focus order.
     */
    public function focus(string $id): void
    {
        $index = array_search($id, $this->ids, true);
        if ($index !== false) {
            $this->index = (int) $index;
        }
    }

    /**
     * Moves focus to the next id in order and returns it, wrapping at the end.
     */
    public function next(): ?string
    {
        if ($this->ids === []) {
            return null;
        }

        $this->index = ($this->index + 1) % count($this->ids);

        return $this->currentId();
    }

    /**
     * Moves focus to the previous id in order and returns it, wrapping at the start.
     */
    public function previous(): ?string
    {
        if ($this->ids === []) {
            return null;
        }

        $this->index = ($this->index - 1 + count($this->ids)) % count($this->ids);

        return $this->currentId();
    }

    /**
     * @return array<int, string>
     */
    public function ids(): array
    {
        return $this->ids;
    }

    private function normalize(int $index): int
    {
        if ($this->ids === []) {
            return 0;
        }

        return max(0, min(count($this->ids) - 1, $index));
    }
}
