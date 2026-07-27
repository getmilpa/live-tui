<?php

declare(strict_types=1);

namespace Milpa\Live\Contracts\Tui;

/**
 * Ordered-id rotation for "which thing has keyboard focus right now",
 * shared by both TUI runtimes. {@see \Milpa\Live\Tui\FocusManager} is the
 * one implementation; putting it behind this contract lets
 * {@see \Milpa\Live\Tui\RetainedTuiLoop} reuse it instead of
 * reimplementing the same modulo-wrapped rotation math ad hoc against a
 * bare string.
 */
interface FocusManagerInterface
{
    /**
     * The id currently holding focus, or null when nothing does.
     *
     * @return string|null The currently focused id, or `null` if {@see ids()} is empty.
     */
    public function currentId(): ?string;

    /**
     * Explicitly focuses `$id`. MUST be a no-op — not an error — if `$id`
     * is not among {@see ids()}, leaving the current focus unchanged.
     */
    public function focus(string $id): void;

    /**
     * Advances focus to the next id, wrapping around to the first id
     * after the last.
     *
     * @return string|null The newly focused id, or `null` if {@see ids()} is empty.
     */
    public function next(): ?string;

    /**
     * Moves focus to the previous id, wrapping around to the last id
     * before the first.
     *
     * @return string|null The newly focused id, or `null` if {@see ids()} is empty.
     */
    public function previous(): ?string;

    /**
     * The full ordered list of ids this manager rotates focus through.
     *
     * @return array<int, string>
     */
    public function ids(): array;
}
