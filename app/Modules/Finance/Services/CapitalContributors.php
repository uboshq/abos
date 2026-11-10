<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Contracts\KnowsWhereAPersonsMoneyBelongs;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\HandLoanAccount;

/**
 * যিনি আগে মূলধন দিয়েছেন, তাঁর টাকা মূলধনেই ফেরে।
 *
 * ── ⭐ মালিকের কথা, ১৯ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * *"capital theke asle eta auto boslei to valo hoy"* — মূলধনের লোকের
 * আদায় হাতে হাতে প্রাপ্যে বসে যেত, আর কেউ ধরত না।
 *
 * ── ⚠️ কেন কথাটা এখানে, Accounts-এ নয় ───────────────────────────────
 * আগে Accounts নিজে `CapitalEntry` খুঁজত, আর তাতে তীরটা উল্টো হত:
 * Finance নিজের `module.php`-তে লিখে রেখেছে সে accounts চেনে, উল্টোটা
 * নয়। ⛔ Accounts-কে Finance চিনতে হলে Finance বন্ধ করে দিলে ভাউচারের
 * পর্দাই ভাঙত।
 *
 * ⓘ এখন কথাটা যার, সে-ই বলে — ঠিক [[ContributesFacts]]-এর যুক্তিতে:
 * *"শেষ কেনা কবে" গ্রাহকের পাতায় বসে, কিন্তু কথাটা বিক্রয়ের।*
 */
final class CapitalContributors implements KnowsWhereAPersonsMoneyBelongs
{
    public function accountCodeFor(int $personId): ?string
    {
        if ($personId <= 0) {
            return null;
        }

        /*
         * ⚠️ কেবল যাঁদের সত্যিই মূলধনের রেকর্ড আছে — একজন অচেনা ব্যক্তির
         * টাকা আন্দাজে মূলধন বানালে ঠিক সেই ভুলটাই ফিরত যেটা `5e7d508c`
         * সারিয়েছিল।
         */
        return CapitalEntry::query()->where('person_id', $personId)->exists()
            ? StandardChart::OWNER_CAPITAL
            : null;
    }

    /**
     * ⓘ পর্দার জন্য গোটা তালিকাটা একবারে — সারি-প্রতি প্রশ্ন করলে
     * দুইশো মানুষের পিকারে দুইশোটা প্রশ্ন হত।
     *
     * @return list<string>
     */
    public function peopleItKnowsAbout(): array
    {
        return CapitalEntry::query()
            ->whereNotNull('person_id')
            ->distinct()
            ->pluck('person_id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    /**
     * ⭐ রসিদের ফর্মে আগে-বসানো খাত — মূলধনের মানুষ → মূলধন (আগের নিয়ম), চলতি হাতধারের মানুষ → হাতধার (১১৭০; মালিক, ৯ নভেম্বর
     * ২০২৬)। ⓘ দুইটাই হলে মূলধন আগে, আগের মতো। হাতধারের একটাই খাত দুই দিকের ([[StandardChart::HAND_LOAN]]), তাই ফেরত আর নতুন
     * ধার দুইটাই সেখানে।
     *
     * @return array<string, string>
     */
    public function suggestedCodes(): array
    {
        $out = [];

        foreach (HandLoanAccount::query()->whereNotNull('person_id')->where('status', HandLoanAccount::ACTIVE)->pluck('person_id') as $id) {
            $out[(string) $id] = StandardChart::HAND_LOAN;
        }

        foreach ($this->peopleItKnowsAbout() as $id) {
            $out[$id] = StandardChart::OWNER_CAPITAL;
        }

        return $out;
    }
}
