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

use Milpa\Live\Contracts\Tui\TuiNodeRendererInterface;
use Milpa\Live\Contracts\Tui\TuiNodeRendererRegistryInterface;
use Milpa\Live\ValueObjects\Tui\TuiNode;

/**
 * Resolves which renderer paints a node, by asking each registered renderer
 * whether it supports it. First match wins, so a catch-all renderer must be
 * registered last.
 */
final class TuiNodeRendererRegistry implements TuiNodeRendererRegistryInterface
{
    /**
     * @var array<int, TuiNodeRendererInterface>
     */
    private array $renderers = [];

    /**
     * Adds a renderer to the resolution order. First registered is first asked, so
     * a catch-all renderer must go last.
     */
    public function register(TuiNodeRendererInterface $renderer): void
    {
        array_unshift($this->renderers, $renderer);
    }

    /**
     * The first registered renderer that supports this node, or null when none
     * does — an unknown type resolves to nothing rather than being guessed at.
     */
    public function resolve(TuiNode $node): ?TuiNodeRendererInterface
    {
        foreach ($this->renderers as $renderer) {
            if ($renderer->supports($node)) {
                return $renderer;
            }
        }

        return null;
    }
}
