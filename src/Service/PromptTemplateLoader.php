<?php

declare(strict_types=1);

namespace App\Service;

final class PromptTemplateLoader
{
    public function __construct(
        private readonly string $baseDir = __DIR__ . '/Prompts',
    ) {}

    /**
     * @return array{system:string, user:string}
     */
    public function load(string $filename): array
    {
        return $this->loadInternal($filename, []);
    }

    /**
     * @param array<string, bool> $stack  // recursion guard
     * @return array{system:string, user:string}
     */
    private function loadInternal(string $filename, array $stack): array
    {
        $path = rtrim($this->baseDir, '/').'/'.$filename;

        if (!is_file($path)) {
            throw new \RuntimeException("Prompt-Datei nicht gefunden: {$path}");
        }

        if (isset($stack[$filename])) {
            throw new \RuntimeException("Include-Zyklus erkannt bei: {$filename}");
        }
        $stack[$filename] = true;

        $raw = (string) file_get_contents($path);

        $system = $this->extractBlock($raw, 'SYSTEM');
        $user   = $this->extractBlock($raw, 'USER');

        // Includes korrekt je nach Bereich auflösen
        $system = $this->resolveIncludes($system, $stack, 'system');
        $user   = $this->resolveIncludes($user, $stack, 'user');

        return [
            'system' => trim($system),
            'user'   => trim($user),
        ];
    }

    /**
     * @param array<string, scalar|null> $vars
     */
    public function render(string $template, array $vars = []): string
    {
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{{' . $k . '}}'] = (string) ($v ?? '');
        }
        return strtr($template, $map);
    }

    private function extractBlock(string $raw, string $name): string
    {
        $pattern = sprintf('/\[%s\](.*?)\[\/%s\]/si', preg_quote($name, '/'), preg_quote($name, '/'));
        if (!preg_match($pattern, $raw, $m)) {
            return '';
        }
        return (string) $m[1];
    }

    /**
     * Include syntax:
     *   [[include:FILE]]           -> includes matching block (system/user) depending on current section
     *   [[include:FILE#system]]    -> force include SYSTEM block
     *   [[include:FILE#user]]      -> force include USER block
     *
     * @param array<string, bool> $stack
     * @param 'system'|'user' $section
     */
    private function resolveIncludes(string $text, array $stack, string $section): string
    {
        return preg_replace_callback(
            '/\[\[include:([a-zA-Z0-9_.\-\/]+)(?:#(system|user))?\]\]/',
            function (array $m) use ($stack, $section): string {
                $file = $m[1];
                $forced = $m[2] ?? null;

                $tpl = $this->loadInternal($file, $stack);

                $pick = $forced ?: $section; // default: same section
                return $tpl[$pick] ?? '';
            },
            $text
        ) ?? $text;
    }

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }
}
