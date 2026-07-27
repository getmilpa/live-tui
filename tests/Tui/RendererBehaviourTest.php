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

use Milpa\Live\Tui\NodeRenderers\CommandPaletteRenderer;
use Milpa\Live\Tui\NodeRenderers\EditorRenderer;
use Milpa\Live\Tui\NodeRenderers\JobMonitorRenderer;
use Milpa\Live\Tui\NodeRenderers\LogViewRenderer;
use Milpa\Live\Tui\NodeRenderers\ProgressBarRenderer;
use Milpa\Live\Tui\NodeRenderers\SelectListRenderer;
use Milpa\Live\Tui\NodeRenderers\SettingsListRenderer;
use Milpa\Live\Tui\NodeRenderers\TextInputRenderer;
use Milpa\Live\Tui\RetainedTuiRenderer;
use Milpa\Live\Tui\SimpleTuiLayoutEngine;
use Milpa\Live\Tui\TuiNodeRendererRegistry;
use Milpa\Live\Tui\NodeRenderers\TextRenderer;
use Milpa\Live\ValueObjects\Tui\BackgroundJob;
use Milpa\Live\ValueObjects\Tui\TuiBounds;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What the interactive renderers actually draw, past the contract they all
 * share: cursors, scrolling, filtering, masking and empty states.
 *
 * `EveryRendererTest` proves none of them ships unrun. This one asks whether
 * what they draw is what their props say.
 */
#[CoversClass(SelectListRenderer::class)]
#[CoversClass(SettingsListRenderer::class)]
#[CoversClass(EditorRenderer::class)]
#[CoversClass(TextInputRenderer::class)]
#[CoversClass(CommandPaletteRenderer::class)]
#[CoversClass(JobMonitorRenderer::class)]
#[CoversClass(LogViewRenderer::class)]
#[CoversClass(ProgressBarRenderer::class)]
#[CoversClass(SimpleTuiLayoutEngine::class)]
#[CoversClass(RetainedTuiRenderer::class)]
#[CoversClass(BackgroundJob::class)]
final class RendererBehaviourTest extends TestCase
{
    /**
     * @param array<string, mixed> $props
     */
    private function draw(object $renderer, string $type, array $props, int $w = 40, int $h = 8, bool $focused = false): string
    {
        $node = new TuiNode('n', $type, props: $props, focusable: true);
        $context = new TuiRenderContext(new TuiBounds(0, 0, $w, $h), $focused ? 'n' : null);

        return implode("\n", $renderer->render($node, $context)->lines);
    }

    // ---- SelectListRenderer --------------------------------------------

    public function testTheSelectListShowsItsItems(): void
    {
        $out = $this->draw(new SelectListRenderer(), 'select-list', ['items' => [
            ['value' => 'a', 'label' => 'Alfa'],
            ['value' => 'b', 'label' => 'Beta'],
        ]]);

        self::assertStringContainsString('Alfa', $out);
        self::assertStringContainsString('Beta', $out);
    }

    public function testTheFilterHidesWhatDoesNotMatch(): void
    {
        $out = $this->draw(new SelectListRenderer(), 'select-list', [
            'items' => [['value' => 'a', 'label' => 'Alfa'], ['value' => 'b', 'label' => 'Beta']],
            'filter' => 'alf',
        ]);

        self::assertStringContainsString('Alfa', $out);
        self::assertStringNotContainsString('Beta', $out);
    }

    public function testAnEmptyFilterResultSaysSoRatherThanShowingNothing(): void
    {
        $out = $this->draw(new SelectListRenderer(), 'select-list', [
            'items' => [['value' => 'a', 'label' => 'Alfa']],
            'filter' => 'zzz',
            'emptyText' => 'NADA-AQUI',
        ]);

        self::assertStringContainsString('NADA-AQUI', $out);
    }

    public function testTheCursorScrollsToStayVisible(): void
    {
        $items = [];
        for ($i = 0; $i < 30; $i++) {
            $items[] = ['value' => (string) $i, 'label' => 'item-' . $i];
        }

        $out = $this->draw(new SelectListRenderer(), 'select-list', [
            'items' => $items,
            'cursor' => 25,
            'maxVisible' => 4,
        ]);

        self::assertStringContainsString('item-25', $out, 'The cursor row has to be on screen.');
        self::assertStringNotContainsString('item-0 ', $out, 'The window must have scrolled past the top.');
    }

    public function testSelectedValuesAreMarked(): void
    {
        $plain = $this->draw(new SelectListRenderer(), 'select-list', [
            'items' => [['value' => 'a', 'label' => 'Alfa']],
        ]);
        $marked = $this->draw(new SelectListRenderer(), 'select-list', [
            'items' => [['value' => 'a', 'label' => 'Alfa']],
            'selected' => ['a'],
        ]);

        self::assertNotSame($plain, $marked);
    }

    // ---- SettingsListRenderer ------------------------------------------

    public function testSettingsShowTheirNameAndValue(): void
    {
        $out = $this->draw(new SettingsListRenderer(), 'settings-list', ['settings' => [
            ['label' => 'Tema', 'currentValue' => 'oscuro', 'values' => ['claro', 'oscuro']],
            ['label' => 'Idioma', 'currentValue' => 'es', 'values' => ['es', 'en']],
        ]], 50);

        self::assertStringContainsString('Tema', $out);
        self::assertStringContainsString('oscuro', $out);
    }

    public function testASettingDescriptionCanBeShownOrHidden(): void
    {
        $props = ['settings' => [['label' => 'Tema', 'currentValue' => 'oscuro', 'description' => 'DESCRIPCION-AQUI']]];

        $with = $this->draw(new SettingsListRenderer(), 'settings-list', $props + ['showDescription' => true], 60);
        $without = $this->draw(new SettingsListRenderer(), 'settings-list', $props + ['showDescription' => false], 60);

        self::assertNotSame($with, $without);
    }

    public function testAnEmptySettingsListSaysSo(): void
    {
        $out = $this->draw(new SettingsListRenderer(), 'settings-list', [
            'settings' => [],
            'emptyText' => 'SIN-AJUSTES',
        ], 50);

        self::assertStringContainsString('SIN-AJUSTES', $out);
    }

    // ---- TextInputRenderer ---------------------------------------------

    public function testTheInputShowsItsValue(): void
    {
        self::assertStringContainsString('hola', $this->draw(new TextInputRenderer(), 'text-input', ['value' => 'hola']));
    }

    public function testAPlaceholderShowsOnlyWhenEmpty(): void
    {
        $vacio = $this->draw(new TextInputRenderer(), 'text-input', ['value' => '', 'placeholder' => 'ESCRIBE']);
        $lleno = $this->draw(new TextInputRenderer(), 'text-input', ['value' => 'hola', 'placeholder' => 'ESCRIBE']);

        self::assertStringContainsString('ESCRIBE', $vacio);
        self::assertStringNotContainsString('ESCRIBE', $lleno);
    }

    public function testASecretIsMaskedRatherThanShown(): void
    {
        $out = $this->draw(new TextInputRenderer(), 'text-input', ['value' => 'contrasena', 'secret' => true]);

        self::assertStringNotContainsString('contrasena', $out);
        self::assertStringContainsString('*', $out);
    }

    public function testThePromptPrecedesTheValue(): void
    {
        $out = $this->draw(new TextInputRenderer(), 'text-input', ['value' => 'x', 'prompt' => '$ ']);

        self::assertStringContainsString('$', $out);
    }

    public function testALongValueScrollsHorizontallyInsteadOfOverflowing(): void
    {
        $largo = str_repeat('abcdefghij', 6);
        $out = $this->draw(new TextInputRenderer(), 'text-input', ['value' => $largo, 'cursor' => 60], 30, 3);

        foreach (explode("\n", $out) as $line) {
            self::assertLessThanOrEqual(30, \Milpa\Live\Tui\TuiString::visibleLength($line));
        }
    }

    public function testTheCaretIsReportedWhenTheInputHoldsIt(): void
    {
        $renderer = new TextInputRenderer();
        $node = new TuiNode('n', 'text-input', props: ['value' => 'hola', 'cursor' => 2], focusable: true);
        $frame = $renderer->render($node, new TuiRenderContext(new TuiBounds(0, 0, 30, 3), 'n'));
        $output = implode("\n", $frame->lines);

        if ($renderer->hasCaret($output)) {
            self::assertIsArray($renderer->caretPosition($output));
        } else {
            self::assertNull($renderer->caretPosition($output));
        }
    }

    // ---- EditorRenderer -------------------------------------------------

    public function testTheEditorShowsItsText(): void
    {
        self::assertStringContainsString('linea', $this->draw(new EditorRenderer(), 'editor', ['text' => "linea uno\nlinea dos"], 40, 8));
    }

    public function testTheEditorPlaceholderShowsOnlyWhenEmpty(): void
    {
        self::assertStringContainsString('VACIO', $this->draw(new EditorRenderer(), 'editor', ['text' => '', 'placeholder' => 'VACIO'], 40, 6));
    }

    public function testTheEditorScrollsToItsOffset(): void
    {
        $texto = implode("\n", array_map(static fn (int $i): string => 'linea-' . $i, range(0, 40)));

        $arriba = $this->draw(new EditorRenderer(), 'editor', ['text' => $texto], 40, 6);
        $abajo = $this->draw(new EditorRenderer(), 'editor', ['text' => $texto, 'scrollOffset' => 20], 40, 6);

        self::assertNotSame($arriba, $abajo);
        self::assertStringContainsString('linea-20', $abajo);
    }

    public function testTheEditorCanDrawWithAndWithoutBorder(): void
    {
        $con = $this->draw(new EditorRenderer(), 'editor', ['text' => 'x', 'border' => true, 'title' => 'T'], 40, 6);
        $sin = $this->draw(new EditorRenderer(), 'editor', ['text' => 'x', 'border' => false], 40, 6);

        self::assertNotSame($con, $sin);
    }

    public function testTheEditorWrapsWhenAskedTo(): void
    {
        $largo = str_repeat('palabra ', 20);

        $conWrap = $this->draw(new EditorRenderer(), 'editor', ['text' => $largo, 'wrap' => true], 30, 8);
        $sinWrap = $this->draw(new EditorRenderer(), 'editor', ['text' => $largo, 'wrap' => false], 30, 8);

        self::assertNotSame($conWrap, $sinWrap);
    }

    // ---- CommandPalette / JobMonitor / LogView --------------------------

    public function testThePaletteShowsQueryAndCommands(): void
    {
        $out = $this->draw(new CommandPaletteRenderer(), 'command-palette', [
            'query' => 'coa',
            'commands' => [
                ['label' => 'coa:admin', 'shortcut' => 'ctrl+a'],
                ['command' => 'coa:routes'],
            ],
            'cursor' => 1,
        ], 50);

        self::assertStringContainsString('coa:admin', $out);
        self::assertStringContainsString('coa:routes', $out);
    }

    public function testAnEmptyPaletteQuerySaysSo(): void
    {
        $out = $this->draw(new CommandPaletteRenderer(), 'command-palette', ['query' => '', 'commands' => []], 50);

        self::assertStringContainsString('empty', $out);
    }

    public function testTheJobMonitorShowsEachJobAndItsLastLine(): void
    {
        $job = (new BackgroundJob('j1', 'Compilar', 'running'))
            ->withProgress(0.5)
            ->withOutput('compilando...', 'stdout');

        $out = $this->draw(new JobMonitorRenderer(), 'job-monitor', ['jobs' => [$job]], 50);

        self::assertStringContainsString('Compilar', $out);
        self::assertStringContainsString('50', $out);
        self::assertStringContainsString('compilando...', $out);
    }

    public function testNoJobsIsStatedRatherThanBlank(): void
    {
        $out = $this->draw(new JobMonitorRenderer(), 'job-monitor', ['jobs' => []], 50);

        self::assertStringContainsString('No background jobs', $out);
    }

    public function testTheLogViewAcceptsArraysObjectsAndStrings(): void
    {
        $out = $this->draw(new LogViewRenderer(), 'log-view', ['events' => [
            ['type' => 'key', 'message' => 'MARCA-ARRAY'],
            (object) ['type' => 'paste', 'payload' => ['x' => 'MARCA-OBJ']],
            'MARCA-STRING',
        ]], 60, 10);

        self::assertStringContainsString('MARCA-ARRAY', $out);
        self::assertStringContainsString('MARCA-STRING', $out);
    }

    // ---- ProgressBar ----------------------------------------------------

    public function testTheProgressBarShowsItsPercentage(): void
    {
        self::assertStringContainsString('42', $this->draw(new ProgressBarRenderer(), 'progress-bar', ['value' => 0.42], 40, 1));
    }

    public function testAnExplicitNullValueIsIndeterminate(): void
    {
        $out = $this->draw(new ProgressBarRenderer(), 'progress-bar', ['value' => null], 40, 1);

        self::assertStringContainsString('┄', $out);
    }

    public function testTheLabelAndPercentCanBeSuppressed(): void
    {
        $con = $this->draw(new ProgressBarRenderer(), 'progress-bar', ['value' => 0.5, 'label' => 'ETIQUETA'], 40, 1);
        $sin = $this->draw(new ProgressBarRenderer(), 'progress-bar', ['value' => 0.5, 'showPercent' => false], 40, 1);

        self::assertStringContainsString('ETIQUETA', $con);
        self::assertStringNotContainsString('%', $sin);
    }

    // ---- BackgroundJob ---------------------------------------------------

    public function testAJobNeedsAnId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new BackgroundJob('', 'x', 'running');
    }

    public function testJobTransitionsProduceNewValuesRatherThanMutating(): void
    {
        $original = new BackgroundJob('j1', 'Tarea', 'running');

        $conSalida = $original->withOutput('linea', 'stderr');
        $conAvance = $original->withProgress(0.75);
        $terminado = $original->withStatus('finished', 0);
        $fallido = $original->withStatus('failed', 1, 'se rompio');

        self::assertSame([], $original->output, 'The original is untouched.');
        self::assertCount(1, $conSalida->output);
        self::assertSame('stderr', $conSalida->output[0]['stream']);
        self::assertSame(0.75, $conAvance->progress);
        self::assertSame('finished', $terminado->status);
        self::assertSame(0, $terminado->exitCode);
        self::assertSame('se rompio', $fallido->error);
    }

    public function testProgressIsClampedToItsRange(): void
    {
        $job = new BackgroundJob('j1', 'T', 'running');

        self::assertSame(1.0, $job->withProgress(5.0)->progress);
        self::assertSame(0.0, $job->withProgress(-5.0)->progress);
    }

    // ---- Layout + retained renderer --------------------------------------

    public function testTheRetainedRendererCompositesTheTreeIntoABuffer(): void
    {
        $registry = new TuiNodeRendererRegistry();
        $registry->register(new TextRenderer());
        $renderer = new RetainedTuiRenderer(new SimpleTuiLayoutEngine(), $registry);

        $buffer = $renderer->render(
            new TuiNode('root', 'box', children: [new TuiNode('a', 'text', props: ['text' => 'MARCA'])]),
            40,
            6,
        );

        self::assertSame(40, $buffer->width());
        self::assertSame(6, $buffer->height());
        self::assertStringContainsString('MARCA', implode("\n", $buffer->lines()));
    }

    public function testAFocusedNodeIsRenderedAsFocused(): void
    {
        $registry = new TuiNodeRendererRegistry();
        $registry->register(new SelectListRenderer());
        $renderer = new RetainedTuiRenderer(new SimpleTuiLayoutEngine(), $registry);

        $tree = static fn (): TuiNode => new TuiNode('root', 'box', children: [
            new TuiNode('lista', 'select-list', props: ['items' => [['value' => 'a', 'label' => 'Alfa']]], focusable: true),
        ]);

        $sin = implode("\n", $renderer->render($tree(), 40, 6)->lines());
        $con = implode("\n", $renderer->render($tree(), 40, 6, 'lista')->lines());

        self::assertNotSame($sin, $con);
    }

    public function testTheRendererReportsNoCaretWhenNothingHoldsOne(): void
    {
        $registry = new TuiNodeRendererRegistry();
        $registry->register(new TextRenderer());
        $renderer = new RetainedTuiRenderer(new SimpleTuiLayoutEngine(), $registry);

        $pos = $renderer->caretPosition(
            new TuiNode('root', 'box', children: [new TuiNode('a', 'text', props: ['text' => 'x'])]),
            40,
            6,
        );

        self::assertNull($pos);
    }

    public function testAnUnknownNodeTypeDoesNotBreakTheComposition(): void
    {
        $registry = new TuiNodeRendererRegistry();
        $registry->register(new TextRenderer());
        $renderer = new RetainedTuiRenderer(new SimpleTuiLayoutEngine(), $registry);

        $buffer = $renderer->render(
            new TuiNode('root', 'box', children: [new TuiNode('raro', 'no-such-type')]),
            30,
            4,
        );

        self::assertCount(4, $buffer->lines());
    }

    public function testDeepTreesGetBoundsForEveryNode(): void
    {
        $frame = (new SimpleTuiLayoutEngine())->layout(
            new TuiNode('root', 'box', children: [
                new TuiNode('a', 'box', children: [
                    new TuiNode('a1', 'text', props: ['text' => 'x']),
                    new TuiNode('a2', 'text', props: ['text' => 'y']),
                ]),
                new TuiNode('b', 'text', props: ['text' => 'z']),
            ]),
            new TuiBounds(0, 0, 40, 10),
        );

        foreach (['root', 'a', 'a1', 'a2', 'b'] as $id) {
            self::assertNotNull($frame->boundsFor($id), "{$id} got no bounds.");
            self::assertNotNull($frame->nodeFor($id), "{$id} is not in the tree.");
        }

        self::assertNull($frame->boundsFor('no-existe'));
        self::assertNull($frame->nodeFor('no-existe'));
    }

    public function testAViewportWithNoRoomStillProducesAFrame(): void
    {
        $frame = (new SimpleTuiLayoutEngine())->layout(
            new TuiNode('root', 'box', children: [new TuiNode('a', 'text', props: ['text' => 'x'])]),
            new TuiBounds(0, 0, 1, 1),
        );

        self::assertNotNull($frame->boundsFor('root'));
    }
}
