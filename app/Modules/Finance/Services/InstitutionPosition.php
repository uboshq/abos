<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Support\ViewedBranch;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InstitutionAccount;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Models\InsurancePremium;

/**
 * ⭐ একটা আর্থিক প্রতিষ্ঠানের সাথে আমাদের পুরো ছবি — অর্থ-মডিউলের পরিকল্পনা ৭, ৬ অক্টোবর ২০২৬: "সব হিসাব, ঋণ, আমানত, বীমা,
 * গ্যারান্টি এক পাতায়"; "DBBL-এ মোট আমানত ৳…, মোট ঋণ ৳…, নিট ৳…"।
 *
 * ── ⭐ নিজের হিসাব নেই ───────────────────────────────────────────────────────
 *   · হিসাবের জের — জোড়া দেওয়া ব্যাংক/MFS খাতের খতিয়ানের জের (প্রতিষ্ঠানের পাতার একই সংখ্যা, হেডারে বাছা শাখায়)
 *   · আমানত — চালু আমানতের আসল ([[Deposit::scopeOpen()]])
 *   · ঋণের বাকি — [[BankFacilityService::standing()]], ঋণের পাতা যা বলে
 *   · গ্যারান্টি — চালু গ্যারান্টির সীমা; সম্ভাব্য দায়, তাই নিটে নয়, আলাদা লাইনে
 *   · বীমা — চালু পলিসির বীমার অঙ্ক আর বাকি প্রিমিয়াম; তথ্য, নিটে নয়
 * নিট = (হিসাবের জের + আমানত) − ঋণের বাকি — ধনাত্মক মানে প্রতিষ্ঠানের কাছে আমাদের বেশি।
 *
 * ⚠️ পুরনো সারিতে `institution_id` ফাঁকা থাকতে পারে (হাতে লেখা নাম) — সেগুলো কোনো প্রতিষ্ঠানে যোগ হয় না; অনুমান করে
 * যোগ করলে ভুল ব্যাংকের টাকা দেখাত।
 */
final class InstitutionPosition
{
    public function __construct(private readonly BankFacilityService $facilities) {}

    /**
     * @return array{accounts: string, deposits: string, held: string, loans: string, guarantees: string,
     *     sum_insured: string, premiums_due: string, net: string}
     */
    public function of(Institution $institution): array
    {
        $accounts = InstitutionAccount::query()->where('institution_id', $institution->id)->with('account')->get()
            ->filter(fn (InstitutionAccount $l) => $l->account !== null)
            ->reduce(fn (string $sum, InstitutionAccount $l) => bcadd($sum, (string) $l->account->balanceOn(now()->toDateString(), ViewedBranch::one()), 4), '0');

        $deposits = bcadd((string) Deposit::query()->open()->where('institution_id', $institution->id)->sum('principal'), '0', 4);

        $live = BankFacility::query()->live()->where('institution_id', $institution->id)->get();
        $loans = $live->where('kind', '!=', BankFacility::GUARANTEE)->values();
        $standing = $loans->isEmpty() ? [] : $this->facilities->standing($loans);
        $owed = array_reduce($standing, fn (string $sum, array $s) => bcadd($sum, (string) $s['used'], 4), '0');
        $guarantees = $live->where('kind', BankFacility::GUARANTEE)
            ->reduce(fn (string $sum, BankFacility $f) => bcadd($sum, (string) $f->limit_amount, 4), '0');

        $policies = InsurancePolicy::query()->active()->where('institution_id', $institution->id);
        $sumInsured = bcadd((string) (clone $policies)->sum('sum_insured'), '0', 4);
        $premiumsDue = bcadd((string) InsurancePremium::query()
            ->where('status', InsurancePremium::DRAFT)
            ->whereIn('policy_id', (clone $policies)->select('id'))
            ->sum('amount'), '0', 4);

        $held = bcadd(bcadd($accounts, '0', 4), $deposits, 4);

        return [
            'accounts' => bcadd($accounts, '0', 4),
            'deposits' => $deposits,
            'held' => $held,
            'loans' => bcadd($owed, '0', 4),
            'guarantees' => bcadd($guarantees, '0', 4),
            'sum_insured' => $sumInsured,
            'premiums_due' => $premiumsDue,
            'net' => bcsub($held, $owed, 4),
        ];
    }

    /** ঋণ ধরে বাকি — প্রতিষ্ঠানের পাতার ঋণের তালিকায় [[of()]]-এর একই উৎস @return array<int, string> */
    public function owedByFacility(Institution $institution): array
    {
        $loans = BankFacility::query()->live()->where('institution_id', $institution->id)
            ->where('kind', '!=', BankFacility::GUARANTEE)->get();

        return array_map(fn (array $s) => (string) $s['used'], $loans->isEmpty() ? [] : $this->facilities->standing($loans));
    }
}
