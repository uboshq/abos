<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\CostLayerUse;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * লট বাছা বিক্রিতে খরচ আসে সেই লটের নিজের স্তর থেকে — চূড়ান্ত অডিট, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * [[CostLayerService::issue()]] পণ্য ধরে FIFO টানত; স্তর লট চিনত না। গুদাম থেকে LOT-NEW (৩,৮০০) বেরোলেও
 * খাতায় বসত LOT-OLD-এর ৩,০০০।
 *
 * ── এখানে কী মাপা ──────────────────────────────────────────────────────
 *   লট দিলে তার নিজের স্তর — পুরনো লট আগে থাকলেও।
 *   লটের স্তরে না কুলালে FIFO-তে পড়ে, কিন্তু **নীরবে নয়**: সারিতে `fallback`, নিরীক্ষায় ঘটনা; আর আগে লটহীন
 *   স্তর, অন্য লটের নিজের স্তর পরে।
 *   লট না দিলে আগের FIFO হুবহু, কোনো চিহ্ন নয়।
 * ⓘ বিক্রির পুরো পথের দাবি: [[TheChosenLotLeavesWithItsOwnCostTest]]।
 */
final class ALotSoldDrawsItsOwnCostTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private CostLayerService $layers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⓘ নতুন পণ্য — ডেমোর পণ্যে আগে থেকেই স্তর আছে, আর তারা FIFO-র অঙ্ক বদলে দিত
        $this->product = app(ProductService::class)->create([
            'name_en' => 'Lot Cost Proof Dal',
            'name_bn' => 'লট খরচ প্রমাণ ডাল',
            'unit_id' => Unit::query()->where('code', 'PCS')->value('id'),
            'purchase_price' => '100',
            'sale_price' => '150',
        ]);
        $this->layers = app(CostLayerService::class);
    }

    public function test_the_chosen_lot_draws_its_own_layer_even_behind_an_older_lot(): void
    {
        $old = $this->lot('LOT-OLD');
        $new = $this->lot('LOT-NEW');
        $this->layers->receive($this->product, '100', '3000', 'test', 1, 'IN-1', '2026-01-01', batch: $old);
        $this->layers->receive($this->product, '100', '3800', 'test', 2, 'IN-2', '2026-02-01', batch: $new);

        $taken = $this->layers->issue($this->product, '10', 'test:out', 9, 'OUT-9', batch: $new);

        $this->assertSame(0, bccomp($taken['cost'], '38000', 4), '⛔ LOT-NEW বেচা, অথচ খরচ '.$taken['cost'].' — অন্য লটের দাম।');
        $this->assertSame([false], array_map(fn (CostLayerUse $u) => (bool) $u->fallback, $taken['uses']));
    }

    public function test_a_lot_without_its_own_layer_falls_back_loudly_and_spares_other_lots(): void
    {
        $lotless = $this->lot('LOT-NONE');
        $other = $this->lot('LOT-OTHER');

        // ⓘ অন্য লটের স্তর সবচেয়ে পুরনো — সাধারণ FIFO হলে ওটাই আগে যেত
        $this->layers->receive($this->product, '50', '2000', 'test', 1, 'IN-1', '2026-01-01', batch: $other);
        $this->layers->receive($this->product, '5', '2500', 'test', 2, 'IN-2', '2026-02-01');
        $this->layers->receive($this->product, '50', '2700', 'test', 3, 'IN-3', '2026-03-01');

        $taken = $this->layers->issue($this->product, '8', 'test:out', 9, 'OUT-9', batch: $lotless);

        // ৫ × ২,৫০০ + ৩ × ২,৭০০ — দুইটাই লটহীন; LOT-OTHER-এর ২,০০০ ছোঁয়া হয়নি
        $this->assertSame(0, bccomp($taken['cost'], '20600', 4),
            '⛔ খরচ '.$taken['cost'].' — লটহীন স্তরের আগে অন্য লটের নিজের স্তর খাওয়া হয়েছে।');
        $this->assertSame([true, true], array_map(fn (CostLayerUse $u) => (bool) $u->fallback, $taken['uses']),
            '⛔ FIFO-তে পড়া টান চিহ্নিত নয় — নীরবে লটের দামের ভান করছে।');

        $event = AuditTrail::query()
            ->where('auditable_type', $lotless->getMorphClass())
            ->where('auditable_id', $lotless->id)
            ->where('action', 'lot_cost_fell_back')
            ->sole();
        $this->assertStringContainsString('OUT-9: 8 of', (string) $event->reason);
    }

    public function test_without_a_lot_nothing_changes(): void
    {
        $this->layers->receive($this->product, '5', '100', 'test', 1, 'IN-1', '2026-01-01', batch: $this->lot('LOT-A'));
        $this->layers->receive($this->product, '5', '200', 'test', 2, 'IN-2', '2026-02-01');

        $taken = $this->layers->issue($this->product, '7', 'test:out', 9, 'OUT-9');

        $this->assertSame(0, bccomp($taken['cost'], '900', 4), 'লট ছাড়া FIFO বদলে গেছে।');
        $this->assertSame([false, false], array_map(fn (CostLayerUse $u) => (bool) $u->fallback, $taken['uses']));
        $this->assertSame(0, AuditTrail::query()->where('action', 'lot_cost_fell_back')->count());
    }

    private function lot(string $no): Batch
    {
        return Batch::query()->create([
            'company_id' => CompanyContext::id(),
            'product_id' => $this->product->id,
            'batch_no' => $no,
        ]);
    }
}
