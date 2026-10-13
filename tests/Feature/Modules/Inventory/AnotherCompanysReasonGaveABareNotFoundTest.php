<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * অন্য কোম্পানির কারণ-কোড গণনা মেনে নেওয়ার যাচাই পার হত — মজুদ লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⛔ নিয়মটা ছিল `exists:mdm_reason_codes,id` — কোম্পানি ছাড়া। অন্য কোম্পানির নম্বর যাচাই পার হয়ে কোম্পানি-ছাঁকা
 * `findOrFail`-এ খালি ৪০৪ দিত, ঘরের পাশে বাংলা বার্তা নয়। ⓘ মজুদের বাকি দরজা ([[StockController]]) আগে থেকেই কোম্পানিতে বাঁধা।
 */
final class AnotherCompanysReasonGaveABareNotFoundTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reason_of_another_company_is_a_field_error(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $foreign = ReasonCode::query()->withoutGlobalScopes()->where('company_id', '!=', $company->id)->first();

        if ($foreign === null) {
            $this->markTestSkipped('ডেমোতে দ্বিতীয় কোম্পানির কারণ-কোড নেই।');
        }

        $paper = StockCount::query()->create([
            'document_no' => 'CNT-RSN-1', 'count_date' => now()->toDateString(),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id, 'status' => 'draft',
        ]);

        $this->from(route('inventory.count.show', $paper))
            ->post(route('inventory.count.approve', $paper), ['reason_code_id' => $foreign->id])
            ->assertSessionHasErrors('reason_code_id');
    }
}
