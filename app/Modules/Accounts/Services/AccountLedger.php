<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Support\ViewedBranch;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * ⭐ একটা খাতের খাতা আর ছকের জের — ওয়েবের পাতা ([[ChartOfAccountsController]]) আর ফোনের দরজা
 * ([[AccountLedgerApiController]]) দুটোই এখান থেকে পড়ে (মালিক, ১৭ অক্টোবর ২০২৬: "mobile app style e")।
 *
 * ⛔ দুই জায়গায় দুই হিসাব লিখলে একদিন ফোনের শেষ জের একটা আর ওয়েবেরটা আরেকটা দেখাত — তাই এক সেবা।
 * ⓘ কেবল পড়া; খতিয়ানে কিছু লেখা হয় না, পোস্টিংয়ের কোনো নিয়ম এখানে নেই।
 */
final class AccountLedger
{
    /** পাতায় সারি — ওয়েব আর ফোন একই */
    public const PER_PAGE = 50;

    /**
     * এক পাতার সারি (চলমান জেরসহ), আর সব পাতার ডেবিট, ক্রেডিট ও শেষ জের — একই শাখায়।
     *
     * ⭐ শাখা হেডারে বাছা শাখাই — সেবা নিজে পড়ে, ডাকা জায়গা ভুলতে পারে না (ওয়েব আর ফোন দুটোই; [[EveryLedgerReaderSaysWhetherItShowsOrChecksTest]])।
     * ⓘ "সব শাখা"-তে null = গোটা কোম্পানি ([[ViewedBranch::one()]])।
     *
     * @return array{entries: LengthAwarePaginator, grand: array{debit: string, credit: string}, closing: string}
     */
    public function page(Account $account, int $page): array
    {
        $branch = ViewedBranch::one();
        $page = max(1, $page);

        $entries = LedgerEntry::query()
            ->forAccount($account->id)
            ->when($branch, fn ($q, int $b) => $q->where('branch_id', $b))
            ->orderBy('trx_date')
            ->orderBy('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page);

        /*
         * চলমান জের শূন্য থেকে শুরু।
         *
         * খোলার জের এখন খতিয়ানের প্রথম সারিটাই ([[OpeningBalanceService]]),
         * তাই এখানে আবার শুরুর মান হিসেবে বসালে প্রতিটা সারিতে সংখ্যাটা
         * দ্বিগুণ হত। HP-র অভিযোগটাই ছিল উল্টোটা: তালিকায় জেরের কোনো
         * লাইন নেই অথচ চলমান জের সরাসরি লাফ দেয় — এখন লাইনটাই আছে।
         */
        $opening = '0';

        /*
         * ⛔ পাতার আগের সারিগুলোর জের — ডেটাবেসেই এক যোগফলে (পুরো-ERP অডিট হিসাব ⚠️১৫-এর একই ফাঁক, ৭ অক্টোবর ২০২৬;
         * [[TheAccountPageSumsItsPastInTheDatabaseTest]])। ⓘ আগে সেই সব সারি PHP-তে তুলে যোগ হত — ব্যস্ত খাতের দশম পাতায় ৪৫০টা
         * সারি মেমরিতে, কেবল একটা সংখ্যার জন্য। বাক্সের পাতা ([[CashTillController::show()]]) একই উপায়ে সারানো।
         */
        if ($page > 1) {
            $opening = bcadd((string) LedgerEntry::query()->withoutGlobalScopes()
                ->fromSub(
                    LedgerEntry::query()
                        ->forAccount($account->id)
                        ->when($branch, fn ($q, int $b) => $q->where('branch_id', $b))
                        ->orderBy('trx_date')
                        ->orderBy('id')
                        ->forPage(1, ($page - 1) * self::PER_PAGE)
                        ->select(['debit', 'credit']),
                    'before_page',
                )
                ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n')
                ->value('n'), '0', 4);
        }

        $account->withRunningBalance($entries->getCollection(), $opening);

        /*
         * ⭐ খাতার সর্বমোট — সব পাতার ডেবিট, ক্রেডিট আর শেষ জের, একই শাখায় এক যোগফলে (মালিক, ১৭ অক্টোবর ২০২৬: "সব পর্দায়
         * summary/grand total")। ⓘ শেষ জের = ডেবিট − ক্রেডিট, শেষ পাতার শেষ সারির চলমান জেরের একই সংখ্যা ([[RunningBalance]])।
         */
        $sums = LedgerEntry::query()
            ->forAccount($account->id)
            ->when($branch, fn ($q, int $b) => $q->where('branch_id', $b))
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();
        $grand = [
            'debit' => (string) ($sums->debit ?? '0'),
            'credit' => (string) ($sums->credit ?? '0'),
        ];

        return ['entries' => $entries, 'grand' => $grand, 'closing' => bcsub($grand['debit'], $grand['credit'], 4)];
    }

    /**
     * প্রতিটা খাতের ব্যালেন্স — একটা কোয়েরিতে, খাত ধরে নয়।
     *
     * balanceOn() একটা খাতের জন্য ঠিক আছে, কিন্তু দুইশো খাতের তালিকায়
     * ওটা দুইশো বার ডাকলে দুইশো কোয়েরি হত, আর গ্রুপগুলোর জন্য আরও।
     * তাই তালিকার জন্য সব যোগফল একবারে এনে গাছ ধরে উপরে তোলা হয়।
     *
     * ⚠️ গ্রুপের জের তার সন্তানদের যোগ — তাই সন্তানরাও `$accounts`-এ থাকতে হয় (ছকের পুরো তালিকা)।
     *
     * @param  Collection<int, Account>  $accounts
     * @return array<int, string> খাতের id → স্বাভাবিক দিকে ধনাত্মক জের
     */
    public function balances(Collection $accounts): array
    {
        $branch = ViewedBranch::one();
        if ($accounts->isEmpty()) {
            return [];
        }

        $sums = LedgerEntry::query()
            ->whereIn('account_id', $accounts->pluck('id'))
            ->when($branch, fn ($q, int $b) => $q->where('branch_id', $b))
            ->groupBy('account_id')
            ->selectRaw('account_id, COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->get()
            ->keyBy('account_id');

        $own = [];

        foreach ($accounts as $account) {
            $row = $sums[$account->id] ?? null;

            $signed = bcsub((string) ($row->d ?? 0), (string) ($row->c ?? 0), 4);

            /* খোলার জের খতিয়ানেই আছে — এখানে আবার যোগ করলে দ্বিগুণ */
            $own[$account->id] = $signed;
        }

        // সন্তানের যোগফল বাবার ঘরে — নিচ থেকে উপরে, তাই কোড অনুসারে
        // উল্টো দিক থেকে ঘোরা হয় (সন্তানের কোড বাবার চেয়ে বড়)।
        foreach ($accounts->sortByDesc('code') as $account) {
            if ($account->parent_id !== null && isset($own[$account->parent_id])) {
                $own[$account->parent_id] = bcadd($own[$account->parent_id], $own[$account->id], 4);
            }
        }

        // শেষে স্বাভাবিক দিকে ধনাত্মক করে ফেরানো
        $out = [];

        foreach ($accounts as $account) {
            $out[$account->id] = $account->nature === Account::CREDIT
                ? bcmul($own[$account->id], '-1', 4)
                : $own[$account->id];
        }

        return $out;
    }
}
