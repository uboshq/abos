<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⓘ পেছনের তারিখের বিক্রি পরে আসা স্তর থেকে খরচ টানলে অডিটে চিহ্ন (পুরো-ERP অডিট, মজুদ ছ১০; fe-র সিদ্ধান্ত (গ), ১০ অক্টোবর ২০২৬)।
 *
 * স্তর ক: ১০ দিন আগে, ৫টা ১০ টাকায়; স্তর খ: ২ দিন আগে, ৫টা ২০ টাকায়। গতকালের বিক্রি ৫টা — স্তর ক শেষ। তারপর পাঁচ দিন
 * আগের তারিখে ২টা — নিজের দিনের আগের স্তর নেই, তাই স্তর খ (পরের তারিখের) থেকে। ⓘ খরচ বদলায় না (৪০), খাতাও না — কেবল
 * চিহ্ন: কোন কাগজ, কত, কোন তারিখের স্তর। পুরো সারাই (পরের বিক্রির খরচ নতুন করে) মালিকের সিদ্ধান্তে, ফ্রিজের পরে।
 */
final class ALaterLayerLeavesAMarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_backdated_sale_that_draws_a_later_layer_is_marked_and_nothing_else_moves(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $product = Product::query()->create(['code' => 'LL-'.mb_substr(md5(microtime()), 0, 6), 'name_en' => 'Layer probe',
            'name_bn' => 'স্তরের নমুনা', 'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true]);
        $costs = app(CostLayerService::class);
        $costs->receive(product: $product, qty: '5', unitCost: '10', sourceType: 'test.in', sourceId: 1, date: now()->subDays(10));
        $costs->receive(product: $product, qty: '5', unitCost: '20', sourceType: 'test.in', sourceId: 2, date: now()->subDays(2));
        $marks = fn () => AuditTrail::query()->where('action', 'cost_from_later_layer')->where('auditable_id', $product->id);

        // ⓘ নিজের দিনের আগের স্তর থেকেই — চিহ্ন নয়
        $costs->issue(product: $product, qty: '5', sourceType: 'test.sale', sourceId: 1, documentNo: 'LL-SALE-1', date: now()->subDay());
        $this->assertSame(0, $marks()->count(), '⛔ ঠিক স্তর থেকে টানলেও চিহ্ন পড়ল।');

        $ledger = LedgerEntry::query()->count();
        $drawn = $costs->issue(product: $product, qty: '2', sourceType: 'test.sale', sourceId: 2, documentNo: 'LL-SALE-2', date: now()->subDays(5));

        $this->assertSame(0, bccomp($drawn['cost'], '40', 4), '⛔ খরচ বদলে গেল — চিহ্ন কেবল চিহ্ন।');
        $this->assertSame($ledger, LedgerEntry::query()->count(), '⛔ চিহ্ন দিতে গিয়ে খাতায় কিছু লেখা হলো।');

        $mark = $marks()->sole();
        foreach (['LL-SALE-2', now()->subDays(5)->toDateString(), ': 2 of', now()->subDays(2)->toDateString()] as $part) {
            $this->assertStringContainsString($part, (string) $mark->reason, "⛔ চিহ্নে '{$part}' নেই — কোন কাগজ, কোন দিন, কত, কোন স্তর।");
        }
    }
}
