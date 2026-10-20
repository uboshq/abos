<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Widgets;

use App\Core\Services\PartyRegistry;
use App\Core\Widgets\WidgetSummary;
use App\Models\User;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Customer\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ⭐ ১৩ · চেকের দিন — আজ ব্যাংকে জমা দিতে হবে এমন হাতের চেক, আর সাত দিনের মধ্যে ফেরত আসা চেক (উইজেটের নকশা ১৯ অক্টোবর,
 * পাতা ২; মালিকের আদেশ, ২০ অক্টোবর ২০২৬; cb)।
 *
 * ⓘ "জমা দিতে হবে" চেকের খাতার রিপোর্টের `status=due`-এর হুবহু ([[ChequeReports]]): পাওয়া চেক, এখনো হাতে (`pending`), তারিখ
 * আজ বা আগে। মোট সব চেকের, সারি সর্বোচ্চ ৮টা — ফেরত চেক আগে (গ্রাহকের খাতায় আবার বাকি, সবচেয়ে জরুরি)।
 * ⓘ শাখার দেয়াল মডেলের নিজের ([[ScopedToUserBranch]]); চাবি চেকের পাতার একই (`accounts.cheque.view`)।
 * ⓘ ক্লাসটা বাকি নিয়ন্ত্রণে, হিসাবে নয়: গ্রাহকের নাম "নাম · পয়েন্ট · শাখা" বানাতে গ্রাহক মডিউল লাগে, আর হিসাব গ্রাহককে চেনে
 * না ([[BoundariesTest]] — গ্রাহক হিসাবের উপর দাঁড়ায়)। ফোনের সুইচ তবু হিসাবের (`accounts`), চেক হিসাবের পাতা।
 * ⓘ ফেরতের আলাদা তারিখ-ঘর নেই, তাই "সাত দিনের মধ্যে" মানে শেষ বদলের দিন (`updated_at`) — ফেরতের পরে চেক আর বদলায় না।
 */
final class ChequesSummary implements WidgetSummary
{
    public const BOUNCED_DAYS = 7;

    public function key(): string
    {
        return 'cheques';
    }

    public function module(): string
    {
        return 'accounts';
    }

    public function permission(): string
    {
        return 'accounts.cheque.view';
    }

    public function data(User $user): array
    {
        $today = Carbon::today();

        $due = Cheque::query()
            ->where('direction', Cheque::RECEIVED)
            ->where('status', Cheque::PENDING)
            ->where('cheque_date', '<=', $today->toDateString())
            ->orderBy('cheque_date')->orderBy('id')
            ->get();

        $bounced = Cheque::query()
            ->where('direction', Cheque::RECEIVED)
            ->where('status', Cheque::BOUNCED)
            ->where('updated_at', '>=', $today->copy()->subDays(self::BOUNCED_DAYS))
            ->latest('updated_at')
            ->get();

        $shown = $bounced->concat($due)->take(8)->values();
        $labels = self::labels($shown);

        return [
            'total' => bcadd((string) $due->sum('amount'), '0', 4),
            'count' => $due->count() + $bounced->count(),
            'rows' => $shown->map(fn (Cheque $c) => [
                'name' => $labels[(string) $c->party_type.':'.$c->party_id] ?? '',
                'bank' => (string) $c->bank_name,
                'no' => (string) $c->cheque_no,
                'date' => $c->cheque_date?->toDateString(),
                'amount' => bcadd((string) $c->amount, '0', 4),
                'state' => $c->status === Cheque::BOUNCED ? 'bounced' : 'due',
                'link' => '/accounts/cheques/'.$c->id,
            ])->all(),
            'link' => '/accounts/cheques',
        ];
    }

    /**
     * গ্রাহক হলে "নাম · পয়েন্ট · শাখা" ([[CreditLimitsSummary::label()]]); অন্য পক্ষ হলে পক্ষের খাতার নাম ([[PartyRegistry::labelsOf()]])
     *
     * @param  Collection<int, Cheque>  $cheques
     * @return array<string, string>
     */
    private static function labels(Collection $cheques): array
    {
        $out = [];
        $customerIds = $cheques->where('party_type', 'customer')->pluck('party_id')->unique()->all();

        foreach (Customer::query()->whereKey($customerIds)->with(['location.parent', 'branch'])->get() as $customer) {
            $out['customer:'.$customer->id] = CreditLimitsSummary::label($customer);
        }

        $others = $cheques->reject(fn (Cheque $c) => $c->party_type === 'customer')
            ->map(fn (Cheque $c) => [$c->party_type, $c->party_id])->all();

        return $out + app(PartyRegistry::class)->labelsOf($others);
    }
}
