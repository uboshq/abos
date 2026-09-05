<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Core\Services\MenuSwitches;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Control Panel — সব মডিউলের সুইচ এক জায়গায়।
 *
 * প্রতিটা মডিউলের নিজের সেটিংস পর্দাও আছে, আর সেটাই রোজকার জায়গা।
 * এই পর্দাটা আলাদা কারণে: নতুন কোম্পানি চালু করার সময় কেউ একবার বসে
 * পুরো সিস্টেমটা নিজের ব্যবসার মতো করে নেয় — তখন আটটা মডিউলের আটটা
 * পর্দা ঘুরে বেড়ানো অর্থহীন।
 *
 * এখানে কোনো মডিউলের নাম লেখা নেই, আর কখনো থাকবে না (সেকশন ১৯.৭):
 * মডিউল যা ঘোষণা করে সেটাই দেখায়। নতুন মডিউল যোগ হলে তার সুইচগুলো
 * নিজে থেকেই এখানে আসে — আর সেটাই "কোর না ছুঁয়ে নতুন মডিউল" কথাটার
 * মানে।
 */
class ControlPanelController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ModuleRegistry $registry,
        private readonly MenuBuilder $menu,
        private readonly MenuSwitches $switches,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.settings.manage')];
    }

    /**
     * ট্যাব ধরে — এক পাতায় সবটা নয়।
     *
     * ── কেন, ৩০ আগস্ট ২০২৬ ──────────────────────────────────────────
     * মালিকের কথা: *"এত লম্বা জিনিস Ctrl+F কইরা খুঁইজা বাইর করতে হবে
     * না, প্রত্যেকটা জিনিস আলাদা আলাদা রাখ।"*
     *
     * আগে তিপ্পান্নটা সুইচ একটা পাতায় ছিল, আর সাথে এখন যোগ হচ্ছে
     * একশোর বেশি মেনু সারি — এক পাতায় দেড়শো সুইচ মানে কেউ কিছু
     * খুঁজে পায় না, তাই কেউ কিছু বদলায়ও না।
     */
    public function edit(Request $request): View
    {
        $tab = (string) $request->query('tab', 'switches');

        return view('system_admin::control-panel.edit', [
            'menu' => $this->menu->forUser($request->user()),
            'tab' => $tab,
            'tabs' => $this->tabs(),

            /*
             * ⭐ মডিউল-পেরোনো ট্যাব হলে **কেবল সেই ট্যাবের সারিগুলো**।
             *
             * ⓘ ব্লেড একটাই লুপ চালায় (`$modules`), তাই এখানে ঠিক
             * জিনিসটা ভরে দিলে পর্দার কোনো নতুন শাখা লাগে না — আর
             * দুইটা শাখা মানে একদিন একটায় বদল, অন্যটায় নয়।
             *
             * ⚠️ সারিগুলো এখানেও **একই সেটিং**, কপি নয়: একই চাবি, একই
             * `settings->get()`। তাই এক দরজায় বদলালে অন্য দরজাতেও
             * বদলায় — মালিকের "সেটিংস এক জায়গায়" নিয়মটা অক্ষত।
             */
            'modules' => in_array($tab, $this->crossTabs(), true)
                ? $this->crossTab($tab)
                : $this->byModule(),

            /*
             * এই ট্যাবের গাছটুকুই — গোটা গাছ নয়।
             *
             * ── কেন, ৩০ আগস্ট ২০২৬ ─────────────────────────────
             * প্রথমে সবটা এক ট্যাবে ছিল, আর পাতাটা দাঁড়াল **৮,৮৯২
             * পিক্সেল** লম্বা — একশো বিয়াল্লিশটা সারি একসাথে।
             * ট্যাব বানানোর কারণটাই ছিল ওই লম্বা পাতা, তাই ওটা
             * থেকে গেলে কাজটার কোনো মানে থাকত না।
             *
             * এখন প্রথম ট্যাবে কেবল মডিউলগুলো (আটটা সারি, এক
             * নজরে), আর প্রতিটা মডিউলের মেনু তার নিজের ট্যাবে —
             * তার ঘোষিত সেটিংসের ঠিক পাশে, কারণ দুইটাই একই
             * মডিউলের কথা।
             */
            'tree' => $this->treeFor($tab),
            'settings' => $this->settings,

            /*
             * কোন সুইচ এখন চালু — পর্দার Alpine-এর জন্য।
             *
             * সার্ভার থেকেই দেওয়া হয়, কারণ ব্লেডের ভেতরে প্রতিটা
             * চেকবক্সে আলাদা করে বসালে একই প্রশ্ন একশোবার জিজ্ঞেস করা
             * হত, আর মডিউল-স্তরের অবস্থাটা সারির ভেতর থেকে জানার
             * উপায়ই থাকত না।
             */
            'switchState' => $this->switchState(),
        ]);
    }

    /**
     * এই ট্যাবে যতটুকু গাছ দেখানো হবে।
     *
     * প্রথম ট্যাবে মডিউলগুলোই — ভেতরের গ্রুপ ও সারি ছাড়া, কারণ ওখানে
     * প্রশ্নটা একটাই: *কোন মডিউলগুলো এই ব্যবসায় লাগে?*
     *
     * @return list<array<string, mixed>>
     */
    private function treeFor(string $tab): array
    {
        $tree = $this->switches->tree();

        if ($tab !== 'switches') {
            return array_values(array_filter($tree, fn (array $m) => $m['code'] === $tab));
        }

        /*
         * গ্রুপগুলো ফেলে দেওয়া হয়, কিন্তু **গুনতিটা রাখা হয়**।
         *
         * "হিসাব" বন্ধ করলে ঠিক কতটা বন্ধ হচ্ছে — চারটা ভাগ,
         * তেত্রিশটা পর্দা — সেটা না জানিয়ে সুইচটা দিলে সিদ্ধান্তটা
         * অন্ধ হত।
         */
        return array_map(fn (array $m) => [
            ...$m,
            'groups' => [],
            'group_count' => count($m['groups']),
            'row_count' => array_sum(array_map(fn (array $g) => count($g['items']), $m['groups'])),
        ], $tree);
    }

    /**
     * প্রতিটা সুইচের বর্তমান অবস্থা — কী => সত্য/মিথ্যা।
     *
     * @return array<string, bool>
     */
    private function switchState(): array
    {
        $state = [];

        foreach ($this->switches->tree() as $module) {
            $state[$module['key']] = (bool) $this->settings->get($module['key'], true);

            foreach ($module['groups'] as $group) {
                $state[$group['key']] = (bool) $this->settings->get($group['key'], true);
            }
        }

        return $state;
    }

    /**
     * কোন কোন ট্যাব আছে।
     *
     * ── কেন প্রথমটা "মডিউল ও মেনু" ──────────────────────────────────
     * মালিক বললেন জরুরি কাজগুলো এক পর্দায় রাখতে। এখানে এসে মানুষ যা
     * করে তা প্রায় সবসময় একটাই: **কিছু একটা চালু বা বন্ধ করা**।
     * বাকি সুইচগুলো (পেছনের তারিখ কত দিন, ছাপার ঘর) একবার বসিয়ে
     * বছরের পর বছর ছোঁয়া হয় না।
     *
     * @return list<array{key: string, label: string}>
     */
    private function tabs(): array
    {
        $tabs = [[
            'key' => 'switches',
            'label' => __('system_admin::control.tab_switches'),
        ]];

        /*
         * ট্যাবের তালিকা রেজিস্ট্রি থেকে, ঘোষিত সেটিংস থেকে নয়।
         *
         * আগে কেবল সেই মডিউলগুলোর ট্যাব হত যারা নিজে সেটিং ঘোষণা করেছে।
         * এখন প্রতিটা মডিউলের মেনুরও সুইচ আছে, তাই একটা মডিউল সেটিং না
         * ঘোষণা করলেও তার ট্যাব লাগে — নাহলে তার পর্দাগুলো বন্ধ করার
         * কোনো জায়গাই থাকত না।
         */
        /*
         * ⭐ মডিউল পেরোনো ট্যাব — মালিকের নির্দেশ, ৫ সেপ্টেম্বর ২০২৬।
         *
         * ── কেন এটা লাগল ────────────────────────────────────────────
         * *"Direct Purchase, Direct Sales — এইগুলোর সুইচগুলো কন্ট্রোল
         * প্যানেলে আলাদা ট্যাবে রাখো।"* কিন্তু ওই দুইটা **দুই
         * মডিউলে**, আর আজকের প্রতিটা ট্যাব একটা করে মডিউল। কাউন্টারের
         * লোক একটাই কাজ করেন, অথচ তার সুইচ খুঁজতে দুই জায়গায় যেতে হত।
         *
         * ── ⚠️ কোরে কোনো মডিউলের নাম নেই, ইচ্ছাকৃতভাবে ──────────────
         * সহজ পথ ছিল এখানে "purchase আর sales-এর অমুক সেটিংগুলো" লিখে
         * দেওয়া। ⛔ তাতে §১৯.৭ ভাঙত: কোর জানত কোন মডিউল আছে আর তাদের
         * সেটিংয়ের নাম কী। আর কাল POS বা রেস্টুরেন্টের কাউন্টার এলে
         * **এই ফাইলটা আবার খুলতে হত**।
         *
         * ⭐ বদলে সেটিং নিজেই বলে সে কোথায় বসতে চায় —
         * `'tab' => 'counter'`। ট্যাবটা তখন নিজে থেকেই জন্মায়, আর
         * কেউ ঘোষণা না করলে জন্মায়ই না (খালি ট্যাব নেই)।
         */
        foreach ($this->crossTabs() as $key) {
            $tabs[] = ['key' => $key, 'label' => __('system_admin::settings_group.'.$key)];
        }

        foreach ($this->switches->tree() as $module) {
            $tabs[] = ['key' => $module['code'], 'label' => $module['label']];
        }

        return $tabs;
    }

    /**
     * যে ট্যাবগুলো মডিউল পেরিয়ে যায় — ঘোষণা থেকে গোনা।
     *
     * ⓘ ক্রমটা ঘোষণার ক্রম নয়, বর্ণানুক্রমও নয় — **প্রথম যে সেটিং
     * ট্যাবটার নাম বলল** সেই ক্রম। ⚠️ নাহলে একটা মডিউল যোগ হলেই
     * ট্যাবের সারি নড়ত, আর যিনি অভ্যাসে তৃতীয় ট্যাবে ক্লিক করেন তিনি
     * অন্য জায়গায় গিয়ে পড়তেন।
     *
     * @return list<string>
     */
    private function crossTabs(): array
    {
        $found = [];

        foreach ($this->settings->definitions() as $definition) {
            $tab = $definition['tab'] ?? null;

            if ($tab !== null && ! in_array($tab, $found, true)) {
                $found[] = (string) $tab;
            }
        }

        return $found;
    }

    /**
     * একটা মডিউল-পেরোনো ট্যাবের সারিগুলো — মডিউল ধরে সাজানো।
     *
     * ── ⭐ সারিগুলো পুরনো জায়গা থেকে **সরে না** ──────────────────────
     * মালিকের সিদ্ধান্ত ছাপার সেটিংস নিয়ে: *"দুই জায়গায়"*। ⓘ যিনি
     * আজ ক্রয়ের ট্যাবে গিয়ে ছাপার সুইচটা বদলান, তিনি কাল ওটা না পেলে
     * ভাবতেন জিনিসটা চলে গেছে।
     *
     * ⚠️ তাই এটা কোনো **সরানো** নয়, দ্বিতীয় একটা **দরজা** — একই সারি,
     * একই চাবি, কেবল আরেকটা পথ। দুইটা কপি নয় বলে একটা বদলালে অন্যটাও
     * বদলায়।
     *
     * @return list<array<string, mixed>>
     */
    private function crossTab(string $tab): array
    {
        $definitions = $this->settings->definitions();
        $modules = [];

        foreach ($this->registry->all() as $module) {
            $items = [];

            foreach ($definitions as $key => $definition) {
                if (($definition['module'] ?? null) !== $module->code) {
                    continue;
                }

                if (($definition['menu'] ?? false) || ($definition['tab'] ?? null) !== $tab) {
                    continue;
                }

                $items[] = [...$definition, 'key' => $key, 'value' => $this->settings->get($key)];
            }

            if ($items === []) {
                continue;
            }

            /*
             * ⓘ মডিউলের নামটা রাখা হয় — একই ট্যাবে ক্রয় আর বিক্রয়ের
             * সুইচ পাশাপাশি থাকলে কোনটা কোথাকার তা না বললে বিভ্রান্তি
             * হত, বিশেষ করে যখন দুইটার নাম প্রায় এক ("সরাসরি ক্রয়" আর
             * "সরাসরি বিক্রয়")।
             */
            $modules[] = [
                'code' => $module->code,
                'label' => $module->label(),
                'groups' => [$tab => $items],
            ];
        }

        return $modules;
    }

    public function update(Request $request): RedirectResponse
    {
        /*
         * পুরো অ্যারেটা একবারে — input('settings.'.$key) দিয়ে নয়।
         *
         * সেটিং-এর কী-তে ডট আছে ("accounts.backdate_days"), আর Laravel-এর
         * input() ডটকে পথ ধরে নেয়। Accounts-এর সেটিংস পর্দায় ঠিক এই
         * ভুলটাই ছিল: প্রতিটা মান null আসত আর কিছুই সেভ হত না, নীরবে।
         *
         * @var array<string, mixed> $submitted
         */
        $submitted = (array) $request->input('settings', []);

        /*
         * এই পাঠানোয় কোন সুইচগুলো সত্যিই ছিল।
         *
         * ── কেন এটা ছাড়া সর্বনাশ হয়, ৩০ আগস্ট ২০২৬ ──────────────────
         * নিচের লুপটা "চেকবক্স অনুপস্থিত" মানে "বন্ধ" ধরে, আর এক পাতার
         * ফর্মে সেটা ঠিক ছিল — সব সুইচ ওই এক পাতাতেই থাকত।
         *
         * ট্যাব আসার পর একটা পাঠানোয় কেবল একটা ট্যাবের ঘরগুলো থাকে।
         * নিয়মটা না বদলানোয় **হিসাব ট্যাবে একটা সুইচ বদলে সংরক্ষণ
         * করতেই অন্য ছয় মডিউলের ৩৪টা সেটিং নীরবে বন্ধ হয়ে গেল** —
         * বিক্রয়ের বাকির সীমা, ক্রয়ের দর-মিলকরণ, মজুদের ব্র্যান্ড,
         * সবকটা। পর্দায় কোনো ভুল দেখায়নি; কেবল "৩৫টি সুইচ বদলেছে"
         * লেখাটা সত্যি কথাটাই বলছিল, আর আমরা পড়িনি।
         *
         * তাই এখন ফর্ম নিজেই বলে দেয় সে কোন কী-গুলো বহন করছে
         * (`scope[]`), আর তার বাইরের কিছু ছোঁয়া হয় না।
         *
         * ── আর খালি হলে কিছুই নয় ───────────────────────────────────
         * পুরনো কোনো পাতা যদি `scope[]` ছাড়াই আসে, কিছুই সংরক্ষণ হয় না।
         * "কিছু সেভ হলো না" ব্যবহারকারী সাথে সাথে দেখতে পান; "৩৪টা
         * সেটিং নীরবে বন্ধ" কেউ মাসের পর মাস দেখেন না।
         *
         * @var array<string, true> $scope
         */
        $scope = array_fill_keys(array_map('strval', (array) $request->input('scope', [])), true);

        $changed = 0;
        $refused = [];

        $changed += $this->saveMenuSwitches($scope, $submitted);

        foreach ($this->settings->definitions() as $key => $definition) {
            if (! isset($scope[$key])) {
                continue;
            }

            /*
             * মেনুর সুইচগুলো উপরে হয়ে গেছে ([[saveMenuSwitches()]])।
             *
             * ওরাও `scope[]`-এ থাকে, তাই এই লুপটা ওদের দ্বিতীয়বার
             * ছুঁত — একই বদল দুইবার গোনা হত, আর ব্যবহারকারী "২টি সুইচ
             * বদলেছে" পড়ে খুঁজতেন দ্বিতীয়টা কী।
             */
            if ($definition['menu'] ?? false) {
                continue;
            }

            $raw = $submitted[$key] ?? null;

            $value = match ($definition['type']) {
                // চেকবক্স না দেখালে ব্রাউজার কিছুই পাঠায় না, তাই
                // অনুপস্থিতিই "বন্ধ"
                'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                'integer' => $raw === null || $raw === '' ? null : (int) $raw,
                default => $raw,
            };

            if ($value === null) {
                continue;
            }

            if ($this->settings->get($key) === $value) {
                continue;
            }

            /*
             * যে পর্দায় কাগজ আছে সেটা আড়াল করতে দেওয়া হয় না।
             *
             * সুইচ বন্ধ করলে মেনু থেকে সারিটা উধাও হয়। যে কোম্পানির
             * দশটা অর্ডার ঝুলে আছে তার অর্ডার-পর্দা কেউ বন্ধ করে দিলে
             * ওই দশটা কাগজের আর কোনো দরজা থাকত না — অথচ সেগুলো বাতিলও
             * হয়নি, শেষও হয়নি। তাই খালি পর্দাই কেবল আড়াল করা যায়।
             *
             * মডিউলের নাম কোরে নেই: ক্লাসটা module.php বলে দেয় ('holds'),
             * কোর শুধু গুনে দেখে (১৯.৭)।
             */
            $holds = $definition['holds'] ?? null;

            if ($value === false && $holds !== null && $holds::query()->exists()) {
                $refused[] = __($definition['label']);

                continue;
            }

            $this->settings->set($key, $value);
            $changed++;
        }

        if ($refused !== []) {
            return back()
                ->with('saved', trans_choice(
                    'system_admin::message.switches_saved',
                    $changed,
                    ['count' => $changed],
                ))
                ->withErrors(['settings' => __('system_admin::validation.screen_holds_records', [
                    'screens' => implode('; ', $refused),
                ])]);
        }

        return back()->with('saved', trans_choice(
            'system_admin::message.switches_saved',
            $changed,
            ['count' => $changed],
        ));
    }

    /**
     * মডিউল, গ্রুপ ও মেনু সারির সুইচগুলো সংরক্ষণ।
     *
     * ── কেন আলাদা করে, ঘোষিত সেটিংসের সাথে নয় ───────────────────────
     * এই কী-গুলো কোনো মডিউল ঘোষণা করে না — নিয়ম ধরে তৈরি
     * ([[MenuSwitches]])। তাই `definitions()` ওদের চেনে না, আর উপরের
     * লুপটা ওদের ছুঁতেই পারত না।
     *
     * ── কেন কেবল যে ট্যাবটা পাঠানো হয়েছে ───────────────────────────
     * ফর্মটা একটা ট্যাবের সুইচগুলোই পাঠায়। সব সুইচের উপর দিয়ে গেলে
     * অন্য ট্যাবের চেকবক্সগুলো "অনুপস্থিত" মানে "বন্ধ" হয়ে যেত —
     * অর্থাৎ একটা ট্যাব সেভ করলে বাকি সব বন্ধ হয়ে যেত, নীরবে।
     *
     * তাই একটা লুকানো ঘর (`scope[]`) বলে দেয় এই পাঠানোয় কোন কী-গুলো
     * ছিল, আর কেবল ওগুলোই দেখা হয়।
     *
     * @param  array<string, true>  $scope
     * @param  array<string, mixed>  $submitted
     */
    private function saveMenuSwitches(array $scope, array $submitted): int
    {
        if ($scope === []) {
            return 0;
        }

        /*
         * ঘোষিত সুইচ এখানে ছোঁয়া হয় না — নিচের লুপটাই ওগুলো করে।
         *
         * ── কেন, ৩০ আগস্ট ২০২৬ ─────────────────────────────────────
         * কিছু মেনু সারি নিজের ঘোষিত সুইচ ব্যবহার করে
         * ([[MenuSwitches::forItem()]]), আর সেই ঘোষণায় `holds` থাকতে
         * পারে — "এই পর্দায় কাগজ থাকলে আড়াল করতে দিও না"।
         *
         * প্রথমে এই মেথডটা ওগুলোও লিখত, আর যেহেতু সে **আগে** চলে,
         * সুইচটা বসে যেত পাহারাটা কোনোদিন দেখার আগেই। দশটা ঝুলন্ত
         * অর্ডার নিয়ে অর্ডার-পর্দা দিব্যি আড়াল হয়ে যেত, আর ওই দশটা
         * কাগজের আর কোনো দরজা থাকত না।
         */
        $definitions = $this->settings->definitions();

        $known = [];

        foreach ($this->switches->tree() as $module) {
            $known[$module['key']] = true;

            foreach ($module['groups'] as $group) {
                $known[$group['key']] = true;

                foreach ($group['items'] as $item) {
                    $known[$item['key']] = true;
                }
            }
        }

        $changed = 0;

        foreach (array_keys($scope) as $key) {
            /*
             * অচেনা কী নীরবে ফেলে দেওয়া হয়।
             *
             * ফর্মটা যে কেউ বদলে পাঠাতে পারেন, আর তখন যেকোনো নামে
             * একটা সেটিং বসে যেত — ব্যবস্থায় এমন সুইচ থাকত যা কোনো
             * কোড কোনোদিন পড়ে না।
             */
            if (! isset($known[$key])) {
                continue;
            }

            // ঘোষিত সুইচ — নিচের লুপের, কারণ সেখানেই `holds` পাহারাটা
            if (! ($definitions[$key]['menu'] ?? false)) {
                continue;
            }

            $value = filter_var($submitted[$key] ?? null, FILTER_VALIDATE_BOOLEAN);

            if ($this->settings->get($key, true) === $value) {
                continue;
            }

            $this->settings->set($key, $value);
            $changed++;
        }

        return $changed;
    }

    /**
     * সুইচগুলো মডিউল ও গ্রুপ অনুসারে সাজানো।
     *
     * গ্রুপের নাম মডিউলের নিজের অনুবাদ থেকে আসে
     * ("accounts::settings_group.entry"), না থাকলে একটা সাধারণ নাম —
     * কারণ কোরের এখানে জানার কথা নয় কোন মডিউলে কোন গ্রুপ আছে।
     *
     * @return list<array<string, mixed>>
     */
    private function byModule(): array
    {
        $definitions = $this->settings->definitions();

        $modules = [];

        foreach ($this->registry->all() as $module) {
            $groups = [];

            foreach ($definitions as $key => $definition) {
                if (($definition['module'] ?? null) !== $module->code) {
                    continue;
                }

                // মেনুর সুইচের নিজের ছক আছে; মডিউলের কার্ডে দ্বিতীয়বার
                // দেখালে একই জিনিসের দুইটা ঘর থাকত, আর কোনটা আসল তা
                // বলা যেত না
                if ($definition['menu'] ?? false) {
                    continue;
                }

                $group = $definition['group'] ?? 'general';

                $groups[$group][] = [
                    ...$definition,
                    'key' => $key,
                    'value' => $this->settings->get($key),
                ];
            }

            // যে মডিউল কোনো সুইচ ঘোষণা করেনি তার জন্য খালি একটা কার্ড
            // দেখানো হয় না — খালি কার্ড শুধু জায়গা নেয়
            if ($groups === []) {
                continue;
            }

            $modules[] = [
                'code' => $module->code,
                'label' => $module->label(),
                'groups' => $groups,
            ];
        }

        return $modules;
    }
}
