<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\MasterData\Models\Location;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * গ্রাহকের তালিকা আর চারটা রিপোর্ট — একই পরিচয়, একই ক্রমে।
 *
 * ── ⭐ মালিকের চাওয়া, ১৯ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * তালিকা: SL# · পার্টি কোড · নাম · পয়েন্ট · এরিয়া · পূর্ণ ঠিকানা · মালিক ·
 * মোবাইল · বকেয়া · অবস্থা · কাজ। বকেয়ার তালিকাতেও একই; বয়স, আদায় আর
 * সীমাহীনদের রিপোর্টে পরিচয়ের কলাম (কোড, নাম, পয়েন্ট, এরিয়া, মোবাইল)।
 * বয়সের রিপোর্টে সাথে শেষ বিল, শেষ আদায় আর ধারের সীমা।
 *
 * ⚠️ "এরিয়া" এখন মইয়ের `territory` চাবি (06e0d8cd-এ কেবল নাম বদলেছে)।
 * ⓘ তাই এখানে গ্রাহক বসে একটা পয়েন্টে, যার উপরে একটা `territory` —
 * আর দাবিটা হলো পয়েন্ট আর এরিয়া দুইটাই ঠিক নাম নিয়ে আসে।
 */
final class EveryCustomerReportSaysWhoAndWhereTest extends TestCase
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

        $area = Location::query()->create([
            'company_id' => $this->company->id, 'code' => 'T-RPT', 'level' => Location::TERRITORY,
            'name_en' => 'Report Area', 'name_bn' => 'রিপোর্ট এরিয়া', 'is_active' => true,
        ]);
        $point = Location::query()->create([
            'company_id' => $this->company->id, 'code' => 'P-RPT', 'level' => Location::POINT,
            'parent_id' => $area->id, 'name_en' => 'Report Point', 'name_bn' => 'রিপোর্ট পয়েন্ট', 'is_active' => true,
        ]);

        $this->customer = app(CustomerService::class)->create([
            'name_en' => 'Who And Where Store',
            'owner_name' => 'Rahim Uddin',
            'phone' => '01711000999',
            'address_en' => '12 Station Road',
            'location_id' => $point->id,
            'credit_limit' => 0,
            'credit_days' => 0,
        ]);

        $this->entry(debit: '5000', date: '2026-08-01');
        $this->entry(credit: '1000', date: '2026-08-20');
    }

    private function entry(string $debit = '0', string $credit = '0', string $date = '2026-08-01'): void
    {
        LedgerEntry::create([
            'company_id' => $this->customer->company_id,
            'branch_id' => $this->customer->branch_id,
            'financial_year_id' => $this->company->currentFinancialYear()?->id,
            'account_id' => 1,
            'party_type' => Customer::drillSourceType(),
            'party_id' => $this->customer->id,
            'trx_date' => $date,
            'debit' => $debit,
            'credit' => $credit,
            'source_type' => 'sales_invoice',
            'source_id' => 1,
            'document_no' => 'WW-0001',
            'narration' => 'test',
        ]);
    }

    /** @return array<string, mixed> */
    private function rowIn(string $report): array
    {
        $rows = app(ReportEngine::class)->run($report, ['from' => '2026-08-01', 'to' => '2026-08-31'])->rows;
        $key = $report === 'customer.no_limit' ? 'id' : 'party_id';

        $row = collect($rows)->firstWhere($key, $this->customer->id);
        $this->assertNotNull($row, "{$report}: গ্রাহকটা রিপোর্টে নেই।");

        return $row;
    }

    public function test_the_four_reports_carry_code_name_point_area_and_mobile(): void
    {
        foreach (['customer.due_list', 'customer.ageing', 'customer.collection', 'customer.no_limit'] as $report) {
            $row = $this->rowIn($report);

            $this->assertSame($this->customer->code, $row['customer_code'], "{$report}: কোড");
            $this->assertStringContainsString('Who And Where Store', (string) $row['customer_name'], "{$report}: নাম");
            $this->assertStringNotContainsString($this->customer->code, (string) $row['customer_name'],
                "{$report}: নামের ঘরে এখনো কোড — কোড এখন নিজের কলামে।");
            $this->assertContains($row['point_name'], ['Report Point', 'রিপোর্ট পয়েন্ট'], "{$report}: পয়েন্ট");
            $this->assertContains($row['area_name'], ['Report Area', 'রিপোর্ট এরিয়া'],
                "{$report}: এরিয়া — `territory` ধাপটা পাওয়া যায়নি।");
            $this->assertSame('01711000999', $row['phone'], "{$report}: মোবাইল");
        }

        $due = $this->rowIn('customer.due_list');
        $this->assertSame('12 Station Road', $due['full_address']);
        $this->assertSame('Rahim Uddin', $due['owner_name']);
        $this->assertSame(0, bccomp((string) $due['outstanding'], '4000', 4));
    }

    public function test_ageing_says_when_the_last_bill_and_the_last_payment_were(): void
    {
        $row = $this->rowIn('customer.ageing');

        $this->assertSame('2026-08-01', substr((string) $row['last_billed'], 0, 10));
        $this->assertSame('2026-08-20', substr((string) $row['last_collected'], 0, 10));
        $this->assertArrayHasKey('credit_limit', $row);
    }

    public function test_the_list_and_the_due_list_show_the_owners_order(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $list = $this->actingAs($owner)->get(route('customer.index'))->assertOk();
        $list->assertSeeInOrder([
            __('customer::field.code'), __('customer::field.name'),
            __('master_data::level.point'), __('master_data::level.territory'),
            __('customer::field.full_address'), __('customer::field.owner_name'),
            __('customer::field.phone'), __('customer::field.outstanding'),
        ]);
        $list->assertSee('রিপোর্ট পয়েন্ট');
        $list->assertSee('রিপোর্ট এরিয়া');
        $list->assertSee('12 Station Road');

        $this->actingAs($owner)
            ->get(route('customer.report.show', ['slug' => 'due-list', 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertSeeInOrder([
                __('customer::field.code'), __('customer::field.name'),
                __('master_data::level.point'), __('master_data::level.territory'),
                __('customer::field.full_address'), __('customer::field.owner_name'), __('customer::field.phone'),
            ]);
    }
}
