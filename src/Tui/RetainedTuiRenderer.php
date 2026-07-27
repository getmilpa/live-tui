<?php

declare(strict_types=1);

namespace Milpa\Live\Tui;

use Milpa\Live\Contracts\Tui\FocusableInterface;
use Milpa\Live\Contracts\Tui\TuiLayoutEngineInterface;
use Milpa\Live\Contracts\Tui\TuiNodeRendererRegistryInterface;
use Milpa\Live\ValueObjects\Tui\TuiBounds;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;
use Milpa\Live\ValueObjects\Tui\TuiNode;

/**
 * Turns a node tree into a filled {@see VirtualTerminalBuffer}: lays the tree
 * out, resolves a renderer per node, and composites each frame at its bounds.
 */
final readonly class RetainedTuiRenderer
{
    public function __construct(
        private TuiLayoutEngineInterface $layout,
        private TuiNodeRendererRegistryInterface $renderers,
    ) {
    }

    /**
     * Lays out the tree and composites every node's frame into a buffer of the
     * given size, marking the focused node as focused.
     */
    public function render(TuiNode $root, int $width, int $height, ?string $focusedId = null): VirtualTerminalBuffer
    {
        $viewport = new TuiBounds(0, 0, $width, $height);
        $layout = $this->layout->layout($root, $viewport);
        $buffer = new VirtualTerminalBuffer($width, $height);

        foreach ($layout->paintOrder as $id) {
            $node = $layout->nodeFor($id);
            $bounds = $layout->boundsFor($id);
            if ($node === null || $bounds === null || $bounds->width < 1 || $bounds->height < 1) {
                continue;
            }

            $renderer = $this->renderers->resolve($node);
            if ($renderer === null) {
                continue;
            }

            if ($node->overlay()) {
                $margin = max(0, (int) ($node->props['overlayMargin'] ?? 0));
                if ($margin > 0) {
                    $erase = new TuiBounds(
                        x: max(0, $bounds->x - $margin),
                        y: max(0, $bounds->y - $margin),
                        width: min($width - max(0, $bounds->x - $margin), $bounds->width + ($margin * 2)),
                        height: min($height - max(0, $bounds->y - $margin), $bounds->height + ($margin * 2)),
                    );
                    $buffer->writeFrame($erase, TuiFrameFactory::fromLines($erase->width, $erase->height, []));
                }
            }

            $buffer->writeFrame($bounds, $renderer->render($node, new TuiRenderContext($bounds, $focusedId, $layout)));
        }

        return $buffer;
    }

    /**
     * Returns the absolute terminal cell of the text caret for the
     * focused node, or `null` when the focused node's renderer does not
     * implement {@see FocusableInterface} or has no caret to position.
     *
     * This walks the same layout frame {@see render()} produces, finds
     * the focused node, resolves its renderer, and — when the renderer
     * is `FocusableInterface` — re-renders the node's frame (cheap; one
     * node) and asks the renderer where its caret sits inside that
     * frame. The caret's `[row, col]` is then offset by the node's
     * layout bounds to get the absolute terminal cell the hardware
     * cursor should move to for IME candidate-window tracking.
     *
     * This is outside {@see render()} because the cell-addressed
     * {@see VirtualTerminalBuffer} cannot represent the zero-width
     * {@see FocusableInterface::CURSOR_MARKER} APC — the marker has to
     * be read straight from the renderer's frame, not from the buffer.
     *
     * @return array{int, int}|null `[row, col]` zero-indexed absolute terminal cell, or null.
     */
    public function caretPosition(TuiNode $root, int $width, int $height, ?string $focusedId = null): ?array
    {
        if ($focusedId === null || $focusedId === '') {
            return null;
        }
        $viewport = new TuiBounds(0, 0, $width, $height);
        $layout = $this->layout->layout($root, $viewport);
        $node = $layout->nodeFor($focusedId);
        $bounds = $layout->boundsFor($focusedId);
        if ($node === null || $bounds === null) {
            return null;
        }
        $renderer = $this->renderers->resolve($node);
        if (!$renderer instanceof FocusableInterface) {
            return null;
        }
        $frame = $renderer->render($node, new TuiRenderContext($bounds, $focusedId, $layout));
        $relative = $renderer->caretPosition(implode("\n", $frame->lines));
        if ($relative === null) {
            return null;
        }
        [$row, $col] = $relative;

        return [$bounds->y + $row, $bounds->x + $col];
    }
}
