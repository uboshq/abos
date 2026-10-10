<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ বদলির ফর্ম প্যাকের একক ফেলে দিত (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬, মজুদ ⓘ১৬)।
 *
 * ⓘ [[StockTransferService]] প্যাক বোঝে ("২ কার্টন" → ২৪ পিস, লাইনে লেখা এককও থাকে), কিন্তু [[StockTransferRequest]]-এর নিয়মে
 * `lines.*.unit_id` ছিল না — `validated()` ঘরটা ফেলে দিত, আর সেবা পেত "২ পিস"। এখন কোম্পানির নিজের এককে লেখা যায়; অন্য
 * কোম্পানির একক থামে।
 */
final class ATransferLineKeepsItsPackTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_cartons_go_as_twenty_four_pieces(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $piece = Unit::query()->where('code', 'PCS')->firstOrFail();
        $carton = Unit::query()->create(['code' => 'Q16CTN', 'name_en' => 'Carton of 12', 'name_bn' => '১২-র কার্টন',
            'base_unit_id' => $piece->id, 'factor' => '12', 'allows_fraction' => false, 'is_active' => true]);
        $product = Product::query()->create(['code' => 'Q16-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Pack probe',
            'name_bn' => 'প্যাকের নমুনা', 'unit_id' => $piece->id, 'is_active' => true]);
        $from = Warehouse::query()->where('is_default', true)->firstOrFail();
        $to = Warehouse::query()->create(['code' => 'Q16-TO', 'name_en' => 'Pack to', 'is_active' => true, 'branch_id' => $from->branch_id]);

        $this->post(route('inventory.transfer.store'), [
            'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'trx_date' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'qty' => '2', 'unit_id' => $carton->id]],
        ])->assertSessionHasNoErrors();

        $line = StockTransfer::query()->latest('id')->firstOrFail()->lines()->sole();
        $this->assertSame(0, bccomp((string) $line->qty, '24', 4), "⛔ ২ কার্টন লেখা হল, অথচ বদলিতে {$line->qty} পিস।");
        $this->assertSame((int) $carton->id, (int) $line->entered_unit_id, '⛔ লাইনে লেখা এককটা থাকল না।');

        // ⛔ অন্য কোম্পানির একক — থামে
        $other = Company::query()->where('id', '!=', $company->id)->orderBy('id')->firstOrFail();
        $foreign = Unit::query()->withoutGlobalScopes()->create(['company_id' => $other->id, 'code' => 'Q16X', 'name_en' => 'Foreign',
            'name_bn' => 'অন্যের', 'base_unit_id' => null, 'factor' => '1', 'allows_fraction' => false, 'is_active' => true]);
        $this->post(route('inventory.transfer.store'), [
            'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'trx_date' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'qty' => '2', 'unit_id' => $foreign->id]],
        ])->assertSessionHasErrors('lines.0.unit_id');
    }
}
