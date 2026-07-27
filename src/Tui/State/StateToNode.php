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

namespace Milpa\Live\Tui\State;

use Milpa\Live\ValueObjects\Tui\TuiNode;

/**
 * Turns a plain state array into a TUI node tree.
 *
 * Total over its input: every shape a state array can carry becomes a node.
 * Nothing is dropped for being an inconvenient shape — that decision is data
 * loss, not formatting.
 *
 * It decides structure, never words. The two headers the key/value table needs
 * are the caller's; everything else is derived from the data or left to the
 * renderer's own defaults.
 */
final readonly class StateToNode
{
    public function __construct(
        private string $fieldHeader = 'Field',
        private string $valueHeader = 'Value',
    ) {
    }

    /**
     * Maps one section's state to a node tree.
     *
     * @param array<string, mixed> $state
     */
    public function map(string $title, array $state): TuiNode
    {
        $children = [];
        $pairs = [];

        foreach ($state as $key => $value) {
            if (is_array($value)) {
                $children[] = $this->table((string) $key, $value);

                continue;
            }

            $pairs[] = [$this->fieldHeader => (string) $key, $this->valueHeader => $value];
        }

        if ($pairs !== []) {
            $children[] = $this->table('record', $pairs);
        }

        return new TuiNode('section', 'box', props: ['title' => $title], children: $children);
    }

    /**
     * Normalises any array shape into assoc rows.
     *
     * Normalising rather than classifying is what makes this total: there is no
     * branch that can fall through, because every shape reduces to rows.
     *
     * @param array<array-key, mixed> $value
     */
    private function table(string $id, array $value): TuiNode
    {
        $rows = [];

        foreach ($value as $key => $item) {
            if (!is_array($item)) {
                // A loose scalar inside an array is a key/value pair.
                $rows[] = [$this->fieldHeader => (string) $key, $this->valueHeader => $item];

                continue;
            }

            // Positional lists get their own index as the column key: a digit,
            // not a word, so nothing here needs translating.
            $rows[] = array_is_list($item) ? $this->positional($item) : $item;
        }

        $keys = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $keys[(string) $key] = true;
            }
        }

        return new TuiNode($id, 'data-table', props: [
            'columns' => array_map(
                static fn (string $key): array => ['key' => $key, 'label' => $key],
                array_keys($keys),
            ),
            'rows' => $rows,
        ]);
    }

    /**
     * PHP normalises numeric string keys back to integers, so what comes out is
     * int-keyed even though the keys are written as strings. The renderer looks
     * cells up with the same coercion, so it lines up — but the declared type
     * has to say what is really there.
     *
     * @param list<mixed> $item
     *
     * @return array<array-key, mixed>
     */
    private function positional(array $item): array
    {
        $row = [];
        foreach ($item as $index => $cell) {
            $row[(string) $index] = $cell;
        }

        return $row;
    }
}
