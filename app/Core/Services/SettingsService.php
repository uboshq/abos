<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Setting;
use InvalidArgumentException;

/**
 * Control Panel-এর পড়া-লেখা — ক্রস-কাটিং নিয়ম ৭।
 *
 * প্রতিটা ঐচ্ছিক ফিল্ডের অন/অফ সুইচ এখানে থাকে, আর সুইচগুলোর *সংজ্ঞা* আসে
 * মডিউলের নিজের module.php থেকে। ফলে নতুন ফিল্ড যোগ করার একই কাজেই তার
 * সুইচটাও চলে আসে — আলাদা করে Control Panel-এ কিছু লিখতে হয় না, তাই
 * ভুলে যাওয়ারও সুযোগ থাকে না।
 *
 * দুই স্তর: কোম্পানির নিজের মান, না থাকলে module.php-র ডিফল্ট।
 */
final class SettingsService
{
    /** @var array<string, mixed> */
    private array $cache = [];

    /** @var array<string, array{type: string, default: mixed, module: string, group: string, label: string}>|null */
    private ?array $definitions = null;

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly MenuSwitches $switches,
    ) {}

    /**
     * সব মডিউলের ঘোষিত সেটিং — Control Panel-এর স্ক্রিন এটা থেকেই তৈরি হয়।
     *
     * @return array<string, array{type: string, default: mixed, module: string, group: string, label: string}>
     */
    public function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $definitions = [];

        foreach ($this->registry->all() as $module) {
            foreach ($module->settings as $setting) {
                $key = $setting['key'];

                if (isset($definitions[$key])) {
                    throw new InvalidArgumentException(
                        "Two modules declare the setting '{$key}'. "
                        .'Prefix it with the module code so they cannot collide.'
                    );
                }

                $definitions[$key] = [
                    'type' => $setting['type'] ?? 'string',
                    'default' => $setting['default'] ?? null,
                    'module' => $module->code,
                    'group' => $setting['group'] ?? 'general',
                    'label' => $setting['label'] ?? $key,

                    /*
                     * এই সুইচটা বন্ধ করলে যে কাগজগুলোর দরজা বন্ধ হয়ে যেত।
                     *
                     * মডিউল একটা মডেলের নাম দেয়; কোর শুধু গুনে দেখে সারি
                     * আছে কি না, আর থাকলে সুইচটা বন্ধ হতে দেয় না। কোরে
                     * কোনো মডিউলের নাম নেই (১৯.৭)।
                     *
                     * ── কেন এই লাইনটা দরকার ────────────────────────────
                     * এখানে চাবিগুলো হাতে লেখা, তাই module.php-তে যোগ করা
                     * যেকোনো নতুন চাবি নীরবে হারিয়ে যায়। 'holds' প্রথমবার
                     * ঠিক তাই হয়েছিল: পাহারাটা লেখা ছিল, কন্ট্রোলার সেটা
                     * কখনো দেখতই না, আর কাগজভরা পর্দা দিব্যি বন্ধ হয়ে
                     * যাচ্ছিল।
                     */
                    'holds' => $setting['holds'] ?? null,
                ];
            }
        }

        return $this->definitions = $definitions + $this->menuSwitches($definitions);
    }

    /**
     * মেনুর সুইচগুলো — কোনো মডিউল ঘোষণা করে না, নিয়ম ধরে তৈরি।
     *
     * ── কেন এগুলোও এখানে আসে, ৩০ আগস্ট ২০২৬ ─────────────────────────
     * `set()` অচেনা কী ফিরিয়ে দেয়, আর সেটা ঠিক: অচেনা কী মানে প্রায়
     * সবসময় টাইপো, আর নীরবে বসে গেলে ব্যবস্থায় এমন সুইচ থাকত যা কোনো
     * কোড কোনোদিন পড়ে না।
     *
     * কিন্তু মেনুর একশোর বেশি কী নিয়ম ধরে তৈরি ([[MenuSwitches]])।
     * পাহারাটা পাশ কাটিয়ে গেলে টাইপো ধরার শক্তিটাই চলে যেত, তাই
     * উল্টোটা করা হলো — কী-গুলোকে **চেনানো** হলো।
     *
     * ── কেন `+`, আর `array_merge` নয় ─────────────────────────────────
     * কিছু সারি নিজের সুইচ ঘোষণা করে, আর সেটা একটা সারির চেয়ে বড় কিছু
     * নিয়ন্ত্রণ করে (এক ঘোষণায় গাড়ি আর গাড়ির ধরন দুইটাই)। `+` ঘোষিতটাকে
     * অক্ষত রাখে; `array_merge` ওটাকে চাপা দিত, আর তখন ঘোষণার
     * লেবেল-ডিফল্ট সব হারাত।
     *
     * @param  array<string, array<string, mixed>>  $declared
     * @return array<string, array{type: string, default: mixed, module: string, group: string, label: string, menu: bool}>
     */
    private function menuSwitches(array $declared): array
    {
        $out = [];

        foreach ($this->switches->tree() as $module) {
            $rows = [[
                'key' => $module['key'],
                'label' => $module['label'],
                'group' => 'module',
            ]];

            foreach ($module['groups'] as $group) {
                $rows[] = [
                    'key' => $group['key'],
                    'label' => $group['name'],
                    'group' => 'group',
                ];

                foreach ($group['items'] as $item) {
                    $rows[] = [
                        'key' => $item['key'],
                        'label' => $item['label'],
                        'group' => 'item',
                    ];
                }
            }

            foreach ($rows as $row) {
                if (isset($declared[$row['key']]) || isset($out[$row['key']])) {
                    continue;
                }

                $out[$row['key']] = [
                    'type' => 'boolean',

                    /*
                     * ডিফল্ট চালু — নতুন পর্দা নিজে থেকেই দেখা যায়।
                     *
                     * উল্টো হলে প্রতিটা নতুন পর্দা ডেলিভারির দিন
                     * অদৃশ্য থাকত, আর কেউ জানত না যে ওটা এসেছে।
                     */
                    'default' => true,
                    'module' => $module['code'],
                    'group' => $row['group'],
                    'label' => $row['label'],
                    'holds' => null,

                    /*
                     * এই চিহ্নটা কন্ট্রোল প্যানেলের জন্য: মেনুর সুইচ
                     * নিজের ছকে দেখানো হয়, মডিউলের সেটিংস কার্ডে নয়।
                     */
                    'menu' => true,
                ];
            }
        }

        return $out;
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        $companyId = CompanyContext::id();
        $cacheKey = $companyId.'|'.$key;

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $row = Setting::query()
            ->where('key', $key)
            ->where('company_id', $companyId)
            ->first();

        if ($row !== null) {
            return $this->cache[$cacheKey] = $row->typedValue();
        }

        $definition = $this->definitions()[$key] ?? null;

        if ($definition !== null) {
            return $this->cache[$cacheKey] = $definition['default'];
        }

        // অচেনা কী মানে সাধারণত টাইপো। চুপচাপ null ফেরালে সুইচটা "বন্ধ"
        // হিসেবে ধরা হত, আর একটা ফিচার নীরবে অদৃশ্য থাকত।
        if (func_num_args() < 2) {
            throw new InvalidArgumentException(
                "Unknown setting '{$key}'. Declare it in the owning module's module.php."
            );
        }

        return $this->cache[$cacheKey] = $fallback;
    }

    public function enabled(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function set(string $key, mixed $value): void
    {
        $definition = $this->definitions()[$key] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException(
                "Unknown setting '{$key}'. Declare it in the owning module's module.php before setting it."
            );
        }

        $companyId = CompanyContext::id();

        Setting::query()->updateOrCreate(
            ['company_id' => $companyId, 'key' => $key],
            [
                'module' => $definition['module'],
                'group' => $definition['group'],
                'type' => $definition['type'],
                'value' => $this->encode($value, $definition['type']),
            ],
        );

        unset($this->cache[$companyId.'|'.$key]);
    }

    /** কোম্পানির ওভাররাইড মুছে ডিফল্টে ফেরা। */
    public function reset(string $key): void
    {
        $companyId = CompanyContext::id();

        /*
         * সারিগুলো মডেল হয়ে মোছা হয়, কোয়েরি বিল্ডার দিয়ে নয়।
         *
         * ── কেন এই পার্থক্যটা গুরুত্বপূর্ণ ──────────────────────────
         * `Setting::query()->...->delete()` কোনো মডেল-ঘটনা ছোঁড়ে না,
         * তাই [[IsAudited]] কিছুই টের পেত না। ফল হত সবচেয়ে খারাপ
         * রকমের অর্ধেক-সত্য: **সেটিং বদলানো অডিটে থাকত, কিন্তু
         * ডিফল্টে ফিরিয়ে দেওয়া থাকত না** — অথচ ওটাও ঠিক একইভাবে
         * পর্দার আচরণ বদলে দেয়।
         *
         * `each()`, `first()` নয়: চাবি-জোড়াটা অনন্য হওয়ার কথা, কিন্তু
         * "হওয়ার কথা" ধরে নিয়ে একটা মুছে বাকিগুলো রেখে দিলে সেটিংটা
         * ফিরিয়েও ফেরত আসত না, আর কারণটা খুঁজে পাওয়া যেত না।
         */
        Setting::query()
            ->where('company_id', $companyId)
            ->where('key', $key)
            ->get()
            ->each(fn (Setting $row) => $row->delete());

        unset($this->cache[$companyId.'|'.$key]);
    }

    /**
     * Control Panel-এর একটা ভাগ — যেমন "সেলস এন্ট্রির ফিল্ড" বা "প্রিন্টের ফিল্ড"।
     *
     * @return array<string, array{value: mixed, definition: array<string, mixed>}>
     */
    public function group(string $module, string $group): array
    {
        $result = [];

        foreach ($this->definitions() as $key => $definition) {
            if ($definition['module'] === $module && $definition['group'] === $group) {
                $result[$key] = ['value' => $this->get($key), 'definition' => $definition];
            }
        }

        return $result;
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    private function encode(mixed $value, string $type): string
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            default => (string) $value,
        };
    }
}
