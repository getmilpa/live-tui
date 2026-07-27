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

use Milpa\Live\Contracts\Tui\TuiLayoutEngineInterface;
use Milpa\Live\ValueObjects\Tui\TuiBounds;
use Milpa\Live\ValueObjects\Tui\TuiLayoutFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;

/**
 * Resolves a node tree into absolute bounds plus a paint order. Stacks children
 * vertically inside their parent and clips to the viewport, so a renderer never
 * has to compute geometry — it is handed the rectangle it may paint.
 */
final class SimpleTuiLayoutEngine implements TuiLayoutEngineInterface
{
    /**
     * Resolves the tree into per-node bounds and a paint order, clipped to the
     * viewport.
     */
    public function layout(TuiNode $root, TuiBounds $viewport): TuiLayoutFrame
    {
        $bounds = [];
        $nodes = [];
        $order = [];

        $this->walk($root, $viewport, $bounds, $nodes, $order);
        $indexedOrder = array_map(
            static fn (string $id, int $index): array => ['id' => $id, 'index' => $index],
            $order,
            array_keys($order),
        );
        usort($indexedOrder, static function (array $a, array $b) use ($nodes): int {
            return ($nodes[$a['id']]->layer() <=> $nodes[$b['id']]->layer())
                ?: ($a['index'] <=> $b['index']);
        });
        $order = array_column($indexedOrder, 'id');

        return new TuiLayoutFrame($root, $bounds, $nodes, $order);
    }

    /**
     * @param array<string, TuiBounds> $bounds
     * @param array<string, TuiNode>   $nodes
     * @param array<int, string>       $order
     */
    private function walk(TuiNode $node, TuiBounds $boundsForNode, array &$bounds, array &$nodes, array &$order): void
    {
        if ($node->hidden()) {
            return;
        }

        $bounds[$node->id] = $boundsForNode;
        $nodes[$node->id] = $node;
        $order[] = $node->id;

        $children = array_values(array_filter($node->children, static fn (TuiNode $child): bool => !$child->hidden()));
        if ($children === []) {
            return;
        }

        $content = $this->contentBounds($node, $boundsForNode);
        $normalChildren = array_values(array_filter($children, static fn (TuiNode $child): bool => !$child->overlay()));
        $overlayChildren = array_values(array_filter($children, static fn (TuiNode $child): bool => $child->overlay()));

        foreach ($this->allocateNormal(
            $normalChildren,
            $content,
            (string) ($node->props['layout'] ?? 'vertical'),
            max(0, (int) ($node->props['gap'] ?? 0)),
        ) as $id => $childBounds) {
            $child = $this->findChild($normalChildren, $id);
            if ($child !== null) {
                $this->walk($child, $childBounds, $bounds, $nodes, $order);
            }
        }

        foreach ($overlayChildren as $child) {
            $this->walk($child, $this->overlayBounds($child, $content), $bounds, $nodes, $order);
        }
    }

    /**
     * @param array<int, TuiNode> $children
     *
     * @return array<string, TuiBounds>
     */
    private function allocateNormal(array $children, TuiBounds $bounds, string $layout, int $gap): array
    {
        if ($children === []) {
            return [];
        }

        return $layout === 'horizontal'
            ? $this->allocateHorizontal($children, $bounds, $gap)
            : $this->allocateVertical($children, $bounds, $gap);
    }

    /**
     * @param array<int, TuiNode> $children
     *
     * @return array<string, TuiBounds>
     */
    private function allocateVertical(array $children, TuiBounds $bounds, int $gap): array
    {
        $fixed = 0;
        $flex = 0;
        foreach ($children as $child) {
            if (isset($child->props['height'])) {
                $fixed += max(0, (int) $child->props['height']);
            } else {
                $flex += max(1, (int) ($child->props['flex'] ?? 1));
            }
        }

        $remaining = max(0, $bounds->height - $fixed - ($gap * max(0, count($children) - 1)));
        $y = $bounds->y;
        $allocated = [];
        $lastFlexId = null;

        foreach ($children as $child) {
            $height = isset($child->props['height'])
                ? max(0, (int) $child->props['height'])
                : (int) floor($remaining * (max(1, (int) ($child->props['flex'] ?? 1)) / max(1, $flex)));
            if (!isset($child->props['height'])) {
                $lastFlexId = $child->id;
            }

            $allocated[$child->id] = new TuiBounds($bounds->x, $y, $bounds->width, min($height, max(0, $bounds->bottom() - $y)));
            $y += $height + $gap;
        }

        if ($lastFlexId !== null && $y < $bounds->bottom()) {
            $last = $allocated[$lastFlexId];
            $allocated[$lastFlexId] = new TuiBounds($last->x, $last->y, $last->width, $last->height + ($bounds->bottom() - $y));
        }

        return $allocated;
    }

    /**
     * @param array<int, TuiNode> $children
     *
     * @return array<string, TuiBounds>
     */
    private function allocateHorizontal(array $children, TuiBounds $bounds, int $gap): array
    {
        $fixed = 0;
        $flex = 0;
        foreach ($children as $child) {
            if (isset($child->props['width'])) {
                $fixed += max(0, (int) $child->props['width']);
            } else {
                $flex += max(1, (int) ($child->props['flex'] ?? 1));
            }
        }

        $remaining = max(0, $bounds->width - $fixed - ($gap * max(0, count($children) - 1)));
        $x = $bounds->x;
        $allocated = [];
        $lastFlexId = null;

        foreach ($children as $child) {
            $width = isset($child->props['width'])
                ? max(0, (int) $child->props['width'])
                : (int) floor($remaining * (max(1, (int) ($child->props['flex'] ?? 1)) / max(1, $flex)));
            if (!isset($child->props['width'])) {
                $lastFlexId = $child->id;
            }

            $allocated[$child->id] = new TuiBounds($x, $bounds->y, min($width, max(0, $bounds->right() - $x)), $bounds->height);
            $x += $width + $gap;
        }

        if ($lastFlexId !== null && $x < $bounds->right()) {
            $last = $allocated[$lastFlexId];
            $allocated[$lastFlexId] = new TuiBounds($last->x, $last->y, $last->width + ($bounds->right() - $x), $last->height);
        }

        return $allocated;
    }

    private function overlayBounds(TuiNode $node, TuiBounds $bounds): TuiBounds
    {
        $width = min($bounds->width, max(1, (int) ($node->props['width'] ?? min(60, max(1, $bounds->width - 4)))));
        $height = min($bounds->height, max(1, (int) ($node->props['height'] ?? min(12, max(1, $bounds->height - 4)))));
        $centerX = $bounds->x + max(0, intdiv($bounds->width - $width, 2));
        $centerY = $bounds->y + max(0, intdiv($bounds->height - $height, 2));
        $x = isset($node->props['x'])
            ? $bounds->x + max(0, (int) $node->props['x'])
            : $centerX;
        $y = isset($node->props['y'])
            ? $bounds->y + max(0, (int) $node->props['y'])
            : $centerY;

        return new TuiBounds(
            x: min($x, max($bounds->x, $bounds->right() - $width)),
            y: min($y, max($bounds->y, $bounds->bottom() - $height)),
            width: $width,
            height: $height,
        );
    }

    /**
     * @param array<int, TuiNode> $children
     */
    private function findChild(array $children, string $id): ?TuiNode
    {
        foreach ($children as $child) {
            if ($child->id === $id) {
                return $child;
            }
        }

        return null;
    }

    private function contentBounds(TuiNode $node, TuiBounds $bounds): TuiBounds
    {
        $padding = max(0, (int) ($node->props['padding'] ?? 0));
        $paddingX = max(0, (int) ($node->props['paddingX'] ?? $padding));
        $paddingY = max(0, (int) ($node->props['paddingY'] ?? $padding));
        $border = $node->type === 'box' && (bool) ($node->props['border'] ?? false) ? 1 : 0;
        $insetX = $paddingX + $border;
        $insetY = $paddingY + $border;

        return new TuiBounds(
            x: $bounds->x + min($insetX, $bounds->width),
            y: $bounds->y + min($insetY, $bounds->height),
            width: max(0, $bounds->width - ($insetX * 2)),
            height: max(0, $bounds->height - ($insetY * 2)),
        );
    }
}
