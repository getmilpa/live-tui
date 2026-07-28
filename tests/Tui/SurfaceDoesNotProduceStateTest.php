<?php

/**
 * This file is part of Milpa Live TUI — the terminal render target of the Milpa PHP framework.
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
use Milpa\Live\Testing\SurfacesConsumeStateNeverProduceIt;
use Milpa\Live\Tui\InteractiveTuiLoop;
use Milpa\Live\Tui\TuiComponentInstance;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * The deliberate attempt to break "shells consume state, never produce it", on this surface.
 *
 * The law came out of read paths, where it is easiest to be true. This drives the terminal's real
 * mutation path — a keystroke landing on a focused input, which the loop turns into a `change`
 * action — and then asks whether the state it ended up with is the component's or partly the
 * loop's.
 *
 * The component below transforms on purpose. A fixture that echoed its input back would let a
 * surface author state and still match, since there would be nothing to author differently.
 */
final class SurfaceDoesNotProduceStateTest extends TestCase
{
    use SurfacesConsumeStateNeverProduceIt;

    private function transformingInput(): ComponentDefinitionInterface
    {
        return new class () implements ComponentDefinitionInterface {
            public static function contract(): ComponentContract
            {
                return new ComponentContract(name: 'input', contractVersion: '1.0');
            }

            public function mount(array $props, ComponentContext $context): StateSnapshot
            {
                return new StateSnapshot($context->componentId, 'input', '1.0', ['value' => ''], ['label' => 'Nombre']);
            }

            public function handle(InteractionRequest $request): InteractionResult
            {
                // Deliberately not a passthrough: uppercases, and stamps how many changes it has
                // seen. A surface that assembled the result itself would have to reproduce both,
                // and reproducing them is exactly what it must not be doing.
                $seen = (int) ($request->state->data['changes'] ?? 0);
                $value = (string) ($request->payload['value'] ?? '');

                return new InteractionResult(new StateSnapshot(
                    $request->componentId,
                    'input',
                    '1.0',
                    ['value' => mb_strtoupper($value), 'changes' => $seen + 1],
                    $request->state->meta,
                ));
            }
        };
    }

    public function test_the_terminal_ends_a_mutation_with_the_components_state_and_nothing_of_its_own(): void
    {
        $component = $this->transformingInput();
        $context = new ComponentContext('c1');
        $mounted = $component->mount([], $context);
        $instance = new TuiComponentInstance('c1', $component, $context, [], $mounted);

        $registry = new ComponentRendererRegistry();
        $registry->register(new TuiComponentRenderer());
        $loop = new InteractiveTuiLoop($registry, [$instance]);

        // The real path: a printable key on the focused input becomes a `change` action.
        $loop->dispatchKey('a');

        self::assertNotSame($mounted, $instance->state, 'The keystroke must have mutated something.');

        // The same request the loop built, rebuilt here, run against the component alone.
        $this->assertSurfaceOnlyConsumedState(
            $component,
            new InteractionRequest(
                componentId: 'c1',
                componentName: 'input',
                action: 'change',
                state: $mounted,
                payload: ['value' => 'a'],
            ),
            $instance->state,
        );
    }
}
