<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Sync\SyncService;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\SyncChange;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesOrder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ অফলাইন আদেশ ফোনের কথায় বসত — পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⛔১, ⛔২, ⛔৩, ⚠️৬।
 *
 * ১. পুশের পাহারা ছিল কেবল পড়ার চাবি `sales.order.view` — দেখার চাবিওয়ালা ম্যানেজার `/sync/sales/push` দিয়ে
 *    আদেশ লিখতেন। এখন লেখার চাবিও ([[SyncsToDevices::requiredPushPermission()]], এখানে `sales.order.create`)।
 * ২. দর আর ছাড় ফোন যা পাঠাত তা-ই বসত; তারিখের কোনো নিয়ম ছিল না। এখন দর সার্ভারের ([[SalesPrice]]),
 *    ছাড় শূন্য, তারিখ ওয়েবের নিয়মে, শূন্য দাম ফেরে।
 * ৩. গ্রাহক খোঁজা শাখা ছাড়া — ফোনে বাছা শাখার বাইরের দোকানের নামে আদেশ বসত।
 * ৬. "1e5" বা অ্যারে পরিমাণে ৫০০ — ফোনের পুরো সারি আটকে থাকত। এখন সেই সারিটাই ফেরে, বাকিগুলো বসে।
 */
class TheOfflineOrderTookThePhonesWordTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $shop;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->shop = Customer::query()->orderBy('id')->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();

        // ⓘ জানা দাম — দর-তালিকা বাদ, পণ্যের নিজের দাম; ফোন পাঠাবে ১ টাকা আর ৫০ টাকা ছাড়
        DB::table('sal_price_list_items')->where('product_id', $this->product->id)->delete();
        $this->product->forceFill(['sale_price' => '123.45'])->save();
    }

    /** ⛔১ — একই মানুষ: দেখার চাবিতে ফেরে, লেখার চাবি পেলে বসে */
    public function test_the_view_key_alone_writes_no_order_and_the_create_key_does(): void
    {
        $manager = $this->person(['sales.order.view']);
        $before = SalesOrder::query()->count();

        $out = $this->push($manager, [$this->change('v-1', $this->order())]);
        $this->assertSame(SyncChange::REJECTED, $out[0]['status'], '⛔ দেখার চাবিতে ফোন থেকে আদেশ বসল।');
        $this->assertSame($before, SalesOrder::query()->count());

        $this->grant($manager, 'sales.order.create');
        $out = $this->push($manager->fresh(), [$this->change('v-2', $this->order())]);
        $this->assertSame(SyncChange::APPLIED, $out[0]['status'], json_encode($out));
        $this->assertSame($before + 1, SalesOrder::query()->count());

        // ⓘ লেখার চাবি একা যথেষ্ট নয় — পড়ার চাবিও লাগে
        $writer = $this->person(['sales.order.create']);
        $out = $this->push($writer, [$this->change('v-3', $this->order())]);
        $this->assertSame(SyncChange::REJECTED, $out[0]['status'], '⛔ পড়ার চাবি ছাড়া ফোন থেকে আদেশ বসল।');
    }

    /** ⛔২ — দর সার্ভারের, ছাড় শূন্য: ফোনের ১ টাকা আর ৫০ টাকা ছাড় বসে না */
    public function test_the_rate_is_the_servers_and_the_phones_discount_is_dropped(): void
    {
        $out = $this->push($this->seller(), [$this->change('p-1', $this->order(rate: '1', discount: '50'))]);
        $this->assertSame(SyncChange::APPLIED, $out[0]['status'], json_encode($out));

        $line = SalesOrder::query()->where('public_id', $out[0]['entityId'])->firstOrFail()->lines()->firstOrFail();
        $this->assertSame(0, bccomp((string) $line->rate, '123.45', 4), '⛔ ফোনের দর বসেছে: '.$line->rate);
        $this->assertSame(0, bccomp((string) ($line->discount ?? '0'), '0', 4), '⛔ ফোনের ছাড় বসেছে: '.$line->discount);
    }

    /** ⛔২ — দাম না থাকলে আদেশ নয়, কারণসহ */
    public function test_a_product_with_no_price_is_refused_with_its_name(): void
    {
        $this->product->forceFill(['sale_price' => '0'])->save();

        $out = $this->push($this->seller(), [$this->change('z-1', $this->order())]);

        $this->assertSame(SyncChange::REJECTED, $out[0]['status']);
        $this->assertStringContainsString((string) ($this->product->name_bn ?: $this->product->name_en), (string) ($out[0]['message'] ?? ''));
    }

    /** ⛔২ ও ⚠️৬ — ভুল তারিখ আর ভুল পরিমাণ সেই সারিটাই ফেরায়; ভালো সারি একই ব্যাচে বসে */
    public function test_each_bad_row_is_refused_alone_and_the_good_one_lands(): void
    {
        $before = SalesOrder::query()->count();
        $rows = [
            $this->change('b-1', $this->order(trxDate: now()->addDay()->toDateString())),
            $this->change('b-2', $this->order(deliverOn: now()->subDays(3)->toDateString(), trxDate: now()->subDay()->toDateString())),
            $this->change('b-3', $this->order(qty: '1e5')),
            $this->change('b-4', $this->order(qty: '0')),
            $this->change('b-5', $this->order(qty: ['2'])),
            $this->change('b-6', $this->order(trxDate: '07/10/2026')),
            $this->change('b-7', array_merge($this->order(), ['lines' => []])),
            $this->change('b-8', $this->order(trxDate: now()->subDays(2)->toDateString(), deliverOn: now()->toDateString())),
        ];

        $out = $this->push($this->seller(), $rows);

        $this->assertSame(
            ['REJECTED', 'REJECTED', 'REJECTED', 'REJECTED', 'REJECTED', 'REJECTED', 'REJECTED', 'APPLIED'],
            array_column($out, 'status'),
            json_encode($out, JSON_UNESCAPED_UNICODE),
        );
        $this->assertSame($before + 1, SalesOrder::query()->count());

        // ⓘ আগের দিনে লেখা আদেশ সেই দিনের তারিখেই বসে
        $kept = SalesOrder::query()->where('public_id', $out[7]['entityId'])->firstOrFail();
        $this->assertSame(now()->subDays(2)->toDateString(), $kept->trx_date->toDateString());
    }

    /** ⛔৩ — ফোনে বাছা শাখার বাইরের দোকানের নামে আদেশ নয়; একই মানুষ, দোকান শাখায় ফিরলে বসে */
    public function test_a_shop_of_another_branch_than_the_one_in_view_takes_no_order(): void
    {
        $home = $this->company->defaultBranch();
        $other = Branch::query()->where('company_id', $this->company->id)->whereKeyNot($home->id)->first()
            ?? Branch::query()->create(['company_id' => $this->company->id, 'code' => 'OOT2', 'name_en' => 'Other branch', 'is_active' => true]);

        $seller = $this->seller();
        $seller->forceFill(['view_all_branches' => false, 'current_branch_id' => $home->id])->save();
        $this->actingAs($seller->fresh());
        app(\App\Core\Services\DataScope::class)->forget();
        $this->assertSame((int) $home->id, \App\Core\Support\ViewedBranch::one(), 'প্রস্তুতিটাই ভুল — এক শাখা দেখা হচ্ছে না।');

        $this->shop->forceFill(['branch_id' => $other->id])->save();
        $out = $this->push($seller->fresh(), [$this->change('br-1', $this->order())]);
        $this->assertSame(SyncChange::REJECTED, $out[0]['status'], '⛔ অন্য শাখার দোকানের নামে ফোন থেকে আদেশ বসল।');

        $this->shop->forceFill(['branch_id' => $home->id])->save();
        $out = $this->push($seller->fresh(), [$this->change('br-2', $this->order())]);
        $this->assertSame(SyncChange::APPLIED, $out[0]['status'], json_encode($out));
    }

    // ── যন্ত্রপাতি ─────────────────────────────────────────────────────────

    private ?User $seller = null;

    private function seller(): User
    {
        return $this->seller ??= $this->person(['sales.order.view', 'sales.order.create']);
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        foreach ($keys as $key) {
            $this->grant($user, $key);
        }

        return $user->fresh();
    }

    private function grant(User $user, string $key): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return array<string, mixed> */
    private function order(string $rate = '100', string $discount = '0', mixed $qty = 2, ?string $trxDate = null, ?string $deliverOn = null): array
    {
        return array_filter([
            'customerId' => (string) $this->shop->public_id,
            'trxDate' => $trxDate ?? now()->toDateString(),
            'deliverOn' => $deliverOn,
            'lines' => [['productId' => (string) $this->product->public_id, 'qty' => $qty, 'rate' => $rate, 'discount' => $discount]],
        ], fn ($v) => $v !== null);
    }

    /** @param  array<string, mixed>  $payload */
    private function change(string $id, array $payload): array
    {
        return [
            'changeId' => $id,
            'entityType' => 'SalesOrder',
            'entityId' => null,
            'operation' => 'CREATE',
            'payloadJson' => json_encode($payload),
            'clientVersion' => 1,
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function push(User $user, array $rows): array
    {
        $this->actingAs($user);

        return app(SyncService::class)->push($user, 'phone-o', 'sales', $rows);
    }
}
