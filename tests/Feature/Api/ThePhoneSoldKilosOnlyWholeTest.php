<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Sync\ProductSync;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ ফোন জানে কোন একক দশমিক নেয় — পূর্ণ নিরীক্ষা §৯ ⚠️১০, ১৮ অক্টোবর ২০২৬ ([[ProductSync]])।
 *
 * ⛔ আগে পণ্যের সিঙ্কে এককের `allows_fraction` যেত না, তাই ফোনের কাউন্টার কেজি-লিটারেও কেবল পূর্ণ সংখ্যা নিত।
 *
 * দাবি: দশমিক-নেওয়া এককের পণ্যে `unitAllowsFraction` = true; পূর্ণ এককের পণ্যে ঘরটা নেই বা false।
 */
final class ThePhoneSoldKilosOnlyWholeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_product_sync_says_which_unit_takes_a_fraction(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $sync = app(ProductSync::class);
        $products = Product::query()->soldInViewedBranch()->whereNotNull('unit_id')->orderBy('id')->limit(2)->get();
        $this->assertCount(2, $products, '⛔ দাবির ভিত্তি নেই — দেখার শাখায় দুটো পণ্য নেই');

        [$kilo, $piece] = $products->all();
        $kiloUnit = Unit::query()->findOrFail($kilo->unit_id);
        $pieceUnit = $piece->unit_id === $kilo->unit_id
            ? Unit::query()->create(['code' => 'PCS-T', 'name_en' => 'Piece', 'name_bn' => 'পিস', 'allows_fraction' => false])
            : Unit::query()->findOrFail($piece->unit_id);
        $kiloUnit->forceFill(['allows_fraction' => true])->save();
        $pieceUnit->forceFill(['allows_fraction' => false])->save();
        $piece->forceFill(['unit_id' => $pieceUnit->id])->save();

        $payloads = collect($sync->pull($owner, null, 500)->records)->keyBy(fn ($r) => $r->entityId)->map(fn ($r) => $r->payload);

        $this->assertTrue($payloads[(string) $kilo->public_id]['unitAllowsFraction'] ?? false, '⛔ কেজির পণ্যে দশমিকের খবর গেল না');
        $this->assertFalse((bool) ($payloads[(string) $piece->public_id]['unitAllowsFraction'] ?? false), '⛔ পিসের পণ্য দশমিক নেয় বলে গেল');
    }
}
