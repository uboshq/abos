<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ⛔ গ্রাহকের নিজের পাতার চারটা ভুল সংখ্যা (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; পোর্টাল)।
 *
 * ⚠️ (ক) সাম্প্রতিক বিলে প্রতিটা বিল ০.০০ — পাতা `grand_total` পড়ত, যে ঘর নেই; বিলের ঘর `total`। (খ) খসড়া আর বাতিল বিল জীবন্ত
 * বিলের মতো বসত। (গ) অগ্রিম "বকেয়া -৫,০০০.০০" — খাতার পাতা একই অঙ্ক "(Cr) ৫,০০০.০০" বলে (মালিক: খালি বিয়োগ নয়)।
 * (ঘ) মূল সীমা ০ আর চলতি অস্থায়ী সীমা ৫০,০০০ — পাতা "নগদ/অগ্রিম" বলত, অথচ কাউন্টার ৫০,০০০ পর্যন্ত ধার দিত।
 */
final class ThePortalHomeReadEveryBillAsZeroTest extends TestCase
{
    use RefreshDatabase;

    private Customer $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();

        $this->shop = Customer::query()->create([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'code' => 'PORTAL-Z', 'name_en' => 'Zero Bill Shop',
            'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
        $this->shop->forceFill(['portal_password' => Hash::make('customer-pass'), 'portal_enabled' => true])->save();
    }

    private function bill(string $no, string $total, string $status): void
    {
        SalesInvoice::query()->create([
            'branch_id' => $this->shop->branch_id, 'document_no' => $no, 'customer_id' => $this->shop->id,
            'trx_date' => now()->toDateString(), 'due_on' => now()->addDays(30)->toDateString(),
            'subtotal' => $total, 'discount' => '0', 'tax' => '0', 'total' => $total, 'status' => $status,
        ]);
    }

    public function test_the_home_page_shows_the_real_bills_with_their_totals(): void
    {
        $this->bill('INV-PZ-LIVE', '15000', DocumentStatus::CONFIRMED);
        $this->bill('INV-PZ-DRAFT', '999', DocumentStatus::DRAFT);
        $this->bill('INV-PZ-GONE', '888', DocumentStatus::CANCELLED);

        $this->actingAs($this->shop, 'portal')->get(route('sales.portal.home'))
            ->assertOk()
            ->assertSee('INV-PZ-LIVE')
            ->assertSee(Money::format('15000'))
            ->assertDontSee('INV-PZ-DRAFT')
            ->assertDontSee('INV-PZ-GONE');
    }
}
