<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Services\CreditExposure;
use App\Modules\Sales\Services\OrderStanding;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ মেয়াদ পেরোনো বাকির দেয়াল — বাকি ও আদায়, ৫ অক্টোবর ২০২৬ ([[CreditExposure::stopsFor()]])।
 *
 * কোম্পানি `customer.overdue_block_days` = ৬০ বসিয়েছে। গ্রাহকের সীমা ১,০০,০০০ — জায়গা অনেক, তবু:
 *   · ৫,০০০-এর বিল, মেয়াদ ৬১ দিন আগে, এক পয়সাও আসেনি → নতুন বাকি বন্ধ (DO/আদেশ, কাউন্টার, সারাংশ — সব পথে)।
 *   · পুরো টাকা এখনই দিলে কেনা চলে।
 *   · মেয়াদ ৫৯ দিন আগে → এখনো চলে; সুইচ ০ বা সীমার সুইচ বন্ধ → দেয়াল নেই।
 *   · বিলে না বাঁধা অগ্রিম ৫,০০০ এসেছে → সবচেয়ে পুরনো বিলেই কাটে, দেয়াল ওঠে।
 */
final class AnOldUnpaidBillStopsNewCreditTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->customer = Customer::query()->create([
            'code' => 'OLD-DUE', 'name_en' => 'Old Due', 'name_bn' => 'Old Due', 'is_active' => true, 'credit_limit' => '100000',
        ]);

        $settings = app(SettingsService::class);
        $settings->set('customer.credit_limit_enabled', true);
        $settings->set('customer.overdue_block_days', 60);
    }

    public function test_a_bill_unpaid_past_the_days_stops_new_credit_on_every_path(): void
    {
        $this->bill('INV-OLD1', '5000', daysPastDue: 61);

        $result = app(CreditExposure::class)->check($this->customer->fresh(), '1000');
        $this->assertFalse($result['fits'], '⛔ ৬১ দিনের পুরনো বাকি নিয়ে DO/আদেশ কুলোল।');
        $this->assertStringContainsString('INV-OLD1', (string) $result['reason']);
        $this->assertSame(0, bccomp($result['short'], '5000', 4), '⛔ দেয়াল উঠতে পুরনো বিলের ৫,০০০ লাগে, এল '.$result['short']);

        try {
            app(CreditExposure::class)->assertRoom($this->customer->fresh(), '1000');
            $this->fail('⛔ কাউন্টার/চালানের দেয়াল পুরনো বাকিতে নতুন বাকি দিয়েছে।');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('INV-OLD1', $e->validator->errors()->first('customer_id'));
            $this->assertStringContainsString('60', $e->validator->errors()->first('customer_id'));
        }

        $standing = app(OrderStanding::class)->for($this->customer->fresh(), '1000');
        $this->assertTrue($standing['stop'], '⛔ সারাংশ "নিশ্চিত হবে না" বলেনি।');
        $this->assertStringContainsString('INV-OLD1', (string) $standing['stop_reason']);
    }

    public function test_paying_in_full_now_still_buys(): void
    {
        $this->bill('INV-OLD2', '5000', daysPastDue: 61);

        app(CreditExposure::class)->assertRoom($this->customer->fresh(), '1000', '1000');
        $this->assertFalse(app(OrderStanding::class)->for($this->customer->fresh(), '0')['stop']);
    }

    public function test_inside_the_days_the_wall_stays_down(): void
    {
        $this->bill('INV-NEW', '5000', daysPastDue: 59);

        $this->assertFits();
    }

    public function test_zero_days_or_the_credit_switch_off_means_no_wall(): void
    {
        $this->bill('INV-OLD3', '5000', daysPastDue: 200);

        app(SettingsService::class)->set('customer.overdue_block_days', 0);
        $this->assertFits();

        app(SettingsService::class)->set('customer.overdue_block_days', 60);
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);
        $this->assertFits();
    }

    public function test_an_advance_on_no_bill_clears_the_oldest_bill_first(): void
    {
        $this->bill('INV-OLD4', '5000', daysPastDue: 90);
        $this->bill('INV-NEW4', '3000', daysPastDue: 0);

        // ⓘ ৫,০০০ জমা, কোনো বিলে বাঁধা নয় — সবচেয়ে পুরনো বিলটাই শোধ ধরা হয়
        $this->advance('5000');

        $this->assertFits();
    }

    public function test_a_partial_advance_leaves_the_rest_of_the_old_bill_standing(): void
    {
        $this->bill('INV-OLD5', '5000', daysPastDue: 90);
        $this->advance('4000');

        $result = app(CreditExposure::class)->check($this->customer->fresh(), '500');
        $this->assertFalse($result['fits']);
        $this->assertSame(0, bccomp($result['short'], '1000', 4), '⛔ বাকি ১,০০০-ই দেয়াল তোলে, এল '.$result['short']);
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function assertFits(): void
    {
        $result = app(CreditExposure::class)->check($this->customer->fresh(), '1000');
        $this->assertTrue($result['fits'], '⛔ দেয়াল ওঠার কথা নয়: '.$result['reason']);
        $this->assertNull($result['reason']);

        app(CreditExposure::class)->assertRoom($this->customer->fresh(), '1000');
        $this->assertFalse(app(OrderStanding::class)->for($this->customer->fresh(), '1000')['stop']);
    }

    /** একটা পাকা বিল, খাতায় বসানো, মেয়াদ আজ থেকে এত দিন আগে */
    private function bill(string $no, string $amount, int $daysPastDue): void
    {
        $due = now()->subDays($daysPastDue)->toDateString();

        $id = (int) DB::table('sal_invoices')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => $no,
            'customer_id' => $this->customer->id,
            'trx_date' => now()->subDays($daysPastDue + 30)->toDateString(),
            'due_on' => $due,
            'subtotal' => $amount,
            'total' => $amount,
            'status' => DocumentStatus::CONFIRMED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postLines([
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => $amount, 'party_type' => 'customer', 'party_id' => $this->customer->id],
            ['account_id' => StandardChart::find(StandardChart::SALES)->id, 'credit' => $amount],
        ], 'sales_invoice', $id);
    }

    private function advance(string $amount): void
    {
        $money = DB::table('accounts')->where('company_id', $this->company->id)->where('money_kind', 'cash')->where('is_group', false)->orderBy('id')->value('id');

        $this->postLines([
            ['account_id' => $money, 'debit' => $amount],
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => $amount, 'party_type' => 'customer', 'party_id' => $this->customer->id],
        ], 'test:advance', random_int(1, 9_999_999));
    }

    private function postLines(array $lines, string $source, int $sourceId): void
    {
        app(PostingEngine::class)->post(
            sourceType: $source, sourceId: $sourceId, trxDate: now()->toDateString(), lines: $lines,
            branchId: $this->company->defaultBranch()?->id,
        );
    }
}
