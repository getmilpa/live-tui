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

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * One mounted Live component as seen by the terminal: pairs the component
 * definition with the state snapshot it was mounted with, so a renderer can
 * paint it without reaching back into the component registry.
 */
final class TuiComponentInstance
{
    /**
     * @param array<string, mixed> $props
     */
    public function __construct(
        public readonly string $id,
        public readonly ComponentDefinitionInterface $component,
        public readonly ComponentContext $context,
        public array $props = [],
        public ?StateSnapshot $state = null,
        public int $cursor = 0,
    ) {
        if ($this->id === '') {
            throw new \InvalidArgumentException('TUI component instance id cannot be empty.');
        }
    }

    /**
     * The contract name of the mounted component.
     */
    public function componentName(): string
    {
        return $this->component::contract()->name;
    }

    /**
     * The state snapshot this instance was mounted with.
     */
    public function mountedState(): StateSnapshot
    {
        $this->state ??= $this->component->mount($this->props, $this->context);

        return $this->state;
    }
}
