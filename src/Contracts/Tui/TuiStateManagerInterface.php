<?php

declare(strict_types=1);

namespace Milpa\Live\Contracts\Tui;

/**
 * Persists an opaque TUI session state array across process runs (e.g. to
 * a JSON file), so an interactive loop can resume where a previous
 * invocation left off. State shape is entirely caller-defined; this
 * contract only owns the load/save/clear lifecycle.
 */
interface TuiStateManagerInterface
{
    /**
     * Loads the previously saved state, if any.
     *
     * @return array<string, mixed>|false `false` specifically (not an empty array or `null`) when there is no
     *                                    persisted state to restore — e.g. no session file exists yet, or the
     *                                    persisted data was unreadable/corrupt. Callers MUST distinguish this
     *                                    from a legitimately empty saved state (`[]`).
     */
    public function loadState(): array|false;

    /**
     * Persists `$state`, replacing whatever was previously saved.
     *
     * @param array<string, mixed> $state
     *
     * @return bool Whether the save succeeded. Implementations MUST NOT throw for an
     *              ordinary I/O failure (e.g. an unwritable path) — the caller decides
     *              whether a failed save is fatal to the interactive session.
     */
    public function saveState(array $state): bool;

    /**
     * Discards any persisted state. MUST be a no-op, not an error, if no
     * state was ever saved.
     */
    public function clearSession(): void;
}
