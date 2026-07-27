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

namespace Milpa\Live\Tests\Fixtures;

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * A Live component that answers with the state it was handed and records every
 * action applied to it.
 *
 * The contract name is deliberately left abstract: it is static, so a double
 * that could change its own name would report the same one for every instance.
 * Subclass it — an anonymous subclass per contract is enough.
 */
abstract class RecordingDouble implements ComponentDefinitionInterface
{
    /** @var list<array{action: string, payload: array<string, mixed>}> */
    public array $applied = [];

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $errors Returned by every handle(), to exercise a rejecting component.
     */
    public function __construct(
        private readonly array $data = [],
        private readonly array $meta = [],
        private readonly array $errors = [],
    ) {
    }

    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot($context->componentId, static::contract()->name, '1.0.0', $this->data, $this->meta);
    }

    /**
     * Folds the payload into the state, so a second key sees the first one's
     * work instead of starting over.
     */
    public function handle(InteractionRequest $request): InteractionResult
    {
        $this->applied[] = ['action' => $request->action, 'payload' => $request->payload];

        $data = $request->state->data;
        foreach ($request->payload as $key => $value) {
            $data[$key] = $value;
        }

        return new InteractionResult(
            new StateSnapshot(
                $request->state->componentId,
                $request->state->componentName,
                $request->state->version,
                $data,
                $request->state->meta,
            ),
            errors: $this->errors,
        );
    }
}
