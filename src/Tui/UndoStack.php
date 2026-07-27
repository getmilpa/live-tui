<?php

declare(strict_types=1);

namespace Milpa\Live\Tui;

/**
 * Generic undo stack with clone-on-push semantics. The PHP port of
 * pi-tui's `UndoStack<S>`. Stores deep clones of state snapshots so
 * the caller can mutate the working state freely without worrying
 * that popping a past snapshot will alias to the current one.
 *
 * PHP has no `structuredClone()` (JS does), so deep cloning is done
 * via `serialize()`/`unserialize()` — which handles arrays, scalars,
 * and any serializable object. For objects that are NOT serializable
 * (closures, resources, …) the caller should pass only POD state
 * (arrays of primitives) to the stack, which is the expected use
 * case for editor/input undo (text + cursor position).
 *
 * Pure state holder — not a renderer, not a contract. An editor or
 * text-input key handler owns one instance, pushes a snapshot before
 * each mutating operation, and pops on `Ctrl+Z` / `Ctrl+Y`.
 *
 * @template S
 */
final class UndoStack
{
    /** @var array<int, mixed> */
    private array $stack = [];

    /**
     * Pushes a deep clone of `$state` onto the stack.
     *
     * @param mixed $state
     */
    public function push(mixed $state): void
    {
        $this->stack[] = $this->clone($state);
    }

    /**
     * Pops and returns the most recent snapshot, or `null` if empty.
     * The returned snapshot is already detached (it was cloned on
     * push), so no re-cloning is needed.
     *
     * @return mixed
     */
    public function pop(): mixed
    {
        return $this->stack === [] ? null : array_pop($this->stack);
    }

    public function clear(): void
    {
        $this->stack = [];
    }

    /**
     * How many states the stack currently holds.
     */
    public function length(): int
    {
        return count($this->stack);
    }

    private function clone(mixed $state): mixed
    {
        if ($state === null || is_scalar($state)) {
            return $state;
        }
        if (is_array($state)) {
            try {
                return unserialize(serialize($state), ['allowed_classes' => false]);
            } catch (\Throwable) {
                return array_map(fn (mixed $v): mixed => $this->clone($v), $state);
            }
        }

        return $state;
    }
}
