<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Keeps the locales honest: every language file and every key present in `en`
 * must exist in each other locale (and vice versa), and no English value may
 * be empty. Run: php vendor/bin/phpunit --no-coverage tests/unit/LanguageTest.php
 *
 * @internal
 */
final class LanguageTest extends CIUnitTestCase
{
    private const BASE = 'en';

    /** @return list<string> locale codes that have a directory under app/Language */
    private function locales(): array
    {
        $out = [];
        foreach (glob(APPPATH . 'Language/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $out[] = basename($dir);
        }
        sort($out);

        return $out;
    }

    /** @return array<string, array<string, string>> file => [dotted.key => value] */
    private function load(string $locale): array
    {
        $files = [];
        foreach (glob(APPPATH . 'Language/' . $locale . '/*.php') ?: [] as $path) {
            $lines = require $path;
            $this->assertIsArray($lines, "{$locale}/" . basename($path) . ' must return an array');
            $files[basename($path, '.php')] = $this->flatten($lines);
        }
        ksort($files);

        return $files;
    }

    /** @return array<string, string> */
    private function flatten(array $lines, string $prefix = ''): array
    {
        $out = [];
        foreach ($lines as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            if (is_array($v)) {
                $out += $this->flatten($v, $key);
            } else {
                $out[$key] = (string) $v;
            }
        }

        return $out;
    }

    public function testBaseLocaleExistsAndHasFiles(): void
    {
        $this->assertContains(self::BASE, $this->locales());
        $this->assertNotEmpty($this->load(self::BASE));
    }

    public function testConfiguredLocalesHaveDirectories(): void
    {
        foreach (config('App')->supportedLocales as $loc) {
            $this->assertDirectoryExists(APPPATH . 'Language/' . $loc, "supportedLocales lists '{$loc}' but app/Language/{$loc} is missing");
        }
    }

    public function testNoEnglishValueIsEmpty(): void
    {
        foreach ($this->load(self::BASE) as $file => $keys) {
            foreach ($keys as $key => $value) {
                $this->assertNotSame('', trim($value), "en/{$file}.php: '{$key}' is empty");
            }
        }
    }

    public function testEveryLocaleMirrorsEnglishFilesAndKeys(): void
    {
        $base = $this->load(self::BASE);

        foreach ($this->locales() as $loc) {
            if ($loc === self::BASE) {
                continue;
            }
            $other = $this->load($loc);

            $this->assertSame(
                array_keys($base),
                array_keys($other),
                "Language files differ between en and {$loc}: missing " . implode(', ', array_diff(array_keys($base), array_keys($other)))
                . '; extra ' . implode(', ', array_diff(array_keys($other), array_keys($base))),
            );

            foreach ($base as $file => $keys) {
                $missing = array_diff_key($keys, $other[$file]);
                $extra   = array_diff_key($other[$file], $keys);
                $this->assertSame([], array_keys($missing), "{$loc}/{$file}.php is missing keys: " . implode(', ', array_keys($missing)));
                $this->assertSame([], array_keys($extra), "{$loc}/{$file}.php has keys not in en: " . implode(', ', array_keys($extra)));

                foreach ($other[$file] as $key => $value) {
                    $this->assertNotSame('', trim($value), "{$loc}/{$file}.php: '{$key}' is empty");
                }
            }
        }
    }

    public function testPlaceholdersMatchBetweenLocales(): void
    {
        $base = $this->load(self::BASE);

        foreach ($this->locales() as $loc) {
            if ($loc === self::BASE) {
                continue;
            }
            $other = $this->load($loc);
            foreach ($base as $file => $keys) {
                foreach ($keys as $key => $value) {
                    if (! isset($other[$file][$key])) {
                        continue; // reported by the mirror test
                    }
                    $this->assertSame(
                        $this->placeholders($value),
                        $this->placeholders($other[$file][$key]),
                        "{$loc}/{$file}.php: '{$key}' uses different placeholders than en",
                    );
                }
            }
        }
    }

    /** @return list<string> sorted ICU argument names used in a string ({0}, {name}, {n, plural, ...}) */
    private function placeholders(string $s): array
    {
        preg_match_all('/\{\s*([A-Za-z0-9_]+)\s*[,}]/', $s, $m);
        $names = array_unique($m[1]);
        sort($names);

        return array_values($names);
    }
}
