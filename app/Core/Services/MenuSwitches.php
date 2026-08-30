<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Module\ModuleDefinition;
use App\Core\Module\ModuleRegistry;

/**
 * প্রতিটা মডিউল, প্রতিটা গ্রুপ, প্রতিটা মেনু সারির নিজের সুইচ।
 *
 * ── কেন এটা লাগল, ৩০ আগস্ট ২০২৬ ──────────────────────────────────────
 * মালিকের কথা: *"সব কটা মডিউল অন-অফ করা যাবে, মডিউলের ভিতরে সাবমডিউল,
 * সব মেনু অন-অফ করা যাবে, সাব মেনুও।"*
 *
 * আগে সুইচ পেত কেবল সেই সারিগুলো যারা `module.php`-তে নিজে একটা
 * `setting` ঘোষণা করেছিল — একশোর বেশি সারির মধ্যে হাতেগোনা কয়েকটা।
 * বাকিগুলো বন্ধ করার কোনো উপায় ছিল না।
 *
 * ── কেন প্রতিটা সারির জন্য module.php-তে সুইচ লেখা হয়নি ──────────────
 * তাহলে একশোর বেশি ঘোষণা হাতে লিখতে হত, আর ১০১তম পর্দাটার দিন কেউ
 * ভুলত — আর ভুলটা কিছুই দেখাত না, সারিটা শুধু চিরকাল চালু থেকে যেত।
 *
 * সারিগুলো ইতিমধ্যেই ঘোষিত (রুট, লেবেল, গ্রুপ)। কী-টা তাই **নিয়ম ধরে
 * বানানো হয়**, ঘোষণা ধরে নয়:
 *
 *   মডিউল      `<code>.enabled`
 *   গ্রুপ       `menu.<code>.<group>`
 *   সারি        `menu.<route>` — প্যারামিটার থাকলে `menu.<route>:k=v`
 *
 * ── কেন মডিউল নিজের ঘোষণাও রয়ে গেল ──────────────────────────────────
 * কিছু সারিতে ঘোষিত সুইচ আছে যেটা অন্য কিছুও নিয়ন্ত্রণ করে (এক মুদ্রার
 * প্রতিষ্ঠানে "বিনিময় হার")। ঘোষিতটা পেলে সেটাই মানা হয়; নাহলে নিয়মের
 * কী। দুইটা থাকলে একটা সারির দুইটা সুইচ হত, আর কোনটা আসল তা বলা যেত না।
 *
 * কোরে কোনো মডিউলের নাম নেই (§১৯.৭) — সবটাই রেজিস্ট্রি থেকে।
 */
final class MenuSwitches
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    /** মডিউলটা চালু কি না, তার সুইচের কী। */
    public function forModule(string $code): string
    {
        return $code.'.enabled';
    }

    /** একটা মেনু গ্রুপের (সাবমডিউল) সুইচের কী। */
    public function forGroup(string $moduleCode, string $group): string
    {
        return 'menu.'.$moduleCode.'.'.$group;
    }

    /**
     * একটা মেনু সারির সুইচের কী।
     *
     * ── কেন প্যারামিটার কী-তে ঢোকে ──────────────────────────────────
     * পাঁচ ধরনের ভাউচার একই রুটের পাঁচটা সারি
     * (`accounts.voucher.index` + `type`)। প্যারামিটার বাদ দিলে একটা
     * বন্ধ করলে পাঁচটাই বন্ধ হত।
     *
     * @param  array<string, mixed>  $item  module.php-এর মেনু সারি
     */
    public function forItem(array $item): string
    {
        /*
         * মডিউল নিজে সুইচ ঘোষণা করে থাকলে সেটাই — নিয়মের কী নয়।
         *
         * ঘোষিত সুইচ প্রায়ই একটা সারির চেয়ে বড় কিছু নিয়ন্ত্রণ করে,
         * আর দুইটা কী থাকলে ব্যবহারকারী একটা বন্ধ করে অবাক হতেন যে
         * পর্দাটা তবু খোলে।
         */
        if (isset($item['setting'])) {
            return (string) $item['setting'];
        }

        $key = 'menu.'.$item['route'];

        foreach ($item['route_params'] ?? [] as $name => $value) {
            $key .= ':'.$name.'='.$value;
        }

        return $key;
    }

    /**
     * ছকের জন্য পুরো গাছটা — মডিউল › গ্রুপ › সারি, প্রতিটার কী সহ।
     *
     * ── কেন কোরে, নিয়ন্ত্রকে নয় ─────────────────────────────────────
     * তিন জায়গায় একই গাছ লাগে: কন্ট্রোল প্যানেলের ছক, মেনু আঁকা, আর
     * বন্ধ পর্দায় ৪০৪। তিনবার লিখলে একদিন একটা বদলাত অন্য দুইটা নয়,
     * আর তখন মেনুতে লুকানো একটা পর্দা ঠিকানা দিয়ে খুলে যেত — ঠিক যে
     * ভুলটা ১৩ আগস্ট HP ধরেছিল।
     *
     * @return list<array{
     *   code: string, label: string, key: string,
     *   groups: list<array{name: string, key: string, items: list<array{label: string, route: string, key: string}>}>
     * }>
     */
    public function tree(): array
    {
        $out = [];

        foreach ($this->registry->all() as $module) {
            $groups = [];

            foreach ($module->menu as $group => $items) {
                $rows = [];

                foreach ($items as $item) {
                    /*
                     * এখনো বানানো হয়নি এমন সারির সুইচ দেখানো হয় না।
                     *
                     * ওগুলো মেনুতেও আসে না ([[MenuBuilder]]), তাই সুইচটা
                     * এমন কিছু বন্ধ করত যা কেউ কোনোদিন দেখেনি।
                     */
                    if ($item['planned'] ?? false) {
                        continue;
                    }

                    $rows[] = [
                        'label' => (string) $item['label'],
                        'route' => (string) $item['route'],
                        'key' => $this->forItem($item),
                    ];
                }

                if ($rows === []) {
                    continue;
                }

                $groups[] = [
                    'name' => (string) $group,
                    'key' => $this->forGroup($module->code, (string) $group),
                    'items' => $rows,
                ];
            }

            if ($groups === []) {
                continue;
            }

            $out[] = [
                'code' => $module->code,
                'label' => $module->label(),
                'key' => $this->forModule($module->code),
                'groups' => $groups,
            ];
        }

        return $out;
    }

    /**
     * এই মডিউলের এই সারিটা কি চালু — তিনটা স্তরই দেখে।
     *
     * ── কেন উপরের স্তর নিচেরটাকে হারায় ──────────────────────────────
     * মডিউল বন্ধ থাকলে তার ভেতরের সারি চালু থাকার কোনো মানে নেই। উল্টো
     * নিয়ম করলে "মডিউলটা বন্ধ করেছি, তবু পর্দাটা খোলে" — আর তখন
     * মডিউলের সুইচটাই মিথ্যা।
     *
     * @param  array<string, mixed>  $item
     */
    public function itemIsOn(SettingsService $settings, ModuleDefinition $module, string $group, array $item): bool
    {
        if (! $settings->get($this->forModule($module->code), true)) {
            return false;
        }

        if (! $settings->get($this->forGroup($module->code, $group), true)) {
            return false;
        }

        return (bool) $settings->get($this->forItem($item), true);
    }
}
