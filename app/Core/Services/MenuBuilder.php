<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Module\ModuleDefinition;
use App\Core\Module\ModuleRegistry;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * সাইডবারের মেনু — module.php থেকে, হাতে লেখা তালিকা থেকে নয়।
 *
 * সেকশন ১৫.২: প্রতিটা মডিউলে একই ছয়-ভাগ প্যাটার্ন। ব্যবহারকারী একটা মডিউল
 * শিখলে বাকি দশটা চেনা লাগবে — সেটা তখনই সম্ভব যখন ক্রমটা সব জায়গায় এক,
 * আর সেটা নিশ্চিত করার একমাত্র উপায় হলো মেনুটা একটাই কোড থেকে তৈরি হওয়া।
 *
 * সেকশন ১৯.৭ অনুযায়ী কোর ফাইলে মেনু হাতে লেখা নিষিদ্ধ।
 */
final class MenuBuilder
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly SettingsService $settings,
        private readonly MenuSwitches $switches,
    ) {}

    /**
     * এই ব্যবহারকারী যা দেখতে পাবে।
     *
     * অনুমতি নেই এমন আইটেম তালিকাতেই আসে না — নিষ্ক্রিয় করে দেখানো হয় না।
     * একটা ধূসর মেনু আইটেম ব্যবহারকারীকে শুধু জানায় সে কী পারে না, আর
     * প্রতিবার ক্লিক করে সেটা আবিষ্কার করে।
     *
     * @return list<array{code: string, label: string, icon: string, section: string, order: int, under: ?string, codes: list<string>, groups: array<string, list<array{label: string, route: string, url: ?string, active: bool}>>}>
     */
    public function forUser(?User $user): array
    {
        $menu = [];

        /*
         * এই অনুরোধের রুটটা কি নিজেই কোনো মেনু সারি?
         *
         * উত্তরটা একবারই বের করা হয়, কারণ পরিবারের নিয়মটা
         * ([[MenuBuilder::onThisScreen()]]) কেবল তখনই খাটে যখন কোনো
         * সারি হুবহু মেলেনি।
         */
        $exact = $this->aRowOwnsThisRoute();

        foreach ($this->registry->all() as $module) {
            if (! $this->moduleEnabled($module)) {
                continue;
            }

            $groups = [];

            foreach ($module->menu as $group => $items) {
                $visible = [];

                foreach ($items as $item) {
                    $planned = (bool) ($item['planned'] ?? false);

                    /*
                     * ── এখনো তৈরি হয়নি এমন স্ক্রিন: দেখা যায়, কিন্তু নিভে ──
                     *
                     * ২৮ আগস্ট ২০২৬-এ এগুলো মেনু থেকে **সরিয়ে** দেওয়া
                     * হয়েছিল, আর কারণটা ভালো ছিল: এক মডিউলের ২৯টা সারির
                     * ২৯টাই নিষ্ক্রিয় হলে মেনুটা আর মেনু থাকে না,
                     * প্রতিশ্রুতির তালিকা হয়ে যায়। মানুষ ক্লিক করেন, কিছু
                     * হয় না, আর ভাবেন ব্যবস্থাটা নষ্ট।
                     *
                     * ── কেন ৩ সেপ্টেম্বরে আবার ফিরল ──────────────────────
                     * মালিক রেস্টুরেন্ট মডিউলের জন্য সরাসরি বলেছেন:
                     * *"eigulo shudu fontend menute rako Coming soon diye,
                     * Backend e pore kaj korbo"* — অর্থাৎ সারিগুলো দেখাই
                     * উদ্দেশ্য, কী কী আসছে তা জানানোর জন্য।
                     *
                     * আর পুরনো আশঙ্কাটা এখন খাটে না, সেটা মেপে দেখা
                     * হয়েছে: **পুরো রিপোতে planned সারি ১৮টা, সবগুলোই
                     * রেস্টুরেন্টে** — যে মডিউলটা নিজেই এখনো খোলস।
                     * বাকি বারোটা মডিউলে একটাও নেই, তাই কোনো ভরা মেনু
                     * প্রতিশ্রুতির তালিকা হয়ে যাচ্ছে না।
                     *
                     * `url` থাকে `null`, তাই সারিটা ক্লিক করা যায় না
                     * (`aria-disabled`), আর পাশে "শীঘ্রই" লেখা বসে।
                     * `ModuleMenuTest` `url === null` সারি এড়িয়ে যায়,
                     * আর দুই দিক থেকেই পাহারা দেয়: planned নয় অথচ রুট
                     * নেই — ভাঙে; planned অথচ রুট আছে — সেটাও ভাঙে,
                     * যাতে পতাকাটা নামাতে ভুল না হয়।
                     */
                    if (! $this->allowed($user, $item['permission'] ?? null)) {
                        continue;
                    }

                    /*
                     * তিনটা স্তরের সুইচ — মডিউল, গ্রুপ, সারি।
                     *
                     * ── কেন তিনটা, ৩০ আগস্ট ২০২৬ ─────────────────────
                     * আগে সুইচ পেত কেবল সেই সারিগুলো যারা `module.php`-তে
                     * নিজে একটা `setting` ঘোষণা করেছিল — একশোর বেশি
                     * সারির মধ্যে হাতেগোনা কয়েকটা। মালিক বললেন সবগুলোই
                     * বন্ধ করা যেতে হবে, আর গ্রুপগুলোও।
                     *
                     * কী-গুলো এখন নিয়ম ধরে বানানো হয়
                     * ([[MenuSwitches]]), তাই ১০১তম পর্দাটাও প্রথম দিন
                     * থেকেই সুইচ পায় — কাউকে কিছু ঘোষণা করতে হয় না।
                     *
                     * কোরে কোনো মডিউলের নাম নেই: কী-টা রেজিস্ট্রির
                     * ঘোষণা থেকেই তৈরি (১৯.৭)।
                     */
                    if (! $this->switches->itemIsOn($this->settings, $module, (string) $group, $item)) {
                        continue;
                    }

                    /*
                     * প্যারামিটারসহ রুট — যেমন পাঁচ ধরনের ভাউচার একই
                     * রুটের পাঁচটা ঠিকানা।
                     *
                     * সক্রিয় কি না তা তখন শুধু রুটের নাম দেখে বলা যায় না:
                     * পাঁচটা সারিরই নাম accounts.voucher.index, তাই একটায়
                     * থাকলে পাঁচটাই সক্রিয় দেখাত। প্যারামিটারগুলোও
                     * মেলাতে হয়।
                     */
                    $params = $item['route_params'] ?? [];

                    $visible[] = [
                        'label' => __($item['label']),
                        'route' => $item['route'],
                        /* এখনো তৈরি হয়নি মানে রুটটাও নেই — `route()` ডাকলে
                           ব্যতিক্রম, আর সেটা গোটা মেনু নামিয়ে দিত */
                        'url' => $planned ? null : route($item['route'], $params),
                        'planned' => $planned,
                        'active' => ! $planned
                            && $this->onThisScreen($item['route'], $exact)
                            && $this->paramsMatch($params),
                    ];
                }

                if ($visible !== []) {
                    $groups[$group] = $visible;
                }
            }

            if ($groups === []) {
                continue;
            }

            $menu[] = [
                'code' => $module->code,
                'label' => $module->label(),
                'icon' => $module->code,
                'section' => $module->nav['section'],
                'order' => $module->nav['order'],

                /* কার ভেতরে বসবে — [[MenuBuilder::settleGuestsIntoTheirHosts()]]
                   এটা দেখে সিদ্ধান্ত নেয়, তারপর কী-টা আর থাকে না। */
                'under' => $module->nav['under'] ?? null,

                /*
                 * এই টাইলটা যে যে মডিউলের রুট ঢেকে রাখে — নিজেরটা, আর
                 * ভেতরে বসা অতিথিদেরগুলো।
                 *
                 * শেলের আটটা চেহারা "আমি কোথায়" প্রশ্নের শেষ ভরসা হিসেবে
                 * রুটের উপসর্গ দেখে। অতিথির টাইল রেলে নেই, তাই তার কোড
                 * এখানে না থাকলে `customer.portal`-এর মতো পাতায় কোনো
                 * টাইলেই দাগ পড়ত না।
                 */
                'codes' => [$module->code],

                'groups' => $this->inFixedOrder($groups),
            ];
        }

        return $this->inNavOrder($this->settleGuestsIntoTheirHosts($menu));
    }

    /**
     * অতিথি মডিউলের সারিগুলো আশ্রয়দাতার টাইলে বসায়, আর তার নিজের টাইল তুলে নেয়।
     *
     * ── কেন সারি সরে, ঘোষণা নয় ───────────────────────────────────────
     * মালিকের শর্ত ছিল *"ব্যাকএন্ড যেমন আছে তেমন থাকবে"*। তাই
     * `module.php`-র `menu` অ্যারে, অনুমতি, রুট, ড্যাশবোর্ড — কিছুই
     * ছোঁয়া হয় না; কেবল **তৈরি হয়ে যাওয়া** সারিগুলো অন্য টাইলে জোড়া হয়।
     *
     * ফলে অনুমতিও মেশে না: প্রতিটা সারি আগেই তার নিজের `permission`
     * দিয়ে ছাঁকা হয়ে এসেছে। যাঁর বিক্রয় আছে অথচ গ্রাহক নেই, তিনি
     * বিক্রয়ের টাইলে গ্রাহকের একটা সারিও দেখবেন না।
     *
     * ── আশ্রয়দাতাকে না পেলে ──────────────────────────────────────────
     * অতিথি **নিজের টাইলেই** থেকে যায়। আশ্রয়দাতা অনুপস্থিত থাকতে পারে
     * তিনভাবে: কোডটা ভুল, মডিউলটা এই কোম্পানিতে বন্ধ, বা এই
     * ব্যবহারকারীর সেখানে একটাও দৃশ্যমান সারি নেই। তিনটার কোনোটাই
     * "গ্রাহকের পর্দাগুলো আর কোথাও থেকে খোলা যায় না" হওয়ার যোগ্য কারণ নয়।
     *
     * @param  list<array<string, mixed>>  $menu
     * @return list<array<string, mixed>>
     */
    private function settleGuestsIntoTheirHosts(array $menu): array
    {
        $at = [];

        foreach ($menu as $i => $entry) {
            $at[$entry['code']] = $i;
        }

        $absorbed = [];

        foreach ($menu as $i => $entry) {
            if ($entry['under'] === null || ! isset($at[$entry['under']])) {
                continue;
            }

            /*
             * অতিথির আশ্রয়দাতা নিজেও অতিথি হতে পারে। তখন সারিগুলো
             * সবচেয়ে বাইরের টাইলে যাওয়া উচিত, নাহলে ওরা এমন একটা
             * টাইলে বসত যেটা নিজেই তুলে নেওয়া হচ্ছে — অর্থাৎ চুপচাপ
             * হারিয়ে যেত। বৃত্ত হলে (ক ভেতরে খ, খ ভেতরে ক) লুপটা
             * থামে আর দুইজনেই নিজের টাইলে থাকে।
             */
            $host = $at[$entry['under']];
            $seen = [$i => true];

            while ($menu[$host]['under'] !== null && isset($at[$menu[$host]['under']])) {
                if (isset($seen[$host])) {
                    continue 2;
                }

                $seen[$host] = true;
                $host = $at[$menu[$host]['under']];
            }

            if ($host === $i) {
                continue;
            }

            /*
             * আশ্রয়দাতার নিজের সারির **পরে** — মালিকের সিদ্ধান্ত,
             * ৪ সেপ্টেম্বর ২০২৬। রোজকার কাজ কাউন্টারে, তাই ওটাই আগে।
             *
             * কী-তে অতিথির কোড জুড়ে দেওয়া হয় যাতে দুইজনের `master`
             * একে অপরকে চাপা না দেয়। নামটা পর্দায় আসে না — শেল
             * `groups` সমতল করে পড়ে — এটা কেবল ক্রমের বালতি।
             */
            foreach ($entry['groups'] as $group => $rows) {
                $menu[$host]['groups'][$entry['code'].':'.$group] = $rows;
            }

            $menu[$host]['codes'][] = $entry['code'];
            $absorbed[$i] = true;
        }

        return array_values(array_filter(
            $menu,
            static fn (int $i): bool => ! isset($absorbed[$i]),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    /**
     * মডিউলগুলো মালিকের দেওয়া ক্রমে — দল, তারপর দলের ভেতরের নম্বর।
     *
     * ── কেন এখানে সাজানো, রেজিস্ট্রিতে নয় ────────────────────────────
     * [[ModuleRegistry::all()]] নির্ভরতার ক্রমে দেয়, আর সেটা **ঠিকই
     * আছে** — মাইগ্রেশন, ইভেন্ট নিবন্ধন আর বুট ওই ক্রমেই চলতে হয়।
     * ওখানে হাত দিলে মেনু সুন্দর হত আর বুট ভাঙত।
     *
     * তাই সাজানোটা এখানে, একদম শেষে: রেজিস্ট্রি মেশিনের প্রশ্নের উত্তর
     * দেয়, MenuBuilder মানুষের।
     *
     * ── অসম্পূর্ণ দল নিয়ে চিন্তা নেই ─────────────────────────────────
     * অনুমতি বা সুইচের কারণে একটা গোটা দল খালি হয়ে যেতে পারে (যেমন
     * বিক্রয়কর্মী FINANCE-এর কিছুই দেখেন না)। তখন ওই দলের কোনো মডিউলই
     * `$menu`-তে আসে না, তাই শিরোনামটাও আঁকা হয় না — [[shell.sidebar]]
     * শিরোনাম বসায় **যা আছে তার উপর**, আগে থেকে ঠিক করা তালিকার উপর নয়।
     *
     * @param  list<array<string, mixed>>  $menu
     * @return list<array<string, mixed>>
     */
    private function inNavOrder(array $menu): array
    {
        $sections = array_flip(ModuleDefinition::NAV_SECTIONS);

        usort($menu, function (array $a, array $b) use ($sections): int {
            return [$sections[$a['section']], $a['order'], $a['code']]
                <=> [$sections[$b['section']], $b['order'], $b['code']];
        });

        return $menu;
    }

    /**
     * ছয়টা ভাগ সবসময় একই ক্রমে — Dashboard, Master, Transactions,
     * Approval, Reports, Settings।
     *
     * module.php-তে যে ক্রমে লেখা হয়েছে সেটা মানা হয় না ইচ্ছাকৃতভাবে:
     * তাহলে এক মডিউলে Reports উপরে আর অন্যটায় নিচে থাকত, আর "একটা শিখলে
     * সব চেনা" কথাটা মিথ্যা হয়ে যেত।
     *
     * @param  array<string, list<array<string, mixed>>>  $groups
     * @return array<string, list<array<string, mixed>>>
     */
    private function inFixedOrder(array $groups): array
    {
        $ordered = [];

        foreach (ModuleDefinition::MENU_GROUPS as $group) {
            if (isset($groups[$group])) {
                $ordered[$group] = $groups[$group];
            }
        }

        return $ordered;
    }

    /**
     * সারিটা কি এই পর্দার — নাকি এই পর্দার **তালিকার** সারি।
     *
     * ── কী ভাঙা ছিল, ২৯ আগস্ট ২০২৬ ──────────────────────────────────
     * নিয়মটা ছিল কেবল `routeIs($item['route'])`। ফলে তালিকার পাতায় সারি
     * জ্বলত, কিন্তু একটা রেকর্ডে ঢুকলেই নিভে যেত — আর তার সাথে
     * breadcrumb-ও, কারণ পথটা এই `active` পতাকা থেকেই তৈরি হয়
     * ([[shell/crumbbar]])।
     *
     * ব্রাউজারে ধরা পড়ল: `/accounts/vouchers/1`-এ পথ ছিল কেবল
     * "ড্যাশবোর্ড" — মডিউলের নামটাও নেই। অর্থাৎ প্রতিটা রেকর্ড পাতায়
     * ব্যবহারকারী "আমি কোথায়" প্রশ্নের উত্তর হারাতেন, আর "উপরে ফেরা"র
     * লিংকটাও।
     *
     * ── নিয়মটা কেন এত সরু ───────────────────────────────────────────
     * শুধু "একই উপসর্গ" বললে এক পরিবারের দুইটা সারি একসাথে জ্বলত —
     * পণ্যের তালিকা আর লেবেল ছাপা, দুইটাই `inventory.product.*`।
     *
     * তাই দুইটা শর্ত: (১) সারিটা `.index`, অর্থাৎ ওটাই ওই পরিবারের
     * তালিকা, আর (২) এই রুটটা নিজে কোনো মেনু সারি নয় — কারণ তাহলে
     * ওটাই জ্বলা উচিত, তার তালিকা নয়।
     */
    private function onThisScreen(string $route, bool $exact): bool
    {
        if (request()->routeIs($route)) {
            return true;
        }

        if ($exact || ! str_ends_with($route, '.index')) {
            return false;
        }

        return request()->routeIs(Str::beforeLast($route, '.').'.*');
    }

    /**
     * এই অনুরোধের রুটটা কি হুবহু কোনো মেনু সারির রুট।
     *
     * অনুমতি বা সুইচ দেখা হয় না ইচ্ছাকৃতভাবে: প্রশ্নটা "এই পর্দার নিজের
     * সারি আছে কি না", "এই ব্যবহারকারী সেটা দেখতে পান কি না" নয়। নাহলে
     * যে ব্যবহারকারী তালিকা দেখতে পান না, তাঁর কাছে রেকর্ড পাতাটা
     * তালিকার সারি হিসেবে জ্বলত।
     */
    private function aRowOwnsThisRoute(): bool
    {
        foreach ($this->registry->all() as $module) {
            foreach ($module->menu as $items) {
                foreach ($items as $item) {
                    if (isset($item['route']) && request()->routeIs($item['route'])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * এই অনুরোধের রুট প্যারামিটারগুলো মেনু সারির সাথে মেলে কি না।
     *
     * @param  array<string, string|int>  $params
     */
    private function paramsMatch(array $params): bool
    {
        foreach ($params as $key => $value) {
            if ((string) request()->route($key) !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    private function allowed(?User $user, ?string $permission): bool
    {
        if ($permission === null) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $user->can($permission);
    }

    /**
     * মডিউল বন্ধ থাকলে মেনুতে নেই — সেকশন ১৯.৫।
     *
     * সেটিংটা ঐচ্ছিক: যে মডিউল সুইচ ঘোষণা করেনি সেটা সবসময় চালু ধরা হয়।
     */
    private function moduleEnabled(ModuleDefinition $module): bool
    {
        return (bool) $this->settings->get($module->code.'.enabled', true);
    }
}
