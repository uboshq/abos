<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ কার্টনের মাপ পণ্যের — ধাপ ১: জায়গাটা, আর তার দুইটা দেয়াল।
 *
 * ⓘ সাবানে ১ কার্টন = ২৪, বিস্কুটে ১ কার্টন = ৪৮ — একই "কার্টন" এককে,
 * দুই পণ্যে দুই মাপ। এককের মাস্টারে এটা লেখা যেত না।
 *
 * ⚠️ এই ধাপে কোনো কোড টেবিলটা পড়ে না। তাই এখানে শুধু দাবি: দুই মাপ
 * পাশাপাশি থাকে, অঙ্ক হারায় না, আর ডেটাবেস নিজেই দুইটা ভুল আটকায়।
 */
final class EachProductKeepsItsOwnPackSizesTest extends TestCase
{
    use RefreshDatabase;

    private Unit $piece;

    private Unit $carton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->piece = $this->unit('TPCS', 'Test piece');
        $this->carton = $this->unit('TCTN', 'Test carton');
    }

    private function unit(string $code, string $name): Unit
    {
        return Unit::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => $code,
            'name_en' => $name,
            'factor' => 1,
            'allows_fraction' => false,
            'is_active' => true,
        ]);
    }

    private function product(string $code): Product
    {
        return Product::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => $code,
            'name_en' => $code,
            'unit_id' => $this->piece->id,
            'is_active' => true,
        ]);
    }

    private function pack(Product $product, Unit $unit, string $factor, ?string $barcode = null): ProductUnit
    {
        return ProductUnit::query()->create([
            'company_id' => CompanyContext::id(),
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'factor' => $factor,
            'barcode' => $barcode,
        ]);
    }

    /**
     * ⭐ একই কার্টন, দুই পণ্যে দুই মাপ — আর বড়টা আগে আসে।
     */
    public function test_one_carton_can_hold_different_counts_for_different_products(): void
    {
        $soap = $this->product('TSOAP');
        $biscuit = $this->product('TBISC');

        $this->pack($soap, $this->piece, '1');
        $this->pack($soap, $this->carton, '24');
        $this->pack($biscuit, $this->piece, '1');
        $this->pack($biscuit, $this->carton, '48');

        $this->assertSame(['24.000000', '1.000000'], $soap->packs()->pluck('factor')->all());
        $this->assertSame(['48.000000', '1.000000'], $biscuit->packs()->pluck('factor')->all());

        $this->assertTrue($soap->packs()->where('unit_id', $this->piece->id)->first()->isBase());
        $this->assertFalse($soap->packs()->where('unit_id', $this->carton->id)->first()->isBase());
    }

    /**
     * ⭐ ছয় দশমিক পর্যন্ত অঙ্ক হারায় না — ⅓ কেজির প্যাকেও।
     */
    public function test_the_factor_keeps_six_decimals(): void
    {
        $pack = $this->pack($this->product('TRICE'), $this->carton, '0.333333');

        $this->assertSame('0.333333', $pack->fresh()->factor);
        $this->assertNotEmpty($pack->public_id, 'নতুন প্যাকের বাইরের কী বসেনি।');
    }

    /**
     * ⛔ একটা পণ্যে একই একক দুইবার নয় — দুইটা "কার্টন" দুই মাপে থাকলে
     * কোনটা সত্যি, কেউ বলতে পারত না।
     */
    public function test_the_same_unit_cannot_appear_twice_on_one_product(): void
    {
        $soap = $this->product('TSOAP');
        $this->pack($soap, $this->carton, '24');

        $this->expectException(QueryException::class);
        $this->pack($soap, $this->carton, '12');
    }

    /**
     * ⛔ একটা বারকোড কোম্পানিতে একটাই — নইলে স্ক্যানে কোন পণ্য, বলা যেত না।
     * ⭐ কিন্তু বারকোড ছাড়া প্যাক যত খুশি।
     */
    public function test_a_barcode_belongs_to_one_pack_but_packs_may_have_none(): void
    {
        $soap = $this->product('TSOAP');
        $biscuit = $this->product('TBISC');

        $this->pack($soap, $this->piece, '1');
        $this->pack($biscuit, $this->piece, '1');
        $this->assertSame(2, ProductUnit::query()->whereNull('barcode')->count(), 'বারকোড ছাড়া দুইটা প্যাক বসেনি।');

        $this->pack($soap, $this->carton, '24', '8901234567890');

        $this->expectException(QueryException::class);
        $this->pack($biscuit, $this->carton, '48', '8901234567890');
    }
}
