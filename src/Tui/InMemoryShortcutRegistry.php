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

use Milpa\Live\Contracts\Tui\ShortcutRegistryInterface;
use Milpa\Live\ValueObjects\Tui\ShortcutBinding;

/**
 * Keyboard shortcuts held in memory and resolved per scope, so the same key can
 * mean different things depending on which surface has focus.
 */
final class InMemoryShortcutRegistry implements ShortcutRegistryInterface
{
    /**
     * @var array<string, ShortcutBinding>
     */
    private array $bindings = [];

    /**
     * Registers a binding; a later binding for the same key and scope replaces it.
     */
    public function register(ShortcutBinding $binding): void
    {
        $this->bindings[$this->key($binding->scope, $binding->key)] = $binding;
    }

    /**
     * The binding for this key in this scope, or null when nothing claims it.
     */
    public function resolve(string $key, string $scope = 'global'): ?ShortcutBinding
    {
        return $this->bindings[$this->key($scope, $key)]
            ?? $this->bindings[$this->key('global', $key)]
            ?? null;
    }

    /**
     * Every registered binding, or only those of the given scope.
     */
    public function all(?string $scope = null): array
    {
        return array_values(array_filter(
            $this->bindings,
            static fn (ShortcutBinding $binding): bool => $scope === null || $binding->scope === $scope,
        ));
    }

    private function key(string $scope, string $key): string
    {
        return strtolower($scope . ':' . $key);
    }
}
