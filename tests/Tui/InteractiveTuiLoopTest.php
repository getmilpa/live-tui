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

namespace Milpa\Live\Tests\Tui;

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Rendering\ComponentRendererRegistry;
use Milpa\Live\Rendering\TuiComponentRenderer;
use Milpa\Live\Tests\Fixtures\FakeTerminal;
use Milpa\Live\Tui\InteractiveTuiLoop;
use Milpa\Live\Tui\TuiComponentInstance;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The component loop, driven over memory streams.
 *
 * Unlike {@see \Milpa\Live\Tui\RetainedTuiLoop::run()}, this one has **no TTY
 * short-circuit**: it enters its body whatever the stream is, and stops when a
 * read comes back empty. That makes it drivable from a test as published —
 * these tests pin that, and pin the sentinel that makes its input model
 * different from the retained loop's.
 */
#[CoversClass(InteractiveTuiLoop::class)]
final class InteractiveTuiLoopTest extends TestCase
{
    public function testAResizeChangesTheWidthItRendersAt(): void
    {
        // Same no-op onResize the retained loop had: without wiring, a terminal
        // that grows keeps getting output sized for the one it used to be.
        $terminal = new FakeTerminal(['q'], 40, 6);
        $loop = $this->loop();

        $loop->runOn($terminal, idleMicroseconds: 0);
        $before = $terminal->output();

        $wide = new FakeTerminal(['q'], 100, 6);
        $this->loop()->runOn($wide, idleMicroseconds: 0);

        // The second run reports a wider terminal, so its rule must be longer.
        self::assertGreaterThan(
            self::longestLine($before),
            self::longestLine($wide->output()),
            'The loop rendered at the same width for two different terminals.',
        );
    }

    private static function longestLine(string $text): int
    {
        $longest = 0;
        foreach (explode("\n", $text) as $line) {
            $longest = max($longest, mb_strlen(rtrim($line)));
        }

        return $longest;
    }

    /**
     * @param list<string> $script
     *
     * @return list<string> everything the terminal was asked to write
     */
    private function writesFrom(array $script): array
    {
        $terminal = new FakeTerminal($script);
        $this->loop()->runOn($terminal, idleMicroseconds: 0);

        return array_values(array_filter($terminal->writes, static fn (string $w): bool => $w !== ''));
    }

    public function testAnEscapeSequenceSplitAcrossReadsIsNotTurnedIntoGarbage(): void
    {
        // The three-byte assembler took the ESC, found nothing after it, and
        // emitted it alone — then the '[' and the 'A' as separate literal keys.
        // A fragmented arrow became Escape, bracket, letter.
        $whole = $this->writesFrom(["\033[A", 'q']);

        self::assertSame($whole, $this->writesFrom(["\033", '[A', 'q']), 'Split after ESC.');
        self::assertSame($whole, $this->writesFrom(["\033", '[', 'A', 'q']), 'Split byte by byte.');
    }

    private function loop(): InteractiveTuiLoop
    {
        $component = new class () implements ComponentDefinitionInterface {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('input', '1.0.0', 'test double');
            }

            public function mount(array $props, ComponentContext $context): StateSnapshot
            {
                return new StateSnapshot($context->componentId, 'input', '1.0.0', ['value' => ''], ['label' => 'Nombre']);
            }

            public function handle(InteractionRequest $request): InteractionResult
            {
                return new InteractionResult($request->state);
            }
        };

        $registry = new ComponentRendererRegistry();
        $registry->register(new TuiComponentRenderer());

        $context = new ComponentContext('c1');

        return new InteractiveTuiLoop($registry, [
            new TuiComponentInstance('c1', $component, $context, [], $component->mount([], $context)),
        ]);
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function streams(string $scripted): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);

        if ($scripted !== '') {
            fwrite($in, $scripted);
        }
        rewind($in);

        return [$in, $out];
    }

    public function testItRunsOverMemoryStreamsWithoutATerminal(): void
    {
        [$in, $out] = $this->streams('q');

        $this->loop()->run($in, $out);

        rewind($out);
        $written = (string) stream_get_contents($out);

        self::assertNotSame('', $written);
        self::assertStringContainsString('Nombre', $written, 'The component was rendered.');
    }

    public function testWithoutATtyItDoesNotClearTheScreenButStillEntersTheLoop(): void
    {
        [$in, $out] = $this->streams('q');

        $this->loop()->run($in, $out);

        rewind($out);
        $written = (string) stream_get_contents($out);

        // No clear-screen — but output exists, so the body ran. This is the
        // difference from RetainedTuiLoop::run(), which returns before the body.
        self::assertStringNotContainsString("\033[2J", $written);
        self::assertNotSame('', $written);
    }

    public function testItRunsThroughTheTerminalContractAndTerminates(): void
    {
        // The question this slice exists for: with atEndOfInput() in the
        // contract, does a loop whose exit condition is EOF terminate when
        // driven by pollInput()? Before it existed, this spun forever.
        $terminal = new FakeTerminal(['q']);

        $this->loop()->runOn($terminal, idleMicroseconds: 0);

        self::assertSame(['start', 'clearScreen', 'stop'], array_values(array_unique($terminal->lifecycle)));
        self::assertStringContainsString('Nombre', $terminal->output());
    }

    public function testWithNoScriptedInputAtAllItStillTerminates(): void
    {
        $terminal = new FakeTerminal([]);

        $this->loop()->runOn($terminal, idleMicroseconds: 0);

        // Painted once, found the input was over, left.
        self::assertNotSame('', $terminal->output());
        self::assertContains('stop', $terminal->lifecycle);
    }

    public function testAnIdleTickDoesNotRepaint(): void
    {
        // Polling means ticks with no key. This loop repaints in FULL, so a
        // paint at the top of the loop would redraw everything ~10x a second.
        $terminal = new FakeTerminal(['', '', 'q']);

        $this->loop()->runOn($terminal, idleMicroseconds: 0);

        $paints = count(array_filter($terminal->lifecycle, static fn (string $e): bool => $e === 'clearScreen'));

        self::assertLessThanOrEqual(2, $paints, 'Idle ticks must not repaint the whole screen.');
    }

    public function testAnEmptyReadEndsTheLoop(): void
    {
        // The sentinel that makes this loop's input model its own: an empty
        // read means STOP. In the retained loop the same '' means "no key this
        // tick, keep going" — so the two cannot share one input source without
        // agreeing on what emptiness means.
        [$in, $out] = $this->streams('');

        $this->loop()->run($in, $out);

        rewind($out);

        // It painted once before discovering the stream was empty, then left.
        self::assertNotSame('', (string) stream_get_contents($out));
    }
}
