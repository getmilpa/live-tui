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

namespace Milpa\Live\Tests\Rendering;

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Rendering\TuiComponentRenderer;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Live\ValueObjects\StateSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The counterpart of the HTML renderers in `milpa/live-web`: the same component
 * definition and the same state snapshot, drawn for a terminal.
 *
 * It is the single largest class in the package and had never been executed.
 * Each supported contract gets a pass here, because the dispatch is one arm per
 * contract and an unexercised arm is an unshipped feature that ships anyway.
 */
#[CoversClass(TuiComponentRenderer::class)]
final class TuiComponentRendererTest extends TestCase
{
    /**
     * A component whose contract name and mounted state the test dictates.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    private function component(string $contract, array $data = [], array $meta = []): ComponentDefinitionInterface
    {
        return new class ($contract, $data, $meta) implements ComponentDefinitionInterface {
            /** @var array<string, mixed> */
            public static array $sharedData = [];

            /** @var array<string, mixed> */
            public static array $sharedMeta = [];

            public static string $sharedContract = 'input';

            /**
             * @param array<string, mixed> $data
             * @param array<string, mixed> $meta
             */
            public function __construct(string $contract, array $data, array $meta)
            {
                self::$sharedContract = $contract;
                self::$sharedData = $data;
                self::$sharedMeta = $meta;
            }

            public static function contract(): ComponentContract
            {
                return new ComponentContract(self::$sharedContract, '1.0.0', 'test double');
            }

            public function mount(array $props, ComponentContext $context): StateSnapshot
            {
                return new StateSnapshot(
                    $context->componentId,
                    self::$sharedContract,
                    '1.0.0',
                    self::$sharedData,
                    self::$sharedMeta,
                );
            }

            public function handle(InteractionRequest $request): InteractionResult
            {
                return new InteractionResult($request->state);
            }
        };
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(ComponentDefinitionInterface $component, array $props = [], int $width = 60): string
    {
        $result = (new TuiComponentRenderer())->render(
            $component,
            new RenderRequest(
                new ComponentContext('c1'),
                $props,
                null,
                RenderTarget::TUI,
                ['width' => $width],
            ),
        );

        self::assertSame(RenderTarget::TUI, $result->format);

        return $result->output;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function renderWithOptions(ComponentDefinitionInterface $component, array $options): string
    {
        return (new TuiComponentRenderer())->render(
            $component,
            new RenderRequest(new ComponentContext('c1'), [], null, RenderTarget::TUI, $options),
        )->output;
    }

    /**
     * Every contract the renderer claims to support, with a state shaped the
     * way that contract expects.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>, 3: string}>
     */
    public static function contracts(): array
    {
        return [
            'input' => ['input', ['value' => 'MARCA'], ['label' => 'Nombre'], 'Nombre'],
            'textarea' => ['textarea', ['value' => 'MARCA'], ['label' => 'Notas'], 'Notas'],
            'select' => ['select', ['value' => 'uno'], ['label' => 'Opcion', 'options' => [
                ['value' => 'uno', 'label' => 'Uno'],
                ['value' => 'dos', 'label' => 'Dos'],
            ]], 'Opcion'],
            'checkbox' => ['checkbox', ['value' => true], ['label' => 'Activo'], 'Activo'],
            'autocomplete' => ['autocomplete', ['query' => 'mun', 'value' => ''], ['label' => 'Cliente', 'suggestions' => [
                ['value' => '1', 'label' => 'Mundo'],
            ]], 'Cliente'],
            'metric-card' => ['metric-card', ['value' => '42'], ['title' => 'Ventas'], 'Ventas'],
            'data-table' => ['data-table', [], [
                'columns' => [['key' => 'a', 'label' => 'A']],
                'rows' => [['a' => 'MARCA']],
            ], 'MARCA'],
            'dashboard-sidebar' => ['dashboard-sidebar', [], ['items' => [
                ['label' => 'Inicio', 'href' => '/'],
            ]], 'Inicio'],
            'dashboard-action-button' => ['dashboard-action-button', [], ['label' => 'Guardar'], 'Guardar'],
            'dashboard-alert-list' => ['dashboard-alert-list', [], ['items' => [
                ['count' => '3', 'text' => 'Todo bien'],
            ]], 'Todo bien'],
            'dashboard-page-header' => ['dashboard-page-header', [], ['title' => 'Panel'], 'Panel'],
            'dashboard-topbar' => ['dashboard-topbar', [], ['title' => 'Barra'], 'Barra'],
            'dashboard-shell' => ['dashboard-shell', [], ['title' => 'Shell'], ''],
            'dashboard-main' => ['dashboard-main', [], ['title' => 'Main'], ''],
            'dashboard-grid' => ['dashboard-grid', [], ['title' => 'Grid'], ''],
            'dashboard-panel' => ['dashboard-panel', [], ['title' => 'Panel'], ''],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    #[DataProvider('contracts')]
    public function testItRendersTheContract(string $contract, array $data, array $meta, string $expected): void
    {
        $out = $this->render($this->component($contract, $data, $meta));

        self::assertNotSame('', $out, "{$contract} rendered nothing.");

        if ($expected !== '') {
            self::assertStringContainsString($expected, $out, "{$contract} lost its content.");
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    #[DataProvider('contracts')]
    public function testNoLineOverflowsTheRequestedWidth(string $contract, array $data, array $meta): void
    {
        $width = 48;
        $out = $this->render($this->component($contract, $data, $meta), width: $width);

        foreach (explode("\n", $out) as $line) {
            self::assertLessThanOrEqual(
                $width,
                \Milpa\Live\Tui\TuiString::visibleLength($line),
                "{$contract} produced a line wider than the terminal.",
            );
        }
    }

    public function testAnUnsupportedContractIsRefusedRatherThanRenderedWrong(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->render($this->component('no-such-contract'));
    }

    public function testItOnlySupportsTheTuiTarget(): void
    {
        $renderer = new TuiComponentRenderer();

        self::assertTrue($renderer->supportsTarget(RenderTarget::TUI));
        self::assertFalse($renderer->supportsTarget(RenderTarget::HTML));
    }

    public function testAFocusedFieldIsMarkedDifferentlyFromAnUnfocusedOne(): void
    {
        $component = $this->component('input', ['value' => 'x'], ['label' => 'Campo']);

        // El foco viaja en options, no en props.
        $plain = $this->renderWithOptions($component, ['width' => 60]);
        $focused = $this->renderWithOptions($component, ['width' => 60, 'focused' => true]);

        self::assertNotSame($plain, $focused, 'Focus has to be visible.');
    }

    public function testAnEmptyDataTableSaysSoInsteadOfRenderingNothing(): void
    {
        $out = $this->render($this->component('data-table', ['rows' => []], ['columns' => [
            ['key' => 'a', 'label' => 'A'],
        ]]));

        self::assertNotSame('', trim($out));
    }

    public function testTheStateComesBackWithTheResult(): void
    {
        $result = (new TuiComponentRenderer())->render(
            $this->component('input', ['value' => 'MARCA'], ['label' => 'L']),
            new RenderRequest(new ComponentContext('c1'), [], null, RenderTarget::TUI, ['width' => 40]),
        );

        self::assertSame('MARCA', $result->state->data['value']);
        self::assertSame('terminal', $result->assets['runtime']);
    }

    public function testAMountedStateIsReusedInsteadOfRemounting(): void
    {
        $component = $this->component('input', ['value' => 'del-mount'], ['label' => 'L']);
        $given = new StateSnapshot('c1', 'input', '1.0.0', ['value' => 'del-request'], ['label' => 'L']);

        $result = (new TuiComponentRenderer())->render(
            $component,
            new RenderRequest(new ComponentContext('c1'), [], $given, RenderTarget::TUI, ['width' => 40]),
        );

        self::assertStringContainsString('del-request', $result->output);
    }
}
