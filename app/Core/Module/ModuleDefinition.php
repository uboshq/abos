<?php

declare(strict_types=1);

namespace App\Core\Module;

use InvalidArgumentException;

/**
 * একটা মডিউলের module.php ফাইলটা পড়ার পর যা দাঁড়ায়।
 *
 * মডিউল কোরকে বলে দেয় সে কী দেয় (মেনু, অনুমতি, ডকুমেন্ট টাইপ, ড্রিল-ডাউনের উৎস)
 * আর কী লাগে (কোন মডিউলগুলোর উপর নির্ভরশীল)। কোর সেটা পড়ে নিজেই সব নিবন্ধন করে —
 * কোথাও হাতে মডিউলের নাম লিখতে হয় না।
 *
 * অ্যারের বদলে এই ক্লাস, কারণ ভুল বানানো module.php বুট-টাইমেই ধরা পড়া দরকার,
 * ছয় মাস পরে একটা ফাঁকা মেনু দেখে নয়।
 */
final class ModuleDefinition
{
    /** সব মডিউলে একই ছয়-ভাগ প্যাটার্ন — প্ল্যান সেকশন ১৫.২ */
    public const MENU_GROUPS = ['dashboard', 'master', 'transactions', 'approval', 'reports', 'settings'];

    private function __construct(
        public readonly string $code,
        /** @var array{en: string, bn: string} */
        public readonly array $name,
        public readonly string $version,
        /** @var list<string> */
        public readonly array $dependsOn,
        /** @var array<string, list<array{label: string, route: string, icon?: string, permission?: string}>> */
        public readonly array $menu,
        /** @var list<string> */
        public readonly array $permissions,
        /** @var array<string, string> prefix => label */
        public readonly array $docTypes,
        /** @var array<string, class-string> source_type => model */
        public readonly array $drillSources,
        /** @var list<array{key: string, label: string, type: string, default: mixed, group?: string}> */
        public readonly array $settings,
        public readonly string $path,
        public readonly string $namespace,
    ) {}

    /**
     * @param  array<string, mixed>  $raw  module.php যা ফেরত দিয়েছে
     */
    public static function fromArray(array $raw, string $path, string $namespace): self
    {
        $code = self::requireString($raw, 'code', $path);

        if (! preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
            throw new InvalidArgumentException(
                "{$path}: code must be lowercase snake_case — got '{$code}'."
            );
        }

        $name = $raw['name'] ?? null;
        if (! is_array($name) || ! isset($name['en'], $name['bn'])) {
            throw new InvalidArgumentException(
                "{$path}: name needs both 'en' and 'bn'. দ্বিভাষিক বাধ্যতামূলক — প্ল্যান সেকশন ৩ নিয়ম ৯."
            );
        }

        $menu = $raw['menu'] ?? [];
        foreach (array_keys($menu) as $group) {
            if (! in_array($group, self::MENU_GROUPS, true)) {
                throw new InvalidArgumentException(
                    "{$path}: unknown menu group '{$group}'. Allowed: ".implode(', ', self::MENU_GROUPS).'.'
                );
            }
        }

        foreach ($raw['permissions'] ?? [] as $permission) {
            if (! str_starts_with((string) $permission, $code.'.')) {
                throw new InvalidArgumentException(
                    "{$path}: permission '{$permission}' must be prefixed with '{$code}.' so two modules can never "
                    .'collide on the same name.'
                );
            }
        }

        return new self(
            code: $code,
            name: ['en' => (string) $name['en'], 'bn' => (string) $name['bn']],
            version: (string) ($raw['version'] ?? '1.0.0'),
            dependsOn: array_values($raw['depends_on'] ?? []),
            menu: $menu,
            permissions: array_values($raw['permissions'] ?? []),
            docTypes: $raw['doc_types'] ?? [],
            drillSources: $raw['drill_sources'] ?? [],
            settings: array_values($raw['settings'] ?? []),
            path: $path,
            namespace: $namespace,
        );
    }

    /** ব্যবহারকারীর ভাষায় মডিউলের নাম। */
    public function label(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        return $this->name[$locale] ?? $this->name['en'];
    }

    public function dir(string ...$segments): string
    {
        return dirname($this->path).($segments ? DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments) : '');
    }

    private static function requireString(array $raw, string $key, string $path): string
    {
        if (! isset($raw[$key]) || ! is_string($raw[$key]) || $raw[$key] === '') {
            throw new InvalidArgumentException("{$path}: '{$key}' is required.");
        }

        return $raw[$key];
    }
}
