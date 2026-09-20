<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * প্রতিষ্ঠানের সেটিংস — সব মডিউল, এক পর্দায়।
 *
 * ── ⛔ কী ভাঙা ছিল, ৭ সেপ্টেম্বর ২০২৬ ────────────────────────────────
 * মডিউলগুলো `module.php`-তে **৭৪টা সুইচ ঘোষণা করে**, আর তার মধ্যে
 * **৬৯টার কোনো পর্দাই ছিল না**:
 *
 *     Sales       ৩৩   ⛔        Customer     ৭   ⛔
 *     Purchase     ৭   ⛔        MasterData   ৬   ⛔
 *     SystemAdmin  ৬   ⛔        Inventory    ৫   ⛔
 *     Hr · Supplier · Approval   ৫   ⛔
 *     Accounts     ৫   ✅  ← একমাত্র যার পর্দা ছিল
 *
 * ⓘ লাইভের `settings` টেবিলে **মোট তিনটা সারি** — কারণটা এটাই। ⚠️ সুইচ
 * থাকা আর সুইচ টেপা যাওয়া এক জিনিস নয়; ঘোষণাটা কাজের অর্ধেক, আর বাকি
 * অর্ধেকটা এতদিন কেউ করেনি।
 *
 * ── কেন এটা "একটা পর্দা কম" নয়, তার চেয়ে বড় ──────────────────────────
 * মালিকের নিয়ম: *"যে ধাপটা কেবল মালিক করতে পারেন, সেটা প্রতিটা ক্রেতার
 * জন্য অসমাপ্ত।"* ⛔ সার্ভার থেকে `SettingsService::set()` ডেকে সুইচ টেপা
 * যেত — **এই একটা ইনস্ট্যান্সে, একবার**। ⓘ যিনি এই ERP কিনবেন তিনি
 * পারতেন না, আর তাঁর কাছে সুইচগুলোর অস্তিত্বই ছিল না।
 *
 * ── ⚠️ `screens` গ্রুপটা এখানে আসে না, ইচ্ছাকৃতভাবে ──────────────────
 * আটটা সুইচ পর্দা চালু/বন্ধ করে, আর সেগুলোর সাথে `holds` বাঁধা:
 * [[ControlPanelController]] সারি গুনে দেখে, আর কাগজ থাকলে পর্দাটা
 * আড়াল করতে **দেয় না**।
 *
 * ⛔ ওগুলো এখানে তুললে ওই পাহারাটা পাশ কাটানো হত — কেউ দশটা ঝুলন্ত
 * চালানওয়ালা কোম্পানির চালান-পর্দা বন্ধ করে দিতেন, আর ওই কাগজগুলোর আর
 * কোনো দরজা থাকত না। ⓘ তাই সেগুলো Control Panel-এই থাকে, যেখানে
 * পাহারাটা আছে; এই পর্দা সেখানে পাঠায়।
 *
 * ⭐ ফলে দুইটা পর্দা একই সারিতে লেখে না — **ভাগটা মালিকানার, রুচির নয়**।
 */
class SettingsController extends Controller implements HasMiddleware
{
    /**
     * ⚠️ যে গ্রুপটা এই পর্দার নয়।
     *
     * ⓘ ধ্রুবক, কারণ নামটা দুই জায়গায় লেখা হলে একদিন একটা বদলাত আর
     * সুইচগুলো নীরবে দুই পর্দায় চলে আসত।
     */
    private const OWNED_BY_CONTROL_PANEL = 'screens';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ModuleRegistry $modules,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        /*
         * ⓘ Control Panel-এর চাবিটাই — নতুন চাবি বানানো হয়নি।
         *
         * ⚠️ দুইটা পর্দাই একই প্রশ্নের উত্তর দেয় ("এই প্রতিষ্ঠান কীভাবে
         * চলবে"), আর আলাদা চাবি দিলে একজনকে অর্ধেক উত্তর দেওয়ার অধিকার
         * দেওয়া যেত — যেটা কেউ চায়নি, অথচ ভুল করে ঘটত।
         */
        return [new Middleware('can:system_admin.settings.manage')];
    }

    public function edit(Request $request): View
    {
        return view('system_admin::settings.edit', [
            'menu' => $this->menu->forUser($request->user()),
            'modules' => $this->byModule(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /*
         * ⚠️ পুরো অ্যারেটা একবারে, `input('settings.'.$key)` দিয়ে নয়।
         *
         * ⓘ সেটিং-এর কী-তে ডট আছে (`customer.zero_limit_blocks`), আর
         * Laravel-এর `input()` ডটকে পথ ধরে নেয় — সে খুঁজত
         * `settings['customer']['zero_limit_blocks']`, অথচ ফর্ম পাঠায়
         * `settings['customer.zero_limit_blocks']`। ⛔ ফলে প্রতিটা মান
         * `null` আসত আর **কিছুই সেভ হত না, নীরবে**।
         *
         * ⭐ ফাঁদটা [[AccountsSettingsController]]-এ নাম ধরে লেখা আছে —
         * সেখানে একবার ঘটেছিল বলেই।
         *
         * @var array<string, mixed> $submitted
         */
        $submitted = (array) $request->input('settings', []);

        $changed = 0;

        foreach ($this->editable() as $key => $definition) {
            /*
             * ⚠️ চেকবক্স না দেখালে ব্রাউজার **কিছুই পাঠায় না**, তাই
             * boolean সবসময় "আছে কি নেই" হিসেবে পড়া হয়। ⓘ integer-এ
             * সেটা চলে না: ঘর ফাঁকা রাখা আর শূন্য লেখা এক জিনিস নয়, আর
             * ফাঁকা মানে "যা ছিল তাই থাক"।
             */
            $raw = $submitted[$key] ?? null;

            $value = match ($definition['type']) {
                'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                'integer' => $raw === null || $raw === '' ? null : (int) $raw,

                /*
                 * ⚠️ `number` ঘরটা প্রথম খসড়ায় ছিল না — ৭ সেপ্টেম্বর ২০২৬।
                 *
                 * ⓘ চারটা সেটিং এই ধরনের (কমিশনের সর্বোচ্চ টাকা, রাউন্ডিং,
                 * অনুমোদনের নিজের সীমা)। ⛔ ঘরটা না থাকায় ওগুলো `default`
                 * শাখায় পড়ে **কাঁচা স্ট্রিং** হিসেবে যেত, আর নিচের তুলনাটা
                 * `5 === '5'` মিলত না — ফলে প্রতিবার সেভ করলেই ওরা "বদলেছে"
                 * ধরা হত।
                 */
                /*
                 * ⚠️ `(float)` নয় — ৭ সেপ্টেম্বর ২০২৬, পূর্ণ সুইটে ধরা।
                 *
                 * ⓘ এই ঘরগুলো **টাকা**: কমিশনের সর্বোচ্চ অঙ্ক, রাউন্ডিংয়ের
                 * সীমা, অনুমোদনের নিজের সীমা। ⛔ `MoneyIsNeverAFloat`
                 * পাহারাটা ঠিকই ধরেছে, আর ছাড় নেওয়ার কোনো কারণ নেই।
                 *
                 * ⭐ `settings.value` একটা **টেক্সট কলাম**, আর নিচের
                 * তুলনাটাও স্ট্রিং ধরে হয় — তাই সংখ্যায় বদলানোর দরকারই
                 * ছিল না। ⓘ যা দরকার তা হলো "খালি নয়" আর "সংখ্যা" —
                 * দুইটাই `is_numeric` বলে দেয়।
                 *
                 * ⚠️ `0.1 + 0.2` এখানে না ঘটলেও নিয়মটা সস্তা রাখাই ভালো:
                 * একদিন কেউ এই মানটা ধরে হিসাব করবেন, আর তখন উৎসটা
                 * float হলে ভুলটা এখান থেকেই শুরু হত।
                 */
                'number' => $raw === null || trim((string) $raw) === '' || ! is_numeric($raw)
                    ? null
                    : trim((string) $raw),

                default => $raw,
            };

            if ($value === null) {
                continue;
            }

            /*
             * ⛔ যা বদলায়নি তার জন্য সারি বসানো হয় না — আর এটাই আসল কথা।
             *
             * ── ⚠️ কী ঘটেছিল, ৭ সেপ্টেম্বর ২০২৬ ─────────────────────────
             * তুলনাটা ছিল `get($key) === $value`, অর্থাৎ **কড়া**। ⓘ কিন্তু
             * সারি না থাকলে `get()` ফেরত দেয় `module.php`-এর ডিফল্ট — যেটা
             * সংখ্যা (`5`), আর ফর্ম পাঠায় স্ট্রিং (`'5'`)। ⛔ ফলে লাইভে
             * **একটা** সুইচ টিপে **পাঁচটা** সারি বসে গিয়েছিল।
             *
             * ── কেন সেটা ক্ষতিকর, যদিও মানটা একই ─────────────────────────
             * ⚠️ একটা override সারি মানে ওই কোম্পানি আর **পণ্যের ডিফল্ট
             * অনুসরণ করে না**। ⓘ কাল ডিফল্টটা বদলালে — নিরাপদ কোনো নতুন
             * মানে — এই কোম্পানিগুলো নীরবে পুরনোটাই ধরে রাখত, আর কেউ বলতে
             * পারত না কেন তারা আলাদা।
             *
             * ── কেন স্ট্রিং ধরে তুলনা ────────────────────────────────────
             * ⭐ সংরক্ষণের সময় `encode()` যা করে, এটা তার আয়না: সবই টেক্সট
             * কলামে যায়। ⓘ তাই "যা বসত, তা-ই কি আছে?" প্রশ্নটার সবচেয়ে সৎ
             * রূপ এটাই — টাইপ মিলুক বা না মিলুক।
             */
            $current = $this->settings->get($key);

            if ($this->asStored($current) === $this->asStored($value)) {
                continue;
            }

            $this->settings->set($key, $value);
            $changed++;
        }

        return back()->with('saved', trans_choice(
            'system_admin::settings.saved',
            $changed,
            ['count' => $changed],
        ));
    }

    /**
     * একটা মান ডাটাবেসে যে চেহারায় বসত।
     *
     * ⓘ [[SettingsService::encode()]]-এর আয়না: `boolean` হয় `'1'`/`'0'`,
     * বাকি সব `(string)`। ⚠️ ওটা private, তাই এখানে ছোট করে আবার লেখা —
     * আর সেজন্যই এই মন্তব্যটা, যাতে একদিন ওটা বদলালে কেউ এটাও দেখেন।
     *
     * ⛔ `(string) true` = `'1'` আর `(string) false` = `''` — তাই boolean-টা
     * আলাদা করে লেখা হয়েছে; নাহলে "বন্ধ" আর "কিছুই নেই" এক দেখাত।
     */
    private function asStored(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * ⛔ যেগুলো এই পর্দা বদলাতে পারে — একটাই তালিকা, দুই জায়গায় নয়।
     *
     * ⚠️ `edit()` আর `update()` আলাদা করে ছাঁকলে একদিন একটা বদলাত, আর
     * তখন পর্দায় দেখা যায় অথচ সেভ হয় না — বা আরও খারাপ, **দেখা যায় না
     * অথচ সেভ হয়ে যায়**।
     *
     * ── ⚠️ দুইটা ছাঁকনি, দুইটা আলাদা কারণে ────────────────────────────
     * ⓘ `definitions()` কেবল `module.php`-এর ঘোষিত সুইচগুলো ফেরত দেয় না —
     * সে **মেনুর প্রতিটা সারির জন্যও** একটা সুইচ বানায় (`menu.sales.reports`,
     * `sales.enabled`)। ⛔ প্রথম খসড়ায় সেগুলো ছাঁকিনি, আর মেপে দেখা গেল
     * পর্দায় **`menu.system_admin.master` জাতীয় কাঁচা চাবি** উঠে আসছে,
     * অনুবাদহীন।
     *
     * ⚠️ ওগুলো Control Panel-এর, আর সেখানে একটা পাহারা আছে যেটা এখানে
     * নেই — তাই ছাঁকনিটা কেবল সৌন্দর্যের নয়।
     *
     * @return array<string, array<string, mixed>>
     */
    private function editable(): array
    {
        return array_filter(
            $this->settings->definitions(),
            fn (array $d) => ! ($d['menu'] ?? false)
                && ($d['group'] ?? 'general') !== self::OWNED_BY_CONTROL_PANEL,
        );
    }

    /**
     * মডিউল ধরে, তার ভেতরে গ্রুপ ধরে।
     *
     * ⓘ ক্রমটা `ModuleRegistry`-র ক্রম — অর্থাৎ সাইডবারের ক্রম। ⚠️
     * বর্ণানুক্রমে সাজালে পর্দাটা সাইডবারের সাথে মিলত না, আর মানুষ
     * "Sales কোথায়" খুঁজতেন এমন এক তালিকায় যার নিজের কোনো যুক্তি নেই।
     *
     * @return list<array{code: string, label: string, groups: array<string, list<array<string, mixed>>>}>
     */
    private function byModule(): array
    {
        $editable = $this->editable();
        $out = [];

        foreach ($this->modules->all() as $module) {
            $groups = [];

            foreach ($editable as $key => $definition) {
                if (($definition['module'] ?? null) !== $module->code) {
                    continue;
                }

                $groups[$definition['group'] ?? 'general'][] = [
                    ...$definition,
                    'key' => $key,
                    'value' => $this->settings->get($key),
                ];
            }

            // ⓘ যে মডিউল কিছু ঘোষণা করে না, তার জন্য খালি শিরোনাম নয়
            if ($groups === []) {
                continue;
            }

            $out[] = [
                'code' => $module->code,
                'label' => $module->label(),
                'groups' => $groups,

                /*
                 * ⓘ বাঁ পাশের তালিকায় সংখ্যাটা দেখানো হয়।
                 *
                 * ⚠️ Blade-এ গুনলে প্রতিটা ট্যাবে একটা nested লুপ লাগত
                 * শুধু একটা সংখ্যার জন্য, আর সংখ্যাটা আর ছাঁকনিটা আলাদা
                 * হয়ে যেতে পারত।
                 */
                'count' => array_sum(array_map('count', $groups)),
            ];
        }

        return $out;
    }
}
