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
                    if (! $this->allowed($user, $item['permission'] ?? null)) {
                        continue;
                    }

                    $visible[] = [
                        'label' => __($item['label']),
                        'route' => $item['route'],
                        // রুটটা এখনো তৈরি হয়নি এমন হতে পারে — মডিউল ধাপে ধাপে
                        // তৈরি হয়। তখন আইটেমটা দেখাবে কিন্তু নিষ্ক্রিয় থাকবে,
                        // কারণ একটা ৫০০ পাতার চেয়ে সেটা ভালো।
                        'url' => Route::has($item['route']) ? route($item['route']) : null,
                        'active' => Route::has($item['route']) && request()->routeIs($item['route']),
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
