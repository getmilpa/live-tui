<?php

declare(strict_types=1);

namespace Milpa\Live\ValueObjects\Tui;

/**
 * One keyboard shortcut binding registered with a
 * {@see \Milpa\Live\Contracts\Tui\ShortcutRegistryInterface}: a key bound
 * to a command name within a scope.
 */
final readonly class ShortcutBinding
{
    /**
     * @param string $key     The keypress this binding matches (registry-defined format, e.g. `'ctrl+p'`).
     * @param string $command The command name to dispatch when `$key` is pressed within `$scope`.
     * @param string $label   Human-readable description, for a shortcut/command palette listing.
     * @param string $scope   The scope this binding applies in; `'global'` is reachable from any scope
     *                        (see {@see \Milpa\Live\Contracts\Tui\ShortcutRegistryInterface::resolve()}).
     *
     * @throws \InvalidArgumentException If `$key` or `$command` is empty.
     */
    public function __construct(
        public string $key,
        public string $command,
        public string $label,
        public string $scope = 'global',
    ) {
        if ($key === '' || $command === '') {
            throw new \InvalidArgumentException('Shortcut key and command cannot be empty.');
        }
    }
}
