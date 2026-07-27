<?php

declare(strict_types=1);

namespace Milpa\Live\Tui;

use Milpa\Live\Contracts\Tui\TuiEventBusInterface;
use Milpa\Live\ValueObjects\Tui\TuiEvent;

/**
 * Synchronous in-process event bus for the TUI: listeners run in registration
 * order, inline, on the same tick the event is published.
 */
final class InMemoryTuiEventBus implements TuiEventBusInterface
{
    /**
     * @var array<string, array<int, callable(TuiEvent): void>>
     */
    private array $listeners = [];

    /**
     * @var array<int, TuiEvent>
     */
    private array $history = [];

    /**
     * Registers a listener for one event type; listeners run in registration order.
     */
    public function subscribe(string $eventType, callable $listener): void
    {
        $this->listeners[$eventType][] = $listener;
    }

    /**
     * Runs every listener for this event's type, inline and synchronously.
     */
    public function publish(TuiEvent $event): void
    {
        $this->history[] = $event;
        foreach ([...($this->listeners[$event->type] ?? []), ...($this->listeners['*'] ?? [])] as $listener) {
            $listener($event);
        }
    }

    public function history(): array
    {
        return $this->history;
    }
}
