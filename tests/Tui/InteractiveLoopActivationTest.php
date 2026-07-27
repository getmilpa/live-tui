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
use Milpa\Live\Tests\Fixtures\RecordingDouble;
use Milpa\Live\Tui\InteractiveTuiLoop;
use Milpa\Live\Tui\TuiComponentInstance;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What Enter, `c` and a typed character actually do to each kind of mounted
 * component — the half of the interactive loop that had shipped unrun.
 *
 * The loop never returns its status: it paints it into the footer. So every
 * assertion here goes through {@see InteractiveTuiLoop::renderScreen()}, which
 * is also what a person would see.
 */
#[CoversClass(InteractiveTuiLoop::class)]
final class InteractiveLoopActivationTest extends TestCase
{
    /**
     * A {@see RecordingDouble} answering to one contract.
     *
     * Each helper below builds its anonymous class at its own source location,
     * which is what gives each one a distinct `contract()` — two doubles built
     * from the same `new class` expression would share it.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $errors
     *
     * @return object The double, implementing ComponentDefinitionInterface, plus a public `$applied` log.
     */
    private function autocompleteDouble(array $data = [], array $meta = [], array $errors = []): object
    {
        return new class ($data, $meta, $errors) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('autocomplete', '1.0.0', 'double');
            }
        };
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     *
     * @return object
     */
    private function dataTableDouble(array $data = [], array $meta = []): object
    {
        return new class ($data, $meta, []) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('data-table', '1.0.0', 'double');
            }
        };
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return object
     */
    private function checkboxDouble(array $data = []): object
    {
        return new class ($data, [], []) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('checkbox', '1.0.0', 'double');
            }
        };
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return object
     */
    private function selectDouble(array $meta = []): object
    {
        return new class ([], $meta, []) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('select', '1.0.0', 'double');
            }
        };
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return object
     */
    private function inputDouble(array $data = []): object
    {
        return new class ($data, [], []) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('input', '1.0.0', 'double');
            }
        };
    }

    /**
     * A contract with no activation and no clear action — the `default` arm.
     *
     * @return object
     */
    private function metricDouble(): object
    {
        return new class ([], [], []) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('metric-card', '1.0.0', 'double');
            }
        };
    }

    /**
     * @param array<string, object> $doubles id => double
     */
    private function loop(array $doubles, int $width = 200): InteractiveTuiLoop
    {
        $registry = new ComponentRendererRegistry();
        $registry->register(new TuiComponentRenderer());

        $instances = [];
        foreach ($doubles as $id => $double) {
            self::assertInstanceOf(ComponentDefinitionInterface::class, $double);
            $context = new ComponentContext($id);
            $instances[] = new TuiComponentInstance($id, $double, $context, [], $double->mount([], $context));
        }

        return new InteractiveTuiLoop($registry, $instances, null, $width);
    }

    private function footerOf(InteractiveTuiLoop $loop): string
    {
        $lines = explode(PHP_EOL, $loop->renderScreen());

        return (string) end($lines);
    }

    // ---- Enter -------------------------------------------------------------

    public function testEnterOnAnAutocompleteSelectsTheItemUnderTheCursor(): void
    {
        $double = $this->autocompleteDouble(['items' => [
            ['value' => 'a', 'label' => 'Alfa'],
            ['value' => 'b', 'label' => 'Beta'],
        ]]);
        $loop = $this->loop(['ac' => $double]);

        $loop->dispatchKey('down');
        $loop->dispatchKey('enter');

        self::assertSame('select', $double->applied[0]['action']);
        self::assertSame(['value' => 'b', 'label' => 'Beta'], $double->applied[0]['payload']['item']);
        self::assertStringContainsString('Selected: Beta', $this->footerOf($loop));
    }

    public function testEnterOnAnEmptyAutocompleteFallsThroughInsteadOfSelectingNothing(): void
    {
        $double = $this->autocompleteDouble(['items' => []]);
        $loop = $this->loop(['ac' => $double]);

        $loop->dispatchKey('enter');

        self::assertSame([], $double->applied, 'Nothing to select means nothing applied.');
        self::assertStringContainsString('No activation action for autocomplete', $this->footerOf($loop));
    }

    public function testEnterOnADataTableTogglesTheRowUnderTheCursor(): void
    {
        $double = $this->dataTableDouble([], ['rows' => [
            ['id' => 'r1', 'name' => 'Uno'],
            ['id' => 'r2', 'name' => 'Dos'],
        ]]);
        $loop = $this->loop(['t' => $double]);

        $loop->dispatchKey('enter');

        self::assertSame('toggle-row', $double->applied[0]['action']);
        self::assertSame('r1', $double->applied[0]['payload']['rowId']);
        self::assertStringContainsString('Toggled row: r1', $this->footerOf($loop));
    }

    /**
     * A row identifies itself by the first of id/key/value/name it carries. A
     * row carrying none of them still has to get a stable id, or toggling it
     * would target a different row on the next render.
     *
     * @return iterable<string, array{array<string, mixed>, ?string}>
     */
    public static function rowShapes(): iterable
    {
        yield 'id wins' => [['id' => 'a', 'key' => 'b', 'value' => 'c', 'name' => 'd'], 'a'];
        yield 'then key' => [['key' => 'b', 'value' => 'c'], 'b'];
        yield 'then value' => [['value' => 'c', 'name' => 'd'], 'c'];
        yield 'then name' => [['name' => 'd'], 'd'];
        yield 'otherwise a hash' => [['algo' => 'sin identidad'], null];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('rowShapes')]
    public function testARowIsIdentifiedByTheFirstKeyItCarries(array $row, ?string $expected): void
    {
        $double = $this->dataTableDouble([], ['rows' => [$row]]);
        $loop = $this->loop(['t' => $double]);

        $loop->dispatchKey('enter');
        $rowId = $double->applied[0]['payload']['rowId'];

        if ($expected !== null) {
            self::assertSame($expected, $rowId);
        } else {
            self::assertSame(sha1((string) json_encode($row)), $rowId, 'The fallback is a hash of the row itself.');
        }
    }

    public function testEnterOnACheckboxFlipsIt(): void
    {
        $double = $this->checkboxDouble(['checked' => false]);
        $loop = $this->loop(['c' => $double]);

        $loop->dispatchKey('enter');

        self::assertSame('change', $double->applied[0]['action']);
        self::assertTrue($double->applied[0]['payload']['checked']);
        self::assertStringContainsString('Toggled checkbox', $this->footerOf($loop));
    }

    public function testEnterOnASelectChoosesTheOptionUnderTheCursor(): void
    {
        $double = $this->selectDouble(['options' => [
            ['value' => 'mx', 'label' => 'México'],
            ['value' => 'co', 'label' => 'Colombia'],
        ]]);
        $loop = $this->loop(['s' => $double]);

        $loop->dispatchKey('down');
        $loop->dispatchKey('enter');

        self::assertSame(['value' => 'co'], $double->applied[0]['payload']);
        self::assertStringContainsString('Selected: Colombia', $this->footerOf($loop));
    }

    public function testADisabledOptionIsNotSelectable(): void
    {
        $double = $this->selectDouble(['options' => [
            ['value' => 'mx', 'label' => 'México', 'disabled' => true],
        ]]);
        $loop = $this->loop(['s' => $double]);

        $loop->dispatchKey('enter');

        self::assertSame([], $double->applied);
        self::assertStringContainsString('No activation action for select', $this->footerOf($loop));
    }

    public function testAnOptionListFillsInWhatItIsMissing(): void
    {
        // No value, no label: the key stands in for both. The loop must not
        // crash on a half-specified option — it comes from the host, not here.
        $double = $this->selectDouble(['options' => ['mx' => ['label' => 'México'], 'co' => []]]);
        $loop = $this->loop(['s' => $double]);

        $loop->dispatchKey('down');
        $loop->dispatchKey('enter');

        self::assertSame(['value' => 'co'], $double->applied[0]['payload']);
        self::assertStringContainsString('Selected: co', $this->footerOf($loop), 'The label falls back to the key.');
    }

    public function testJunkInAnOptionListIsSkippedRatherThanRendered(): void
    {
        $double = $this->selectDouble(['options' => ['no soy un arreglo', ['value' => 'ok', 'label' => 'Ok']]]);
        $loop = $this->loop(['s' => $double]);

        $loop->dispatchKey('enter');

        self::assertSame(['value' => 'ok'], $double->applied[0]['payload'], 'The string was dropped, not counted.');
    }

    public function testEnterOnAComponentWithNoActionSaysSoInsteadOfFailing(): void
    {
        $loop = $this->loop(['m' => $this->metricDouble()]);

        $loop->dispatchKey('enter');

        self::assertStringContainsString('No activation action for metric-card', $this->footerOf($loop));
    }

    // ---- clear ---------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function clearActions(): iterable
    {
        yield 'autocomplete' => ['autocomplete', 'clear'];
        yield 'data-table' => ['data-table', 'clear-selection'];
        yield 'input' => ['input', 'reset'];
        yield 'checkbox' => ['checkbox', 'reset'];
        yield 'select' => ['select', 'reset'];
    }

    #[DataProvider('clearActions')]
    public function testCEachContractClearsWithItsOwnAction(string $contract, string $action): void
    {
        $double = match ($contract) {
            'autocomplete' => $this->autocompleteDouble(),
            'data-table' => $this->dataTableDouble(),
            'input' => $this->inputDouble(),
            'checkbox' => $this->checkboxDouble(),
            default => $this->selectDouble(),
        };
        $loop = $this->loop(['x' => $double]);

        $loop->dispatchKey('c');

        self::assertSame($action, $double->applied[0]['action']);
        self::assertStringContainsString('Cleared ' . $contract, $this->footerOf($loop));
    }

    public function testCOnAComponentWithNothingToClearSaysSo(): void
    {
        $loop = $this->loop(['m' => $this->metricDouble()]);

        $loop->dispatchKey('c');

        self::assertStringContainsString('No clear action for metric-card', $this->footerOf($loop));
    }

    // ---- typing ---------------------------------------------------------------

    public function testAPrintableCharacterAppendsToAnInput(): void
    {
        $double = $this->inputDouble(['value' => 'ho']);
        $loop = $this->loop(['i' => $double]);

        $loop->dispatchKey('l');

        self::assertSame('change', $double->applied[0]['action']);
        self::assertSame('hol', $double->applied[0]['payload']['value']);
        self::assertStringContainsString('Changed: hol', $this->footerOf($loop));
    }

    public function testBackspaceRemovesTheLastCharacter(): void
    {
        $double = $this->inputDouble(['value' => 'hola']);
        $loop = $this->loop(['i' => $double]);

        $loop->dispatchKey("\177");

        self::assertSame('hol', $double->applied[0]['payload']['value']);
    }

    public function testEmptyingAnInputIsReportedAsEmptyNotAsBlank(): void
    {
        $double = $this->inputDouble(['value' => 'a']);
        $loop = $this->loop(['i' => $double]);

        $loop->dispatchKey("\177");

        self::assertStringContainsString('Changed: (empty)', $this->footerOf($loop));
    }

    public function testTypingIntoAnAutocompleteSearchesInsteadOfChanging(): void
    {
        $double = $this->autocompleteDouble(['query' => 'me']);
        $loop = $this->loop(['ac' => $double]);

        $loop->dispatchKey('x');

        self::assertSame('search', $double->applied[0]['action']);
        self::assertSame('mex', $double->applied[0]['payload']['query']);
        self::assertStringContainsString('Search: mex', $this->footerOf($loop));
    }

    public function testBackspaceOnAnAutocompleteShortensTheQuery(): void
    {
        $double = $this->autocompleteDouble(['query' => 'mex']);
        $loop = $this->loop(['ac' => $double]);

        $loop->dispatchKey("\177");

        self::assertSame('me', $double->applied[0]['payload']['query']);
    }

    public function testSpaceActivatesWhenTheFocusedComponentDoesNotTakeText(): void
    {
        $double = $this->checkboxDouble(['checked' => true]);
        $loop = $this->loop(['c' => $double]);

        $loop->dispatchKey(' ');

        self::assertSame('change', $double->applied[0]['action']);
        self::assertFalse($double->applied[0]['payload']['checked']);
    }

    public function testAnErrorFromTheComponentBecomesTheStatus(): void
    {
        $double = $this->autocompleteDouble(['query' => ''], [], ['query' => 'Consulta inválida']);
        $loop = $this->loop(['ac' => $double]);

        $loop->dispatchKey('z');

        self::assertStringContainsString('Consulta inválida', $this->footerOf($loop));
    }

    // ---- movement --------------------------------------------------------------

    public function testUpAndDownMoveTheCursorWithinTheChoicesAndStopAtTheEnds(): void
    {
        $double = $this->selectDouble(['options' => [
            ['value' => 'a', 'label' => 'A'],
            ['value' => 'b', 'label' => 'B'],
        ]]);
        $loop = $this->loop(['s' => $double]);

        $loop->dispatchKey("\033[B");
        self::assertStringContainsString('Cursor: 2/2', $this->footerOf($loop));

        $loop->dispatchKey("\033[B");
        self::assertStringContainsString('Cursor: 2/2', $this->footerOf($loop), 'It clamps instead of running off.');

        $loop->dispatchKey("\033[A");
        $loop->dispatchKey("\033[A");
        self::assertStringContainsString('Cursor: 1/2', $this->footerOf($loop), 'And clamps at the top too.');
    }

    public function testWithNothingToScrollThroughUpAndDownMoveFocusInstead(): void
    {
        $loop = $this->loop(['a' => $this->metricDouble(), 'b' => $this->inputDouble()]);

        $loop->dispatchKey('down');

        self::assertSame('b', $loop->focusedId());
        self::assertStringContainsString('Focus: b', $this->footerOf($loop));

        $loop->dispatchKey('up');

        self::assertSame('a', $loop->focusedId());
    }

    // ---- the loops ----------------------------------------------------------------

    public function testRunOnDrivesTheLoopFromATerminalAndStopsWhenAskedTo(): void
    {
        $terminal = new FakeTerminal(['', 'q'], 30, 10);
        $loop = $this->loop(['i' => $this->inputDouble()]);

        $loop->runOn($terminal, idleMicroseconds: 0);

        self::assertFalse($loop->running());
        self::assertSame('start', $terminal->lifecycle[0]);
        self::assertSame('stop', end($terminal->lifecycle), 'The terminal is handed back however the loop leaves.');
        self::assertNotSame([], $terminal->writes, 'It painted at least once.');
    }

    public function testRunOnStopsWhenTheInputEndsRatherThanSpinningForever(): void
    {
        $terminal = new FakeTerminal([], 30, 10);
        $loop = $this->loop(['i' => $this->inputDouble()]);

        $loop->runOn($terminal, idleMicroseconds: 0);

        self::assertTrue($loop->running(), 'It ran out of input; nobody asked it to quit.');
        self::assertSame('start', $terminal->lifecycle[0]);
        self::assertSame('stop', end($terminal->lifecycle));
    }

    public function testRunOnAdoptsTheTerminalWidthAtStartupWithoutWaitingForAResize(): void
    {
        // The resize callback only fires on CHANGE. A loop that never asks
        // would paint at its constructed width on a terminal of another size.
        $terminal = new FakeTerminal(['q'], 24, 10);
        $loop = $this->loop(['i' => $this->inputDouble()], width: 200);

        $loop->runOn($terminal, idleMicroseconds: 0);

        $header = explode(PHP_EOL, implode('', $terminal->writes))[0];

        self::assertLessThanOrEqual(24, mb_strlen($header), 'It painted at the terminal width, not at 200.');
    }

    public function testAResizeMidSessionIsAdopted(): void
    {
        $terminal = new FakeTerminal(['', 'q'], 80, 10);
        $loop = $this->loop(['i' => $this->inputDouble()]);

        $anchoOriginal = mb_strlen(explode(PHP_EOL, $loop->renderScreen())[0]);
        $loop->runOn($terminal, idleMicroseconds: 0);
        $loop->resizeTo(10);
        $anchoNuevo = mb_strlen(explode(PHP_EOL, $loop->renderScreen())[0]);

        self::assertLessThanOrEqual(10, $anchoNuevo);
        self::assertGreaterThan($anchoNuevo, $anchoOriginal);
    }

    public function testResizingToNothingStillLeavesAColumnToPaintIn(): void
    {
        $loop = $this->loop(['i' => $this->inputDouble()]);

        $loop->resizeTo(0);

        self::assertNotSame('', $loop->renderScreen());
    }

    public function testTheStreamLoopAssemblesAnArrowFromItsBytes(): void
    {
        $double = $this->selectDouble(['options' => [
            ['value' => 'a', 'label' => 'A'],
            ['value' => 'b', 'label' => 'B'],
        ]]);
        $loop = $this->loop(['s' => $double]);

        $input = fopen('php://memory', 'r+');
        self::assertIsResource($input);
        fwrite($input, "\033[B");
        rewind($input);
        $output = fopen('php://memory', 'r+');
        self::assertIsResource($output);

        $loop->run($input, $output);

        rewind($output);
        $painted = (string) stream_get_contents($output);

        self::assertStringContainsString('Cursor: 2/2', $painted, 'The three bytes became one Down.');
        self::assertStringNotContainsString("\033[2J", $painted, 'Not a tty: no screen-clearing escapes.');
    }

    public function testTheStreamLoopStopsWhenTheStreamRunsOut(): void
    {
        $loop = $this->loop(['i' => $this->inputDouble()]);

        $input = fopen('php://memory', 'r+');
        self::assertIsResource($input);
        fwrite($input, 'a');
        rewind($input);
        $output = fopen('php://memory', 'r+');
        self::assertIsResource($output);

        $loop->run($input, $output);

        rewind($output);
        self::assertStringContainsString('Changed: a', (string) stream_get_contents($output));
    }

    public function testQuittingStopsTheLoopAndSaysSo(): void
    {
        $loop = $this->loop(['i' => $this->inputDouble()]);

        self::assertFalse($loop->dispatchKey("\033"), 'Escape quits, and dispatchKey reports it is no longer running.');
        self::assertFalse($loop->running());
        self::assertStringContainsString('Quit', $this->footerOf($loop));
    }

    public function testAHeaderTooLongForTheWidthIsTruncatedWithAMarker(): void
    {
        $loop = $this->loop(['componente-con-un-id-larguisimo' => $this->inputDouble()], width: 12);

        $first = explode(PHP_EOL, $loop->renderScreen())[0];

        self::assertSame(12, mb_strlen($first));
        self::assertStringEndsWith('~', $first);
    }
}
