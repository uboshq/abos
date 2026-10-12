<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Core\Contracts\ProvisionsCompany;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Setting;

/**
 * ⭐ নতুন কোম্পানির বাকির সুইচ — খোলার দিনেই বসানো (মালিকের উত্তর ১, ১১ অক্টোবর ২০২৬; বাকি নিয়ন্ত্রণ ধাপ ২.১)।
 *
 * ⓘ মালিক: *"কোনো বিল মেয়াদ পেরোলেই নতুন বাকি নয়"*। নতুন কোম্পানিতে তাই মেয়াদ পেরোনোর দেয়াল চালু, ছাড়ের দিন ০।
 *
 * ── ⛔ কেন ডিফল্টে নয়, এখানে ────────────────────────────────────────
 * ডিফল্ট **চলতি** কোম্পানিগুলোর উপরেও পড়ে, যাদের কেউ কোনোদিন এই সুইচ ছোঁয়নি — পরদিন সকালে তাদের প্রতিটা মেয়াদ পেরোনো
 * গ্রাহক থেমে যেত। ⭐ নতুন কোম্পানির কোনো "গতকাল" নেই ([[SalesDefaults]]-এর একই যুক্তি)।
 *
 * ⓘ বারবার ডাকা নিরাপদ: সারি আগেই থাকলে (মালিক নিজে বদলেছেন) ছোঁয়া হয় না।
 */
final class CustomerCreditDefaults implements ProvisionsCompany
{
    public const OVERDUE_STOP = 'customer.overdue_stop';

    public function __construct(private readonly SettingsService $settings) {}

    public function provisionCompany(): void
    {
        $already = Setting::query()
            ->where('company_id', CompanyContext::id())
            ->where('key', self::OVERDUE_STOP)
            ->exists();

        if (! $already) {
            $this->settings->set(self::OVERDUE_STOP, true);
        }
    }
}
