<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⛔ ফোনের প্রিন্সিপালের খাতা "নতুন আগে" বলত, অথচ পাতা ১-এ সবচেয়ে পুরনো ৫০টা দিত — পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⚠️১৮।
 *
 * পঞ্চাশের বেশি সারি হলে আজকের বিল শেষ পাতায় লুকাত, আর প্রথম সারির জের মাথার জের হত না।
 *
 * ⭐ দাবি: পাতা ১ = খাতার শেষ পাতা (ওয়েবের খোলা পাতা), নতুন আগে, প্রথম সারির জের = মাথার জের; পাতা ২ ঠিক তার আগের সারিগুলো, ফাঁক বা দোহারা
 * ছাড়া; শেষে `next_page` নেই; খাতার বাইরের পাতা খালি। ([[PurchaseApiController::principal()]])
 */
final class ThePhoneLedgerShowedTheOldestFirstTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_one_is_the_newest_fifty_and_the_pages_walk_back_without_a_gap(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $supplier = Supplier::query()->onlySuppliers()->orderBy('id')->firstOrFail();
        $bills = app(PurchaseBillService::class);
        $bills->confirm($bills->create(['supplier_id' => $supplier->id, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->orderBy('id')->firstOrFail()->id, 'qty' => '10', 'rate' => '100']]));

        // ⓘ ১২০টা পুরনো সারি — বিলের খাতার সারির নকল, প্রতিদিন একটা করে পেছনে, প্রতিটায় আলাদা নম্বর
        $seed = (array) DB::table('ledger_entries')->where('party_type', Supplier::drillSourceType())->where('party_id', $supplier->id)->orderBy('id')->first();
        $this->assertNotEmpty($seed, 'দাবির ভিত্তি নেই — বিলের খাতার সারি নেই।');
        unset($seed['id']);
        foreach (range(1, 120) as $n) {
            DB::table('ledger_entries')->insert([...$seed, 'trx_date' => now()->subDays(200 - $n)->toDateString(),
                'document_no' => 'OLD-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'debit' => '0', 'credit' => (string) $n,
                ...(array_key_exists('public_id', $seed) ? ['public_id' => (string) \Illuminate\Support\Str::uuid()] : [])]);
        }
        $all = LedgerEntry::query()->forParty(Supplier::drillSourceType(), $supplier->id)->count();
        $this->assertGreaterThan(100, $all);

        $one = $this->phone($owner, $supplier, 1);
        $dates = array_column($one['entries'], 'date');
        // ⓘ খাতার শেষ পাতা — ওয়েবের খোলা পাতার একই সারি (পাতার সীমা পুরনো দিক থেকে, তাই প্রথম পাতা ৫০-এর কম হতে পারে)
        $this->assertCount($all % 50 ?: 50, $one['entries']);
        $this->assertSame(now()->toDateString(), $dates[0], '⛔ পাতা ১-এ আজকের সারি সবার আগে নেই — পুরনো আগে আসে।');
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates, '⛔ পাতার ভেতরে নতুন আগে নয়।');
        $this->assertSame(0, bccomp((string) $one['entries'][0]['balance'], (string) $one['balance'], 4), '⛔ প্রথম সারির জের মাথার জের নয়।');
        $this->assertSame(2, $one['next_page']);

        $two = $this->phone($owner, $supplier, 2);
        $three = $this->phone($owner, $supplier, 3);
        $nos = [...array_column($one['entries'], 'no'), ...array_column($two['entries'], 'no'), ...array_column($three['entries'], 'no')];
        $this->assertCount($all, $nos, '⛔ তিন পাতায় খাতার সব সারি নেই।');
        $this->assertSame($nos, array_values(array_unique($nos)), '⛔ এক সারি দুই পাতায়।');
        $walk = [...array_column($one['entries'], 'date'), ...array_column($two['entries'], 'date'), ...array_column($three['entries'], 'date')];
        $back = $walk;
        rsort($back);
        $this->assertSame($back, $walk, '⛔ পাতা ২ ঠিক পাতা ১-এর আগের সারি থেকে শুরু হয় না।');
        $this->assertSame('OLD-001', $three['entries'][array_key_last($three['entries'])]['no'], '⛔ শেষ পাতার শেষ সারি সবচেয়ে পুরনো নয়।');
        $this->assertNull($three['next_page'], '⛔ শেষ পাতার পরেও পরের পাতা।');
        // ⓘ জের সারি থেকে সারিতে মেলে, পাতার সীমাতেও — পাতা ২-এর প্রথম জের = পাতা ১-এর শেষ জের − ঐ সারির টাকা
        $last = $one['entries'][array_key_last($one['entries'])];
        $this->assertSame(0, bccomp(bcsub((string) $last['balance'], bcsub((string) $last['credit'], (string) $last['debit'], 4), 4), (string) $two['entries'][0]['balance'], 4),
            '⛔ পাতার সীমায় জের ভাঙে।');

        $beyond = $this->phone($owner, $supplier, 9);
        $this->assertSame([[], null], [$beyond['entries'], $beyond['next_page']]);
    }

    /** @return array<string, mixed> */
    private function phone(User $owner, Supplier $supplier, int $page): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($owner->fresh(), [AuthController::APP]);

        return $this->getJson('/api/v1/purchase/principals/'.$supplier->public_id.'?page='.$page)->assertOk()->json();
    }
}
