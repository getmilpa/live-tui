<?php

declare(strict_types=1);

namespace Milpa\Live\Tui\NodeRenderers;

use Milpa\Live\Contracts\Component\ComponentRegistryInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Contracts\Tui\TuiNodeRendererInterface;
use Milpa\Live\Tui\TuiFrameFactory;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Live\ValueObjects\StateSnapshot;
use Milpa\Live\ValueObjects\Tui\TuiFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;

/**
 * The bridge between the retained TUI tree (layout engine, overlays,
 * {@see \Milpa\Live\Tui\VirtualTerminalBuffer} diffing) and the real
 * component render path -- the same {@see ComponentRendererInterface}
 * seam the HTML renderers use, resolved here for {@see RenderTarget::TUI}.
 * Before this class existed, the retained tree could only host decorative
 * node types (panel/status-bar/job-monitor/...); this makes 'component'
 * a node type that renders an actual autocomplete/data-table/form-field/
 * dashboard-primitive component, painted by whichever
 * ComponentRendererInterface the caller wires in (today: TuiComponentRenderer).
 *
 * Deliberately stateless: a node's props carry the component name, its
 * props, and its already-mounted {@see StateSnapshot} (or null to mount
 * fresh). Owning/caching that state across frames is the job of whatever
 * builds the TuiNode tree each frame (mirroring how every other
 * TuiNodeRendererInterface implementation in this codebase is a pure
 * node-in/frame-out mapping, and how {@see \Milpa\Live\Tui\TuiComponentInstance}
 * already owns state for the legacy loop) -- not this renderer's concern.
 *
 * Caveat for tree-builders: {@see \Milpa\Live\Rendering\TuiComponentRenderer}
 * clamps its own render width to a floor of 40 columns regardless of what
 * is requested. Give a 'component' node bounds narrower than that and the
 * component will render wider than its allotted space; TuiFrameFactory
 * then right-truncates every line to fit, which silently mangles box
 * borders instead of failing loudly. Size 'component' node widths to
 * >= 40 (directly, or via enough flex share of the parent).
 */
final readonly class ComponentTuiNodeRenderer implements TuiNodeRendererInterface
{
    public function __construct(
        private ComponentRegistryInterface $components,
        private ComponentRendererInterface $componentRenderer,
    ) {
    }

    /**
     * Matches nodes of type `'component'`.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'component';
    }

    /**
     * Renders the named component (from `$node->props['component']`)
     * through the injected {@see ComponentRendererInterface} and adapts
     * its text output into a {@see TuiFrame} sized to `$context->bounds`.
     * See the class docblock's caveat: bounds narrower than 40 columns
     * cause silent right-truncation of the component's own output rather
     * than a loud failure.
     *
     * @throws \InvalidArgumentException If the node has no `props['component']` name, or `props['state']` is
     *                                   set but is not a {@see StateSnapshot}.
     * @throws \RuntimeException         If the injected renderer does not support {@see RenderTarget::TUI}.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $name = (string) ($node->props['component'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException(
                "TUI node '{$node->id}' of type 'component' is missing a props['component'] name.",
            );
        }

        if (!$this->componentRenderer->supportsTarget(RenderTarget::TUI)) {
            throw new \RuntimeException(
                'The component renderer injected into ComponentTuiNodeRenderer does not support the TUI render target.',
            );
        }

        $state = $node->props['state'] ?? null;
        if ($state !== null && !$state instanceof StateSnapshot) {
            throw new \InvalidArgumentException(
                "TUI node '{$node->id}' props['state'] must be a StateSnapshot or null.",
            );
        }

        $result = $this->componentRenderer->render(
            $this->components->get($name),
            new RenderRequest(
                context: new ComponentContext((string) ($node->props['contextId'] ?? $node->id)),
                props: (array) ($node->props['props'] ?? []),
                state: $state,
                target: RenderTarget::TUI,
                options: [
                    'width' => $context->bounds->width,
                    'focused' => $context->focused($node),
                    'cursor' => (int) ($node->props['cursor'] ?? 0),
                ],
            ),
        );

        return TuiFrameFactory::fromLines(
            $context->bounds->width,
            $context->bounds->height,
            explode(PHP_EOL, $result->output),
        );
    }
}
