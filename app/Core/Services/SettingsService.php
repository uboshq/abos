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

    /**
     * কোন কোম্পানির সব সারি একবারে তোলা হয়ে গেছে।
     *
     * ⓘ একই অনুরোধে কোম্পানি বদলাতে পারে (কনসোল, সিডার), তাই কোম্পানি
     * ধরে আলাদা — নইলে দ্বিতীয় কোম্পানি প্রথমটার সেটিং পড়ত।
     *
     * @var array<string, true>
     */
    private array $primed = [];

    /**
     * ঘোষিত সেটিংগুলো, একবার গুনে রাখা।
     *
     * ⚠️ আকারটা **খোলা** (`...`), আর সেটা ইচ্ছাকৃত: মডিউল নিজের সেটিংয়ে
     * যে চাবিই দিক সেটা এখানে পৌঁছায় ([[SettingsService::definitions()]])।
     * বন্ধ আকার লিখলে PHPStan ঘোষিত অথচ অতালিকাভুক্ত চাবিকে "নেই" বলত —
     * আর ঠিক সেটাই `tab`-এর বেলায় ঘটেছিল।
     *
     * @var array<string, array{type: string, default: mixed, module: string, group: string, label: string, holds: ?string, ...}>|null
     */
    private ?array $definitions = null;

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly MenuSwitches $switches,
    ) {}

    /**
     * সব মডিউলের ঘোষিত সেটিং — Control Panel-এর স্ক্রিন এটা থেকেই তৈরি হয়।
     *
     * @return array<string, array{type: string, default: mixed, module: string, group: string, label: string, holds: ?string, ...}>
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

                /*
                 * মডিউল যা বলেছে তার সবটাই যায়, শুধু ফাঁকগুলো ভরে দেওয়া হয়।
                 *
                 * ── ⚠️ আগে এখানে চাবিগুলো হাতে লেখা ছিল ─────────────────
                 * আগের কোড একটা নতুন অ্যারে বানাত আর `$setting` থেকে নাম
                 * ধরে ধরে পাঁচ-ছয়টা চাবি তুলে আনত। ফল: **module.php-তে
                 * যোগ করা যেকোনো নতুন চাবি নীরবে হারিয়ে যেত** — ব্যতিক্রম
                 * নয়, সতর্কতা নয়, কেবল অনুপস্থিতি।
                 *
                 * ⓘ এটা দুইবার ঘটেছে, আর দ্বিতীয়বারটা প্রথমটার পাশে লেখা
                 * সতর্কবার্তা সত্ত্বেও:
                 *
                 *   ১. `holds` — পাহারাটা লেখা ছিল, কন্ট্রোলার সেটা কখনো
                 *      দেখতই না, আর কাগজভরা পর্দা দিব্যি বন্ধ হয়ে যাচ্ছিল।
                 *      তখন একটা লাইন যোগ করে সারানো হয়েছিল।
                 *
                 *   ২. `tab` — [[ControlPanelController::crossTabs()]] ঘোষণা
                 *      থেকে ট্যাব গোনে (`'tab' => 'counter'`), আর সেটাই
                 *      §১৯.৭ মানার উপায়: কোরে মডিউলের নাম না লিখে সেটিং
                 *      নিজেই বলে সে কোথায় বসতে চায়। ⛔ কিন্তু চাবিটা এই
                 *      তালিকায় ছিল না, তাই `$definition['tab']` চিরকাল
                 *      null — অর্থাৎ **ব্যবস্থাটা লেখা ছিল আর কোনোদিন
                 *      চলতে পারত না**। আজ কোনো মডিউল `tab` ঘোষণা করে না
                 *      বলে কেউ টের পায়নি; প্রথম যিনি করতেন, তিনি একটা
                 *      নীরব অ-ঘটনা পেতেন।
                 *
                 * ⭐ ১২ সেপ্টেম্বর ২০২৬-এ larastan বসানোর দিনে ধরা পড়ল —
                 * PHPStan বলছিল `Offset 'tab' … does not exist` আর
                 * `in_array() … always false`। সংকেতটা ঠিক ছিল।
                 *
                 * ── কেন এবার আর তালিকা নয় ──────────────────────────────
                 * তৃতীয়বার যাতে না ঘটে, তাই নাম ধরে তোলা বন্ধ: `$setting`
                 * পুরোটাই যায়, আর `+` কেবল **অনুপস্থিত** ঘরগুলো ভরে।
                 * এখন নতুন চাবি যোগ করতে এই ফাইলটা খুলতেই হয় না।
                 *
                 * `key` বাদ, কারণ সেটা অ্যারের চাবি হয়ে আগেই আছে; দুই
                 * জায়গায় থাকলে একদিন দুইটা আলাদা কথা বলত।
                 *
                 * `module` `+`-এর বাইরে বসে, কারণ ওটা কোরের হিসাব — মডিউল
                 * নিজের কোড ভুল করেও ঘোষণা করতে পারবে না।
                 */
                $definitions[$key] = array_diff_key($setting, ['key' => null]) + [
                    'type' => 'string',
                    'default' => null,
                    'group' => 'general',
                    'label' => $key,

                    /*
                     * এই সুইচটা বন্ধ করলে যে কাগজগুলোর দরজা বন্ধ হয়ে যেত।
                     *
                     * মডিউল একটা মডেলের নাম দেয়; কোর শুধু গুনে দেখে সারি
                     * আছে কি না, আর থাকলে সুইচটা বন্ধ হতে দেয় না। কোরে
                     * কোনো মডিউলের নাম নেই (১৯.৭)।
                     */
                    'holds' => null,
                ];

                $definitions[$key]['module'] = $module->code;
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
        /*
         * ⭐ কিছু সুইচ গোটা ব্যবস্থার, একটা কোম্পানির নয় — ২২ সেপ্টেম্বর ২০২৬।
         *
         * ── ⛔ কেন এটা লাগল ─────────────────────────────────────────
         * মালিক চাইলেন HR গ্রুপ-ভিত্তিক করার সুইচ। ⚠️ কিন্তু সেটিং
         * কোম্পানি ধরে বসে, তাই TCL সুইচটা চালু করলে **TCL দেখত DEM-এর
         * কর্মী, আর DEM দেখত না TCL-এর**।
         *
         * ⛔ আর DEM কখনো রাজি হয়নি। ⓘ এক পাশ থেকে খোলা একটা দেয়াল
         * সুবিধা নয়, ফাঁস — আর সবচেয়ে খারাপ ধরনের ফাঁস, কারণ যিনি
         * দেখা যাচ্ছেন তিনি জানেনই না।
         *
         * ⭐ তাই `'scope' => 'product'` লেখা সুইচগুলো কোম্পানির সারি
         * পড়েই না — একটাই উত্তর, সবার জন্য এক।
         */
        $companyId = $this->isProductWide($key) ? null : CompanyContext::id();
        $cacheKey = $companyId.'|'.$key;

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $this->primeFor($companyId);

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
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

    /**
     * ⭐ একটা কোম্পানির সব সেটিং একবারে — ২০ সেপ্টেম্বর ২০২৬, অডিটে ধরা।
     *
     * ── ⛔ কী ঘটত ──────────────────────────────────────────────────────
     * প্রতিটা `get()` একটা করে কোয়েরি করত। ⓘ মেনু আঁকতে গিয়ে
     * [[MenuSwitches::itemIsOn()]] প্রতি সারিতে **তিনটা** সেটিং দেখে
     * (মডিউল · দল · সারি), আর তাতে একটা পাতা খোলার আগেই ২৪৭টা কোয়েরি
     * হত। ⚠️ আর তার প্রায় সবগুলোই **কিছুই না পেয়ে** ফিরত: সারিটা
     * টেবিলে নেই, তাই ডিফল্টে গিয়ে পড়ত। অর্থাৎ ২৪৭ বার ডাটাবেসে গিয়ে
     * কিছু না জেনে ফেরা।
     *
     * ⭐ সেটিং অল্প কয়েকশো সারি, আর একটা পাতা এমনিতেই ডজনখানেক দেখে —
     * তাই সবগুলো একবারে তুলে নেওয়াই সস্তা। ১টা কোয়েরি, তারপর সব উত্তর
     * স্মৃতি থেকে।
     *
     * ⚠️ না-থাকা সারিও স্মৃতিতে বসে (ডিফল্ট হিসেবে), তাই একই অচেনা চাবি
     * বারবার জিজ্ঞেস করলেও দ্বিতীয়বার কোয়েরি হয় না।
     */
    private function primeFor(?int $companyId): void
    {
        if (isset($this->primed[(string) $companyId])) {
            return;
        }

        $this->primed[(string) $companyId] = true;

        foreach (Setting::query()->where('company_id', $companyId)->get() as $row) {
            $this->cache[$companyId.'|'.$row->key] = $row->typedValue();
        }
    }

    public function enabled(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /**
     * এই সুইচটা কি গোটা ব্যবস্থার?
     *
     * ⚠️ অচেনা চাবি → না। ⓘ নিরাপদ দিকটা এখানে "কোম্পানির", কারণ ভুল
     * করে একটা সাধারণ সুইচ সবার জন্য এক করে দিলে এক কোম্পানির পছন্দ
     * নীরবে বাকিদের উপর বসে যেত।
     */
    public function isProductWide(string $key): bool
    {
        return ($this->definitions()[$key]['scope'] ?? 'company') === 'product';
    }

    public function set(string $key, mixed $value): void
    {
        $definition = $this->definitions()[$key] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException(
                "Unknown setting '{$key}'. Declare it in the owning module's module.php before setting it."
            );
        }

        /* ⓘ পড়ার নিয়মের সাথে মিল — নাহলে লেখা হত এক জায়গায়, পড়া হত অন্য জায়গায় */
        $companyId = $this->isProductWide($key) ? null : CompanyContext::id();

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
        $this->primed = [];
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
