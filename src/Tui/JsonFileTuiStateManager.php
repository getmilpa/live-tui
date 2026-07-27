<?php

declare(strict_types=1);

namespace Milpa\Live\Tui;

use Milpa\Live\Contracts\Tui\TuiStateManagerInterface;

/**
 * Session persistence backed by a single JSON file. Survives a restart of the
 * program, not a crash mid-write: every save rewrites the whole document, and
 * a failed write leaves the previous session intact rather than a partial one.
 */
final readonly class JsonFileTuiStateManager implements TuiStateManagerInterface
{
    public function __construct(
        private string $path,
    ) {
    }

    /**
     * Reads the persisted session, or false when there is none or it is unreadable.
     */
    public function loadState(): array|false
    {
        if (!is_file($this->path)) {
            return false;
        }

        $json = file_get_contents($this->path);
        if (!is_string($json) || trim($json) === '') {
            return false;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : false;
    }

    /**
     * Persists the session, returning whether the write succeeded.
     */
    public function saveState(array $state): bool
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }

        return file_put_contents($this->path, $json . PHP_EOL) !== false;
    }

    /**
     * Discards the persisted session, leaving no file behind.
     */
    public function clearSession(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}
