<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Module\ModuleDefinition;
use App\Core\Module\ModuleRegistry;
use App\Models\User;
use Illuminate\Support\Facades\Route;

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
                        'active' => request()->routeIs($item['route'])
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
