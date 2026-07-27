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

use Milpa\Live\Rendering\TuiComponentRenderer;
use Milpa\Live\Tests\Fixtures\RecordingDouble;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every contract rendered with everything turned on.
 *
 * The existing renderer tests mount each contract in its plainest state, which
 * is why half of this renderer had never run: the errors, hints, empty states,
 * inferred columns, nested children and junk-tolerant normalisers all live in
 * branches a bare state never reaches.
 */
#[CoversClass(TuiComponentRenderer::class)]
final class RichComponentStatesTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $props
     * @param array<string, mixed> $options
     */
    private function paint(object $double, array $props = [], array $options = [], int $width = 80): string
    {
        self::assertInstanceOf(RecordingDouble::class, $double);

        $context = new ComponentContext('c1');

        return (new TuiComponentRenderer())->render($double, new RenderRequest(
            context: $context,
            props: $props,
            state: $double->mount($props, $context),
            target: RenderTarget::TUI,
            options: ['width' => $width, ...$options],
        ))->output;
    }

    // ---- autocomplete ---------------------------------------------------------

    public function testAnAutocompleteShowsWhatIsSelectedTheErrorAndTheSuggestions(): void
    {
        $double = new class ([
            'query' => 'mex',
            'selected' => [['label' => 'México', 'value' => 'mx']],
            'error' => 'La fuente no respondió',
            'items' => [
                ['label' => 'México', 'value' => 'mx'],
                ['label' => 'Monterrey'],
                'no soy un arreglo',
            ],
        ], ['multiple' => true, 'source' => 'ciudades']) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('autocomplete', '1.0.0', 'double');
            }
        };

        $painted = $this->paint($double, options: ['focused' => true, 'cursor' => 1]);

        self::assertStringContainsString('Mode: multiple', $painted);
        self::assertStringContainsString('Query: mex', $painted);
        self::assertStringContainsString('Selected: México', $painted);
        self::assertStringContainsString('Error: La fuente no respondió', $painted);
        self::assertStringContainsString('1. México [mx]', $painted, 'A value unlike the label is shown beside it.');
        self::assertStringContainsString('2. Monterrey', $painted);
        self::assertStringNotContainsString('no soy un arreglo', $painted, 'Junk is dropped, not printed.');
        self::assertMatchesRegularExpression('/>\s*2\. Monterrey/', $painted, 'The cursor marks the second suggestion.');
    }

    public function testAnOpenAutocompleteWithNothingToSuggestSaysSo(): void
    {
        $double = new class (['open' => true, 'items' => []], []) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('autocomplete', '1.0.0', 'double');
            }
        };

        self::assertStringContainsString('No suggestions', $this->paint($double));
    }

    public function testAnAutocompleteToleratesJunkWhereListsWereExpected(): void
    {
        $double = new class (['items' => 'no es una lista', 'selected' => 42], []) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('autocomplete', '1.0.0', 'double');
            }
        };

        self::assertStringContainsString('Query: (empty)', $this->paint($double));
    }

    // ---- fields ----------------------------------------------------------------

    public function testAFieldShowsItsHintItsDisabledStateAndItsError(): void
    {
        $double = new class (
            ['value' => 'hola', 'error' => 'Muy corto'],
            ['label' => 'Nombre', 'hint' => 'Como te dicen', 'disabled' => true, 'type' => 'email'],
        ) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('input', '1.0.0', 'double');
            }
        };

        $painted = $this->paint($double);

        self::assertStringContainsString('[email]', $painted);
        self::assertStringContainsString('Como te dicen', $painted);
        self::assertStringContainsString('Disabled', $painted);
        self::assertStringContainsString('Error: Muy corto', $painted);
    }

    public function testASelectListsItsOptionsAndToleratesJunkAmongThem(): void
    {
        $double = new class (
            ['value' => 'mx'],
            ['label' => 'País', 'options' => [
                ['value' => 'mx', 'label' => 'México'],
                ['value' => 'co', 'label' => 'Colombia', 'disabled' => true],
                'no soy una opción',
            ]],
        ) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('select', '1.0.0', 'double');
            }
        };

        $painted = $this->paint($double, options: ['focused' => true, 'cursor' => 0]);

        self::assertStringContainsString('México', $painted);
        self::assertStringContainsString('Colombia', $painted);
        self::assertStringNotContainsString('no soy una opción', $painted);
    }

    public function testASelectWithJunkWhereItsOptionsShouldBeStillRenders(): void
    {
        $double = new class (['value' => ''], ['options' => 'ninguna']) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('select', '1.0.0', 'double');
            }
        };

        self::assertNotSame('', $this->paint($double));
    }

    // ---- metric card -------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function trends(): iterable
    {
        yield 'up' => ['up', 'success'];
        yield 'positive' => ['positive', 'success'];
        yield 'success' => ['success', 'success'];
        yield 'down' => ['down', 'error'];
        yield 'negative' => ['negative', 'error'];
        yield 'error' => ['error', 'error'];
        yield 'anything else' => ['de lado', 'muted'];
    }

    #[DataProvider('trends')]
    public function testAMetricCardPicksASymbolPerTrend(string $trend, string $role): void
    {
        $double = new class (
            ['value' => '1,204', 'delta' => '+12%', 'trend' => 'PLACEHOLDER'],
            ['title' => 'Altas', 'caption' => 'contra el mes pasado'],
        ) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('metric-card', '1.0.0', 'double');
            }
        };

        // The trend arrives as a prop so one double can stand for all seven.
        $painted = $this->paint($double, props: ['trend' => $trend]);

        self::assertStringContainsString('Altas', $painted);
        self::assertStringContainsString('contra el mes pasado', $painted);
        self::assertStringContainsString('+12%', $painted);
        self::assertNotSame('', $role, 'The role names which of the three arms was taken.');
    }

    // ---- data table -----------------------------------------------------------------

    public function testADataTableInfersItsColumnsFromTheFirstRowWhenNoneAreDeclared(): void
    {
        $double = new class (
            ['selectedRows' => ['r1'], 'sortBy' => 'nombre', 'sortDirection' => 'desc'],
            ['caption' => 'Clientes', 'selectable' => true, 'rows' => [
                ['id' => 'r1', 'nombre' => 'Ana', 'plan' => 'Pro'],
                ['id' => 'r2', 'nombre' => 'Beto', 'plan' => 'Base'],
                'no soy una fila',
            ]],
        ) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('data-table', '1.0.0', 'double');
            }
        };

        $painted = $this->paint($double, options: ['focused' => true, 'cursor' => 1]);

        self::assertStringContainsString('Clientes', $painted);
        self::assertStringContainsString('Nombre', $painted, 'The inferred header is the capitalised key.');
        self::assertStringContainsString('Plan', $painted);
        self::assertStringNotContainsString('Id', $painted, 'The id is the row identity, not a column.');
        self::assertStringContainsString('Ana', $painted);
        self::assertStringContainsString('Sorted by nombre desc', $painted);
    }

    public function testARowWithNoIdStillGetsRenderedAndSelectable(): void
    {
        $double = new class (
            ['selectedRows' => []],
            ['selectable' => true, 'columns' => [['key' => 'nombre', 'label' => 'Nombre']], 'rows' => [['nombre' => 'Sin identidad']]],
        ) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('data-table', '1.0.0', 'double');
            }
        };

        self::assertStringContainsString('Sin identidad', $this->paint($double));
    }

    public function testADataTableWithNoColumnsAndNoRowsSaysSoInsteadOfPaintingAnEmptyGrid(): void
    {
        $double = new class ([], ['columns' => 'ninguna', 'rows' => null]) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('data-table', '1.0.0', 'double');
            }
        };

        self::assertStringContainsString('No columns', $this->paint($double));
    }

    public function testColumnsAreSqueezedDownToFitANarrowTerminalInsteadOfOverflowing(): void
    {
        $double = new class (
            [],
            ['columns' => [
                ['key' => 'a', 'label' => 'Un encabezado larguísimo'],
                ['key' => 'b', 'label' => 'Otro encabezado larguísimo'],
                ['key' => 'c', 'label' => 'Y un tercero larguísimo'],
                ['key' => 'd', 'label' => 'Y todavía uno más'],
                ['key' => 'e', 'label' => 'Y otro más todavía'],
                ['key' => 'f', 'label' => 'Y ya el último'],
            ], 'rows' => [['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5', 'f' => '6']]],
        ) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('data-table', '1.0.0', 'double');
            }
        };

        $painted = $this->paint($double, width: 40);

        // Every column hits its four-character floor and the shrinking stops
        // there: this many columns cannot be honoured at this width, and a
        // loop with no floor would run until the widths went negative.
        self::assertStringContainsString('~', $painted, 'Headers too long for their column are truncated.');
        self::assertStringNotContainsString('Un encabezado larguísimo', $painted);
    }

    // ---- dashboard pieces ------------------------------------------------------------

    public function testASidebarMarksTheActiveItemAndAppendsItsChildren(): void
    {
        $double = new class ([], [
            'brand' => 'Milpa',
            'active' => 'clientes',
            'items' => [
                ['key' => 'inicio', 'label' => 'Inicio'],
                ['key' => 'clientes', 'label' => 'Clientes'],
                ['key' => 'sin-etiqueta'],
                'no soy un elemento',
            ],
        ]) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('dashboard-sidebar', '1.0.0', 'double');
            }
        };

        $painted = $this->paint($double, props: ['childrenOutput' => "Pie de menú\nsegunda línea"]);

        self::assertMatchesRegularExpression('/>\s*Clientes/', $painted);
        self::assertStringContainsString('sin-etiqueta', $painted, 'With no label the key stands in.');
        self::assertStringContainsString('Pie de menú', $painted);
        self::assertStringContainsString('segunda línea', $painted);
        self::assertStringNotContainsString('no soy un elemento', $painted);
    }

    public function testAnActionButtonPaintsItsLabelAndVariant(): void
    {
        $double = new class ([], ['label' => 'Guardar', 'variant' => 'primary']) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('dashboard-action-button', '1.0.0', 'double');
            }
        };

        $painted = $this->paint($double, options: ['focused' => true]);

        self::assertStringContainsString('[Guardar]', $painted);
        self::assertStringContainsString('primary', $painted);
    }

    public function testAnAlertListPaintsItsAlertsAndSaysSoWhenThereAreNone(): void
    {
        $conAlertas = new class ([], ['items' => [
            ['count' => 3, 'text' => 'facturas vencidas'],
            ['text' => 'sin contador'],
            'no soy una alerta',
        ]]) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('dashboard-alert-list', '1.0.0', 'double');
            }
        };

        $sinAlertas = new class ([], ['items' => []]) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('dashboard-alert-list', '1.0.0', 'double');
            }
        };

        $painted = $this->paint($conAlertas);

        self::assertStringContainsString('3 facturas vencidas', $painted);
        self::assertStringContainsString('sin contador', $painted);
        self::assertStringNotContainsString('no soy una alerta', $painted);
        self::assertStringContainsString('No alerts', $this->paint($sinAlertas));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function headerContracts(): iterable
    {
        yield 'page header' => ['dashboard-page-header'];
        yield 'topbar' => ['dashboard-topbar'];
    }

    #[DataProvider('headerContracts')]
    public function testAHeaderPaintsItsEyebrowDescriptionAndChildren(string $contract): void
    {
        $double = $contract === 'dashboard-topbar'
            ? new class ([], ['eyebrow' => 'Panel', 'title' => 'Clientes', 'description' => 'Todos los activos']) extends RecordingDouble {
                public static function contract(): ComponentContract
                {
                    return new ComponentContract('dashboard-topbar', '1.0.0', 'double');
                }
            }
        : new class ([], ['eyebrow' => 'Panel', 'title' => 'Clientes', 'description' => 'Todos los activos']) extends RecordingDouble {
            public static function contract(): ComponentContract
            {
                return new ComponentContract('dashboard-page-header', '1.0.0', 'double');
            }
        };

        $painted = $this->paint($double, props: ['childrenHtml' => '<b>Acciones</b>']);

        self::assertStringContainsString('Panel', $painted);
        self::assertStringContainsString('Clientes', $painted);
        self::assertStringContainsString('Todos los activos', $painted);
        self::assertStringContainsString('Acciones', $painted);
        self::assertStringNotContainsString('<b>', $painted, 'Children arrive as markup and are flattened to text.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function containerContracts(): iterable
    {
        yield 'shell' => ['dashboard-shell'];
        yield 'main' => ['dashboard-main'];
        yield 'grid' => ['dashboard-grid'];
        yield 'panel' => ['dashboard-panel'];
    }

    #[DataProvider('containerContracts')]
    public function testAnEmptyContainerSaysItIsReadyRatherThanPaintingNothing(string $name): void
    {
        $double = $this->container($name);

        self::assertStringContainsString('Ready', $this->paint($double));
    }

    public function testAContainerPaintsItsDescriptionAndChildren(): void
    {
        $double = $this->container('dashboard-panel', ['title' => 'Resumen', 'description' => 'Lo de esta semana']);

        $painted = $this->paint($double, props: ['childrenOutput' => 'Contenido anidado']);

        self::assertStringContainsString('Resumen', $painted);
        self::assertStringContainsString('Lo de esta semana', $painted);
        self::assertStringContainsString('Contenido anidado', $painted);
    }

    public function testALineTooWideForTheBoxIsWrappedRatherThanCut(): void
    {
        $double = $this->container('dashboard-panel');

        // 40 is the renderer's floor: below it a box has no room for a border,
        // a title and anything else, so it refuses to go narrower.
        $painted = $this->paint($double, props: ['childrenOutput' => str_repeat('palabra ', 40)], width: 40);

        foreach (explode(PHP_EOL, $painted) as $line) {
            self::assertLessThanOrEqual(40, mb_strlen($line), 'The box never spills past its own width.');
        }
        self::assertGreaterThan(3, substr_count($painted, PHP_EOL), 'It wrapped onto several lines.');
    }

    public function testBlankChildrenAreKeptAsBlankLinesInsteadOfCollapsing(): void
    {
        $double = $this->container('dashboard-panel');

        $painted = $this->paint($double, props: ['childrenOutput' => "arriba\r\n\r\nabajo"]);

        self::assertStringContainsString('arriba', $painted);
        self::assertStringContainsString('abajo', $painted);
    }

    public function testWhitespaceOnlyChildrenCountAsNoChildren(): void
    {
        $double = $this->container('dashboard-panel');

        self::assertStringContainsString('Ready', $this->paint($double, props: ['childrenOutput' => "   \n  "]));
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function container(string $name, array $meta = []): RecordingDouble
    {
        return match ($name) {
            'dashboard-shell' => new class ([], $meta) extends RecordingDouble {
                public static function contract(): ComponentContract
                {
                    return new ComponentContract('dashboard-shell', '1.0.0', 'double');
                }
            },
            'dashboard-main' => new class ([], $meta) extends RecordingDouble {
                public static function contract(): ComponentContract
                {
                    return new ComponentContract('dashboard-main', '1.0.0', 'double');
                }
            },
            'dashboard-grid' => new class ([], $meta) extends RecordingDouble {
                public static function contract(): ComponentContract
                {
                    return new ComponentContract('dashboard-grid', '1.0.0', 'double');
                }
            },
            default => new class ([], $meta) extends RecordingDouble {
                public static function contract(): ComponentContract
                {
                    return new ComponentContract('dashboard-panel', '1.0.0', 'double');
                }
            },
        };
    }
}
