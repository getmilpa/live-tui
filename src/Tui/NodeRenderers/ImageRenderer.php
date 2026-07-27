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

use Milpa\Live\Contracts\Tui\TuiNodeRendererInterface;
use Milpa\Live\Tui\TuiString;
use Milpa\Live\ValueObjects\Tui\TuiFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;

/**
 * Image placeholder renderer with optional Kitty/iTerm2 inline-image
 * passthrough. The TUI analog of pi-tui's `Image` component. PHP does
 * not have native Kitty graphics protocol bindings, so this renderer
 * is intentionally conservative: when the terminal is known to support
 * inline images (via `props['protocol']` = `'kitty'` or `'iterm2'`), it
 * emits the raw base64 escape the caller supplied in `props['data']`;
 * otherwise it renders a text placeholder describing the image
 * (`[image: name.png · 800×600]`) so the layout still accounts for the
 * image's cell footprint and the user knows an image lives there.
 *
 * The caller is responsible for:
 *  - Detecting terminal capabilities (Kitty vs iTerm2 vs none) and
 *    passing the right `protocol`/`data` props.
 *  - Pre-encoding the image data as base64.
 *  - Pre-computing the cell dimensions from the pixel dimensions and
 *    the terminal's cell aspect ratio.
 *
 * This keeps the renderer pure and free of native I/O — the same
 * "node-in/frame-out" contract every other renderer in this namespace
 * follows.
 *
 * Node props (all optional):
 *  - `data`       string  Base64-encoded image data, used only when `protocol` is set.
 *  - `mimeType`   string  Image MIME type (e.g. `'image/png'`). Default: `'image/png'`.
 *  - `protocol`   string  `'kitty'` | `'iterm2'` | `'none'`. Default: `'none'`.
 *  - `filename`   string  Shown in the placeholder. Default: ''.
 *  - `widthPx`    int     Image width in pixels (placeholder only). Default: 0.
 *  - `heightPx`   int     Image height in pixels (placeholder only). Default: 0.
 *  - `widthCells` int     Image width in terminal cells (layout footprint). Default: derived.
 *  - `heightCells` int    Image height in terminal cells (layout footprint). Default: 1.
 *  - `maxWidthCells` int  Cap on width. Default: node width.
 *  - `maxHeightCells` int Cap on height. Default: node height.
 *  - `fallbackColor` string Theme role for the placeholder text. Default: 'muted'.
 */
final class ImageRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
{
    /**
     * True only for `image` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'image';
    }

    /**
     * Emits the inline-image escape sequence when the terminal supports it, and
     * a labelled placeholder box when it does not.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $protocol = (string) ($node->props['protocol'] ?? 'none');
        $filename = (string) ($node->props['filename'] ?? '');
        $mimeType = (string) ($node->props['mimeType'] ?? 'image/png');
        $widthPx = (int) ($node->props['widthPx'] ?? 0);
        $heightPx = (int) ($node->props['heightPx'] ?? 0);
        $widthCells = (int) ($node->props['widthCells'] ?? 0);
        $heightCells = (int) ($node->props['heightCells'] ?? 1);
        $maxWidthCells = (int) ($node->props['maxWidthCells'] ?? $context->bounds->width);
        $maxHeightCells = (int) ($node->props['maxHeightCells'] ?? $context->bounds->height);
        $width = $context->bounds->width;
        $height = $context->bounds->height;

        if ($protocol === 'kitty' || $protocol === 'iterm2') {
            $data = (string) ($node->props['data'] ?? '');
            if ($data !== '') {
                $rows = $this->renderInlineImage($protocol, $data, $mimeType, $width, $height, $widthCells, $heightCells, $maxWidthCells, $maxHeightCells, $filename);

                return $this->frame($width, $height, $rows);
            }
        }

        $rows = [];
        $placeholder = $this->placeholder($filename, $mimeType, $widthPx, $heightPx, $width);
        for ($i = 0; $i < $height; $i++) {
            $rows[] = $i === 0 ? $placeholder : str_repeat(' ', $width);
        }

        return $this->frame($width, $height, $rows);
    }

    /**
     * @return array<int, string>
     */
    private function renderInlineImage(string $protocol, string $data, string $mimeType, int $width, int $height, int $widthCells, int $heightCells, int $maxWidthCells, int $maxHeightCells, string $filename): array
    {
        $targetWidth = min($width, max(1, $widthCells > 0 ? $widthCells : $maxWidthCells));
        $targetHeight = min($height, max(1, $heightCells > 0 ? $heightCells : $maxHeightCells));
        $sequence = $protocol === 'kitty'
            ? $this->kittySequence($data, $mimeType, $targetWidth, $targetHeight)
            : $this->iterm2Sequence($data, $mimeType, $filename);

        $rows = array_fill(0, $targetHeight, '');
        $rows[$targetHeight - 1] = $sequence;

        while (count($rows) < $height) {
            $rows[] = str_repeat(' ', $width);
        }

        return array_slice($rows, 0, $height);
    }

    private function kittySequence(string $data, string $mimeType, int $width, int $height): string
    {
        $payload = "f=32,s={$width}x{$height},a=T;" . $data;

        return "\x1b_G" . $payload . "\x1b\\";
    }

    private function iterm2Sequence(string $data, string $mimeType, string $filename): string
    {
        $name = $filename !== '' ? ';name=' . rawurlencode($filename) : '';

        return "\x1b]1337;File=inline=1;width=auto;height=auto;preserveAspectRatio=1{$name}:" . $data . "\x07";
    }

    private function placeholder(string $filename, string $mimeType, int $widthPx, int $heightPx, int $width): string
    {
        $label = $filename !== '' ? $filename : trim(strstr($mimeType, '/') ?: $mimeType, '/');
        $dims = $widthPx > 0 && $heightPx > 0 ? " · {$widthPx}×{$heightPx}" : '';
        $text = "[image: {$label}{$dims}]";

        return TuiString::padEnd(TuiString::truncate($text, $width), $width);
    }
}
