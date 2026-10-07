<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * হিসাবের পাঁচ মিল — কাঁচা টেবিল থেকে পড়া, অ্যাপের রিপোর্ট থেকে নয়।
 *
 * ⓘ চেকলিস্ট "ব্যবসা চালু, সরাসরি ক্রয় ও বিক্রয়" §১। ⚠️ ভাগ করা ট্রেইট
 * `tests/Concerns/ChecksTheFiveMatches.php` অন্য সেশন লিখছে — এই ফাইল
 * লেখার দিন (২৭ সেপ্টেম্বর ২০২৬) সেটা ডিস্কে ছিল না। সেটা এলে এটা
 * তার জায়গা ছেড়ে দেবে।
 *
 * ⛔ কেন রিপোর্টের সেবা নয়: রিপোর্ট আর পোস্টিং একই ভুল ভাগ করলে দুইটাই
 * একসাথে ভুল বলত, আর পরীক্ষা সবুজ থাকত।
 */
trait ReadsTheCounterBooks
{
    /**
     * এক মুহূর্তের খাতা, মজুদ, পক্ষ আর টাকার ছবি।
     *
     * @param  list<int>  $productIds
     * @return array{debit: string, credit: string, rows: int, accounts: array<int, string>, party: string, floor: array<string, string>, layers: string, uses: int, movements: int, vouchers: int}
     */
    protected function books(Customer $customer, array $productIds): array
    {
        $company = CompanyContext::id();

        $totals = DB::table('ledger_entries')->where('company_id', $company)
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c, COUNT(*) as n')
            ->first();

        $accounts = DB::table('ledger_entries')->where('company_id', $company)
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(debit) - SUM(credit) as net')
            ->pluck('net', 'account_id')
            ->map(fn ($v) => bcadd((string) $v, '0', 4))
            ->all();

        $party = DB::table('ledger_entries')->where('company_id', $company)
            ->where('party_type', Customer::drillSourceType())
            ->where('party_id', $customer->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')
            ->value('net');

        // ⓘ পণ্য · গুদাম · লট ধরে তাকের পরিমাণ
        $floor = DB::table('inv_stock_movements')->where('company_id', $company)
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id', 'warehouse_id', 'batch_id')
            ->selectRaw('product_id, warehouse_id, batch_id, SUM(floor_change) as qty')
            ->get()
            ->mapWithKeys(fn ($r) => [
                $r->product_id.'/'.$r->warehouse_id.'/'.($r->batch_id ?? '-') => bcadd((string) $r->qty, '0', 4),
            ])
            ->all();

        $layers = DB::table('inv_cost_layers')->where('company_id', $company)
            ->whereIn('product_id', $productIds)
            ->get(['qty_remaining', 'unit_cost'])
            ->reduce(fn (string $sum, $l) => bcadd($sum, bcmul((string) $l->qty_remaining, (string) $l->unit_cost, 4), 4), '0');

        // ⓘ ক্রম ডাটাবেসের খেয়াল — তুলনার আগে চাবি ধরে সাজানো
        ksort($accounts);
        ksort($floor);

        return [
            'debit' => bcadd((string) $totals->d, '0', 4),
            'credit' => bcadd((string) $totals->c, '0', 4),
            'rows' => (int) $totals->n,
            'accounts' => $accounts,
            'party' => bcadd((string) $party, '0', 4),
            'floor' => $floor,
            'layers' => bcadd($layers, '0', 4),
            'uses' => DB::table('inv_cost_layer_uses')->where('company_id', $company)->count(),
            'movements' => DB::table('inv_stock_movements')->where('company_id', $company)->count(),
            'vouchers' => DB::table('vouchers')->where('company_id', $company)->count(),
        ];
    }

    /** একটা খাতের নড়াচড়া (ডেবিট − ক্রেডিট) দুই ছবির মাঝে। */
    protected function moved(array $before, array $after, int $accountId): string
    {
        return bcsub($after['accounts'][$accountId] ?? '0', $before['accounts'][$accountId] ?? '0', 4);
    }

    protected function accountId(string $code): int
    {
        return (int) Account::query()->postable()->where('code', $code)->value('id');
    }

    /** @return list<int> এই কোম্পানির সব টাকার খাত (নগদ, ব্যাংক, বিকাশ) */
    protected function moneyAccountIds(): array
    {
        return Account::query()->ofMoneyKind(Account::MONEY_KINDS)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    protected function assertMoney(string $expected, string $actual, string $message): void
    {
        $this->assertSame(0, bccomp($expected, $actual, 4), $message." — প্রত্যাশা {$expected}, পাওয়া {$actual}");
    }

    /**
     * ⭐ পাঁচ মিল — কিছুই নড়েনি।
     *
     * ⚠️ খসড়া, আটকে থাকা আর ফিরিয়ে দেওয়া পথে এটাই পুরো দাবি: খাতার
     * প্রতিটা খাত, পক্ষ, তাক, স্তর আর টাকার খাত হুবহু আগের মতো।
     */
    protected function assertBooksUnchanged(array $before, array $after, string $when): void
    {
        $this->assertSame($before['rows'], $after['rows'], "⛔ {$when}: খাতায় নতুন সারি বসেছে।");
        $this->assertSame($before['accounts'], $after['accounts'], "⛔ {$when}: কোনো খাতের জের বদলেছে (মিল ১/৪/৫)।");
        $this->assertMoney($before['party'], $after['party'], "⛔ {$when}: গ্রাহকের খতিয়ান নড়েছে (মিল ৩)");
        $this->assertSame($before['floor'], $after['floor'], "⛔ {$when}: তাকের মাল নড়েছে (মিল ২)।");
        $this->assertMoney($before['layers'], $after['layers'], "⛔ {$when}: মজুদের স্তরের মূল্য বদলেছে (মিল ২)");
        $this->assertSame($before['uses'], $after['uses'], "⛔ {$when}: খরচের স্তর থেকে টান পড়েছে।");
        $this->assertSame($before['movements'], $after['movements'], "⛔ {$when}: মজুদের চলাচলের সারি বসেছে।");
    }

    /**
     * ⭐ পাঁচ মিল — একটা বিক্রির পরে, হাতে গোনা অঙ্কের সাথে।
     *
     * ⓘ `sales` = বিলের মোট (ভ্যাট ও ছাড় নেই) · `cogs` = হাতে গোনা খরচ ·
     * `received` = টাকার খাত → জমা · `stockOut` = তাকের চাবি → কত বেরোল।
     *
     * @param  array{sales: string, cogs: string, received: array<int, string>, stockOut: array<string, string>}  $hand
     */
    protected function assertBooksMatch(array $before, array $after, array $hand, string $when): void
    {
        $receivable = $this->accountId(StandardChart::RECEIVABLE);
        $sales = $this->accountId(StandardChart::SALES);
        $cogs = $this->accountId(StandardChart::COST_OF_GOODS_SOLD);
        $inventory = $this->accountId(StandardChart::INVENTORY);

        $paid = array_reduce($hand['received'], fn (string $s, string $a) => bcadd($s, $a, 4), '0');

        // ── মিল ১: খাতা — ডেবিট = ক্রেডিট, আর প্রতিটা খাতের নড়াচড়া
        $this->assertMoney(
            bcsub($after['debit'], $before['debit'], 4),
            bcsub($after['credit'], $before['credit'], 4),
            "⛔ {$when}: নতুন সারিগুলোতে ডেবিট ≠ ক্রেডিট (মিল ১)",
        );
        $this->assertMoney(bcadd(bcadd($hand['sales'], $hand['cogs'], 4), $paid, 4), bcsub($after['debit'], $before['debit'], 4),
            "⛔ {$when}: মোট ডেবিট হাতের গোনা (বিল + খরচ + জমা) নয় (মিল ১)");
        $this->assertMoney(bcsub($hand['sales'], $paid, 4), $this->moved($before, $after, $receivable),
            "⛔ {$when}: পাওনা খাতের নড়াচড়া (বিল − জমা) ভুল (মিল ১)");
        $this->assertMoney(bcmul($hand['sales'], '-1', 4), $this->moved($before, $after, $sales),
            "⛔ {$when}: বিক্রয় খাতের ক্রেডিট ভুল (মিল ১)");

        // ── মিল ২: মজুদ — পরিমাণ, স্তরের মূল্য, আর মজুদ খাত = স্তরের নড়াচড়া
        $this->assertMoney(bcmul($hand['cogs'], '-1', 4), $this->moved($before, $after, $inventory),
            "⛔ {$when}: মজুদ খাত থেকে হাতে গোনা খরচ বেরোয়নি (মিল ২)");
        $this->assertMoney(bcmul($hand['cogs'], '-1', 4), bcsub($after['layers'], $before['layers'], 4),
            "⛔ {$when}: মজুদের স্তরের মূল্য হাতে গোনা খরচ অনুযায়ী কমেনি (মিল ২)");

        foreach (array_unique([...array_keys($before['floor']), ...array_keys($after['floor'])]) as $key) {
            $out = $hand['stockOut'][$key] ?? '0';
            $this->assertMoney(
                bcsub($before['floor'][$key] ?? '0', $out, 4),
                $after['floor'][$key] ?? '0',
                "⛔ {$when}: তাকের পরিমাণ ভুল — {$key} (মিল ২)",
            );
        }

        // ── মিল ৩: গ্রাহকের খতিয়ান = পাওনা খাতের তাঁর অংশ
        $this->assertMoney(bcsub($hand['sales'], $paid, 4), bcsub($after['party'], $before['party'], 4),
            "⛔ {$when}: গ্রাহকের খতিয়ান হাতের গোনা বকেয়া নয় (মিল ৩)");
        $this->assertMoney($this->moved($before, $after, $receivable), bcsub($after['party'], $before['party'], 4),
            "⛔ {$when}: গ্রাহকের খতিয়ান আর পাওনা খাত আলাদা নড়েছে (মিল ৩)");

        // ── মিল ৪: প্রতিটা টাকার খাত — যেখানে জমা পড়েনি সেখানে শূন্য
        foreach ($this->moneyAccountIds() as $id) {
            $this->assertMoney($hand['received'][$id] ?? '0', $this->moved($before, $after, $id),
                "⛔ {$when}: টাকার খাত #{$id}-এর জের হাতে গোনা টাকা নয় (মিল ৪)");
        }

        // ── মিল ৫: বিক্রয় − বিক্রীত পণ্যের খরচ = মোট লাভ
        $this->assertMoney($hand['cogs'], $this->moved($before, $after, $cogs),
            "⛔ {$when}: বিক্রীত পণ্যের খরচ হাতে গোনা অঙ্ক নয় (মিল ৫)");
        $profit = bcsub(bcmul($this->moved($before, $after, $sales), '-1', 4), $this->moved($before, $after, $cogs), 4);
        $this->assertMoney(bcsub($hand['sales'], $hand['cogs'], 4), $profit,
            "⛔ {$when}: বিক্রয় − খরচ ≠ হাতে গোনা মোট লাভ (মিল ৫)");

        // ⓘ আর কোনো খাত নড়েনি — অচেনা খাতে টাকা গেলে উপরের কোনো দাবি ধরত না
        $known = [$receivable, $sales, $cogs, $inventory, ...$this->moneyAccountIds()];
        foreach (array_unique([...array_keys($before['accounts']), ...array_keys($after['accounts'])]) as $id) {
            if (in_array((int) $id, $known, true)) {
                continue;
            }
            $this->assertMoney('0', $this->moved($before, $after, (int) $id), "⛔ {$when}: অপ্রত্যাশিত খাত #{$id} নড়েছে");
        }
    }
}
