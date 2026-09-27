<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * ক্রয়ের ড্রপডাউন লরিওয়ালাকেও সরবরাহকারী হিসেবে দেখাত — মালিকের নির্দেশ, ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * সরবরাহকারী আর সেবাদাতার **তালিকা** আগে থেকেই আলাদা ছিল
 * ([[Supplier::scopeOnlySuppliers()]])। ⚠️ কিন্তু ক্রয়ের প্রতিটা ড্রপডাউন —
 * সরাসরি ক্রয়, আদেশ, বিল, গ্রহণ, ফেরত, দরপত্র, চুক্তি — সব পক্ষ দেখাত:
 * পরিবহন, মেরামত, হাম্মালি। আর সরবরাহকারীর ফর্মের "ধরন" ঘরে সেবার
 * ধরনগুলোও ছিল। মালিকের কথায়: Suppliers মানে কেবল যাঁরা বিক্রির মাল দেন,
 * যেমন Pran Foods।
 *
 * ⭐ প্রতিটা দাবি নাম ধরে — "এই পক্ষটা এই ড্রপডাউনে আছে/নেই" — সংখ্যা ধরে নয়।
 */
final class TheSupplierListAlsoOfferedTheLorryTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $goods;

    private Supplier $lorry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->goods = $this->party('PRAN', 'Pran Foods Test', 'VENDOR');
        $this->lorry = $this->party('LORRY', 'Lorry Test Transport', 'TRANSPORT');
    }

    /** ⭐ দুই তালিকা — প্রতিটা পক্ষ ঠিক নিজেরটায়। */
    public function test_each_list_holds_only_its_own(): void
    {
        $this->get(route('supplier.index'))->assertOk()
            ->assertSee(route('supplier.show', $this->goods), false)
            ->assertDontSee(route('supplier.show', $this->lorry), false);

        $this->get(route('supplier.service.index'))->assertOk()
            ->assertSee(route('supplier.show', $this->lorry), false)
            ->assertDontSee(route('supplier.show', $this->goods), false);
    }

    /** ⛔ ক্রয়ের প্রতিটা "নতুন" পর্দার ড্রপডাউনে কেবল পণ্যের সরবরাহকারী। */
    public function test_every_purchase_dropdown_offers_only_goods_suppliers(): void
    {
        foreach (['purchase.direct.create', 'purchase.order.create', 'purchase.bill.create',
            'purchase.receipt.create', 'purchase.return.create', 'purchase.rfq.create', 'purchase.contract.create'] as $route) {
            $ids = $this->offered($route);

            $this->assertContains($this->goods->id, $ids, "ⓘ দাবির ভিত্তি: {$route}-এ পণ্যের সরবরাহকারী নেই।");
            $this->assertNotContains($this->lorry->id, $ids, "⛔ {$route}-এর সরবরাহকারী ড্রপডাউনে পরিবহনকারী।");
        }
    }

    /** ⛔ সরবরাহকারীর ফর্মের "ধরন"-এ সেবার ধরন নেই; সেবাদাতার ফর্মে সরবরাহকারী নেই। */
    public function test_the_type_dropdown_follows_the_list(): void
    {
        $supplierForm = $this->typeCodes($this->get(route('supplier.create'))->assertOk()->viewData('partyTypes'));
        $serviceForm = $this->typeCodes($this->get(route('supplier.create', ['kind' => 'service']))->assertOk()->viewData('partyTypes'));

        $this->assertSame(['VENDOR'], $supplierForm, '⛔ সরবরাহকারীর ফর্মে সেবার ধরনও দেখা যায়।');
        $this->assertNotContains('VENDOR', $serviceForm, '⛔ সেবাদাতার ফর্মে "সরবরাহকারী" ধরন।');
        $this->assertContains('TRANSPORT', $serviceForm, 'ⓘ দাবির ভিত্তি: সেবাদাতার ফর্মে পরিবহন থাকার কথা।');

        $editLorry = $this->typeCodes($this->get(route('supplier.edit', $this->lorry))->assertOk()->viewData('partyTypes'));
        $this->assertContains('TRANSPORT', $editLorry, '⛔ সেবাদাতা সম্পাদনায় নিজের ধরনটাই হারায় — জমা দিলে ধরন বদলে যেত।');
    }

    /**
     * ⭐ পুরনো কাগজে সেবাদাতা বসানো থাকলে, সম্পাদনার পর্দায় সে থাকে।
     *
     * ⛔ নাহলে ড্রপডাউন নীরবে অন্য কাউকে বেছে নিত, আর জমা দিলে পক্ষটা বদলে যেত।
     */
    public function test_an_old_paper_keeps_its_own_party_on_edit(): void
    {
        /* ⓘ পুরনো কাগজটা বানানো হয় আসল সেবা দিয়ে, তারপর তার পক্ষটা সেবাদাতা করা —
           ঠিক যেভাবে এই সারাইয়ের আগে কেউ ড্রপডাউন থেকে লরিওয়ালাকে বেছে থাকতে পারেন */
        $order = app(PurchaseOrderService::class)->create(
            ['supplier_id' => $this->goods->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'ordered_qty' => '10', 'rate' => '100']],
        );
        $order->forceFill(['supplier_id' => $this->lorry->id])->save();

        $ids = $this->offered('purchase.order.edit', $order);

        $this->assertContains($this->lorry->id, $ids, '⛔ পুরনো আদেশের পক্ষ সম্পাদনার ড্রপডাউন থেকে হারিয়েছে।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return list<int> */
    private function offered(string $route, mixed $param = null): array
    {
        $suppliers = $this->get(route($route, $param ?? []))->assertOk()->viewData('suppliers');

        return collect($suppliers)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<string> */
    private function typeCodes(Collection $types): array
    {
        return $types->pluck('code')->sort()->values()->all();
    }

    private function party(string $code, string $name, string $type): Supplier
    {
        return Supplier::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => $code,
            'name_en' => $name,
            'name_bn' => $name,
            'party_type_id' => PartyType::query()->where('code', $type)->firstOrFail()->id,
            'is_active' => true,
        ]);
    }
}
