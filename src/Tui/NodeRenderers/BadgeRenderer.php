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
 * Compact status pill: `[label]`, the TUI analog of a badge chip in a web UI.
 *
 * Single-line by design; the label is truncated to fit the node width minus the
 * brackets so the closing `]` is never dropped.
 *
 * ── EL COLOR NO SE APLICA AQUÍ, Y NO SALE DE LOS PROPS ──────────────────────
 *
 * Lo pone `TuiAnsiPainter` al final, y lo DEDUCE del texto entre corchetes:
 * `ready`/`done` → success, `error`/`failed` → error, `claude|gpt|gemini` →
 * azul, el resto → accent. Este renderer emite texto plano, como toda la capa
 * (*«TuiString's contract is plain text, not styled output»*).
 *
 * La versión anterior de este bloque decía que el pintor «toma el `role` de los
 * props». Era falso, y costó: quien fue a implementar el color por actor buscó
 * un camino que no existe. Una descripción que promete un mecanismo inexistente
 * es peor que ninguna — la ausencia se nota, la mentira no.
 *
 * Props:
 *  - `label` string  Texto de la píldora. Default: ''.
 *  - `fill`  string  Relleno del contorno. Default: 'square'.
 */
final class BadgeRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
{
    /**
     * True only for `badge` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'badge';
    }

    /**
     * Draws the pill on one line, colouring it by the node's semantic role.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $label = (string) ($node->props['label'] ?? '');
        $fill = (string) ($node->props['fill'] ?? 'square');
        $width = $context->bounds->width;

        [$open, $close] = match ($fill) {
            'round' => ['(', ')'],
            'angle' => ['<', '>'],
            default => ['[', ']'],
        };

        $innerWidth = max(0, $width - 2);
        $fitted = $this->truncateLabel($label, $innerWidth);
        $line = $open . $fitted . $close;

        $rows = [];
        $rowIndex = 0;
        for ($i = 0; $i < $context->bounds->height; $i++) {
            $rows[] = $i === $rowIndex ? TuiString::padEnd($line, $width) : str_repeat(' ', $width);
        }

        return $this->frame($width, $context->bounds->height, $rows);
    }

    /**
     * Truncates the label to fit inside the brackets without ever dropping
     * the closing bracket — `TuiString::truncate()` alone can eat the whole
     * inner width and leave `[…]` only when the ellipsis fits, but for very
     * narrow widths we want the closing bracket to survive even if the
     * label becomes a bare ellipsis.
     */
    private function truncateLabel(string $label, int $innerWidth): string
    {
        if ($innerWidth <= 0) {
            return '';
        }
        if (TuiString::visibleLength($label) <= $innerWidth) {
            return $label;
        }
        if ($innerWidth === 1) {
            return '…';
        }

        return TuiString::truncate($label, $innerWidth);
    }
}
