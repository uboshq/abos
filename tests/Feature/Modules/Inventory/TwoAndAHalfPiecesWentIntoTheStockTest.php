<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\PackConversion;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * পিসের পণ্যে আড়াই পিস মজুদে বসত — Inventory অডিট ম১৯, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ ভগ্নাংশ না চলা এককের যাচাই কেবল অন্য এককে লেখা পরিমাণে চলত ([[PackConversion::toStockQty()]]): "½ বাক্স"
 * থামত, অথচ পণ্যের নিজের এককে "২.৫ পিস" সোজা কাগজে আর মজুদে যেত — গণনা কখনো মিলত না।
 * ⭐ এখন নিজের এককেও একই নিয়ম, প্রতিটা কাগজের সাধারণ দরজায় ([[ReadsPackedQuantities]])।
 */
final class TwoAndAHalfPiecesWentIntoTheStockTest extends TestCase
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

    public function test_a_piece_product_refuses_half_a_piece_in_its_own_unit(): void
    {
        $piece = $this->product('M19-PC', false);

        try {
            app(PackConversion::class)->toStockQty($piece, '2.5');
            $this->fail('⛔ পিসের পণ্যে আড়াই পিস চলল।');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('M19-PC', implode(' ', $e->validator->errors()->all()));
        }

        $this->assertSame('3', app(PackConversion::class)->toStockQty($piece, '3'), 'পুরো সংখ্যাও আটকে গেল।');
        $this->assertSame('2.5', app(PackConversion::class)->toStockQty($this->product('M19-KG', true), '2.5'), 'ভাঙা চলা এককেও আটকাল।');
    }

    public function test_a_bill_line_of_half_a_piece_is_refused_at_the_door(): void
    {
        $piece = $this->product('M19-BILL', false);

        $this->expectException(ValidationException::class);

        app(SalesInvoiceService::class)->create(
            ['customer_id' => Customer::query()->orderBy('id')->firstOrFail()->id,
                'warehouse_id' => Warehouse::query()->orderBy('id')->firstOrFail()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $piece->id, 'qty' => '2.5', 'rate' => '100']],
        );
    }

    /**
     * ⭐ একবার নিয়মটা ফেরানো হয়েছিল (c3c6a9b9) এই ভুল কারণে যে "কেজি-লিটারে কেউ টিক দেয় না"। কোম্পানির নিজের
     * KG আর LTR ভগ্নাংশ-চলা হয়েই বসে, PCS নয় — তাই ২.৫ কেজি চলে, ২.৫ পিস নয় (মালিক, ৬ অক্টোবর ২০২৬)।
     */
    public function test_the_companys_own_kg_and_litre_allow_fractions_and_pieces_do_not(): void
    {
        $unit = fn (string $code) => Unit::query()->where('code', $code)->firstOrFail();

        $this->assertTrue((bool) $unit('KG')->allows_fraction, '⛔ কোম্পানির কেজি ভগ্নাংশ-চলা নয়।');
        $this->assertTrue((bool) $unit('LTR')->allows_fraction, '⛔ কোম্পানির লিটার ভগ্নাংশ-চলা নয়।');
        $this->assertFalse((bool) $unit('PCS')->allows_fraction, '⛔ কোম্পানির পিস ভগ্নাংশ-চলা।');

        $sugar = Product::query()->create(['code' => 'M19-SUGAR', 'name_en' => 'Loose sugar', 'name_bn' => 'খোলা চিনি',
            'unit_id' => $unit('KG')->id, 'sale_price' => '140', 'is_active' => true]);
        $this->assertSame('2.5', app(PackConversion::class)->toStockQty($sugar, '2.5'), '⛔ ২.৫ কেজি চিনি আটকাল।');
    }

    private function product(string $code, bool $fraction): Product
    {
        $unit = Unit::query()->create(['code' => $code.'U', 'name_en' => $code.' unit', 'name_bn' => $code.' একক', 'factor' => '1',
            'allows_fraction' => $fraction, 'is_active' => true]);

        return Product::query()->create(['code' => $code, 'name_en' => $code, 'name_bn' => $code, 'unit_id' => $unit->id,
            'sale_price' => '100', 'is_active' => true]);
    }
}
