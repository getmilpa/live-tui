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

namespace Milpa\Live\Tests\Tui\NodeRenderers;

use Milpa\Live\Contracts\Tui\TuiNodeRendererInterface;
use Milpa\Live\Tui\TuiString;
use Milpa\Live\ValueObjects\Tui\TuiBounds;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The contract every node renderer owes, checked against every one of them.
 *
 * Twenty of the twenty-three shipped renderers had never been executed by any
 * test. This does not replace the specific tests they each deserve — it makes
 * sure none of them ships having never run at all, which is what
 * `tools/validate-contract-coverage.php` measures.
 */
final class EveryRendererTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string<TuiNodeRendererInterface>, 1: string}>
     */
    public static function renderers(): array
    {
        $out = [];
        foreach (glob(__DIR__ . '/../../../src/Tui/NodeRenderers/*.php') ?: [] as $file) {
            $short = basename($file, '.php');
            $class = 'Milpa\\Live\\Tui\\NodeRenderers\\' . $short;

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->implementsInterface(TuiNodeRendererInterface::class)) {
                continue;
            }

            // A renderer that needs collaborators cannot be exercised generically;
            // it earns its own test. Today that is only ComponentTuiNodeRenderer.
            if (($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
                continue;
            }

            // The node type each renderer answers to, read from its own source
            // rather than hardcoded here — a list in the test would drift.
            $source = (string) file_get_contents($file);
            preg_match("/\\\$node->type === '([a-z0-9-]+)'/", $source, $m);
            $out[$short] = [$class, $m[1] ?? ''];
        }

        return $out;
    }

    /**
     * @param class-string<TuiNodeRendererInterface> $class
     */
    #[DataProvider('renderers')]
    public function testItAnswersOnlyForTheTypeItClaims(string $class, string $type): void
    {
        if ($type === '') {
            self::markTestSkipped('Structural or fallback renderer: it does not dispatch on a single type.');
        }

        $renderer = new $class();

        self::assertTrue($renderer->supports(new TuiNode('n', $type)));
        self::assertFalse($renderer->supports(new TuiNode('n', 'no-such-type-' . $type)));
    }

    /**
     * @param class-string<TuiNodeRendererInterface> $class
     */
    #[DataProvider('renderers')]
    public function testItFillsExactlyTheBoundsItWasGiven(string $class, string $type): void
    {
        // The caller composites the frame at these bounds WITHOUT clamping, so
        // a renderer that returns the wrong size corrupts its neighbours.
        $renderer = new $class();
        $bounds = new TuiBounds(0, 0, 40, 6);

        $frame = $renderer->render(new TuiNode('n', $type === '' ? 'box' : $type), new TuiRenderContext($bounds));

        self::assertSame($bounds->width, $frame->width, 'Frame width must match the bounds.');
        self::assertSame($bounds->height, $frame->height, 'Frame height must match the bounds.');
        self::assertCount($bounds->height, $frame->lines, 'One line per row of the bounds.');

        foreach ($frame->lines as $row => $line) {
            self::assertTrue(mb_check_encoding($line, 'UTF-8'), "Row {$row} carries broken UTF-8.");
            self::assertSame($bounds->width, TuiString::visibleLength($line), "Row {$row} is not the declared width.");
        }
    }
}
