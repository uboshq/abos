<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ পরিবহনকারীর শুরুর দেনা মালের দেনায় (২১১১) বসত (Supplier নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[SupplierService::openingAccountFor()]])।
 *
 * ⚠️ ভাড়া আর মজুরির পরের সব দাখিলা ২১১৬/২১১৭-এ বসে, আর পরিবহন-হাম্মালির খতিয়ান ওই ঘরই পড়ে — তাই শুরুর ৩,০০০ টাকা সেখানে
 * দেখাতই না, আর মালের দেনায় ভাড়ার টাকা মিশত। ⭐ এখন পরিবহনকারী ২১১৬-এ, হাম্মালি ঠিকাদার ২১১৭-এ, মালের সরবরাহকারী ২১১১-এ।
 */
final class ATransportersOpeningLandedInTradePayablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_kind_of_supplier_opens_on_its_own_payable(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $type = fn (string $code, string $en) => PartyType::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => $code],
            ['name_en' => $en, 'name_bn' => $en, 'applies_to' => PartyType::SUPPLIER, 'is_active' => true],
        );

        foreach ([['TRANSPORT', StandardChart::TRANSPORT_PAYABLE], ['LABOUR', StandardChart::LABOUR_PAYABLE], [null, StandardChart::PAYABLE]] as [$code, $account]) {
            $supplier = app(SupplierService::class)->create([
                'name_en' => 'Opening '.($code ?? 'GOODS'), 'opening_balance' => '3000',
                'party_type_id' => $code === null ? null : $type($code, $code)->id,
            ]);

            $row = LedgerEntry::query()->where('party_type', Supplier::drillSourceType())->where('party_id', $supplier->id)
                ->where('credit', '>', 0)->firstOrFail();

            $this->assertSame((int) StandardChart::find($account)->id, (int) $row->account_id,
                '⛔ '.($code ?? 'মালের সরবরাহকারী').'-এর শুরুর দেনা ভুল ঘরে বসল');
        }
    }
}
