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

    /**
     * @param list<string> $keys
     */
    public function testALoneEscapeIsNotHeldForever(): void
    {
        // InputBuffer holds a bare ESC waiting for the rest of a sequence that
        // may never come, and merges it with whatever arrives next — so ESC
        // then 'q' half a second later becomes alt+q. flush() exists for
        // exactly this and the loop has to call it when a tick brings nothing.
        $registry = new TuiNodeRendererRegistry();
        $registry->register(new TextRenderer());
        $seen = [];

        // ESC, then ticks with nothing for longer than the timeout, then a key
        // that must arrive whole.
        $terminal = new FakeTerminal(["\033", '', '', 'q']);

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
            static function (string $key) use (&$seen): bool {
                $seen[] = $key;

                return true;
            },
        );

        $loop->runOn($terminal, idleMicroseconds: 2000, maxTicks: 12, escapeTimeoutMicroseconds: 500);

        self::assertNotContains(
            "\033q",
            $seen,
            'A lone escape was merged with the next key instead of being flushed.',
        );
    }

    /**
     * @param list<string> $script
     *
     * @return list<string> the NORMALISED keys handleKey saw, in hex
     */
    private function keysFrom(array $script): array
    {
        $registry = new TuiNodeRendererRegistry();
        $registry->register(new TextRenderer());
        $seen = [];

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
            static function (string $key) use (&$seen): bool {
                $seen[] = bin2hex($key);

                return true;
            },
        );

        $loop->runOn(new FakeTerminal($script), idleMicroseconds: 0, maxTicks: 20);

        return $seen;
    }

    public function testAnEscapeSequenceSplitAcrossReadsIsReassembled(): void
    {
        // This is what InputBuffer exists for: a terminal is free to deliver
        // half a CSI now and half on the next read. Every split of the same
        // sequence has to produce the same key.
        $whole = $this->keysFrom(["\033[A", 'q']);

        self::assertSame(['7570'], $whole, 'A whole CSI must arrive as one key — normalised to up.');
        self::assertSame($whole, $this->keysFrom(["\033", '[A', 'q']), 'Split after ESC.');
        self::assertSame($whole, $this->keysFrom(["\033[", 'A', 'q']), 'Split before the final byte.');
        self::assertSame($whole, $this->keysFrom(["\033", '[', 'A', 'q']), 'Split byte by byte.');
    }

    public function testAnIdleTickDoesNotDestroyASequenceStillArriving(): void
    {
        // The regression this slice exists to catch: flushing on ANY idle tick
        // emits the ESC as Escape and throws away the rest of the sequence.
        // A fragment that is still arriving is not an abandoned Escape — only
        // elapsed time tells them apart.
        self::assertSame(
            ['7570'],
            $this->keysFrom(["\033", '', '[A', 'q']),
            'An idle tick between fragments destroyed the sequence.',
        );
    }

    private function resizingLoop(array $keys, ?FakeTerminal &$terminal, int $w, int $h, int $newW, int $newH): RetainedTuiLoop
    {
        $registry = new TuiNodeRendererRegistry();
        $registry->register(new TextRenderer());
        $terminal = new FakeTerminal($keys, $w, $h);
        $captured = $terminal;
        $ticks = 0;

        return new RetainedTuiLoop(
            new RetainedTuiRenderer(new SimpleTuiLayoutEngine(), $registry),
            fn (): TuiNode => new TuiNode('root', 'box', children: [
                new TuiNode('a', 'text', props: ['text' => $this->label]),
            ]),
            ['a'],
            'a',
            $w,
            $h,
            true,
            static fn (string $key): bool => false,
            // Resize on the SECOND tick, so the first frame is painted at the
            // old size and the resize genuinely happens mid-run.
            static function () use ($captured, &$ticks, $newW, $newH): void {
                $ticks++;
                if ($ticks === 2) {
                    $captured->resizeTo($newW, $newH);
                }
            },
        );
    }

    public function testItAdoptsTheTerminalSizeAtStartupNotJustOnResize(): void
    {
        // The resize callback only fires on CHANGE. A loop constructed with a
        // guessed size and run on a real terminal has to ask once, or it paints
        // at the guess until the user happens to resize the window.
        $registry = new TuiNodeRendererRegistry();
        $registry->register(new TextRenderer());
        $terminal = new FakeTerminal(['q'], 72, 8);

        $loop = new RetainedTuiLoop(
            new RetainedTuiRenderer(new SimpleTuiLayoutEngine(), $registry),
            fn (): TuiNode => new TuiNode('root', 'box', children: [
                new TuiNode('a', 'text', props: ['text' => $this->label]),
            ]),
            ['a'],
            'a',
            20,
            3,
            true,
        );

        $loop->runOn($terminal, idleMicroseconds: 0);

        self::assertStringContainsString(
            str_repeat(' ', 60),
            $terminal->output(),
            'The loop painted at its constructed size instead of the terminal\'s.',
        );
    }

    public function testAResizeMidRunChangesTheGeometryOfTheNextFrame(): void
    {
        $loop = $this->resizingLoop(['', '', '', 'q'], $terminal, 40, 6, 72, 10);

        $loop->runOn($terminal, idleMicroseconds: 0);

        $painted = array_values(array_filter($terminal->writes, static fn (string $w): bool => $w !== ''));

        self::assertCount(2, $painted, 'One frame at the old size, one after the resize.');
        self::assertStringNotContainsString(str_repeat(' ', 60), $painted[0], 'The first frame is the old width.');
        self::assertStringContainsString(str_repeat(' ', 60), $painted[1], 'The second carries the new one.');
    }

    public function testAShrinkMidRunForcesAFullRepaintRatherThanADiff(): void
    {
        // The diff walks the CURRENT buffer's rows, so after a shrink the rows
        // that no longer exist are never visited and their contents would stay
        // on screen. The frame after a resize has to be a full paint.
        $loop = $this->resizingLoop(['', '', '', 'q'], $terminal, 60, 10, 60, 4);

        $loop->runOn($terminal, idleMicroseconds: 0);

        $painted = array_values(array_filter($terminal->writes, static fn (string $w): bool => $w !== ''));

        self::assertCount(2, $painted, 'The resize must have produced a further frame.');
        self::assertStringContainsString("\033[H", $painted[1], 'A resize repaints in full.');
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
