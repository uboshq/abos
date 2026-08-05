<?php

declare(strict_types=1);

namespace App\Core\Module;

use App\Core\Contracts\Importer;
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
        /**
         * রিপোর্ট সরবরাহকারী ক্লাসগুলো — প্রতিটার একটা স্থির
         * registerAll(ReportEngine) পদ্ধতি থাকতে হবে।
         *
         * @var list<class-string>
         */
        public readonly array $reports,

        /**
         * পুরনো খাতা থেকে কী কী আনা যায়।
         *
         * প্রতিটা মডিউল নিজে বলে দেয় সে কোন সারিগুলো নিতে পারে, তাই
         * ইমপোর্টের পর্দাটা কোনো মডিউলের নাম জানে না (সেকশন ১৯.৭)।
         *
         * @var array<string, class-string>
         */
        public readonly array $imports,
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
        foreach ($menu as $group => $items) {
            if (! in_array($group, self::MENU_GROUPS, true)) {
                throw new InvalidArgumentException(
                    "{$path}: unknown menu group '{$group}'. Allowed: ".implode(', ', self::MENU_GROUPS).'.'
                );
            }

            foreach ($items as $item) {
                foreach (['label', 'route', 'permission'] as $key) {
                    if (! isset($item[$key])) {
                        throw new InvalidArgumentException(
                            "{$path}: every menu item needs '{$key}'. A menu item without a permission would show "
                            .'to everyone, and one without a label would render as an empty row.'
                        );
                    }
                }

                if (! in_array($item['permission'], $raw['permissions'] ?? [], true)) {
                    throw new InvalidArgumentException(
                        "{$path}: menu item '{$item['route']}' asks for permission '{$item['permission']}', which "
                        .'this module does not declare. Nobody would ever be granted it, so the item would be '
                        .'invisible to every user including the owner.'
                    );
                }

                /*
                 * সুইচের পেছনের সারি — সুইচটা ঘোষিত কি না তা এখানেই ধরা।
                 *
                 * অঘোষিত কী দিলে SettingsService সেটা চিনত না, আর সারিটা
                 * চিরকাল অদৃশ্য থাকত — কোনো ভুলের বার্তা ছাড়াই। টাইপো
                 * সবচেয়ে সম্ভাব্য কারণ, আর টাইপোর শাস্তি একটা হারিয়ে
                 * যাওয়া মেনু সারি হওয়া উচিত নয়।
                 */
                if (isset($item['setting'])) {
                    $declared = array_column($raw['settings'] ?? [], 'key');

                    if (! in_array($item['setting'], $declared, true)) {
                        throw new InvalidArgumentException(
                            "{$path}: menu item '{$item['route']}' is gated on setting '{$item['setting']}', which "
                            .'this module does not declare. The item would never appear, and nothing would say why.'
                        );
                    }
                }
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
            reports: self::validateReports($raw['reports'] ?? [], $path),
            imports: self::validateImports($raw['imports'] ?? [], $path),
            path: $path,
            namespace: $namespace,
        );
    }

    /**
     * ইমপোর্টারগুলো সত্যিই আছে কি না — বুট-টাইমে।
     *
     * ভুল নাম ধরা না পড়লে ইমপোর্টের পর্দায় একটা সারি দেখাত, আর ক্লিক
     * করলে ৫০০ পড়ত — অর্থাৎ ভুলটা ধরা পড়ত ব্যবহারকারীর হাতে, প্রথম
     * গ্রাহকের ডেটা আনার দিনে।
     *
     * @param  array<string, mixed>  $imports
     * @return array<string, class-string>
     */
    private static function validateImports(array $imports, string $path): array
    {
        foreach ($imports as $key => $class) {
            if (! is_string($class) || ! class_exists($class)) {
                throw new InvalidArgumentException(
                    "{$path}: importer '{$key}' points at '".(is_string($class) ? $class : gettype($class))."', which does not exist."
                );
            }

            if (! is_subclass_of($class, Importer::class)) {
                throw new InvalidArgumentException(
                    "{$path}: importer {$class} must implement the Importer contract."
                );
            }
        }

        return $imports;
    }

    /**
     * রিপোর্ট সরবরাহকারীরা সত্যিই নিবন্ধন করতে পারে কি না — বুট-টাইমে।
     *
     * ভুল ক্লাসের নাম বা পদ্ধতি না থাকা ধরা না পড়লে রিপোর্টগুলো নীরবে
     * অনুপস্থিত থাকত, আর কেউ খুঁজতে গিয়ে বুঝত মেনুতে আছে কিন্তু খোলে না।
     *
     * @param  list<mixed>  $reports
     * @return list<class-string>
     */
    private static function validateReports(array $reports, string $path): array
    {
        foreach ($reports as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                throw new InvalidArgumentException(
                    "{$path}: report provider '".(is_string($class) ? $class : gettype($class))."' does not exist."
                );
            }

            if (! method_exists($class, 'registerAll')) {
                throw new InvalidArgumentException(
                    "{$path}: report provider {$class} needs a static registerAll(ReportEngine) method."
                );
            }
        }

        return array_values($reports);
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
