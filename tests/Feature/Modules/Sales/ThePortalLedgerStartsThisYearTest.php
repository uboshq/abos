<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ⛔ পোর্টালের খতিয়ান ডিফল্টে ১৯৭০ থেকে নয়, চলতি অর্থবছরের শুরু থেকে (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, বিক্রয় ৯;
 * [[PortalController::range()]], [[CustomerPapers::yearStart()]])।
 *
 * ⓘ একই বদল uboshq/abos#12-এও আছে, হুবহু একই লেখায় — যেটা আগে মেলে, অন্যটা কোনো সংঘাত ছাড়াই বসে যায়।
 */
final class ThePortalLedgerStartsThisYearTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_ledger_opens_on_this_financial_year_not_1970(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $customer = Customer::query()->create([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'code' => 'LEDGER-1',
            'name_en' => 'Ledger Customer', 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
        $customer->forceFill(['portal_enabled' => true, 'portal_password' => Hash::make('shop-pass-2026')])->save();
        $this->post(route('sales.portal.login.attempt'), ['code' => 'LEDGER-1', 'password' => 'shop-pass-2026']);

        $from = $this->get(route('sales.portal.ledger'))->assertOk()->viewData('from');

        $this->assertNotSame('1970-01-01', $from, '⛔ খতিয়ান ১৯৭০ থেকে');
        $this->assertTrue($from <= now()->toDateString() && $from >= now()->subYear()->toDateString(), 'চলতি অর্থবছরের শুরু: '.$from);
    }
}
