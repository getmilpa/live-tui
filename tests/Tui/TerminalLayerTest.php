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
use Milpa\Live\Contracts\Component\ComponentRegistryInterface;
use Milpa\Live\Rendering\TuiComponentRenderer;
use Milpa\Live\Tui\InMemoryBackgroundJobManager;
use Milpa\Live\Tui\NodeRenderers\ComponentTuiNodeRenderer;
use Milpa\Live\Tui\NodeRenderers\ImageRenderer;
use Milpa\Live\Tui\StreamTerminal;
use Milpa\Live\Tui\SynchronizedOutput;
use Milpa\Live\Tui\TerminalCapabilities;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;
use Milpa\Live\ValueObjects\Tui\TuiBounds;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The layer that touches the device: the stream-backed terminal, what it can be
 * asked to do, and how capabilities are read off the environment.
 *
 * Everything here runs over memory streams. A device is never opened, which is
 * exactly why none of it had been executed before.
 */
#[CoversClass(StreamTerminal::class)]
#[CoversClass(TerminalCapabilities::class)]
#[CoversClass(SynchronizedOutput::class)]
#[CoversClass(ComponentTuiNodeRenderer::class)]
#[CoversClass(ImageRenderer::class)]
#[CoversClass(InMemoryBackgroundJobManager::class)]
final class TerminalLayerTest extends TestCase
{
    /**
     * @return array{0: StreamTerminal, 1: resource, 2: resource}
     */
    private function terminal(string $input = ''): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);

        if ($input !== '') {
            fwrite($in, $input);
            rewind($in);
        }

        return [new StreamTerminal(null, $in, $out), $in, $out];
    }

    private function written(mixed $out): string
    {
        rewind($out);

        return (string) stream_get_contents($out);
    }

    // ---- StreamTerminal --------------------------------------------------

    public function testItWritesRawBytesUnmodified(): void
    {
        [$terminal, , $out] = $this->terminal();
        $terminal->write('hola');

        self::assertSame('hola', $this->written($out));
    }

    public function testTheCursorAndScreenCommandsEmitTheirEscapes(): void
    {
        [$terminal, , $out] = $this->terminal();

        $terminal->hideCursor();
        $terminal->showCursor();
        $terminal->clearLine();
        $terminal->clearFromCursor();
        $terminal->clearScreen();
        $terminal->setTitle('milpa');
        $terminal->moveBy(2);
        $terminal->moveBy(-3);
        $terminal->moveBy(0);

        $written = $this->written($out);

        self::assertStringContainsString("\x1b[?25l", $written);
        self::assertStringContainsString("\x1b[?25h", $written);
        self::assertStringContainsString("\x1b[2K", $written);
        self::assertStringContainsString("\x1b[J", $written);
        self::assertStringContainsString("\x1b[2J", $written);
        self::assertStringContainsString('milpa', $written);
        self::assertStringContainsString("\x1b[2B", $written, 'A positive move goes down.');
        self::assertStringContainsString("\x1b[3A", $written, 'A negative move goes up.');
    }

    public function testItReportsASizeEvenWithoutADevice(): void
    {
        [$terminal] = $this->terminal();

        self::assertGreaterThan(0, $terminal->columns());
        self::assertGreaterThan(0, $terminal->rows());
    }

    public function testItPollsWhatIsOnTheStream(): void
    {
        [$terminal] = $this->terminal('abc');

        self::assertSame('abc', $terminal->pollInput());
        self::assertSame('', $terminal->pollInput(), 'Nothing left.');
    }

    public function testAnExhaustedStreamReportsTheEndOfItsInput(): void
    {
        [$terminal] = $this->terminal('a');

        $terminal->pollInput();
        $terminal->pollInput();

        self::assertTrue($terminal->atEndOfInput());
    }

    public function testStoppingBeforeStartingIsHarmless(): void
    {
        [$terminal, , $out] = $this->terminal();
        $terminal->stop();

        self::assertSame('', $this->written($out));
    }

    public function testStartingAndStoppingOverAMemoryStreamRestoresTheCursor(): void
    {
        [$terminal, , $out] = $this->terminal('q');

        $terminal->start(static function (): void {
        }, static function (): void {
        });
        $terminal->stop();

        self::assertStringContainsString("\x1b[?25h", $this->written($out), 'stop() hands the cursor back.');
    }

    public function testInputIsHandedToTheRegisteredHandler(): void
    {
        [$terminal] = $this->terminal();
        $seen = [];

        $terminal->start(static function (string $bytes) use (&$seen): void {
            $seen[] = $bytes;
        }, static function (): void {
        });
        $terminal->dispatchInput('hola');

        self::assertSame(['hola'], $seen);
    }

    public function testDispatchingWithoutAHandlerIsHarmless(): void
    {
        [$terminal] = $this->terminal();
        $terminal->dispatchInput('hola');

        self::assertTrue(true, 'Nothing blew up.');
    }

    // ---- SynchronizedOutput ----------------------------------------------

    public function testSynchronisedOutputBracketsTheBytesWhenEnabled(): void
    {
        $sync = new SynchronizedOutput(true);

        self::assertTrue($sync->enabled());
        self::assertStringContainsString('hola', $sync->wrap('hola'));
        self::assertNotSame('hola', $sync->wrap('hola'));
    }

    public function testSynchronisedOutputIsATransparentPassthroughWhenDisabled(): void
    {
        $sync = new SynchronizedOutput(false);

        self::assertFalse($sync->enabled());
        self::assertSame('hola', $sync->wrap('hola'));
    }

    public function testWrappingNothingProducesNothing(): void
    {
        self::assertSame('', (new SynchronizedOutput(true))->wrap(''));
    }

    // ---- TerminalCapabilities --------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function imageCapableTerminals(): array
    {
        return [
            'kitty by program' => ['', 'kitty', TerminalCapabilities::IMAGES_KITTY],
            'ghostty by program' => ['', 'ghostty', TerminalCapabilities::IMAGES_KITTY],
            'wezterm by program' => ['', 'wezterm', TerminalCapabilities::IMAGES_KITTY],
            'iterm by program' => ['', 'iTerm.app', TerminalCapabilities::IMAGES_ITERM2],
            'kitty by term' => ['xterm-kitty', '', TerminalCapabilities::IMAGES_KITTY],
            'ghostty by term' => ['xterm-ghostty', '', TerminalCapabilities::IMAGES_KITTY],
            'plain xterm' => ['xterm-256color', '', TerminalCapabilities::IMAGES_NONE],
            'nothing at all' => ['', '', TerminalCapabilities::IMAGES_NONE],
        ];
    }

    #[DataProvider('imageCapableTerminals')]
    public function testItReadsTheImageProtocolOffTheEnvironment(string $term, string $program, string $expected): void
    {
        self::assertSame($expected, (new TerminalCapabilities($term, $program))->imageProtocol());
    }

    public function testItAnswersTheOtherCapabilityQuestions(): void
    {
        $kitty = new TerminalCapabilities('xterm-kitty', 'kitty', 'truecolor');
        $plain = new TerminalCapabilities('dumb', '', '');

        foreach ([$kitty, $plain] as $caps) {
            self::assertIsBool($caps->supportsKittyKeyboard());
            self::assertIsBool($caps->supportsSynchronizedOutput());
            self::assertIsBool($caps->supportsOsc11());
            self::assertIsBool($caps->supportsTruecolor());
        }

        self::assertTrue($kitty->supportsTruecolor(), 'COLORTERM=truecolor is the whole signal.');
        self::assertFalse($plain->supportsTruecolor());
    }

    public function testItCanBeBuiltFromTheRealEnvironment(): void
    {
        self::assertInstanceOf(TerminalCapabilities::class, TerminalCapabilities::fromEnvironment());
    }

    // ---- ImageRenderer ----------------------------------------------------

    public function testAnImageWithoutSupportFallsBackToAPlaceholder(): void
    {
        $frame = (new ImageRenderer())->render(
            new TuiNode('img', 'image', props: [
                'filename' => 'foto.png',
                'mimeType' => 'image/png',
                'protocol' => TerminalCapabilities::IMAGES_NONE,
            ]),
            new TuiRenderContext(new TuiBounds(0, 0, 40, 5)),
        );

        self::assertStringContainsString('foto.png', implode("\n", $frame->lines));
    }

    public function testAnImageWithoutAFilenameFallsBackToItsMimeType(): void
    {
        $frame = (new ImageRenderer())->render(
            new TuiNode('img', 'image', props: ['mimeType' => 'image/jpeg']),
            new TuiRenderContext(new TuiBounds(0, 0, 40, 5)),
        );

        self::assertNotSame('', trim(implode('', $frame->lines)));
    }

    public function testAnInlineProtocolProducesDifferentOutputThanTheFallback(): void
    {
        $node = static fn (string $protocol): TuiNode => new TuiNode('img', 'image', props: [
            'filename' => 'foto.png',
            'mimeType' => 'image/png',
            'data' => base64_encode('bytes-de-imagen'),
            'protocol' => $protocol,
            'widthCells' => 10,
            'heightCells' => 3,
        ]);

        $context = new TuiRenderContext(new TuiBounds(0, 0, 40, 5));
        $renderer = new ImageRenderer();

        $none = implode("\n", $renderer->render($node(TerminalCapabilities::IMAGES_NONE), $context)->lines);
        $kitty = implode("\n", $renderer->render($node(TerminalCapabilities::IMAGES_KITTY), $context)->lines);
        $iterm = implode("\n", $renderer->render($node(TerminalCapabilities::IMAGES_ITERM2), $context)->lines);

        self::assertNotSame($none, $kitty);
        self::assertNotSame($none, $iterm);
        self::assertNotSame($kitty, $iterm);
    }

    // ---- ComponentTuiNodeRenderer -----------------------------------------

    public function testItRendersAComponentNodeThroughTheRegistry(): void
    {
        $component = new class () implements ComponentDefinitionInterface {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('input', '1.0.0', 'test double');
            }

            public function mount(array $props, ComponentContext $context): StateSnapshot
            {
                return new StateSnapshot($context->componentId, 'input', '1.0.0', ['value' => 'MARCA'], ['label' => 'Campo']);
            }

            public function handle(InteractionRequest $request): InteractionResult
            {
                return new InteractionResult($request->state);
            }
        };

        $registry = new class ($component) implements ComponentRegistryInterface {
            public function __construct(private readonly ComponentDefinitionInterface $component)
            {
            }

            public function has(string $name): bool
            {
                return $name === 'input';
            }

            public function get(string $name): ComponentDefinitionInterface
            {
                return $this->component;
            }

            public function register(string $name, ComponentDefinitionInterface $component): void
            {
            }
        };

        $renderer = new ComponentTuiNodeRenderer($registry, new TuiComponentRenderer());

        self::assertTrue($renderer->supports(new TuiNode('n', 'component')));
        self::assertFalse($renderer->supports(new TuiNode('n', 'text')));

        $frame = $renderer->render(
            new TuiNode('n', 'component', props: ['component' => 'input', 'contextId' => 'c1']),
            new TuiRenderContext(new TuiBounds(0, 0, 50, 6)),
        );

        self::assertStringContainsString('Campo', implode("\n", $frame->lines));
    }

    public function testAComponentNodeWithoutANameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ComponentTuiNodeRenderer($this->emptyRegistry(), new TuiComponentRenderer()))->render(
            new TuiNode('n', 'component', props: []),
            new TuiRenderContext(new TuiBounds(0, 0, 40, 4)),
        );
    }

    public function testAStateThatIsNotASnapshotIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ComponentTuiNodeRenderer($this->emptyRegistry(), new TuiComponentRenderer()))->render(
            new TuiNode('n', 'component', props: ['component' => 'input', 'state' => 'no soy un snapshot']),
            new TuiRenderContext(new TuiBounds(0, 0, 40, 4)),
        );
    }

    public function testAnUnknownComponentFailsLoudRatherThanDrawingSomethingWrong(): void
    {
        // Deliberate: the renderer does not consult has() before get(), so an
        // unknown name surfaces as the registry's own error. The trade-off is
        // real and worth naming — one bad node takes the whole frame with it,
        // rather than the frame showing "unknown component" in one panel.
        $this->expectException(\RuntimeException::class);

        (new ComponentTuiNodeRenderer($this->emptyRegistry(), new TuiComponentRenderer()))->render(
            new TuiNode('n', 'component', props: ['component' => 'no-existe']),
            new TuiRenderContext(new TuiBounds(0, 0, 40, 4)),
        );
    }

    private function emptyRegistry(): ComponentRegistryInterface
    {
        return new class () implements ComponentRegistryInterface {
            public function has(string $name): bool
            {
                return false;
            }

            public function get(string $name): ComponentDefinitionInterface
            {
                throw new \RuntimeException('no such component');
            }

            public function register(string $name, ComponentDefinitionInterface $component): void
            {
            }
        };
    }

    // ---- InMemoryBackgroundJobManager -------------------------------------

    public function testTheJobManagerTracksAJobThroughItsWholeLife(): void
    {
        $jobs = new InMemoryBackgroundJobManager();

        $job = $jobs->start('Compilar', 'make build');
        self::assertSame('Compilar', $job->label);
        self::assertNotNull($jobs->get($job->id));

        $jobs->appendOutput($job->id, 'compilando', 'stdout');
        $jobs->progress($job->id, 0.5);
        self::assertSame(0.5, $jobs->get($job->id)?->progress);

        $jobs->finish($job->id, 0);
        self::assertSame(0, $jobs->get($job->id)?->exitCode);

        self::assertCount(1, $jobs->all());
    }

    public function testAFailedJobKeepsItsReason(): void
    {
        $jobs = new InMemoryBackgroundJobManager();
        $job = $jobs->start('Fallar', 'false');

        $jobs->fail($job->id, 'se rompio', 1);

        self::assertSame('se rompio', $jobs->get($job->id)?->error);
    }

    public function testACancelledJobIsRecordedAsSuch(): void
    {
        $jobs = new InMemoryBackgroundJobManager();
        $job = $jobs->start('Cancelar', 'sleep 100');

        $jobs->cancel($job->id);

        self::assertNotSame('running', $jobs->get($job->id)?->status);
    }

    public function testAnUnknownJobIdIsAnswerableWithoutBlowingUp(): void
    {
        $jobs = new InMemoryBackgroundJobManager();

        self::assertNull($jobs->get('no-existe'));
        self::assertSame([], $jobs->all());
    }
}
