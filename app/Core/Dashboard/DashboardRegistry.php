<?php

declare(strict_types=1);

namespace App\Core\Dashboard;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuSwitches;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\BranchModule;
use App\Models\User;

/**
 * হোম পর্দার সংখ্যাগুলো জোগাড় করা — মডিউলদের জিজ্ঞেস করে।
 *
 * কোর এখানে কোনো মডিউলের নাম জানে না; কেবল রেজিস্ট্রি হেঁটে যারা
 * উইজেট ঘোষণা করেছে তাদের ডাকে (সেকশন ১৯.৭)।
 */
class DashboardRegistry
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly MenuSwitches $switches,
        private readonly SettingsService $settings,
    ) {}

    /**
     * এই কোম্পানি ও শাখায় চালু মডিউলগুলো — বন্ধ মডিউলের ঘর হোমে আসে না।
     *
     * ⛔ আগে কেবল অনুমতি দেখা হত: HR বন্ধ করা কোম্পানির হোমে "আজ উপস্থিত
     * ০ / ৫" আর ছুটির অপেক্ষা, আর ক্লিকে ৪০৪। মেনুর নিয়মটাই এখানে
     * ([[MenuSwitches::moduleIsOn()]]) — ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬।
     *
     * @return list<\App\Core\Module\ModuleDefinition>
     */
    private function modulesSwitchedOn(): array
    {
        $switchedOff = BranchModule::switchedOffIn(CompanyContext::branchId());

        return array_values(array_filter(
            $this->modules->all(),
            fn ($module): bool => $this->switches->moduleIsOn($this->settings, $module, $switchedOff),
        ));
    }

    /**
     * এই ব্যবহারকারী যা দেখতে পারেন — দল ধরে সাজানো।
     *
     * ── কেন অনুমতি এখানে ছাঁকা হয়, প্রতিটা মডিউলে নয় ────────────────
     * প্রতিটা সরবরাহকারীকে নিজে ছাঁকতে বললে উনিশতমটা একদিন ভুলত, আর
     * তখন ডেলিভারি ম্যান হোম পর্দায় আজকের মোট বিক্রয় দেখত। উইজেট শুধু
     * ঘোষণা করে কোন চাবি লাগে; চাবি মেলানো এক জায়গাতেই।
     *
     * @return array<string, list<Widget>>
     */
    public function forUser(?User $user): array
    {
        $grouped = array_fill_keys(Widget::GROUPS, []);

        foreach ($this->modulesSwitchedOn() as $module) {
            foreach ($module->widgets as $provider) {
                /*
                 * সরবরাহকারী ব্যতিক্রম ছুঁড়লে পাতাটা ভাঙবে — চাপা দেওয়া
                 * হয় না।
                 *
                 * একটা ভাঙা উইজেট নীরবে বাদ দিলে হোম পর্দা ঠিকই খুলত,
                 * শুধু একটা সংখ্যা থাকত না — আর যে সংখ্যাটা কখনো ছিল
                 * সেটার অনুপস্থিতি কেউ খেয়াল করে না। ভুল সংখ্যার চেয়ে
                 * নিখোঁজ সংখ্যা কম বিপজ্জনক নয়।
                 */
                foreach ($provider::widgets() as $widget) {
                    if ($user?->can($widget->permission) !== true) {
                        continue;
                    }

                    $grouped[$widget->group][] = $widget;
                }
            }
        }

        foreach ($grouped as $group => $widgets) {
            usort($widgets, fn (Widget $a, Widget $b) => [$a->sort, $a->label] <=> [$b->sort, $b->label]);
            $grouped[$group] = $widgets;
        }

        return $grouped;
    }

    /**
     * ⭐ মাস্টার তালিকার স্বাস্থ্য — যে মডিউল `health()` দেয় (মালিকের নকশা §১২, ৪ অক্টোবর ২০২৬; [[MasterHealth]])।
     *
     * ⓘ আলাদা ডাক, `widgets()` নয় — হোম প্রতিবার খোলায় পাঁচটা তালিকা গোনার দরকার নেই। চাবি মেলানো এখানেই, যেমন উপরে।
     *
     * @return list<Widget>
     */
    public function health(?User $user): array
    {
        $out = [];

        foreach ($this->modulesSwitchedOn() as $module) {
            foreach ($module->widgets as $provider) {
                if (! method_exists($provider, 'health')) {
                    continue;
                }

                foreach ($provider::health() as $widget) {
                    if ($user?->can($widget->permission) === true) {
                        $out[] = $widget;
                    }
                }
            }
        }

        usort($out, fn (Widget $a, Widget $b) => [$a->sort, $a->label] <=> [$b->sort, $b->label]);

        return $out;
    }
}
