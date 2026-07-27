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

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Rendering\ComponentRendererRegistry;
use Milpa\Live\Rendering\TuiComponentRenderer;
use Milpa\Live\Tests\Fixtures\FakeTerminal;
use Milpa\Live\Tui\CombinedAutocompleteProvider;
use Milpa\Live\Tui\FilePathProvider;
use Milpa\Live\Tui\InMemoryShortcutRegistry;
use Milpa\Live\Tui\InteractiveTuiLoop;
use Milpa\Live\Tui\JsonFileTuiStateManager;
use Milpa\Live\Tui\SlashCommandProvider;
use Milpa\Live\Tui\TuiComponentInstance;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;
use Milpa\Live\ValueObjects\Tui\ShortcutBinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The component loop's keyboard, focus and session behaviour, plus the
 * autocomplete providers and the session store it can be given.
 *
 * The loop is the second largest class in the package; only its happy path had
 * ever run.
 */
#[CoversClass(InteractiveTuiLoop::class)]
#[CoversClass(TuiComponentInstance::class)]
#[CoversClass(SlashCommandProvider::class)]
#[CoversClass(FilePathProvider::class)]
#[CoversClass(CombinedAutocompleteProvider::class)]
#[CoversClass(JsonFileTuiStateManager::class)]
#[CoversClass(InMemoryShortcutRegistry::class)]
final class ComponentLoopBehaviourTest extends TestCase
{
    private function component(string $contract = 'input'): ComponentDefinitionInterface
    {
        return new class ($contract) implements ComponentDefinitionInterface {
            public static string $name = 'input';

            public function __construct(string $contract)
            {
                self::$name = $contract;
            }

            public static function contract(): ComponentContract
            {
                return new ComponentContract(self::$name, '1.0.0', 'test double');
            }

            public function mount(array $props, ComponentContext $context): StateSnapshot
            {
                return new StateSnapshot($context->componentId, self::$name, '1.0.0', ['value' => ''], ['label' => 'Campo']);
            }

            public function handle(InteractionRequest $request): InteractionResult
            {
                return new InteractionResult($request->state);
            }
        };
    }

    /**
     * @param list<string> $ids
     */
    private function loop(array $ids = ['c1'], ?JsonFileTuiStateManager $store = null): InteractiveTuiLoop
    {
        $registry = new ComponentRendererRegistry();
        $registry->register(new TuiComponentRenderer());

        $instances = [];
        foreach ($ids as $id) {
            $component = $this->component();
            $context = new ComponentContext($id);
            $instances[] = new TuiComponentInstance($id, $component, $context, [], $component->mount([], $context));
        }

        return new InteractiveTuiLoop($registry, $instances, $store);
    }

    // ---- focus and keys --------------------------------------------------

    public function testItStartsFocusedOnTheFirstInstance(): void
    {
        self::assertSame('c1', $this->loop(['c1', 'c2'])->focusedId());
    }

    public function testTabMovesFocusForwardAndShiftTabBack(): void
    {
        $loop = $this->loop(['c1', 'c2', 'c3']);

        $loop->dispatchKey("\t");
        self::assertSame('c2', $loop->focusedId());

        $loop->dispatchKey("\033[Z");
        self::assertSame('c1', $loop->focusedId());
    }

    public function testFocusWrapsAround(): void
    {
        $loop = $this->loop(['c1', 'c2']);

        $loop->dispatchKey("\033[Z");
        self::assertSame('c2', $loop->focusedId(), 'Going back from the first wraps to the last.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function navigationKeys(): array
    {
        return [
            'arrow up' => ["\033[A"],
            'arrow down' => ["\033[B"],
            'enter' => ["\n"],
            'space' => [' '],
            'letter c' => ['c'],
            'letter r' => ['r'],
            'backspace' => ["\177"],
            'a printable letter' => ['x'],
        ];
    }

    #[DataProvider('navigationKeys')]
    public function testTheLoopKeepsRunningForAnOrdinaryKey(string $key): void
    {
        $loop = $this->loop(['c1', 'c2']);

        self::assertTrue($loop->dispatchKey($key), "{$key} should not stop the loop.");
        self::assertTrue($loop->running());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function quitKeys(): array
    {
        return ['q' => ['q'], 'escape' => ["\033"]];
    }

    #[DataProvider('quitKeys')]
    public function testAQuitKeyStopsTheLoop(string $key): void
    {
        $loop = $this->loop();

        self::assertFalse($loop->dispatchKey($key));
        self::assertFalse($loop->running());
    }

    public function testAnEmptyKeyChangesNothing(): void
    {
        $loop = $this->loop();

        self::assertTrue($loop->dispatchKey(''));
    }

    public function testTypingReachesTheFocusedComponent(): void
    {
        $loop = $this->loop();
        $before = $loop->renderScreen();

        $loop->dispatchKey('h');
        $loop->dispatchKey('i');

        self::assertNotSame($before, $loop->renderScreen(), 'Typing has to change what is drawn.');
    }

    public function testTheScreenNamesTheFocusedInstance(): void
    {
        self::assertStringContainsString('c1', $this->loop(['c1', 'c2'])->renderScreen());
    }

    public function testSessionStateCarriesTheFocus(): void
    {
        $loop = $this->loop(['c1', 'c2']);
        $loop->dispatchKey("\t");

        $state = $loop->sessionState();

        self::assertIsArray($state);
        self::assertSame('c2', $state['focus'] ?? null);
    }

    // ---- session store ---------------------------------------------------

    public function testASessionIsPersistedAndRestored(): void
    {
        $file = sys_get_temp_dir() . '/milpa-tui-' . bin2hex(random_bytes(6)) . '.json';
        $store = new JsonFileTuiStateManager($file);

        try {
            self::assertFalse($store->loadState(), 'Nothing has been saved yet.');

            $loop = $this->loop(['c1', 'c2'], $store);
            $loop->dispatchKey("\t");

            $saved = $store->loadState();
            self::assertIsArray($saved);
            self::assertSame('c2', $saved['focus'] ?? null);

            // A fresh loop over the same store picks the focus back up.
            self::assertSame('c2', $this->loop(['c1', 'c2'], $store)->focusedId());

            $store->clearSession();
            self::assertFalse($store->loadState());
        } finally {
            @unlink($file);
        }
    }

    public function testAnUnwritableSessionPathDoesNotBreakTheLoop(): void
    {
        $store = new JsonFileTuiStateManager('/no/such/directory/session.json');

        self::assertFalse($store->loadState());
        self::assertFalse($store->saveState(['focus' => 'c1']));
    }

    // ---- runOn -----------------------------------------------------------

    public function testItPaintsThroughTheContractAndStops(): void
    {
        $terminal = new FakeTerminal(['q']);

        $this->loop()->runOn($terminal, idleMicroseconds: 0);

        self::assertContains('start', $terminal->lifecycle);
        self::assertContains('stop', $terminal->lifecycle);
        self::assertNotSame('', $terminal->output());
    }

    public function testItStopsWhenTheInputRunsOut(): void
    {
        $terminal = new FakeTerminal([]);

        $this->loop()->runOn($terminal, idleMicroseconds: 0);

        self::assertContains('stop', $terminal->lifecycle);
    }

    public function testResizingChangesTheWidthItDrawsAt(): void
    {
        $angosto = new FakeTerminal(['q'], 30, 6);
        $ancho = new FakeTerminal(['q'], 100, 6);

        $this->loop()->runOn($angosto, idleMicroseconds: 0);
        $this->loop()->runOn($ancho, idleMicroseconds: 0);

        $mayor = static function (string $text): int {
            $max = 0;
            foreach (explode("\n", $text) as $line) {
                $max = max($max, mb_strlen(rtrim($line)));
            }

            return $max;
        };

        self::assertGreaterThan($mayor($angosto->output()), $mayor($ancho->output()));
    }

    // ---- autocomplete providers ------------------------------------------

    public function testTheSlashProviderTriggersOnASlash(): void
    {
        $provider = new SlashCommandProvider([['name' => 'help'], ['name' => 'quit'], ['name' => 'hello']]);

        self::assertTrue($provider->shouldTrigger('/he', 3));
        self::assertFalse($provider->shouldTrigger('hola', 4));
    }

    public function testTheSlashProviderSuggestsWhatMatches(): void
    {
        $provider = new SlashCommandProvider([['name' => 'help'], ['name' => 'quit'], ['name' => 'hello']]);
        $suggestions = $provider->suggestions('/he', 3);

        self::assertNotSame([], $suggestions);
        foreach ($suggestions as $s) {
            self::assertStringContainsString('he', strtolower((string) ($s['value'] ?? $s['label'] ?? '')));
        }
    }

    public function testTheFileProviderTriggersOnAPathPrefix(): void
    {
        $provider = new FilePathProvider(static fn (string $dir): array => [
            ['name' => 'archivo.txt', 'isDir' => false],
            ['name' => 'carpeta', 'isDir' => true],
        ]);

        self::assertTrue($provider->shouldTrigger('./a', 3));
        self::assertTrue($provider->shouldTrigger('~/x', 3));
        self::assertFalse($provider->shouldTrigger('hola', 4));
    }

    public function testTheFileProviderListsWhatTheListerReturns(): void
    {
        $provider = new FilePathProvider(static fn (string $dir): array => [
            ['name' => 'archivo.txt', 'isDir' => false],
            ['name' => 'carpeta', 'isDir' => true],
        ]);

        $suggestions = $provider->suggestions('./', 2);

        self::assertNotSame([], $suggestions);
    }

    public function testTheCombinedProviderDelegatesToWhicheverTriggers(): void
    {
        $combined = new CombinedAutocompleteProvider(
            new SlashCommandProvider([['name' => 'help', 'description' => 'ayuda']]),
            new FilePathProvider(static fn (string $dir): array => [['name' => 'x.txt', 'isDir' => false]]),
        );

        self::assertTrue($combined->shouldTrigger('/he', 3));
        self::assertTrue($combined->shouldTrigger('./', 2));
        self::assertFalse($combined->shouldTrigger('hola', 4));
        self::assertNotSame([], $combined->suggestions('/he', 3));
    }

    public function testAcceptingASuggestionRewritesTheText(): void
    {
        $combined = new CombinedAutocompleteProvider(
            new SlashCommandProvider([['name' => 'help', 'description' => 'ayuda']]),
            new FilePathProvider(static fn (string $dir): array => []),
        );

        $result = $combined->acceptSuggestion('/he', 3, '/help');

        self::assertIsArray($result);
        self::assertStringContainsString('/help', (string) ($result['text'] ?? ''));
    }

    // ---- shortcut registry ------------------------------------------------

    public function testAShortcutResolvesOnlyInItsScope(): void
    {
        $registry = new InMemoryShortcutRegistry();
        $registry->register(new ShortcutBinding('ctrl+p', 'palette', 'Paleta', 'global'));
        $registry->register(new ShortcutBinding('ctrl+p', 'preview', 'Vista', 'editor'));

        self::assertSame('palette', $registry->resolve('ctrl+p', 'global')?->command);
        self::assertSame('preview', $registry->resolve('ctrl+p', 'editor')?->command);
        self::assertNull($registry->resolve('ctrl+z', 'global'));
    }

    public function testTheRegistryListsEverythingOrJustOneScope(): void
    {
        $registry = new InMemoryShortcutRegistry();
        $registry->register(new ShortcutBinding('ctrl+p', 'palette', 'Paleta', 'global'));
        $registry->register(new ShortcutBinding('ctrl+s', 'save', 'Guardar', 'editor'));

        self::assertCount(2, $registry->all(null));
        self::assertCount(1, $registry->all('editor'));
    }
}
