<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * ⛔ টাকার তালিকার নিচে মোট নেই — ওয়েব-মান ধাপ ২, ২০ অক্টোবর ২০২৬ (মালিক, ১ অক্টোবর ২০২৬: *"kono list er niche grand total
 * nai keno"*)।
 *
 * <p>লাইভ নিরীক্ষায় ৪৮টা তালিকায় মোট নেই; টাকা বা পরিমাণের ছয়টা (লক্ষ্য, ডিলারের লক্ষ্য, ডেলিভারির মাপকাঠি, নগদের পূর্বাভাস,
 * প্রিন্সিপালের খোলা মজুদ, গণনা) এই ধাপে ঠিক হলো।
 *
 * <p>নিয়ম: যে ব্লেডে [[x-ui.table]] আর টাকার কলাম (`Money::format`, `$money(`, `'total' => 'money'`) আছে, তার প্রতিটা টেবিল
 * `:grand` (গোটা তালিকার সর্বমোট) বা `:totals` (পর্দার নিজের যোগের সারি) পায়।
 *
 * <p>⚠️ আজ [[ALLOWED]]-এর ফাইলগুলো নিয়ম মানে না — সবগুলো ভুলও নয়: দামের তালিকায় দাম যোগ হয় না, কাগজের পাতার মোট তথ্যের
 * অংশেই থাকে। ⓘ তাই তালিকাটা কেবল ছোট হয় (ratchet): নতুন ফাইল নিয়ম না মানলে লাল; তালিকার কেউ ঠিক হয়ে গেলেও লাল — তাকে
 * তালিকা থেকে সরাতে হবে, যাতে সে আর পিছলে যেতে না পারে। প্রতিটা ফাইল আলাদা করে দেখা বাকি (fe-র তালিকায়)।
 */
final class AMoneyListEndsInATotalTest extends TestCase
{
    /** আজকের ব্যতিক্রম — কেবল কমে */
    private const ALLOWED = [
        'app/Modules/Accounts/Resources/views/asset/run/preview.blade.php',
        'app/Modules/Accounts/Resources/views/asset/run/show.blade.php',
        'app/Modules/Accounts/Resources/views/asset/show.blade.php',
        'app/Modules/Accounts/Resources/views/coa/show.blade.php',
        'app/Modules/Accounts/Resources/views/control/posting.blade.php',
        'app/Modules/Accounts/Resources/views/dashboard/show.blade.php',
        'app/Modules/Accounts/Resources/views/loan/show.blade.php',
        'app/Modules/Accounts/Resources/views/reconciliation/show.blade.php',
        'app/Modules/Accounts/Resources/views/till/show.blade.php',
        'app/Modules/Accounts/Resources/views/year-end/index.blade.php',
        'app/Modules/Approval/Resources/views/flow/coverage.blade.php',
        'app/Modules/Approval/Resources/views/inbox/mine.blade.php',
        'app/Modules/Approval/Resources/views/limit/index.blade.php',
        'app/Modules/Customer/Resources/views/show.blade.php',
        'app/Modules/Finance/Resources/views/account-analysis/index.blade.php',
        'app/Modules/Finance/Resources/views/bank-facility/show.blade.php',
        'app/Modules/Finance/Resources/views/budget/actual.blade.php',
        'app/Modules/Finance/Resources/views/capital/index.blade.php',
        'app/Modules/Finance/Resources/views/carrier-labour/index.blade.php',
        'app/Modules/Finance/Resources/views/deposit/partials/institutions.blade.php',
        'app/Modules/Finance/Resources/views/deposit/show.blade.php',
        'app/Modules/Finance/Resources/views/expense/index.blade.php',
        'app/Modules/Finance/Resources/views/hand-loan/index.blade.php',
        'app/Modules/Finance/Resources/views/hand-loan/show.blade.php',
        'app/Modules/Finance/Resources/views/institution/show.blade.php',
        'app/Modules/Finance/Resources/views/insurance/claim.blade.php',
        'app/Modules/Finance/Resources/views/insurance/show.blade.php',
        'app/Modules/Finance/Resources/views/investment/returns.blade.php',
        'app/Modules/Finance/Resources/views/profit/index.blade.php',
        'app/Modules/Finance/Resources/views/rental/partials/people.blade.php',
        'app/Modules/Finance/Resources/views/withdrawal/index.blade.php',
        'app/Modules/Hr/Resources/views/employee/salary.blade.php',
        'app/Modules/Hr/Resources/views/payroll/show.blade.php',
        'app/Modules/Inventory/Resources/views/product/index.blade.php',
        'app/Modules/Inventory/Resources/views/product/show.blade.php',
        'app/Modules/Inventory/Resources/views/stock/lot-assign.blade.php',
        'app/Modules/Inventory/Resources/views/stock/opening.blade.php',
        'app/Modules/Purchase/Resources/views/bill/show.blade.php',
        'app/Modules/Purchase/Resources/views/order/show.blade.php',
        'app/Modules/Purchase/Resources/views/payment/show.blade.php',
        'app/Modules/Purchase/Resources/views/receipt/show.blade.php',
        'app/Modules/Purchase/Resources/views/return/show.blade.php',
        'app/Modules/Restaurant/Resources/views/production/index.blade.php',
        'app/Modules/Restaurant/Resources/views/production/show.blade.php',
        'app/Modules/Sales/Resources/views/cancellation/show.blade.php',
        'app/Modules/Sales/Resources/views/challan/show.blade.php',
        'app/Modules/Sales/Resources/views/collection/show.blade.php',
        'app/Modules/Sales/Resources/views/crm/opportunity/show.blade.php',
        'app/Modules/Sales/Resources/views/depot_check/index.blade.php',
        'app/Modules/Sales/Resources/views/invoice/show.blade.php',
        'app/Modules/Sales/Resources/views/order/show.blade.php',
        'app/Modules/Sales/Resources/views/overview/index.blade.php',
        'app/Modules/Sales/Resources/views/price_book/history.blade.php',
        'app/Modules/Sales/Resources/views/price_book/show.blade.php',
        'app/Modules/Sales/Resources/views/price_list/history.blade.php',
        'app/Modules/Sales/Resources/views/price_list/index.blade.php',
        'app/Modules/Sales/Resources/views/quotation/show.blade.php',
        'app/Modules/Sales/Resources/views/return/show.blade.php',
        'app/Modules/Sales/Resources/views/route/index.blade.php',
        'app/Modules/Sales/Resources/views/route/show.blade.php',
        'app/Modules/Supplier/Resources/views/show.blade.php',
    ];

    public function test_a_money_table_carries_a_total_unless_it_is_a_known_exception(): void
    {
        $looked = 0;
        $offenders = [];

        foreach ($this->blades() as $relative) {
            $source = (string) file_get_contents(base_path($relative));

            preg_match_all('/<x-ui\.table\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/s', $source, $tables);

            if ($tables[0] === [] || preg_match('/Money::format|\$money\(|\'total\'\s*=>\s*\'money\'/', $source) !== 1) {
                continue;
            }

            $looked++;

            foreach ($tables[0] as $tag) {
                if (preg_match('/:grand=|:totals=/', $tag) !== 1) {
                    $offenders[] = $relative;

                    break;
                }
            }
        }

        $this->assertGreaterThan(80, $looked, 'মাত্র '.$looked.'টা টাকার তালিকা চোখে পড়ল — খোঁজার ছাঁচ ভেঙেছে কি না দেখুন।');

        $new = array_values(array_diff($offenders, self::ALLOWED));
        $this->assertSame([], $new, "⛔ নতুন টাকার তালিকা মোট ছাড়া (:grand বা :totals দিন):\n  ".implode("\n  ", $new));

        $fixed = array_values(array_diff(self::ALLOWED, $offenders));
        $this->assertSame([], $fixed, "ⓘ এগুলো এখন মোট দেখায় — ALLOWED থেকে সরান, যাতে আবার পিছলে না যায়:\n  ".implode("\n  ", $fixed));
    }

    /**
     * কমিট করা ব্লেড, রিপোর ভেতরের পথে ([[AChartLabelRowCouldNotShrinkTest::blades()]]-এর কারণেই)।
     *
     * @return list<string>
     */
    private function blades(): array
    {
        $lines = [];

        exec('git -C '.escapeshellarg(base_path()).' ls-tree -r --name-only HEAD app resources 2>&1', $lines, $code);

        if ($code !== 0) {
            return [];
        }

        return array_values(array_filter($lines, fn (string $l) => str_ends_with($l, '.blade.php') && is_file(base_path($l))));
    }
}
