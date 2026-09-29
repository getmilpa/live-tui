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

// A process that holds a StreamTerminal on a real pseudo-terminal, for tests that must see what a person sees.
// `read`: prints every byte the terminal delivers for up to three seconds (hex), then stops and prints the
// terminal's flags. `hold`: waits to be ended from outside. `fatal`: dies of a fatal error with the terminal raw.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$mode = $argv[1] ?? 'read';
$terminal = new Milpa\Live\Tui\StreamTerminal();
$terminal->start(static function (string $bytes): void {
}, static function (): void {
});
fwrite(STDOUT, 'raw:' . trim((string) shell_exec('stty -a')) . "\nready\n");

if ($mode === 'fatal') {
    /** @phpstan-ignore-next-line the point is that it does not exist */
    milpa_live_tui_no_such_function();
}

$until = microtime(true) + 3.0;
while (microtime(true) < $until) {
    $chunk = $terminal->pollInput();
    if ($chunk !== '' && $mode === 'read') {
        fwrite(STDOUT, 'got:' . bin2hex($chunk) . "\n");
        break;
    }
    usleep(10000);
}

$terminal->stop();
fwrite(STDOUT, 'after:' . trim((string) shell_exec('stty -a')) . "\nend\n");
