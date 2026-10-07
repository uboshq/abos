<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Module\ModuleRegistry;
use Tests\TestCase;

/**
 * এক নাম দুই মেনুতে — ব্যবহারকারী জানেন না কোন দরজায় ঢুকবেন।
 *
 * ── ⛔ কেন এই পাহারাটা লাগল, ১৮ সেপ্টেম্বর ২০২৬ ──────────────────────
 * মালিক দুইটা স্ক্রিনশট পাঠিয়ে জিজ্ঞেস করলেন: *"expance dui jaygay
 * keno?"* — "খরচ" নামটা অর্থের মেনুতেও ছিল, হিসাবের মেনুতেও।
 *
 * ⓘ দুইটা পর্দা সত্যিই আলাদা ছিল — হিসাবেরটা খরচের ভাউচার লেখার
 * তালিকা, অর্থেরটা কোন খাতে কত গেল তার ছবি। ⚠️ কিন্তু মেনুতে নাম এক
 * হওয়ায় সেই পার্থক্যটা কোথাও দেখা যেত না। ব্যবহারকারী একটায় ঢুকে যা
 * খুঁজছিলেন তা না পেয়ে ভাবতেন সিস্টেমে জিনিসটা নেই।
 *
 * ⛔ খোঁজ নিয়ে দেখা গেল খরচ একা নয় — "আদায়" (হিসাব বনাম বিক্রয়),
 * "পরিশোধ" (হিসাব বনাম ক্রয়), "মাল বুঝে নেওয়া" (মজুদ বনাম ক্রয়) —
 * চারটা জোড়া। মালিক একটা চোখে ধরেছিলেন, বাকি তিনটা ধরা পড়েনি।
 *
 * ── ⭐ নিয়মটা কেন "একই রুট হলে ছাড়" ─────────────────────────────────
 * প্রতিটা মডিউলের নিজের "ড্যাশবোর্ড" আছে, আর ওগুলোর নাম এক থাকাই ঠিক —
 * ওরা একই রুট (`module.dashboard`), শুধু প্যারামিটার আলাদা, আর
 * ব্যবহারকারী ওটাকে নিজের মডিউলের বারেই দেখেন। ⓘ অর্থাৎ ভুলটা নাম
 * এক হওয়া নয়, **এক নামে দুই ভিন্ন গন্তব্য** হওয়া।
 *
 * ⚠️ নতুন কোনো মেনু সারি যোগ করার সময় এই পরীক্ষা ভাঙলে নামটা বদলান,
 * পরীক্ষাটা নয় — মালিকের প্রশ্নটা আবার ফিরে আসবে না।
 */
final class OneNameInTwoMenusAndNobodyKnowsWhichDoorTest extends TestCase
{
    /** কোনো বাংলা মেনু-নাম দুইটা আলাদা রুটে যায় না। */
    public function test_no_bengali_menu_name_leads_to_two_different_screens(): void
    {
        $this->assertNoNameIsShared('bn');
    }

    /** ইংরেজিতেও একই নিয়ম — দুই ভাষায় দুই রকম বিভ্রান্তি হয় না। */
    public function test_no_english_menu_name_leads_to_two_different_screens(): void
    {
        $this->assertNoNameIsShared('en');
    }

    private function assertNoNameIsShared(string $locale): void
    {
        /** @var array<string, array<string, string>> $byName */
        $byName = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->menu as $group => $rows) {
                foreach ($rows as $row) {
                    if (! isset($row['label'], $row['route'])) {
                        continue;
                    }

                    $name = __($row['label'], [], $locale);

                    // ⓘ গন্তব্য = রুটের নাম। একই রুটের ভিন্ন প্যারামিটার
                    //    (যেমন প্রতি মডিউলের ড্যাশবোর্ড) এক গন্তব্যই।
                    $byName[$name][$row['route']] = "{$module->code}/{$group} → {$row['route']}";
                }
            }
        }

        $clashes = [];

        foreach ($byName as $name => $targets) {
            if (count($targets) > 1) {
                $clashes[] = "  \"{$name}\" →\n    ".implode("\n    ", $targets);
            }
        }

        $this->assertSame([], $clashes, sprintf(
            "[%s] এক নাম, একাধিক গন্তব্য — ব্যবহারকারী জানবেন না কোনটায় ঢুকবেন:\n%s",
            $locale,
            implode("\n", $clashes)
        ));
    }
}
