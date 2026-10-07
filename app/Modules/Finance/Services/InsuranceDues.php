<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Models\InsurancePremium;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ⭐ বীমার সতর্কতার হিসাব — অর্থ-মডিউলের পরিকল্পনা ৬.৫, ৬ অক্টোবর ২০২৬: মেয়াদ শেষের ৩০ দিন আগে নবায়ন, আর বাকি প্রিমিয়াম
 * (দিন পার, বা সামনের সাত দিনে)। ঘণ্টা ([[DueNotices]]) আর অর্থের ড্যাশবোর্ড দুটোই এখান থেকে পড়ে, তাই দুই জায়গা কখনো আলাদা
 * কথা বলে না — [[RentalDues]]-এর একই ধাঁচ।
 *
 * ⓘ নবায়নের নিয়ম পলিসির নিজের ([[InsurancePolicy::scopeDueForRenewal()]], ৩০ দিন — বীমার তালিকার "নবায়ন" ট্যাবও সেটাই
 * পড়ে)। প্রিমিয়ামের দিন = তার সময়কালের শুরু (`period_from`); "বাকি" মানে খসড়া, অর্থাৎ এখনো ভাউচারে মেটেনি।
 */
final class InsuranceDues
{
    /** বাকি প্রিমিয়ামের তাগাদা কত দিন আগে থেকে */
    public const PREMIUM_DAYS = 7;

    /**
     * যে চালু পলিসির মেয়াদ ৩০ দিনের ভিতরে শেষ, বা পেরিয়ে গেছে — শেষের দিন ধরে।
     * ⓘ `$inView` — হেডারে বাছা শাখা (ড্যাশবোর্ড); ঘণ্টা গোটা কোম্পানি ধরে।
     *
     * @return Collection<int, InsurancePolicy>
     */
    public function renewals(bool $inView = false, ?Carbon $today = null): Collection
    {
        return InsurancePolicy::query()->when($inView, fn ($q) => $q->inViewedBranch())
            ->dueForRenewal($today)->with('institution')->orderBy('ends_on')->orderBy('id')->get();
    }

    /**
     * বাকি প্রিমিয়াম — খসড়া, চালু পলিসির, যার দিন আজ পেরিয়েছে বা সামনের সাত দিনে।
     *
     * @return Collection<int, InsurancePremium>
     */
    public function premiumsDue(bool $inView = false, ?Carbon $today = null): Collection
    {
        $until = ($today ?? Carbon::today())->copy()->addDays(self::PREMIUM_DAYS)->toDateString();

        return InsurancePremium::query()
            ->where('status', InsurancePremium::DRAFT)
            ->whereNull('voucher_id')
            ->where('period_from', '<=', $until)
            ->whereHas('policy', fn ($p) => $p->active()->when($inView, fn ($q) => $q->inViewedBranch()))
            ->with('policy')
            ->orderBy('period_from')->orderBy('id')
            ->get();
    }
}
