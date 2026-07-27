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

namespace Milpa\Live\Tests\Tui\State;

use Milpa\Live\Tui\NodeRenderers\DataTableRenderer;
use Milpa\Live\Tui\State\StateToNode;
use Milpa\Live\ValueObjects\Tui\TuiBounds;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The mapper's contract is totality: every shape a state array can carry has to
 * come out the other side. These tests enumerate the shapes rather than sample
 * them, because the defect this class exists to avoid is a shape falling
 * through in silence.
 */
#[CoversClass(StateToNode::class)]
final class StateToNodeTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function shapes(): array
    {
        return [
            'string scalar'      => ['MARK', 'MARK'],
            'int scalar'         => [424242, '424242'],
            'bool scalar'        => [true, 'true'],
            'null'               => [null, '—'],
            'row table'          => [[['c' => 'MARK']], 'MARK'],
            'flat list'          => [['MARK'], 'MARK'],
            'nested assoc'       => [['k' => 'MARK'], 'MARK'],
            'list of lists'      => [[['MARK']], 'MARK'],
            'heterogeneous rows' => [[['a' => 'MARK'], ['b' => 1]], 'MARK'],
        ];
    }

    #[DataProvider('shapes')]
    public function testEveryShapeSurvivesToTheRenderedFrame(mixed $value, string $expected): void
    {
        $rendered = self::render((new StateToNode())->map('X', ['field' => $value]));

        self::assertStringContainsString(
            $expected,
            $rendered,
            'A shape the mapper can receive vanished instead of being shown.',
        );
    }

    public function testAnEmptyArrayProducesATableRatherThanNothing(): void
    {
        $node = (new StateToNode())->map('X', ['field' => []]);

        self::assertCount(1, $node->children, 'An empty array must still declare itself.');
        self::assertSame('data-table', $node->children[0]->type);
    }

    public function testTheKeyValueHeadersAreTheCallers(): void
    {
        $rendered = self::render((new StateToNode('Campo', 'Valor'))->map('X', ['siteName' => 'Milpa']));

        self::assertStringContainsString('Campo', $rendered);
        self::assertStringContainsString('Valor', $rendered);
    }

    public function testTheMapperDecidesNoWordsOfItsOwn(): void
    {
        // Mechanically, not by promise: the only human-readable strings this
        // class may contain are the two default headers on its constructor.
        $source = (string) file_get_contents(__DIR__ . '/../../../src/Tui/State/StateToNode.php');
        $code = self::stripComments($source);

        preg_match_all("/'([^']{2,})'/", $code, $matches);
        $literals = array_values(array_diff($matches[1], ['Field', 'Value', 'data-table', 'section', 'record', 'box', 'title', 'columns', 'rows', 'key', 'label']));

        self::assertSame([], $literals, 'The mapper grew a word of its own: ' . implode(', ', $literals));
    }

    public function testColumnKeysForPositionalListsAreDigits(): void
    {
        $node = (new StateToNode())->map('X', ['pairs' => [['a', 1]]]);
        $columns = $node->children[0]->props['columns'];

        self::assertSame(['0', '1'], array_column($columns, 'key'));
    }

    private static function render(TuiNode $node): string
    {
        $renderer = new DataTableRenderer();
        $out = '';
        foreach ($node->children as $child) {
            $out .= implode("\n", $renderer->render($child, new TuiRenderContext(new TuiBounds(0, 0, 60, 6)))->lines) . "\n";
        }

        return $out;
    }

    private static function stripComments(string $php): string
    {
        $out = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
