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
use Milpa\Live\Tui\InMemoryTuiEventBus;
use Milpa\Live\Tui\InputBuffer;
use Milpa\Live\Tui\NodeRenderers\TextRenderer;
use Milpa\Live\Tui\RetainedTuiLoop;
use Milpa\Live\Tui\RetainedTuiRenderer;
use Milpa\Live\Tui\SimpleTuiLayoutEngine;
use Milpa\Live\Tui\TuiNodeRendererRegistry;
use Milpa\Live\ValueObjects\Tui\TuiEvent;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every key a read carries reaches the screen, once, in the order it was pressed (greenhouse decisions/0524).
 *
 * The loop dispatches one key per pass; a read can carry many. Before this, a read that arrived while a key was
 * still queued was thrown away whole (evidence/1053, F1), an arrow key and the letters typed after it came out as
 * one unknown key, and a character split between two reads came out as two bytes that are no key.
 */
#[CoversClass(RetainedTuiLoop::class)]
#[CoversClass(InputBuffer::class)]
final class EveryKeyArrivesTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function reads(): iterable
    {
        yield 'the 1053 transcript: two keys per poll' => [
            ['ca', 'll', ':l', 'ab', '-1', '05', '3_', 'ke', 'rn', 'el'],
            str_split('call:lab-1053_kernel'),
        ];
        yield 'a read that arrives while keys are still queued' => [
            ['abc', 'def', 'ghi'],
            str_split('abcdefghi'),
        ];
        yield 'a paste without bracketed paste: one read, a sentence' => [
            ['hola, casa — ¿qué tal?'],
            ['h', 'o', 'l', 'a', ',', ' ', 'c', 'a', 's', 'a', ' ', '—', ' ', '¿', 'q', 'u', 'é', ' ', 't', 'a', 'l', '?'],
        ];
        yield 'ñ split between two reads' => [
            ["a\xC3", "\xB1b"],
            ['a', 'ñ', 'b'],
        ];
        yield 'a four-byte character in three reads' => [
            ["\xF0", "\x9F\x8C", "\xBDx"],
            ["\u{1F33D}", 'x'],
        ];
        yield 'an arrow and the letters after it, in one read' => [
            ["\033[Axy"],
            ["\033[A", 'x', 'y'],
        ];
        yield 'arrows back to back' => [
            ["\033[A\033[B\033[C\033[D"],
            ["\033[A", "\033[B", "\033[C", "\033[D"],
        ];
        yield 'an arrow split between two reads' => [
            ["x\033[", 'Bz'],
            ['x', "\033[B", 'z'],
        ];
        yield 'text, Enter, text' => [
            ["uno\rdos"],
            ['u', 'n', 'o', "\r", 'd', 'o', 's'],
        ];
        yield 'a byte that starts no character is a key of its own and waits for nothing' => [
            ["a\xFFb"],
            ['a', "\xFF", 'b'],
        ];
    }

    /**
     * @param list<string> $reads
     * @param list<string> $expected
     */
    #[DataProvider('reads')]
    public function testEveryKeyOfEveryReadArrivesInOrder(array $reads, array $expected): void
    {
        self::assertSame($expected, $this->typed([...$reads, "\x03"]));
    }

    public function testHalfACharacterWaitsForItsOtherHalfPastTheEscapeTimeout(): void
    {
        // The escape timeout is for a lone ESC. A character split by a slow link arrives later than any timeout, and
        // flushing its first byte would hand the screen a byte that is no key (evidence/1058: `ñ` lost at 250 ms).
        self::assertSame(['a', 'ñ', 'b'], $this->typed(["a\xC3", '', '', '', '', "\xB1b", "\x03"], escapeTimeout: 0));
    }

    public function testALoneEscapeIsStillFlushedWhenItsTimeoutPasses(): void
    {
        self::assertSame(["\033", 'x'], $this->typed(["\033", '', 'x', "\x03"], escapeTimeout: 0));
    }

    public function testAReadThatArrivesWithKeysStillQueuedIsKeptWhole(): void
    {
        // The pushed and the polled bytes of one pass are one read; the next pass brings another before the first
        // is drained. Nothing of either may be lost or reordered.
        $keys = $this->typed(['abcd', 'efgh', '', '', "ij\x03"]);

        self::assertSame(str_split('abcdefghij'), $keys);
    }

    public function testCtrlCInsideARunStopsWhereItWasPressed(): void
    {
        // Ctrl-C is a key like any other: what came before it arrives, what came after it does not run.
        self::assertSame(['a', 'b'], $this->typed(["ab\x03cd"]));
    }

    public function testAPasteInBracketedModeIsOneEventAndTheKeysAroundItStillArrive(): void
    {
        $events = new InMemoryTuiEventBus();
        $pasted = [];
        $events->subscribe('paste.received', static function (TuiEvent $event) use (&$pasted): void {
            $pasted[] = $event->payload['content'];
        });

        $keys = $this->typed(
            ['a' . BracketedPaste::PASTE_BEGIN . 'dos ñ', "\033[A tres" . BracketedPaste::PASTE_END . 'b', "\x03"],
            new BracketedPaste($events, collapse: false),
        );

        self::assertSame(['a', 'b'], $keys);
        self::assertSame(["dos ñ\033[A tres"], $pasted);
    }

    public function testWhatIsTypedWhileTheScreenIsBusyArrivesAfterTheWorkInOrder(): void
    {
        $registry = new TuiNodeRendererRegistry();
        $registry->register(new TextRenderer());
        $received = [];
        $busy = new FakeTerminal(['ab', "c\x03", '']);
        $quits = [];

        $loop = new RetainedTuiLoop(
            new RetainedTuiRenderer(new SimpleTuiLayoutEngine(), $registry),
            static fn (): TuiNode => new TuiNode('root', 'box', children: [new TuiNode('a', 'text', props: ['text' => 'x'])]),
            ['a'],
            'a',
            40,
            6,
            handleKey: static function (string $key, RetainedTuiLoop $loop) use (&$received, &$quits, $busy): bool {
                $received[] = $loop->lastRawKey();
                if ($key === 'w') {
                    // Long work: the person types while it runs; each repaint reads it.
                    $quits[] = $loop->readWhileBusy($busy);
                    $quits[] = $loop->readWhileBusy($busy);
                    $quits[] = $loop->readWhileBusy($busy);
                }

                return true;
            },
            quitKeys: ['ctrl+c'],
        );

        $loop->runOn(new FakeTerminal(['w', 'd', "\x03"]), idleMicroseconds: 0, maxTicks: 50);

        self::assertSame([false, true, false], $quits, 'the read that held Ctrl-C says so; an empty one says nothing');
        self::assertSame(['w', 'a', 'b', 'c'], $received, 'typed ahead, kept in order, and nothing after the quit key');
    }

    public function testFeedKeysCutsWhereAPersonPressed(): void
    {
        $buffer = new InputBuffer();

        self::assertSame(['a'], $buffer->feedKeys("a\xC3"));
        self::assertSame("\xC3", $buffer->pending(), 'Half a character waits for its other half.');
        self::assertSame(['ñ', "\033[1;5C", 'z'], $buffer->feedKeys("\xB1\033[1;5Cz"));
        self::assertSame('', $buffer->pending());
    }

    public function testFeedStillHandsBackTheWholeCharacter(): void
    {
        $buffer = new InputBuffer();

        self::assertSame('', $buffer->feed("\xE2\x80"));
        self::assertSame('—x', $buffer->feed("\x94x"));
    }

    public function testCharactersSplitsTextWithoutGuessing(): void
    {
        self::assertSame(['a', 'ñ', "\xC3", 'b'], InputBuffer::characters("añ\xC3b"));
        self::assertSame(["\xE2", "\x80"], InputBuffer::characters("\xE2\x80"));
        self::assertSame([], InputBuffer::characters(''));
    }

    /**
     * Runs a loop over the given reads — one per poll — and returns every raw key the screen's handler received.
     *
     * @param list<string> $reads
     *
     * @return list<string>
     */
    private function typed(array $reads, ?BracketedPaste $paste = null, int $escapeTimeout = 50000): array
    {
        $registry = new TuiNodeRendererRegistry();
        $registry->register(new TextRenderer());
        $received = [];

        $loop = new RetainedTuiLoop(
            new RetainedTuiRenderer(new SimpleTuiLayoutEngine(), $registry),
            static fn (): TuiNode => new TuiNode('root', 'box', children: [new TuiNode('a', 'text', props: ['text' => 'x'])]),
            ['a'],
            'a',
            40,
            6,
            handleKey: static function (string $key, RetainedTuiLoop $loop) use (&$received): bool {
                $received[] = $loop->lastRawKey();

                return true;
            },
            paste: $paste,
            quitKeys: ['ctrl+c'],
        );

        $loop->runOn(new FakeTerminal($reads), idleMicroseconds: 0, maxTicks: 200, escapeTimeoutMicroseconds: $escapeTimeout);

        return $received;
    }
}
