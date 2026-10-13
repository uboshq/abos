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

        // ⓘ দাবি নিজেই অন্য কোম্পানির কারণ বানায় — ডেমোর উপর নির্ভর করে বাদ পড়ে না (১৩ অক্টোবর ২০২৬)
        $other = Company::query()->whereKeyNot($company->id)->orderBy('id')->firstOrFail();
        $foreign = ReasonCode::query()->withoutGlobalScopes()->create([
            'company_id' => $other->id, 'code' => 'RSN-FOREIGN', 'name_en' => 'Foreign reason', 'name_bn' => 'অন্য কোম্পানির কারণ',
            'context' => ReasonCode::STOCK_ADJUSTMENT, 'is_active' => true,
        ]);

        // ⓘ আর এই কোম্পানিরই মুছে ফেলা কারণ — `exists` মোছা সারিও পায়, তারপর মোছা-ছাঁকা findOrFail খালি ৪০৪ দিত
        $deleted = ReasonCode::query()->create([
            'company_id' => $company->id, 'code' => 'RSN-GONE', 'name_en' => 'Deleted reason', 'name_bn' => 'মোছা কারণ',
            'context' => ReasonCode::STOCK_ADJUSTMENT, 'is_active' => true,
        ]);
        $deleted->delete();

        $paper = StockCount::query()->create([
            'document_no' => 'CNT-RSN-1', 'count_date' => now()->toDateString(),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id, 'status' => 'draft',
        ]);

        foreach ([$foreign->id, $deleted->id] as $reasonId) {
            $this->from(route('inventory.count.show', $paper))
                ->post(route('inventory.count.approve', $paper), ['reason_code_id' => $reasonId])
                ->assertSessionHasErrors('reason_code_id');
        }
    }
}
