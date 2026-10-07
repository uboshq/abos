<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\SyncState;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SignsInPastTheSecondStep;
use Tests\TestCase;

/**
 * ⛔ ফোনে অন্য শাখার গ্রাহক আর বকেয়া — মালিক, ৬ অক্টোবর ২০২৬: *"অ্যাপে অন্য ব্রাঞ্চের কাস্টমার আর কাস্টমার ব্যালেন্সও
 * হিসাবে ঢুকেছে"* ([[SyncService::sawAnotherView()]], [[CustomerDueSync]])।
 *
 * দাবি — এক মানুষ, দুই দরজা, দুই শাখা (প্রতিটায় একজন গ্রাহক):
 *   · ফোনে শাখা A — কেবল A-র গ্রাহক; বকেয়ার তালিকার সংখ্যা A-র কাগজের (ওয়েবের তালিকার হুবহু), সীমার সংখ্যা গোটা কোম্পানির
 *   · তারপর একই মানুষ **ওয়েবের হেডারে** B বাছলেন, ফোনে কিছু না করে — পরের টানা গোড়া থেকে: B-র গ্রাহক (যিনি একটুও
 *     বদলাননি) আসে, `view` বদলায় — ফোন সেটা দেখে আগের ক্যাশ মোছে; A আর আসে না
 *   · দেখা না বদলালে গোড়া থেকে নয় — জলচিহ্ন থাকে
 */
final class TheWebHeaderMovedThePhonesBranchTooTest extends TestCase
{
    use RefreshDatabase;
    use SignsInPastTheSecondStep;

    private const DEVICE = 'handset-two-doors';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_one_person_two_doors_two_branches_the_phone_never_keeps_the_other_branch(): void
    {
        $tdepot = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $a = Branch::acrossAllCompanies()->where('code', 'NTK')->firstOrFail();
        $b = Branch::acrossAllCompanies()->where('company_id', $tdepot->id)->where('id', '<>', $a->id)->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        [$shopA, $shopB] = Customer::query()->withoutGlobalScopes()->where('company_id', $tdepot->id)->orderBy('id')->take(2)->get()->all();
        $shopA->forceFill(['branch_id' => $a->id])->saveQuietly();
        $shopB->forceFill(['branch_id' => $b->id])->saveQuietly();
        DB::table('ledger_entries')->where('party_type', Customer::drillSourceType())->whereIn('party_id', [$shopA->id, $shopB->id])->delete();
        // ⓘ A-র দোকান দুই শাখা থেকেই কিনেছে: A-তে ১০০, B-তে ৪০; B-র দোকান B-তে ৭০
        $this->due($shopA, $a, '100');
        $this->due($shopA, $b, '40');
        $this->due($shopB, $b, '70');
        // ⓘ সব পুরনো — জলচিহ্নের পরে কিছুই নড়েনি; তাই B-র দোকান আসে কেবল গোড়া থেকে টানলে
        $this->age([$shopA->id, $shopB->id]);

        $token = $this->signIn();
        $this->withToken($token)->postJson('/api/v1/workspace', ['company' => $tdepot->public_id, 'branch' => $a->public_id])->assertOk();

        $first = $this->pull($token);
        $this->assertSame([(string) $shopA->public_id], $this->ids($first, 'Customer'), '⛔ শাখা A-র ফোনে অন্য শাখার গ্রাহক।');
        $this->assertSame([(string) $shopA->public_id], $this->ids($first, 'CustomerDue'), '⛔ শাখা A-র ফোনে অন্য শাখার বকেয়া।');
        $due = $this->payload($first, 'CustomerDue');
        $this->assertSame('100.00', $due['outstandingInView'], '⛔ বকেয়ার তালিকায় অন্য শাখার কাগজও গোনা — ওয়েবের তালিকা কেবল A-রটা দেখায়।');
        $this->assertSame(0, bccomp('140', (string) $due['outstanding'], 2), 'সীমার সংখ্যা গোটা কোম্পানির থাকার কথা (সীমা পরম)।');
        $this->complete($token);

        // ⓘ দেখা একই — জলচিহ্ন থাকে, গোড়া থেকে নয়
        $same = $this->pull($token);
        $this->assertSame($first['view'], $same['view']);
        $this->assertSame(1, SyncState::query()->withoutGlobalScopes()->where('device_id', self::DEVICE)->count(), '⛔ দেখা না বদলেও জলচিহ্ন মুছল।');

        // ⭐ একই মানুষ, ওয়েবের হেডারে B — ফোনে কিছু না করে
        $this->app['auth']->forgetGuards();
        $this->actingAs($owner->fresh())->post(route('branch.switch'), ['branch_id' => (string) $b->id])->assertRedirect();
        $this->assertSame($b->id, $owner->fresh()->current_branch_id, 'প্রস্তুতিটাই ভুল — ওয়েবের হেডার শাখা বদলায়নি।');

        $after = $this->pull($token);
        $this->assertNotSame($first['view'], $after['view'], '⛔ দেখা বদলাল অথচ ফোনকে বলা হলো না — আগের শাখার ক্যাশ রয়ে যাবে।');
        $this->assertSame([(string) $shopB->public_id], $this->ids($after, 'Customer'),
            '⛔ ওয়েবে শাখা বদলের পরে টানা গোড়া থেকে নয় — না-বদলানো B-র দোকান ফোনে কোনোদিন আসত না, আর A রয়ে যেত।');
        $this->assertSame('70.00', $this->payload($after, 'CustomerDue')['outstandingInView']);
    }

    /**
     * ⭐ বাকি সিঙ্কও দেখার শাখা মানে — বিক্রয় আদেশ, আদায়, মজুদ (ওয়েবের তালিকা আর মজুদের পাতার একই নিয়ম)।
     * ⛔ আগে এরা কেবল নাগাল মানত: শাখা A দেখা ফোনে B-র আদেশ আর আদায় নামত, আর মজুদ ছিল সব শাখার গুদামের যোগ।
     */
    public function test_orders_collections_and_stock_on_the_phone_are_the_viewed_branchs_too(): void
    {
        $tdepot = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $a = Branch::acrossAllCompanies()->where('code', 'NTK')->firstOrFail();
        $b = Branch::acrossAllCompanies()->where('company_id', $tdepot->id)->where('id', '<>', $a->id)->firstOrFail();

        // ⓘ দুটো আদেশ আর দুটো আদায় — সেবার নিজের দরজায়, তারপর একটা A-র, একটা B-র
        \App\Core\Support\CompanyContext::set($tdepot->id, $a->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(\App\Modules\Accounts\Services\StandardChart::class)->install();
        $customer = Customer::query()->withoutGlobalScopes()->where('company_id', $tdepot->id)->orderBy('id')->firstOrFail();
        $warehouse = DB::table('inv_warehouses')->where('company_id', $tdepot->id)->where('is_default', true)->value('id');
        $productId = DB::table('inv_products')->where('company_id', $tdepot->id)->orderBy('id')->value('id');
        foreach ([1, 2] as $_) {
            app(\App\Modules\Sales\Services\SalesOrderService::class)->create(
                ['customer_id' => $customer->id, 'warehouse_id' => $warehouse, 'trx_date' => now()->toDateString()],
                [['product_id' => $productId, 'ordered_qty' => '1', 'rate' => '10']],
            );
            app(\App\Modules\Sales\Services\CollectionService::class)->create(
                ['customer_id' => $customer->id, 'trx_date' => now()->toDateString(), 'amount' => '50', 'instrument' => 'cash'], [],
            );
        }

        $orders = DB::table('sal_orders')->where('company_id', $tdepot->id)->orderBy('id')->limit(2)->pluck('id')->all();
        $this->assertCount(2, $orders, 'প্রস্তুতিটাই ভুল — দুটো আদেশ লাগে।');
        DB::table('sal_orders')->where('id', $orders[0])->update(['branch_id' => $a->id]);
        DB::table('sal_orders')->where('id', $orders[1])->update(['branch_id' => $b->id]);

        $collections = DB::table('sal_collections')->where('company_id', $tdepot->id)->orderBy('id')->limit(2)->pluck('id')->all();
        $this->assertCount(2, $collections, 'প্রস্তুতিটাই ভুল — দুটো আদায় লাগে।');
        DB::table('sal_collections')->where('id', $collections[0])->update(['branch_id' => $a->id]);
        DB::table('sal_collections')->where('id', $collections[1])->update(['branch_id' => $b->id]);

        // ⓘ ডিফল্ট গুদাম A-র, বাকি সব B-র — ফোনের মজুদ কেবল ডিফল্ট গুদামের হওয়ার কথা
        $home = DB::table('inv_warehouses')->where('company_id', $tdepot->id)->where('is_default', true)->value('id');
        DB::table('inv_warehouses')->where('company_id', $tdepot->id)->update(['branch_id' => $b->id]);
        DB::table('inv_warehouses')->where('id', $home)->update(['branch_id' => $a->id]);
        // ⓘ মজুদের সারি নিজের গুদামের শাখায় — আসল ব্যবসায় গুদাম শাখা বদলায় না
        DB::table('inv_stock_movements')->where('company_id', $tdepot->id)->update(['branch_id' => $b->id]);
        DB::table('inv_stock_movements')->where('warehouse_id', $home)->update(['branch_id' => $a->id]);
        $floor = fn (?int $warehouse) => DB::table('inv_stock_movements')->where('company_id', $tdepot->id)
            ->when($warehouse !== null, fn ($q) => $q->where('warehouse_id', $warehouse))
            ->groupBy('product_id')->selectRaw('product_id, SUM(floor_change) as qty')->pluck('qty', 'product_id');
        $atHome = $floor($home);
        $everywhere = $floor(null);
        $product = collect($everywhere->keys())->first(fn ($id) => bccomp((string) $everywhere[$id], (string) ($atHome[$id] ?? '0'), 4) !== 0);
        $this->assertNotNull($product, 'প্রস্তুতিটাই ভুল — অন্য গুদামে মজুদ আছে এমন পণ্য নেই।');

        // ⓘ দুই পণ্য: একটা কেবল B-তে বিক্রি হয়, একটা কেবল A-তে (ওয়েবের পণ্য-তালিকা B-রটা A-তে দেখায় না)
        [$onlyA, $onlyB] = DB::table('inv_products')->where('company_id', $tdepot->id)->orderBy('id')->limit(2)->pluck('id')->all();
        foreach ([[$onlyA, $a->id], [$onlyB, $b->id]] as [$p, $branch]) {
            DB::table('inv_product_branches')->insert(['company_id' => $tdepot->id, 'product_id' => $p, 'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
        }

        $token = $this->signIn();
        $this->withToken($token)->postJson('/api/v1/workspace', ['company' => $tdepot->public_id, 'branch' => $a->public_id])->assertOk();

        $sales = $this->pull($token, 'sales');
        $id = fn (string $table, int $row) => (string) DB::table($table)->where('id', $row)->value('public_id');
        $this->assertContains($id('sal_orders', $orders[0]), $this->ids($sales, 'SalesOrder'));
        $this->assertNotContains($id('sal_orders', $orders[1]), $this->ids($sales, 'SalesOrder'), '⛔ শাখা A-র ফোনে B-র বিক্রয় আদেশ।');
        $this->assertContains($id('sal_collections', $collections[0]), $this->ids($sales, 'Collection'));
        $this->assertNotContains($id('sal_collections', $collections[1]), $this->ids($sales, 'Collection'), '⛔ শাখা A-র ফোনে B-র আদায়।');

        $inventory = $this->pull($token, 'inventory');
        $this->assertSame([], $inventory['unreadable'], 'মজুদের টানা পড়া গেল না।');
        $this->assertFalse($inventory['hasMore'], 'প্রস্তুতিটাই ভুল — একটা পাতায় সব মজুদ আসেনি।');
        $stock = collect($inventory['records'])->where('entityType', 'StockOnHand')
            ->map(fn ($r) => json_decode((string) $r['payloadJson'], true))->keyBy('productId');
        $publicId = (string) DB::table('inv_products')->where('id', $product)->value('public_id');
        $this->assertContains($id('inv_products', $onlyA), $this->ids($inventory, 'Product'));
        $this->assertNotContains($id('inv_products', $onlyB), $this->ids($inventory, 'Product'), '⛔ শাখা A-র ফোনে কেবল B-তে বিক্রি হয় এমন পণ্য।');
        $this->assertSame(0, bccomp((string) ($atHome[$product] ?? '0'), (string) ($stock[$publicId]['floor'] ?? '0'), 4),
            '⛔ শাখা A-র ফোনের মজুদে B-র গুদামের মালও যোগ হয়েছে: ঘরে '.($atHome[$product] ?? '0').', সবখানে '.$everywhere[$product].', ফোনে '.json_encode($stock[$publicId] ?? null).' ধরন '.json_encode(collect($inventory['records'])->countBy('entityType')).' মোট মজুদ-সারি '.$stock->count().' পণ্য '.$product);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  list<int>  $customers */
    private function age(array $customers): void
    {
        $then = now()->subDay();
        DB::table('customers')->whereIn('id', $customers)->update(['updated_at' => $then, 'created_at' => $then]);
        DB::table('ledger_entries')->where('party_type', Customer::drillSourceType())->whereIn('party_id', $customers)->update(['created_at' => $then]);
        DB::table('mdm_locations')->update(['updated_at' => $then]);
    }

    private function due(Customer $customer, Branch $branch, string $amount): void
    {
        DB::table('ledger_entries')->insert([
            'company_id' => $customer->company_id,
            'branch_id' => $branch->id,
            'financial_year_id' => FinancialYear::query()->withoutGlobalScopes()->where('company_id', $customer->company_id)->value('id'),
            'account_id' => 1,
            'party_type' => Customer::drillSourceType(),
            'party_id' => $customer->id,
            'trx_date' => now()->toDateString(),
            'debit' => $amount,
            'credit' => 0,
            'source_type' => 'test',
            'source_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function signIn(): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', [
            'identifier' => 'owner@abos.test',
            'password' => 'password',
            'code' => $this->secondStepCode('owner@abos.test'),
            'deviceId' => self::DEVICE,
            'appVersion' => '0.4.20',
            'platform' => 'android',
        ])->assertOk()->json('accessToken');
    }

    /** @return array<string, mixed> */
    private function pull(string $token, string $module = 'customer'): array
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/v1/sync/'.$module.'/pull?limit=1000&deviceId='.self::DEVICE)->assertOk()->json();
    }

    private function complete(string $token): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/sync/customer/pull-complete?deviceId='.self::DEVICE)->assertOk();
    }

    /** @param  array<string, mixed>  $batch @return array<string, mixed> */
    private function payload(array $batch, string $type): array
    {
        return json_decode((string) collect($batch['records'])->firstWhere('entityType', $type)['payloadJson'], true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>  $batch @return list<string> */
    private function ids(array $batch, string $type): array
    {
        return collect($batch['records'])->where('entityType', $type)->pluck('entityId')->map(fn ($id) => (string) $id)->sort()->values()->all();
    }
}
