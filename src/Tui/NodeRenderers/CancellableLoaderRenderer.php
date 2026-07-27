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

namespace Milpa\Live\Tui\NodeRenderers;

use Milpa\Live\Contracts\Tui\TuiEventBusInterface;
use Milpa\Live\Contracts\Tui\TuiNodeRendererInterface;
use Milpa\Live\Tui\TuiString;
use Milpa\Live\ValueObjects\Tui\TuiEvent;
use Milpa\Live\ValueObjects\Tui\TuiFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;

/**
 * A loader that can be cancelled by the user pressing Escape. The TUI
 * analog of pi-tui's `CancellableLoader`. Extends {@see LoaderRenderer}
 * with an `aborted` visual state and a `loader.aborted` event published
 * to the {@see TuiEventBusInterface} when the node's props carry
 * `aborted: true`. The caller is responsible for routing Escape to set
 * that prop — this renderer does not handle keyboard input itself,
 * keeping the "node-in/frame-out" purity of every other renderer in
 * this namespace.
 *
 * The `loader.aborted` event mirrors the `paste.received` convention
 * (typed string + structured payload) so the same bus subscribers can
 * react to either. Payload: `{nodeId, label, reason}`.
 *
 * Node props (all optional, in addition to LoaderRenderer's):
 *  - `aborted`   bool   Show the canceled state and publish the event when true. Default: false.
 *  - `nodeId`    string Id of the node, included in the event payload for routing. Default: node.id.
 *  - `reason`    string Caller-provided abort reason, included in the event payload. Default: 'user_canceled'.
 */
final class CancellableLoaderRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
{
    public function __construct(
        private readonly ?TuiEventBusInterface $events = null,
    ) {
    }

    /**
     * True only for `cancellable-loader` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'cancellable-loader';
    }

    /**
     * Draws the spinner together with the hint that Escape cancels it.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $aborted = (bool) ($node->props['aborted'] ?? false);
        $message = (string) ($node->props['message'] ?? 'Working…');
        $frames = is_array($node->props['frames'] ?? null) && $node->props['frames'] !== []
            ? array_map('strval', $node->props['frames'])
            : ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
        $frameIndex = (int) ($node->props['frame'] ?? 0);
        $width = $context->bounds->width;

        if ($aborted) {
            $this->publishAbort($node);
            $glyph = '◌';
            $stateMessage = (string) ($node->props['abortMessage'] ?? 'canceled');
        } else {
            $glyph = $frames[$frameIndex % count($frames)];
            $stateMessage = $message;
        }

        $messageWidth = max(0, $width - 2);
        $fittedMessage = TuiString::truncate($stateMessage, $messageWidth);
        $line = TuiString::padEnd(TuiString::truncate($glyph . ' ' . $fittedMessage, $width), $width);

        $rows = [];
        for ($i = 0; $i < $context->bounds->height; $i++) {
            $rows[] = $i === 0 ? $line : str_repeat(' ', $width);
        }

        return $this->frame($width, $context->bounds->height, $rows);
    }

    private function publishAbort(TuiNode $node): void
    {
        if ($this->events === null) {
            return;
        }
        $this->events->publish(TuiEvent::now('loader.aborted', [
            'nodeId' => (string) ($node->props['nodeId'] ?? $node->id),
            'label' => (string) ($node->props['message'] ?? ''),
            'reason' => (string) ($node->props['reason'] ?? 'user_canceled'),
        ], source: 'cancellable-loader'));
    }
}
