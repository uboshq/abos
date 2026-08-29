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
    ) {}

    /**
     * এই ব্যবহারকারী যা দেখতে পাবে।
     *
     * অনুমতি নেই এমন আইটেম তালিকাতেই আসে না — নিষ্ক্রিয় করে দেখানো হয় না।
     * একটা ধূসর মেনু আইটেম ব্যবহারকারীকে শুধু জানায় সে কী পারে না, আর
     * প্রতিবার ক্লিক করে সেটা আবিষ্কার করে।
     *
     * @return list<array{code: string, label: string, icon: string, groups: array<string, list<array{label: string, route: string, url: ?string, active: bool}>>}>
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
                    // এখনো তৈরি হয়নি এমন স্ক্রিন মেনুতে আসে না।
                    //
                    // আগে আসত — নিষ্ক্রিয় সারি হিসেবে, "৫০০ পাতার চেয়ে ভালো"
                    // যুক্তিতে। কিন্তু ২৯টা সারির মধ্যে ২৯টাই নিষ্ক্রিয় হলে
                    // মেনুটা আর মেনু থাকে না, প্রতিশ্রুতির তালিকা হয়ে যায়।
                    // ব্যবহারকারী ক্লিক করে, কিছু হয় না, আর ভাবে সিস্টেম নষ্ট।
                    //
                    // ঘোষণাটা module.php-তেই থাকে (planned => true), তাই
                    // ক্রমটা হারায় না। ModuleMenuTest দুই দিক থেকেই পাহারা
                    // দেয়: planned নয় অথচ রুট নেই — ভাঙে; planned অথচ রুট
                    // আছে — সেটাও ভাঙে, যাতে পতাকাটা তুলতে ভুল না হয়।
                    if ($item['planned'] ?? false) {
                        continue;
                    }

                    if (! $this->allowed($user, $item['permission'] ?? null)) {
                        continue;
                    }

                    /*
                     * সুইচের পেছনের সারি — বন্ধ থাকলে মেনুতে নেই।
                     *
                     * মডিউল-স্তরের সুইচ (নিয়ম ১৯.৫) পুরো মডিউলটা লুকায়,
                     * কিন্তু কখনো একটা মডিউলের ভেতরে দুই-একটা পর্দাই
                     * ঐচ্ছিক — এক মুদ্রার প্রতিষ্ঠানে "বিনিময় হার"।
                     * সেগুলোর জন্য সারির নিজের সুইচ।
                     *
                     * কোরে কোনো মডিউলের নাম নেই: সুইচের কী-টা module.php
                     * থেকেই আসে, আর কোর শুধু সেটা মেনে চলে (১৯.৭)।
                     */
                    if (isset($item['setting']) && ! $this->settings->get($item['setting'])) {
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
                        'url' => route($item['route'], $params),
                        'active' => $this->onThisScreen($item['route'], $exact)
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
                'groups' => $this->inFixedOrder($groups),
            ];
        }

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
