<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\FinancialYear;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashCount;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * নগদ গণনা — হাতে যা আছে, খাতায় যা থাকার কথা।
 *
 * মিললে কোনো এন্ট্রি হয় না, শুধু রেকর্ড থাকে যে ওই দিন গোনা হয়েছিল।
 *
 * না মিললে পার্থক্যটা একটা জাবেদা হয়ে বসে — কারণ খাতার সংখ্যাটা তখন
 * মিথ্যা, আর মিথ্যা রেখে দিলে পরদিনের গণনাও মিলবে না, আর কোন দিনের
 * ভুল তা আর বলা যাবে না। কম পড়লে সেটা খরচ; বেশি হলে অন্যান্য আয়।
 * দুইটাই কাউকে দোষ না দিয়ে হিসাবটা সত্যি রাখে।
 */
final class CashCountService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly VoucherService $vouchers,
    ) {}

    /**
     * গণনা সংরক্ষণ — এখনো কোনো সমন্বয় হয় না।
     *
     * @param  array<string, mixed>  $data
     * @param  array<int|string, int|string|null>  $counts  নোটের সংখ্যা
     */
    public function record(array $data, array $counts): CashCount
    {
        return DB::transaction(function () use ($data, $counts) {
            $till = CashTill::query()->find($data['cash_till_id'] ?? null);

            if ($till === null) {
                throw ValidationException::withMessages([
                    'cash_till_id' => __('accounts::validation.till_not_found'),
                ]);
            }

            $trxDate = Carbon::parse($data['trx_date'] ?? now());

            // গোনা টাকাটা নোটের হিসাব থেকেই, ব্যবহারকারীর লেখা মোট থেকে
            // নয় — নাহলে কাগজটা নিজের সাথেই অসঙ্গত হতে পারত
            $counted = CashCount::totalOf($counts);

            // খাতার সংখ্যা ওই তারিখ পর্যন্ত, আজ পর্যন্ত নয়: পুরনো তারিখের
            // গণনা লিখলে আজকের ব্যালেন্সের সাথে মেলানোটা অর্থহীন হত
            $expected = $till->balance($trxDate->toDateString());

            return CashCount::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $till->branch_id ?? CompanyContext::branchId(),
                'financial_year_id' => $this->year($trxDate)->id,
                'document_no' => $this->numbers->next('CC'),
                'trx_date' => $trxDate->toDateString(),
                'cash_till_id' => $till->id,
                'counted_amount' => $counted,
                'expected_amount' => $expected,
                'difference' => bcsub($counted, $expected, 4),
                'denominations' => $this->cleanCounts($counts),
                'narration' => $data['narration'] ?? null,
                'status' => DocumentStatus::DRAFT,
                'counted_by' => $data['counted_by'] ?? auth()->id(),
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * গণনা অনুমোদন — পার্থক্য থাকলে এখনই সমন্বয় বসে।
     *
     * অনুমোদন আলাদা ধাপ, কারণ পার্থক্য মানে কারও হাতে টাকা কম বা বেশি,
     * আর সেটা ক্যাশিয়ার নিজেই নিষ্পত্তি করে ফেললে গণনার কোনো মানে থাকে
     * না। যিনি গুনেছেন আর যিনি অনুমোদন করছেন — দুইজনের নামই থাকে।
     */
    public function approve(CashCount $count): CashCount
    {
        if ($count->isApproved()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.count_already_approved'),
            ]);
        }

        return DB::transaction(function () use ($count) {
            if (! $count->matches()) {
                $count->forceFill(['adjustment_voucher_id' => $this->adjustmentFor($count)->id])->save();
            }

            $count->forceFill([
                'status' => DocumentStatus::CONFIRMED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ])->save();

            return $count->fresh();
        });
    }

    /**
     * পার্থক্যের জাবেদা।
     *
     * কম পড়লে: খরচ ডেবিট, নগদ ক্রেডিট — টাকাটা নেই, তাই খাতা থেকেও যায়।
     * বেশি হলে: নগদ ডেবিট, অন্যান্য আয় ক্রেডিট।
     *
     * খাতগুলো কোড ধরে খোঁজা, নাম ধরে নয় — নাম বদলানো যায়, কোড নয়।
     */
    private function adjustmentFor(CashCount $count): Voucher
    {
        $cash = (int) $count->till->account_id;
        $shortage = ! $count->isSurplus();

        $other = $shortage
            ? $this->accountOr('5299', Account::EXPENSE)   // বিবিধ খরচ
            : $this->accountOr('4300', Account::INCOME);   // অন্যান্য আয়

        $amount = ltrim((string) $count->difference, '-');
        $amount = number_format((float) $amount, 4, '.', '');

        $note = __('accounts::message.count_adjustment', [
            'no' => $count->document_no,
            'till' => $count->till->name(),
        ]);

        $voucher = $this->vouchers->create(
            [
                'type' => Voucher::JOURNAL,
                'trx_date' => $count->trx_date->toDateString(),
                'narration' => $note,
                'branch_id' => $count->branch_id,
            ],
            $shortage
                ? [
                    ['account_id' => $other->id, 'debit' => $amount, 'credit' => '0', 'narration' => $note],
                    ['account_id' => $cash, 'debit' => '0', 'credit' => $amount, 'narration' => $note],
                ]
                : [
                    ['account_id' => $cash, 'debit' => $amount, 'credit' => '0', 'narration' => $note],
                    ['account_id' => $other->id, 'debit' => '0', 'credit' => $amount, 'narration' => $note],
                ],
        );

        return $this->vouchers->post($voucher);
    }

    /**
     * প্রমিত ছকের খাতটা, না পেলে ওই ধরনের প্রথমটা।
     *
     * কোম্পানি প্রমিত ছক বদলে ফেলতে পারে, আর তখন "৫২৯৯ বিবিধ খরচ"
     * নাও থাকতে পারে। ব্যতিক্রম ছুঁড়লে গণনার অনুমোদনটাই আটকে যেত,
     * অথচ টাকাটা তো সত্যিই কম পড়েছে — সেটা কোথাও বসাতেই হবে।
     */
    private function accountOr(string $code, string $type): Account
    {
        $account = StandardChart::find($code);

        if ($account !== null && ! $account->is_group && $account->is_active) {
            return $account;
        }

        $fallback = Account::query()->ofType($type)->postable()->active()->orderBy('code')->first();

        if ($fallback === null) {
            throw ValidationException::withMessages([
                'difference' => __('accounts::validation.no_adjustment_account', ['type' => __('accounts::type.'.$type)]),
            ]);
        }

        return $fallback;
    }

    /**
     * শুধু ধনাত্মক সংখ্যাগুলো, নোট অনুসারে সাজানো।
     *
     * @param  array<int|string, int|string|null>  $counts
     * @return array<int, int>
     */
    private function cleanCounts(array $counts): array
    {
        $out = [];

        foreach (CashCount::DENOMINATIONS as $note) {
            $qty = (int) ($counts[$note] ?? 0);

            if ($qty > 0) {
                $out[$note] = $qty;
            }
        }

        return $out;
    }

    private function year(Carbon $date): FinancialYear
    {
        $year = FinancialYear::forDate($date);

        if ($year === null) {
            throw ValidationException::withMessages([
                'trx_date' => __('accounts::validation.no_financial_year', ['date' => $date->format('d/m/Y')]),
            ]);
        }

        return $year;
    }
}
