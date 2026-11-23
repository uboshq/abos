<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Reports\CoreReports;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Reports\MoneyInReports;
use App\Modules\Sales\Reports\SalesPlace;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ "টাকা আসা"য় কেবল রসিদের কাগজের আসল টাকা — মালিক, ২৩ নভেম্বর ২০২৬ (সমন্বয়কের মারফত): জাবেদা, শুরুর জের, কন্ট্রা, ক্রেডিট নোট,
 * বাতিল বা উল্টানো কাগজ গোনা হবে না।
 *
 * ⓘ আগে নিয়মটা ছিল বাদের তালিকা (উল্টো দাখিলা, পরে উল্টানো, টাকা-থেকে-টাকা) — তাই জাবেদা দিয়ে ব্যাংকে তোলা টাকা বা শুরুর জের, যার
 * অপর পাশ টাকার খাত নয়, "আদায়" হয়ে আসত। এখন অনুমতির তালিকা ([[CoreReports::MONEY_IN]])।
 *
 * এই দাবি তিনটা কথা মেলায়, ডেমোর আগের সারি বাদ দিতে **আগে-পরের তফাত** ধরে:
 *   ১. গোনা টাকা = কেবল রসিদ ভাউচার আর পুরনো আদায়ের কাগজ; হিসাবের তালিকা আর বিক্রয়ের সব রূপ একই অঙ্ক বলে।
 *   ২. বাদ পড়া প্রতিটা কাগজ "বাদ গেছে"-তে আছে, ঠিক কারণে।
 *   ৩. গোনা + বাদ = টাকার খাতে যা ডেবিট হয়েছে তার সবটা — একটা সারিও দুই পাশে নয়, একটাও হারানো নয়।
 */
final class TheMoneyInReportsCountOnlyReceiptPapersTest extends TestCase
{
    use RefreshDatabase;

    /** রিপোর্টের সব রূপ, যাদের "মোট" একই অঙ্ক হতে হয় */
    private const VIEWS = [MoneyInReports::DAY, MoneyInReports::BRANCH, MoneyInReports::PERSON, MoneyInReports::CHANNEL];

    public function test_only_receipt_papers_are_money_in_and_everything_else_is_listed_as_left_out(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $bank = Account::query()->create(['company_id' => $company->id, 'code' => '1102-MIN', 'name_en' => 'Money-in Bank', 'name_bn' => 'টাকা আসার ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT, 'money_kind' => Account::BANK]);
        $cash = app(CashTillService::class)->ensurePrimaryTill()->account_id;
        $receivable = StandardChart::find(StandardChart::RECEIVABLE);
        $receivable = $receivable->is_group ? Account::query()->postable()->whereKey($receivable->selfAndDescendants()->pluck('id'))->orderBy('code')->firstOrFail() : $receivable;
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $party = ['party_type' => 'customer', 'party_id' => $customer->id];
        $engine = app(PostingEngine::class);
        $today = now()->toDateString();

        $before = $this->picture($today);

        $id = random_int(1, 4_000_000);
        // গোনা হবে: রসিদ ভাউচার ১,০০০ আর পুরনো আদায়ের কাগজ ২৫০
        $engine->post(sourceType: 'receipt_voucher', sourceId: $id, trxDate: $today, lines: [
            ['account_id' => $bank->id, 'debit' => '1000'], ['account_id' => $receivable->id, 'credit' => '1000', ...$party]]);
        $engine->post(sourceType: 'collection', sourceId: $id + 1, trxDate: $today, lines: [
            ['account_id' => $bank->id, 'debit' => '250'], ['account_id' => $receivable->id, 'credit' => '250', ...$party]]);
        // রসিদ নয়: জাবেদা ৪০০ আর শুরুর জের ৩০০ — অপর পাশ টাকার খাত নয়, তাই আগের নিয়মে এগুলো "আদায়" হত
        $engine->post(sourceType: 'journal_voucher', sourceId: $id + 2, trxDate: $today, lines: [
            ['account_id' => $bank->id, 'debit' => '400'], ['account_id' => $receivable->id, 'credit' => '400', ...$party]]);
        $engine->post(sourceType: 'opening_balance', sourceId: $id + 3, trxDate: $today, lines: [
            ['account_id' => $bank->id, 'debit' => '300'], ['account_id' => $receivable->id, 'credit' => '300', ...$party]]);
        // রসিদ, কিন্তু টাকা এসেছে আরেক টাকার খাত থেকে ৫০০
        $engine->post(sourceType: 'receipt_voucher', sourceId: $id + 4, trxDate: $today, lines: [
            ['account_id' => $bank->id, 'debit' => '500'], ['account_id' => $cash, 'credit' => '500']]);
        // রসিদ, পরে বাতিল ৭০০
        $engine->post(sourceType: 'receipt_voucher', sourceId: $id + 5, trxDate: $today, lines: [
            ['account_id' => $bank->id, 'debit' => '700'], ['account_id' => $receivable->id, 'credit' => '700', ...$party]]);
        $engine->reverse(sourceType: 'receipt_voucher', sourceId: $id + 5, reversalDate: $today, reason: 'ভুল রসিদ');
        // বাতিল পরিশোধের উল্টো দাখিলা ২০০ — ব্যাংকে ডেবিট, অথচ কারো কাছ থেকে টাকা আসেনি
        $engine->post(sourceType: 'payment_voucher', sourceId: $id + 6, trxDate: $today, lines: [
            ['account_id' => $receivable->id, 'debit' => '200', ...$party], ['account_id' => $bank->id, 'credit' => '200']]);
        $engine->reverse(sourceType: 'payment_voucher', sourceId: $id + 6, reversalDate: $today, reason: 'ভুল পরিশোধ');

        $after = $this->picture($today);
        $moved = fn (string $key): string => bcsub($after[$key], $before[$key], 2);

        $this->assertSame('1250.00', $moved('accounts.inflow'), '⛔ হিসাবের আদায়ের তালিকায় রসিদ ছাড়া অন্য কাগজের টাকা (বা রসিদের টাকা বাদ)।');

        foreach ([...self::VIEWS, MoneyInReports::PAPERS, ...array_map(MoneyInReports::levelKey(...), SalesPlace::LEVELS)] as $view) {
            $this->assertSame('1250.00', $moved($view), "⛔ {$view}: বিক্রয়ের টাকা আসার এই রূপ হিসাবের তালিকার অঙ্ক বলছে না।");
        }

        $this->assertSame('700.00', $moved('left:other_paper'), '⛔ জাবেদা (৪০০) আর শুরুর জের (৩০০) "রসিদ নয়"-এ নেই।');
        $this->assertSame('500.00', $moved('left:transfer'), '⛔ টাকার খাত থেকে টাকার খাতে আসা ৫০০ "এক খাত থেকে আরেক খাতে"-তে নেই।');
        $this->assertSame('700.00', $moved('left:cancelled'), '⛔ পরে বাতিল হওয়া রসিদের ৭০০ "বাতিল"-এ নেই।');
        $this->assertSame('200.00', $moved('left:reversal'), '⛔ বাতিল পরিশোধের উল্টো দাখিলার ২০০ "উল্টো দাখিলা"-য় নেই।');
        $this->assertSame($moved(MoneyInReports::EXCLUDED), $moved(MoneyInReports::EXCLUDED_PAPERS), '⛔ "বাদ গেছে"-র যোগ আর তার কাগজের তালিকার যোগ আলাদা।');

        // ⭐ গোনা + বাদ = ব্যাংকে যা ডেবিট হল: ১,০০০ + ২৫০ + ৪০০ + ৩০০ + ৫০০ + ৭০০ + ২০০
        $this->assertSame('3350.00', bcadd($moved('accounts.inflow'), $moved(MoneyInReports::EXCLUDED), 2),
            '⛔ গোনা আর বাদ মিলিয়ে টাকার খাতের সব ডেবিট হয় না — কোনো সারি হারিয়েছে বা দুইবার এসেছে।');
    }

    public function test_the_list_of_receipt_papers_names_no_journal_opening_or_reversal(): void
    {
        foreach (CoreReports::MONEY_IN as $paper) {
            $this->assertDoesNotMatchRegularExpression('/journal|opening|contra|credit_note|reversal/', $paper,
                '⛔ আসা টাকার কাগজের তালিকায় রসিদ নয় এমন কাগজ — মালিকের নিয়ম: কেবল আসল টাকা।');
        }

        $this->assertContains('receipt_voucher', CoreReports::MONEY_IN);
    }

    /**
     * এই মুহূর্তের ছবি — প্রতিটা রূপের মোট, আর "বাদ গেছে"-র প্রতি কারণের অঙ্ক।
     *
     * @return array<string, string>
     */
    private function picture(string $day): array
    {
        $reports = app(ReportEngine::class);
        $asked = ['from' => $day, 'to' => $day];
        $total = fn (string $key, string $column): string => bcadd((string) ($reports->run($key, $asked, 1, 500)->totals[$column] ?? '0'), '0', 2);

        $out = ['accounts.inflow' => $total('accounts.inflow', 'debit'), MoneyInReports::PAPERS => $total(MoneyInReports::PAPERS, 'amount'),
            MoneyInReports::EXCLUDED => $total(MoneyInReports::EXCLUDED, 'amount'), MoneyInReports::EXCLUDED_PAPERS => $total(MoneyInReports::EXCLUDED_PAPERS, 'amount')];

        foreach ([...self::VIEWS, ...array_map(MoneyInReports::levelKey(...), SalesPlace::LEVELS)] as $view) {
            $out[$view] = $total($view, 'total');
        }

        foreach (CoreReports::KEPT_OUT as $reason) {
            $out['left:'.$reason] = '0.00';
        }

        foreach ($reports->run(MoneyInReports::EXCLUDED, $asked, 1, 500)->rows as $row) {
            $out['left:'.$row['reason']] = bcadd((string) $row['amount'], '0', 2);
        }

        return $out;
    }
}
