<?php

declare(strict_types=1);

namespace Milpa\Live\Contracts\Tui;

use Milpa\Live\ValueObjects\Tui\ShortcutBinding;

/**
 * Registers and resolves keyboard shortcut bindings by key within a scope
 * (e.g. a modal's own scope vs the `'global'` default), so a TUI runtime
 * can look up "what command does this keypress trigger right now" without
 * every screen/overlay maintaining its own key-to-command table.
 */
interface ShortcutRegistryInterface
{
    /**
     * Registers a binding, replacing any existing binding for the same
     * key within the same scope.
     */
    public function register(ShortcutBinding $binding): void;

    /**
     * Resolves the binding for `$key` within `$scope`. Implementations
     * SHOULD fall back to the `'global'` scope when no binding is
     * registered for `$key` in the requested (non-global) scope, so
     * global shortcuts remain reachable from any scope.
     *
     * @return ShortcutBinding|null `null` if no binding matches, in either the requested scope or the
     *                              global fallback.
     */
    public function resolve(string $key, string $scope = 'global'): ?ShortcutBinding;

    /**
     * All registered bindings, optionally filtered to one scope.
     *
     * @return array<int, ShortcutBinding> All bindings if `$scope` is `null`; only that scope's bindings otherwise.
     */
    public function all(?string $scope = null): array;
}
