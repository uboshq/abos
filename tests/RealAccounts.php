<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;

/**
 * পরীক্ষায় খাতগুলো — **কোড ধরে**, আইডি হার্ডকোড করে নয়।
 *
 * ── ⛔ কী ভাঙা ছিল, ৬ সেপ্টেম্বর ২০২৬ ────────────────────────────────
 * আটটা টেস্ট ক্লাসে `['account_id' => 1101, ...]` জাতীয় লাইন ছিল, যেন
 * ওগুলো আইডি। ⚠️ মেপে দেখা গেল **তিনটাই ভুল**:
 *
 *     1101  →  আসল আইডি ৩১২৭, আর সেটা একটা **গ্রুপ** (হাতে নগদ)
 *     4001  →  এই চার্টে **নেই** (বিক্রয় ৪১০০)
 *     2201  →  এই চার্টে **নেই** (ভ্যাট প্রদেয় ২১২০)
 *     10/20 →  কোনো অর্থই নেই, নিছক বসিয়ে দেওয়া সংখ্যা
 *
 * ⓘ পূর্ণ সুইটে এর ফল ছিল **২৫টা ত্রুটি**, সব একই বাক্যে:
 * *"points at account 1101, which does not exist in this company"*।
 *
 * ── ⚠️ কেন এটা টেস্টের দোষ, কোডের নয় ────────────────────────────────
 * পোস্টিং ইঞ্জিন ঠিকই কাজ করছে — সে অস্তিত্বহীন খাত প্রত্যাখ্যান করে,
 * আর গ্রুপে বসাতে দেয় না। ⭐ **সে ঠিক যা করার কথা তাই করছে**; টেস্টগুলোই
 * এমন এক চার্টের কথা ধরে বসে ছিল যেটা আর নেই।
 *
 * ── ⭐ কেন একটা সাধারণ জায়গা ─────────────────────────────────────────
 * আটটা ফাইলে আলাদা করে লুকআপ লিখলে পরেরবার চার্ট বদলালে **আটটা জায়গায়**
 * ঠিক করতে হত, আর কেউ একটা ভুলে যেত। ⓘ এখানে একবার — আর চার্ট নিজের
 * ধ্রুবক বদলালে সুইট **নিজে থেকেই** নতুন কোডে চলে।
 *
 * ⚠️ CLAUDE.md-তে ফাঁদটা নাম ধরে লেখা: *"`CASH_IN_HAND`='1101' একটা
 * **গ্রুপ** — টাকা বসে till-এর সন্তানে, `CashTillService::
 * ensurePrimaryTill()` দিয়ে।"*
 */
trait RealAccounts
{
    /**
     * নগদের খাত — till-এর সন্তান, গ্রুপ নয়।
     *
     * ⚠️ `StandardChart::CASH_IN_HAND` ('1101') সরাসরি ব্যবহার করা যাবে না:
     * সে একটা গ্রুপ, আর গ্রুপে দাখিলা বসে না। ⓘ প্রতিটা কোম্পানির একটা
     * প্রাথমিক till থাকে, আর টাকা বসে **তার** খাতে।
     */
    protected function cashAccountId(): int
    {
        return (int) app(CashTillService::class)->ensurePrimaryTill()->account->id;
    }

    /** বিক্রয়ের খাত — ৪১০০, আগের `4001` নয়। */
    protected function salesAccountId(): int
    {
        return $this->accountByCode(StandardChart::SALES);
    }

    /** ভ্যাট প্রদেয় — ২১২০, আগের `2201` নয়। */
    protected function vatAccountId(): int
    {
        return $this->accountByCode(StandardChart::VAT_PAYABLE);
    }

    /** পাওনা — গ্রাহকের কাছে যা পাব। */
    protected function receivableAccountId(): int
    {
        return $this->accountByCode(StandardChart::RECEIVABLE);
    }

    /**
     * কোড ধরে খাত — না পেলে **থেমে যাওয়া**, চুপ করে ০ ফেরানো নয়।
     *
     * ⓘ `firstOrFail()` ইচ্ছাকৃত: চার্ট থেকে কোনো কোড উধাও হলে সেটা
     * এখানেই নাম ধরে ধরা পড়বে। ⚠️ `first()?->id ?? 0` লিখলে টেস্টটা
     * চলতে থাকত আর ব্যর্থ হত অনেক পরে, একটা অর্থহীন বার্তা নিয়ে —
     * ঠিক যে জিনিসটা আজ ২৫টা ত্রুটির কারণ ছিল।
     */
    protected function accountByCode(string $code): int
    {
        return (int) Account::query()->where('code', $code)->firstOrFail()->id;
    }
}
