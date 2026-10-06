<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Dashboard\DashboardRegistry;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerMetrics;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * হোমের "বাজারে বকেয়া" মোট, নিট নয় — ৬ অক্টোবর ২০২৬ (IAS 1 ¶৩২: সম্পদ আর দায় কাটাকাটি নিষেধ)।
 *
 * ⓘ দাবি, আগে-পরের ফারাক ধরে: দোকান A-তে বকেয়া ১০০০, দোকান B-তে অগ্রিম ৩০০, আর পাওনা খাতে গ্রাহকহীন ৫০০ →
 * KPI ঠিক ১০০০ বাড়ে, নিচের লেখায় অগ্রিম ঠিক ৩০০ বাড়ে, গ্রাহকহীন সারি কোথাও নেই; আর KPI ফোনের
 * সংখ্যার ([[CustomerMetrics::dues()]]) সমান।
 * ⛔ আগের নিয়মে (১১১০-এর নিট জের) KPI ১০০০ − ৩০০ + ৫০০ = ১২০০ বাড়ত।
 */
final class TheHomeOwedByCustomersIsGrossTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_shops_advance_does_not_cut_another_shops_due(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $before = $this->read($owner);

        // ⓘ নতুন দুই দোকান — ডেমোর পুরনো জের যেন ফারাক না নাড়ায়
        [$a, $b] = array_map(fn (string $code) => Customer::query()->create([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'code' => $code,
            'name_en' => 'Shop '.$code, 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]), ['OWE-A', 'OWE-B']);
        $receivable = StandardChart::find(StandardChart::RECEIVABLE)->id;
        $sales = StandardChart::find(StandardChart::SALES)->id;
        $cash = StandardChart::find(StandardChart::OWNER_CAPITAL)->id;
        $branch = $company->defaultBranch()?->id;
        $party = Customer::drillSourceType();

        $post = fn (array $lines, int $id) => app(PostingEngine::class)->post(
            sourceType: 'test:owed-gross', sourceId: $id, trxDate: now(), lines: $lines, branchId: $branch);

        // দোকান A — বকেয়া ১০০০
        $post([['account_id' => $receivable, 'debit' => '1000', 'party_type' => $party, 'party_id' => $a->id],
            ['account_id' => $sales, 'credit' => '1000']], 1);
        // দোকান B — অগ্রিম ৩০০ (আগে কোনো বিল নেই, জের ঋণাত্মক)
        $post([['account_id' => $cash, 'debit' => '300'],
            ['account_id' => $receivable, 'credit' => '300', 'party_type' => $party, 'party_id' => $b->id]], 2);
        // ⛔ গ্রাহকহীন সারি — পাওনা খাতে, কিন্তু কারও নামে নয়
        $post([['account_id' => $receivable, 'debit' => '500'], ['account_id' => $sales, 'credit' => '500']], 3);

        $after = $this->read($owner);

        $this->assertSame('1000.0000', bcsub($after['phone'], $before['phone'], 4), 'ফোনের বকেয়া ঠিক ১০০০ বাড়েনি — দাবির ভিত নেই।');
        $this->assertSame('300.0000', bcsub($after['advance'], $before['advance'], 4), '⛔ অগ্রিম ঠিক ৩০০ বাড়েনি।');
        $this->assertSame(Money::format($after['phone']), $after['kpi'], '⛔ হোমের "বাজারে বকেয়া" ফোনের সংখ্যার সমান নয়।');
        $this->assertNotSame($before['kpi'], $after['kpi']);
        $this->assertSame(__('customer::dashboard.kpi_owed_advance', ['amount' => Money::format($after['advance'])]), $after['hint'],
            '⛔ নিচের লেখায় অগ্রিম আলাদা নেই।');

        // ⓘ পাতাতেও একই লেখা
        $this->get(route('dashboard'))->assertOk()->assertSee($after['kpi'])->assertSee($after['hint']);
    }

    /** @return array{kpi: string, hint: ?string, phone: string, advance: string} */
    private function read(User $owner): array
    {
        $widget = collect(app(DashboardRegistry::class)->forUser($owner)['kpi'] ?? [])
            ->firstWhere('label', __('customer::dashboard.kpi_owed'));
        $this->assertNotNull($widget, 'হোমে "বাজারে বকেয়া" নেই।');
        $dues = app(CustomerMetrics::class)->dues($owner, now()->toDateString());

        return ['kpi' => $widget->value, 'hint' => $widget->hint, 'phone' => $dues['amount'], 'advance' => $dues['advance']];
    }
}
