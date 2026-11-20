<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Purchase\Services\UnloadingSplit;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HiresTheLabour;
use Tests\TestCase;

/**
 * ⭐ মালিকের উদাহরণ, মাল গ্রহণে, পয়সা পর্যন্ত — আর মজুরি মজুদের দামে (মালিক, ১৮ নভেম্বর ২০২৬: "ওকে"; ২০ নভেম্বর ২০২৬ দাবি)।
 *
 * ⓘ মালিকের কথা: ১০০ কার্টন, ৫০ টাকা দেওয়া, ১০ কার্টন হাতে ১ টাকা = ১০ টাকা → বাকি ৪০ টাকা ৯০ কার্টনে সমান ভাগ।
 *   এখানে ৯০ কার্টন দুই সারিতে (৩০ + ৬০) → ১৩.৩৩ আর ২৬.৬৭ (শেষ সমান-ভাগের সারি বাকিটা নেয়)।
 * ⓘ কার্টন = পণ্যের কেনার প্যাক ([[UnloadingSplit::cartonFactors()]]) — তাই পরিমাণ কার্টন × প্যাকের মাপ, ডেমোর প্যাক যাই হোক।
 *
 * দাবি:
 *  - সারির ভাগ ১০.০০ · ১৩.৩৩ · ২৬.৬৭, যোগ হুবহু ৫০.০০০০।
 *  - খরচের স্তরের মোট মূল্য = মাল + ৫০, প্রতিটা সারির স্তর = সারির মাল + তার ভাগ; খাতায় ১১২০-এর ডেবিট ঠিক ততটাই।
 *  - বাতিলে স্তরে কিছু থাকে না, আর খাতায় ১১২০ আর টিল দুটোরই নিট শূন্য।
 */
final class TheUnloadingLandsInTheStockValueToThePaisaTest extends TestCase
{
    use HiresTheLabour;
    use RefreshDatabase;

    public function test_the_owners_example_splits_to_the_paisa_lands_in_the_layers_and_cancels_clean(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        config(['abos.unloading_required' => true]);

        $products = Product::query()->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)
            ->where('qc_required', false)->orderBy('id')->take(3)->get();
        $this->assertCount(3, $products, 'দৃশ্যটাই বানানো যায়নি — ডেমোতে তিনটা সাধারণ পণ্য নেই।');
        $products->each(fn (Product $p) => $p->forceFill(['unload_rate' => null])->save());

        $factors = UnloadingSplit::cartonFactors($products->pluck('id')->all());
        $till = $this->labourTill();

        // ⓘ ১০ কার্টন হাতে ১ টাকা · ৩০ কার্টন · ৬০ কার্টন — পরিমাণ মূল এককে
        $plan = [[$products[0], '10', '1'], [$products[1], '30', ''], [$products[2], '60', '']];
        $lines = array_map(fn (array $p) => [
            'product_id' => $p[0]->id,
            'received_qty' => bcmul($p[1], $factors[$p[0]->id], 4),
            'rate' => '100',
            'unload_rate' => $p[2],
        ], $plan);

        $receipts = app(PurchaseReceiptService::class);
        $draft = $receipts->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->firstOrFail()->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString(),
                'unload_mode' => 'cash', 'unload_amount' => '50', 'unload_account_id' => $till->id],
            $lines,
        );
        $this->assertSame(0, bccomp((string) $draft->unload_cartons, '100', 4), 'দৃশ্যটাই বানানো যায়নি — ১০০ কার্টন নয়।');

        $receipt = $receipts->confirm($draft->fresh());
        $rows = $receipt->lines()->orderBy('line_no')->get();

        $this->assertSame(['10.0000', '13.3300', '26.6700'], $rows->map(fn ($l) => bcadd((string) $l->unload_cost, '0', 4))->all(),
            '⛔ মালিকের উদাহরণ মেলেনি — হাতের ১০, বাকি ৪০ কার্টনপ্রতি সমান, শেষ সারি বাকিটা।');
        $this->assertSame('50.0000', $rows->reduce(fn (string $s, $l) => bcadd($s, (string) $l->unload_cost, 4), '0'),
            '⛔ ভাগের যোগফল দেওয়া ৫০ টাকা নয় — পয়সা হারাল বা বাড়ল।');

        // ⭐ মজুদের দামে — স্তর আর খাতা দুটোই মাল + ৫০
        $goods = bcadd((string) $receipt->total, '0', 4);
        $landed = bcadd($goods, '50', 4);
        $this->assertSame(0, bccomp($this->layerValue(PurchaseReceipt::STOCK_SOURCE, $receipt->id), $landed, 4),
            '⛔ খরচের স্তরে মজুরি বসেনি — মজুদের দাম কম দেখাচ্ছে।');
        $this->assertSame($landed, $this->booked(PurchaseReceipt::drillSourceType(), $receipt->id, StandardChart::INVENTORY, 'debit'),
            '⛔ খাতার মজুদ (১১২০) আর স্তরের দাম আলাদা — দুই খাতা মেলে না।');
        $this->assertSame('50.0000', $this->booked(PurchaseReceipt::drillSourceType(), $receipt->id, (int) $till->id, 'credit'));

        // ⓘ প্রতিটা সারির স্তর = সারির মাল + নিজের ভাগ
        foreach ($rows as $row) {
            $value = \App\Modules\Inventory\Models\CostLayer::query()
                ->where('source_type', PurchaseReceipt::STOCK_SOURCE)->where('source_id', $receipt->id)
                ->where('product_id', $row->product_id)->get()
                ->reduce(fn (string $s, $l) => bcadd($s, bcmul((string) $l->qty_in, (string) $l->unit_cost, 8), 8), '0');
            $this->assertSame(0, bccomp($value, bcadd(bcmul((string) $row->received_qty, '100', 4), (string) $row->unload_cost, 4), 4),
                "⛔ সারি {$row->line_no}-এর স্তরে নিজের ভাগ বসেনি।");
        }

        // ⭐ বাতিল — স্তর খালি, খাতায় নিট শূন্য
        $receipts->cancel($receipt->fresh(), 'Wrong lorry');

        $this->assertSame(0, bccomp($this->layerValue(PurchaseReceipt::STOCK_SOURCE, $receipt->id, 'qty_remaining'), '0', 4),
            '⛔ বাতিলের পরেও স্তরে মাল বা মজুরির দাম রয়ে গেল।');

        foreach ([StandardChart::INVENTORY, (int) $till->id] as $account) {
            $net = '0';
            foreach ([PurchaseReceipt::drillSourceType(), PurchaseReceipt::drillSourceType().':reversal'] as $source) {
                $net = bcadd($net, bcsub($this->booked($source, $receipt->id, $account, 'debit'), $this->booked($source, $receipt->id, $account, 'credit'), 4), 4);
            }
            $this->assertSame(0, bccomp($net, '0', 4), "⛔ বাতিলের পরে খাত {$account}-এ মজুরি রয়ে গেল (নিট {$net})।");
        }
    }
}
