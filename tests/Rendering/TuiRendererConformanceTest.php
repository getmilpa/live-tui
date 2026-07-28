<?php

/**
 * This file is part of Milpa Live TUI — the terminal render target of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/live-tui
 */

declare(strict_types=1);

namespace Milpa\Live\Tests\Rendering;

use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Rendering\TuiComponentRenderer;
use Milpa\Live\Testing\RendersTheSameComponent;
use Milpa\Live\ValueObjects\RenderTarget;
use PHPUnit\Framework\TestCase;

/**
 * This surface, held to the contract package's suite.
 *
 * The assertions are not written here on purpose. They ship in `milpa/live` beside the interface
 * they enforce, so the web target and this one answer the *same* questions rather than each
 * package's idea of them — which is the only way "one component, every surface" can be checked at
 * all, given the two surfaces do not depend on each other and must not start.
 */
final class TuiRendererConformanceTest extends TestCase
{
    use RendersTheSameComponent;

    protected function rendererUnderTest(): ComponentRendererInterface
    {
        return new TuiComponentRenderer();
    }

    protected function targetUnderTest(): RenderTarget
    {
        return RenderTarget::TUI;
    }
}
