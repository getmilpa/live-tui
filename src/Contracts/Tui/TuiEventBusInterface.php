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

namespace Milpa\Live\Contracts\Tui;

use Milpa\Live\ValueObjects\Tui\TuiEvent;

/**
 * A synchronous publish/subscribe bus for {@see TuiEvent}s, decoupling
 * event sources (e.g. {@see BackgroundJobManagerInterface} transitions)
 * from whatever reacts to them (e.g. a job-monitor node renderer) without
 * either depending on the other directly.
 */
interface TuiEventBusInterface
{
    /**
     * Registers a listener for events of `$eventType`. The type `'*'` is
     * a wildcard: implementations MUST invoke a `'*'` listener for every
     * published event, regardless of its own type.
     *
     * @param callable(TuiEvent): void $listener
     */
    public function subscribe(string $eventType, callable $listener): void;

    /**
     * Publishes an event: appends it to {@see history()} and synchronously
     * invokes every listener subscribed to its exact type plus every
     * `'*'`-subscribed listener.
     */
    public function publish(TuiEvent $event): void;

    /**
     * The full log of events published so far, in publish order.
     *
     * @return array<int, TuiEvent>
     */
    public function history(): array;
}
