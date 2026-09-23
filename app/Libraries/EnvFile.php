<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Minimal, dependency-free reader/writer for CodeIgniter .env files.
 *
 * Used by `php spark tickethub:url` and by docker/env-sync.php, which runs in
 * the container entrypoint before the framework boots — so this class must not
 * touch anything outside plain PHP.
 *
 * Only the keys you set or remove change; comments, ordering and every other
 * line are kept byte for byte. Values are written so that CodeIgniter's DotEnv
 * parser reads back exactly what was set.
 */
final class EnvFile
{
    /** @var list<string> */
    private array $lines;

    private function __construct(private readonly string $path, string $contents)
    {
        $this->lines = $contents === '' ? [] : preg_split('/\R/', rtrim($contents, "\r\n"));
    }

    public static function open(string $path): self
    {
        return new self($path, is_file($path) ? (string) file_get_contents($path) : '');
    }

    /** Parsed value of a key, or null when the key is absent (or commented out). */
    public function get(string $key): ?string
    {
        $i = $this->find($key);

        return $i === null ? null : self::decode(explode('=', $this->lines[$i], 2)[1] ?? '');
    }

    /** Sets a key in place, or appends it (under $section, if given and new). */
    public function set(string $key, string $value, ?string $section = null): self
    {
        $line = $key . ' = ' . self::encode($value);
        $i    = $this->find($key);
        if ($i !== null) {
            $this->lines[$i] = $line;
        } else {
            if ($section !== null && ! in_array('# ' . $section, $this->lines, true)) {
                $this->lines[] = '';
                $this->lines[] = '# ' . $section;
            }
            $this->lines[] = $line;
        }

        return $this;
    }

    public function remove(string $key): self
    {
        $i = $this->find($key);
        if ($i !== null) {
            array_splice($this->lines, $i, 1);
        }

        return $this;
    }

    public function contents(): string
    {
        return implode("\n", $this->lines) . "\n";
    }

    /**
     * Writes atomically (temp file + rename) so a crash never leaves half a
     * config behind. Writes through a symlink to its target, keeping the link.
     */
    public function save(?int $mode = null): void
    {
        $target = is_link($this->path) ? (string) realpath($this->path) : $this->path;
        if ($target === '') {
            $target = $this->path;
        }
        $tmp = $target . '.tmp' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $this->contents()) === false) {
            throw new \RuntimeException('Cannot write ' . $tmp);
        }
        $mode ??= is_file($target) ? (fileperms($target) & 0777) : 0640;
        @chmod($tmp, $mode);
        if (is_file($target)) {
            @chown($tmp, (int) fileowner($target));
            @chgrp($tmp, (int) filegroup($target));
        }
        if (! @rename($tmp, $target)) {
            @unlink($tmp);

            throw new \RuntimeException('Cannot replace ' . $target);
        }
    }

    /** Index of the active (uncommented) line for $key. */
    private function find(string $key): ?int
    {
        foreach ($this->lines as $i => $line) {
            $t = ltrim($line);
            if ($t === '' || $t[0] === '#' || ! str_contains($t, '=')) {
                continue;
            }
            $name = trim(explode('=', $t, 2)[0]);
            $name = (string) preg_replace('/^export\s+/', '', $name);
            if ($name === $key) {
                return $i;
            }
        }

        return null;
    }

    /** Value as it must appear on the right of `=`. */
    public static function encode(string $value): string
    {
        // Plain tokens (URLs, numbers, booleans, CIDR lists) need no quoting.
        if ($value !== '' && preg_match('#^[A-Za-z0-9_.:/@+,=%-]+$#', $value)) {
            return $value;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /** Mirrors CodeIgniter\Config\DotEnv::sanitizeValue(). */
    public static function decode(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $q = $raw[0];
        if ($q === '"' || $q === "'") {
            if (preg_match('/^' . $q . '((?:[^' . $q . '\\\\]|\\\\\\\\|\\\\' . $q . ')*)' . $q . '/', $raw, $m)) {
                return str_replace(['\\' . $q, '\\\\'], [$q, '\\'], $m[1]);
            }

            return $raw;
        }

        return trim(explode(' #', $raw, 2)[0]);
    }
}
