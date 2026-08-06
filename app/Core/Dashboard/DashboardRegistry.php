<?php

declare(strict_types=1);

namespace App\Core\Dashboard;

use App\Core\Module\ModuleRegistry;
use App\Models\User;

/**
 * হোম পর্দার সংখ্যাগুলো জোগাড় করা — মডিউলদের জিজ্ঞেস করে।
 *
 * কোর এখানে কোনো মডিউলের নাম জানে না; কেবল রেজিস্ট্রি হেঁটে যারা
 * উইজেট ঘোষণা করেছে তাদের ডাকে (সেকশন ১৯.৭)।
 */
class DashboardRegistry
{
    public function __construct(private readonly ModuleRegistry $modules) {}

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

        foreach ($this->modules->all() as $module) {
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
}
