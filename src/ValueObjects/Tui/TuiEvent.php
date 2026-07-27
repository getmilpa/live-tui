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

namespace Milpa\Live\ValueObjects\Tui;

/**
 * One event published on a
 * {@see \Milpa\Live\Contracts\Tui\TuiEventBusInterface}. `$type` is the
 * dispatch key listeners subscribe by (e.g. `'job.started'`); event type
 * naming is a bus-wide convention, not enforced by this class.
 */
final readonly class TuiEvent
{
    /**
     * @param string               $type    The event type, used for subscriber dispatch; MUST NOT be empty.
     * @param array<string, mixed> $payload Event-specific data.
     * @param string|null          $source  The component/subsystem that published this event, if identified.
     * @param float                $time    Unix timestamp (with microsecond precision) the event occurred at; `0.0`
     *                                      unless set via {@see now()}.
     *
     * @throws \InvalidArgumentException If `$type` is empty.
     */
    public function __construct(
        public string $type,
        public array $payload = [],
        public ?string $source = null,
        public float $time = 0.0,
    ) {
        if ($type === '') {
            throw new \InvalidArgumentException('TUI event type cannot be empty.');
        }
    }

    /**
     * Builds an event timestamped with the current time
     * ({@see microtime()}), for the common case of publishing an event as
     * it happens.
     *
     * @param array<string, mixed> $payload
     */
    public static function now(string $type, array $payload = [], ?string $source = null): self
    {
        return new self($type, $payload, $source, microtime(true));
    }
}
