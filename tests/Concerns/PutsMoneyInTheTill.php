<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Core\Engines\Posting\PostingEngine;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;

/**
 * টিলে বা ওয়ালেটে টাকা রাখা — পরীক্ষার আগে, খাতার নিয়ম মেনে।
 *
 * ── কেন লাগল, ২৭ সেপ্টেম্বর ২০২৬ ───────────────────────────────────────
 * নগদ আর মোবাইল ব্যাংকিংয়ের খাত এখন শূন্যের নিচে নামে না ([[CashOnHand]],
 * লাইভ QA-র PMT-0001)। ⚠️ DemoSeeder কোনো টিলে টাকা বসায় না, তাই যে
 * পরীক্ষাগুলো খালি টিল থেকে পরিশোধ করত, সেগুলো এখন ঠিক কারণেই আটকায়।
 *
 * ⭐ টাকা আসে মালিকের পুঁজি থেকে (Dr খাত / Cr ৩১০০), PostingEngine দিয়ে —
 * সরাসরি টেবিলে লেখা নয়। তাই খাতা মেলে, আর পাঁচ মিলের হিসাব অক্ষত থাকে।
 * ⓘ TestCase ছোঁয়া হয়নি (নিয়ম ২ক): যে পরীক্ষার টাকা লাগে সে নিজে এটা নেয়।
 */
trait PutsMoneyInTheTill
{
    /**
     * `$account`-এ `$amount` টাকা, `$date` তারিখে (না দিলে আজ)।
     *
     * ⚠️ পিছনের তারিখের পরিশোধের আগে টাকা রাখতে হলে সেই তারিখ বা তার আগেরটা
     * দিন — নাহলে পাহারা ঠিকই বলবে সেদিন টাকা ছিল না।
     */
    protected function putMoneyIn(Account $account, string $amount, ?string $date = null): void
    {
        $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();

        app(PostingEngine::class)->post(
            sourceType: 'test:money-in',
            sourceId: random_int(1, PHP_INT_MAX),
            trxDate: $date ?? now()->toDateString(),
            lines: [
                ['account_id' => $account->getKey(), 'debit' => $amount, 'narration' => 'পরীক্ষার টাকা'],
                ['account_id' => $capital->getKey(), 'credit' => $amount, 'narration' => 'পরীক্ষার টাকা'],
            ],
        );
    }
}
