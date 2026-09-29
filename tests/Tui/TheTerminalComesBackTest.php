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

use Milpa\Live\Tui\StreamTerminal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * However a screen ends, the person gets their terminal back (greenhouse decisions/0524, evidence/1053 F2).
 *
 * Ctrl-C used to reach the process as SIGINT: it died between two frames and left the terminal without echo and
 * without lines. Now Ctrl-C is a key the screen reads, and what still ends the process from outside — a signal, a
 * fatal error — restores the terminal before it goes. The pseudo-terminal tests are the only way to see it: a
 * pipe is no terminal, so `stty` and the line discipline never enter.
 */
#[CoversClass(StreamTerminal::class)]
final class TheTerminalComesBackTest extends TestCase
{
    public function testCtrlCArrivesAsAKeyAndTheTerminalComesBackWithEcho(): void
    {
        [$output, $status] = $this->onAPseudoTerminal('read', static function ($process, $master): void {
            fwrite($master, "\x03");
        });

        self::assertFalse($status['signaled'], 'Ctrl-C killed the process by signal: ' . $output);
        self::assertSame(0, $status['exitcode']);
        self::assertStringContainsString('got:03', $output, 'Ctrl-C must reach the screen as the key it is.');
        self::assertMatchesRegularExpression('/raw:.*(?<![\w-])-isig\b/s', $output);
        $after = substr($output, (int) strpos($output, 'after:'));
        foreach (['echo', 'icanon', 'isig'] as $flag) {
            self::assertMatchesRegularExpression('/(?<![\w-])' . $flag . '\b/', $after, "{$flag} did not come back: {$after}");
        }
    }

    public function testATerminateSignalRestoresTheTerminalAndStillEndsTheProcessByIt(): void
    {
        if (!\function_exists('pcntl_signal')) {
            self::markTestSkipped('Without pcntl a signal cannot be caught; the supervisor restores instead.');
        }

        [$output, $status] = $this->onAPseudoTerminal('hold', static function ($process): void {
            proc_terminate($process, 15);
        });

        self::assertTrue($status['signaled'], 'Whoever waits must still read that a signal ended it.');
        self::assertSame(15, $status['termsig']);
        self::assertStringContainsString("\x1b[?25h", substr($output, (int) strpos($output, 'ready')), 'stop() ran before the process died.');
    }

    public function testAFatalErrorHandsTheTerminalBack(): void
    {
        [$output] = $this->onAPseudoTerminal('fatal', static function (): void {
        });

        self::assertStringContainsString('milpa_live_tui_no_such_function', $output);
        self::assertStringContainsString("\x1b[?25h", substr($output, (int) strpos($output, 'ready')), 'The shutdown function must restore the terminal.');
    }

    public function testTheEndingSignalsAreHandedBackOnStop(): void
    {
        if (!\function_exists('pcntl_signal')) {
            self::markTestSkipped('pcntl is not loaded.');
        }
        $mine = static function (): void {
        };
        pcntl_signal(\SIGHUP, $mine);
        $before = pcntl_signal_get_handler(\SIGTERM);

        $terminal = $this->memoryTerminal($out);
        $terminal->start(static function (): void {
        }, static function (): void {
        });
        self::assertIsCallable(pcntl_signal_get_handler(\SIGTERM));
        self::assertNotSame($mine, pcntl_signal_get_handler(\SIGHUP));
        $terminal->stop();

        self::assertSame($before, pcntl_signal_get_handler(\SIGTERM));
        self::assertSame($mine, pcntl_signal_get_handler(\SIGHUP));
        pcntl_signal(\SIGHUP, \SIG_DFL);
    }

    public function testAnIgnoredSignalStaysIgnored(): void
    {
        if (!\function_exists('pcntl_signal')) {
            self::markTestSkipped('pcntl is not loaded.');
        }
        pcntl_signal(\SIGHUP, \SIG_IGN);

        $terminal = $this->memoryTerminal($out);
        $terminal->start(static function (): void {
        }, static function (): void {
        });
        self::assertSame(\SIG_IGN, pcntl_signal_get_handler(\SIGHUP));
        $terminal->stop();

        self::assertSame(\SIG_IGN, pcntl_signal_get_handler(\SIGHUP));
        pcntl_signal(\SIGHUP, \SIG_DFL);
    }

    public function testASignalTheProcessAlreadyHandledRestoresTheTerminalThenReachesItsHandler(): void
    {
        if (!\function_exists('pcntl_signal') || !\function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are needed to signal this process.');
        }
        $received = [];
        pcntl_signal(\SIGHUP, static function (int $signal) use (&$received): void {
            $received[] = $signal;
        });

        $terminal = $this->memoryTerminal($out);
        $terminal->start(static function (): void {
        }, static function (): void {
        });
        posix_kill(getmypid(), \SIGHUP);
        pcntl_signal_dispatch();

        self::assertSame([\SIGHUP], $received);
        rewind($out);
        self::assertStringContainsString("\x1b[?25h", (string) stream_get_contents($out), 'The terminal was restored first.');
        self::assertIsCallable(pcntl_signal_get_handler(\SIGHUP));
        self::assertNotSame($received, []);
        pcntl_signal(\SIGHUP, \SIG_DFL);
    }

    public function testStoppingTwiceWritesTheRestoreOnce(): void
    {
        $terminal = $this->memoryTerminal($out);
        $terminal->start(static function (): void {
        }, static function (): void {
        });
        $terminal->stop();
        $terminal->stop();

        rewind($out);
        self::assertSame(1, substr_count((string) stream_get_contents($out), "\x1b[?25h"));
    }

    /**
     * @param resource|null $out
     */
    private function memoryTerminal(&$out): StreamTerminal
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);

        return new StreamTerminal(null, $in, $out);
    }

    /**
     * Runs the fixture as the session leader of a fresh pseudo-terminal — so the terminal's line discipline is live
     * and Ctrl-C would be a signal if the terminal let it — waits for it to be ready, acts, and collects until it ends.
     *
     * @param callable(resource, resource): void $act
     *
     * @return array{0: string, 1: array{signaled: bool, termsig: int, exitcode: int}}
     */
    private function onAPseudoTerminal(string $mode, callable $act): array
    {
        if (\PHP_OS_FAMILY !== 'Linux' || !is_executable('/usr/bin/setsid') && !is_executable('/bin/setsid')) {
            self::markTestSkipped('Needs Linux and setsid to give the child a controlling terminal.');
        }
        $process = @proc_open(
            ['setsid', '-c', \PHP_BINARY, \dirname(__DIR__) . '/Fixtures/raw-terminal-child.php', $mode],
            [0 => ['pty'], 1 => ['pty'], 2 => ['pty']],
            $pipes,
        );
        if (!\is_resource($process)) {
            self::markTestSkipped('This PHP cannot open a pseudo-terminal.');
        }
        stream_set_blocking($pipes[1], false);

        $output = '';
        $deadline = microtime(true) + 10.0;
        $acted = false;
        $status = proc_get_status($process);
        while (microtime(true) < $deadline) {
            $chunk = @fread($pipes[1], 8192);
            $output .= \is_string($chunk) ? $chunk : '';
            if (!$acted && str_contains($output, 'ready')) {
                $acted = true;
                $act($process, $pipes[0]);
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $chunk = @fread($pipes[1], 8192);
                $output .= \is_string($chunk) ? $chunk : '';
                break;
            }
            usleep(10000);
        }
        if ($status['running']) {
            proc_terminate($process, 9);
            self::fail('The child never ended: ' . $output);
        }

        return [$output, ['signaled' => $status['signaled'], 'termsig' => $status['termsig'], 'exitcode' => $status['exitcode']]];
    }
}
