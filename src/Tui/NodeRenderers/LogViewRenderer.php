<?php

declare(strict_types=1);

namespace Milpa\Live\Tui\NodeRenderers;

use Milpa\Live\Contracts\Tui\TuiNodeRendererInterface;
use Milpa\Live\ValueObjects\Tui\TuiFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;

/**
 * Scrolling event log: renders the tail of the `events` prop as
 * `[type] message` lines, accepting arrays, objects with a `type`, or plain
 * strings, and keeps only the last lines that fit the box.
 */
final class LogViewRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
{
    /**
     * True only for `log-view` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'log-view';
    }

    /**
     * Draws the tail of the event list that fits inside the box.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $lines = [];
        $events = is_array($node->props['events'] ?? null) ? $node->props['events'] : [];
        foreach ($events as $event) {
            if (is_array($event)) {
                $lines[] = '[' . (string) ($event['type'] ?? 'event') . '] ' . (string) ($event['message'] ?? json_encode($event['payload'] ?? []));
            } elseif (is_object($event) && property_exists($event, 'type')) {
                $lines[] = '[' . (string) $event->type . '] ' . json_encode($event->payload ?? [], JSON_UNESCAPED_SLASHES);
            } else {
                $lines[] = (string) $event;
            }
        }

        return $this->frame(
            $context->bounds->width,
            $context->bounds->height,
            $this->boxed('logs', array_slice($lines, -max(1, $context->bounds->height - 4)), $context->bounds->width, $context->bounds->height, $context->focused($node)),
        );
    }
}
