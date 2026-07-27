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

use Milpa\Live\Tests\Fixtures\FakeTerminal;
use Milpa\Live\Tui\BracketedPaste;
use Milpa\Live\Tui\NodeRenderers\TextRenderer;
use Milpa\Live\Tui\RetainedTuiLoop;
use Milpa\Live\Tui\RetainedTuiRenderer;
use Milpa\Live\Tui\SimpleTuiLayoutEngine;
use Milpa\Live\Tui\StreamTerminal;
use Milpa\Live\Tui\TuiNodeRendererRegistry;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The interactive path, driven end to end without a terminal.
 *
 * `run()` cannot be tested: it gates on `stream_isatty()` and, when that is
 * false, renders one screen and returns without ever entering the loop — so
 * over a memory stream the whole interactive body is unreachable. `runOn()`
 * expresses the same body through {@see \Milpa\Live\Contracts\Tui\TerminalInterface},
 * which is what these tests drive.
 */
#[CoversClass(RetainedTuiLoop::class)]
final class RetainedTuiLoopTest extends TestCase
{
    private string $label = 'uno';

    /**
     * @param list<string> $keys
     */
    private function loop(array $keys, ?FakeTerminal &$terminal = null): RetainedTuiLoop
    {
        $registry = new TuiNodeRendererRegistry();
        $registry->register(new TextRenderer());

        $terminal = new FakeTerminal($keys);

        return new RetainedTuiLoop(
            new RetainedTuiRenderer(new SimpleTuiLayoutEngine(), $registry),
            fn (): TuiNode => new TuiNode('root', 'box', children: [
                new TuiNode('a', 'text', props: ['text' => $this->label]),
            ]),
            ['a'],
            'a',
            40,
            6,
        );
    }

    public function testAScriptedQuitKeyRunsAndStopsTheLoop(): void
    {
        $loop = $this->loop(['q'], $terminal);

        $loop->runOn($terminal, idleMicroseconds: 0);

        self::assertSame(['start', 'clearScreen', 'hideCursor', 'stop'], $terminal->lifecycle);
        self::assertTrue($terminal->cursorVisible, 'The loop must hand the cursor back.');
    }

    public function testTheFirstFrameIsPaintedThroughTheContract(): void
    {
        $loop = $this->loop(['q'], $terminal);

        $loop->runOn($terminal, idleMicroseconds: 0);

        self::assertNotSame('', $terminal->output());
        self::assertStringContainsString('uno', $terminal->output());
    }

    public function testAKeyReachesTheHandlerAndChangesWhatIsPainted(): void
    {
        $loop = $this->loop(['x', 'q'], $terminal);
        $loop = $this->withHandler($loop, $terminal);

        $loop->runOn($terminal, idleMicroseconds: 0);

        // 'x' flipped the state, so a later frame must carry the new text —
        // proving the key travelled input → assembly → dispatch → repaint.
        self::assertStringContainsString('dos', $terminal->output());
    }

    public function testAnUnchangedFrameCostsNothing(): void
    {
        // Three ticks with no key, then quit. Only the first tick may paint.
        $loop = $this->loop(['', '', '', 'q'], $terminal);

        $loop->runOn($terminal, idleMicroseconds: 0);

        $nonEmpty = array_values(array_filter($terminal->writes, static fn (string $w): bool => $w !== ''));

        self::assertCount(
            1,
            $nonEmpty,
            'A retained loop must write once for the first frame and stay silent while nothing changes.',
        );
    }

    public function testRunOverANonTtyStreamNeverEntersTheInteractiveBody(): void
    {
        // This is the gap runOn() exists to close: kept as a test so the
        // limitation is recorded rather than remembered.
        $loop = $this->loop([], $terminal);

        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);

        $loop->run($in, $out);

        rewind($out);
        $written = (string) stream_get_contents($out);

        self::assertStringNotContainsString("\033[2J", $written, 'run() short-circuits before the interactive path.');
        self::assertStringNotContainsString("\033[?25l", $written, 'run() never hides the cursor over a non-TTY.');
    }

    public function testRunOnKeepsBracketedPasteModeLikeRunDoes(): void
    {
        // run() brackets the whole session in paste mode when a detector is
        // wired. runOn() must not quietly drop it, or a paste turns back into
        // a burst of keystrokes.
        $registry = new TuiNodeRendererRegistry();
        $registry->register(new TextRenderer());
        $terminal = new FakeTerminal(['q']);

        $loop = new RetainedTuiLoop(
            new RetainedTuiRenderer(new SimpleTuiLayoutEngine(), $registry),
            fn (): TuiNode => new TuiNode('root', 'box', children: [
                new TuiNode('a', 'text', props: ['text' => $this->label]),
            ]),
            ['a'],
            'a',
            40,
            6,
            true,
            null,
            null,
            null,
            null,
            new BracketedPaste(),
        );

        $loop->runOn($terminal, idleMicroseconds: 0);

        self::assertStringContainsString(BracketedPaste::MODE_BEGIN, $terminal->output());
        self::assertStringContainsString(BracketedPaste::MODE_END, $terminal->output());
    }

    public function testTheRealStreamTerminalCanDriveTheLoop(): void
    {
        // Not a fake: this is the concrete terminal run() now delegates to,
        // over memory streams. It exercises the delegation everywhere except
        // the parts that need a device — raw mode and signals.
        $registry = new TuiNodeRendererRegistry();
        $registry->register(new TextRenderer());

        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        fwrite($in, 'q');
        rewind($in);

        $loop = new RetainedTuiLoop(
            new RetainedTuiRenderer(new SimpleTuiLayoutEngine(), $registry),
            fn (): TuiNode => new TuiNode('root', 'box', children: [
                new TuiNode('a', 'text', props: ['text' => $this->label]),
            ]),
            ['a'],
            'a',
            40,
            6,
        );

        $loop->runOn(new StreamTerminal(null, $in, $out), idleMicroseconds: 0);

        rewind($out);
        $written = (string) stream_get_contents($out);

        self::assertStringContainsString('uno', $written, 'The frame reached the stream.');
        self::assertStringContainsString("\x1b[?25l", $written, 'The cursor was hidden through the contract.');
        self::assertStringContainsString("\x1b[?25h", $written, 'And handed back.');
    }

    private function withHandler(RetainedTuiLoop $loop, FakeTerminal $terminal): RetainedTuiLoop
    {
        $registry = new TuiNodeRendererRegistry();
        $registry->register(new TextRenderer());

        return new RetainedTuiLoop(
            new RetainedTuiRenderer(new SimpleTuiLayoutEngine(), $registry),
            fn (): TuiNode => new TuiNode('root', 'box', children: [
                new TuiNode('a', 'text', props: ['text' => $this->label]),
            ]),
            ['a'],
            'a',
            40,
            6,
            true,
            function (string $key): bool {
                if ($key === 'x') {
                    $this->label = 'dos';

                    return true;
                }

                return false;
            },
        );
    }
}
