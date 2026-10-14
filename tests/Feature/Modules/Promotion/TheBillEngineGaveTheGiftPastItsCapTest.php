<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Models\PromotionCondition;
use App\Modules\Promotion\Services\BillPromotionEngine;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\ConditionKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * গোটা বিলের ইঞ্জিন উপহারের প্রতি-বিল সীমা ভুলে গেল — Promotion লাইন-ধরা নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⓘ [[PromotionEngine::worthOf()]] উপহারের পরিমাণে `cap_per_bill` মানে, আর `worth`-ও সেই পরিমাণে।
 * ⛔ [[BillPromotionEngine::offersForBill()]] সারির অফারের `gift_qty` লিখত ধাপের কাঁচা অঙ্ক থেকে — *"৫টা
 * ফ্রি, বিলে সর্বোচ্চ ২"*-এ `worth` বলত ২টার দাম, অথচ `gift_qty` বলত ৫।
 */
final class TheBillEngineGaveTheGiftPastItsCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_a_line_gift_on_the_bill_keeps_the_per_bill_cap(): void
    {
        $product = Product::query()->orderBy('id')->firstOrFail();

        $offer = new Promotion([
            'name_en' => 'Five free, two a bill',
            'name_bn' => 'পাঁচটা ফ্রি, বিলে দুইটা',
            'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addWeek(),
            'priority' => 999,
        ]);
        $offer->code = 'PROM-GC-0001';
        $offer->type = PromotionType::BUY_X_GET_Y;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::ADDS;
        $offer->created_by = auth()->id();
        $offer->save();

        $step = PromotionCondition::query()->create([
            'promotion_id' => $offer->id,
            'kind' => ConditionKind::QUANTITY,
            'value_from' => '1',
            'value_to' => null,
            'step_order' => 1,
        ]);

        PromotionBenefit::query()->create([
            'promotion_id' => $offer->id,
            'promotion_condition_id' => $step->id,
            'kind' => BenefitKind::GOODS,
            'amount' => '5',
            'gift_product_id' => $product->id,
            'gift_unit_id' => $product->unit_id,
            'cap_per_bill' => '2',
        ]);

        $rows = app(BillPromotionEngine::class)->offersForBill([[
            'line_id' => 1,
            'product_id' => (int) $product->id,
            'qty' => '100',
            'value' => '10000',
        ]]);

        $row = $rows->first(fn (array $r) => (int) $r['promotion']->id === (int) $offer->id);

        $this->assertNotNull($row, 'অফারটা বিলে খাটেইনি।');
        $this->assertSame(0, bccomp('2', (string) $row['gift_qty'], 4),
            'বিলে সর্বোচ্চ ২টা উপহার, অথচ গোটা বিলের ইঞ্জিন বলছে '.$row['gift_qty'].'।');
    }
}
