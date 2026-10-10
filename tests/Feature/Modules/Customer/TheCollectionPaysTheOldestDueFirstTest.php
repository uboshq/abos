<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ বকেয়ার বয়স — আদায় সবচেয়ে পুরনো বকেয়া থেকে, আর অগ্রিম আলাদা (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, গ্রাহক ১২;
 * [[PartyReports::ageing()]])।
 *
 * ⓘ জানা ছোট খাতা: ক — ১০০ দিন আগে ১,০০০ বিল, ১০ দিন আগে ৫০০ বিল, ৫ দিন আগে ৮০০ আদায়। খ — ১০ দিন আগে ৩০০ বিল, ৫ দিন আগে
 * ১,০০০ আদায় (৭০০ আগাম)। আগে ক-এর ৯০+ দিন ১,০০০ আর ০–৩০ দিন −৩০০ দেখাত; খ −৭০০ বকেয়া হয়ে মোট বকেয়া ০ করে দিত।
 */
final class TheCollectionPaysTheOldestDueFirstTest extends TestCase
{
    use RefreshDatabase;

    public function test_collections_clear_the_oldest_dues_and_an_advance_stands_apart(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        // ⓘ খালি খাতা থেকে — ডেমোর গ্রাহকদের বকেয়া এই হিসাবে না মেশে
        DB::table('ledger_entries')->where('party_type', Customer::drillSourceType())->delete();

        $a = $this->customer('AGE-A', 'Old Due Shop');
        $b = $this->customer('AGE-B', 'Paid Ahead Shop');

        $this->book($a, 1000, 0, 100);
        $this->book($a, 500, 0, 10);
        $this->book($a, 0, 800, 5);
        $this->book($b, 300, 0, 10);
        $this->book($b, 0, 1000, 5);

        $result = app(ReportEngine::class)->run('customer.ageing', ['to' => now()->toDateString()], 1, 100);
        $rows = collect($result->rows)->keyBy(fn ($r) => (int) ((array) $r)['party_id']);

        $this->assertMoney('200', $rows[$a->id], 'bucket_90', '⛔ আদায় পুরনো বকেয়া থেকে কাটেনি');
        $this->assertMoney('0', $rows[$a->id], 'bucket_60', 'ক');
        $this->assertMoney('500', $rows[$a->id], 'bucket_current', '⛔ আদায় নতুন বিলের বালতিতে ঋণাত্মক হয়ে বসল');
        $this->assertMoney('700', $rows[$a->id], 'outstanding', 'ক-এর বকেয়া');
        $this->assertMoney('0', $rows[$a->id], 'advance', 'ক-এর অগ্রিম নেই');

        $this->assertMoney('0', $rows[$b->id], 'outstanding', '⛔ আগাম জমা ঋণাত্মক বকেয়া হয়ে বসল');
        $this->assertMoney('0', $rows[$b->id], 'bucket_current', '⛔ আগাম জমা বালতিতে ঋণাত্মক');
        $this->assertMoney('700', $rows[$b->id], 'advance', 'খ-এর অগ্রিম আলাদা ঘরে');

        $this->assertSame(0, bccomp('700', (string) $result->totals['outstanding'], 2), '⛔ মোট বকেয়ায় অগ্রিম কাটাকাটি হল: '.$result->totals['outstanding']);
        $this->assertSame(0, bccomp('700', (string) $result->totals['advance'], 2));
    }

    private function customer(string $code, string $name): Customer
    {
        return Customer::query()->create(['company_id' => CompanyContext::id(), 'code' => $code, 'name_en' => $name, 'is_active' => true]);
    }

    private function book(Customer $customer, int $debit, int $credit, int $daysAgo): void
    {
        $receivable = (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
        $other = (int) Account::query()->postable()->active()->where('type', Account::INCOME)->value('id');
        $amount = (string) max($debit, $credit);
        $party = ['party_type' => Customer::drillSourceType(), 'party_id' => $customer->id];

        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: now()->subDays($daysAgo)->toDateString(), lines: $debit > 0
            ? [['account_id' => $receivable, 'debit' => $amount] + $party, ['account_id' => $other, 'credit' => $amount]]
            : [['account_id' => $other, 'debit' => $amount], ['account_id' => $receivable, 'credit' => $amount] + $party]);
    }

    private function assertMoney(string $expected, mixed $row, string $key, string $why): void
    {
        $actual = (string) (((array) $row)[$key] ?? 'missing');
        $this->assertSame(0, bccomp($expected, is_numeric($actual) ? $actual : '-1', 2), "{$why}: {$key} চাই {$expected}, এল {$actual}");
    }
}
