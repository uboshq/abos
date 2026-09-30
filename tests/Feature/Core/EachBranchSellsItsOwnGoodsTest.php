<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * প্রতিটা শাখা নিজের মাল দেখে — ৩০ সেপ্টেম্বর ২০২৬ (মালিকের সিদ্ধান্ত খ)।
 *
 * ── ⛔ কেন ─────────────────────────────────────────────────────────────
 * UNIVER-এর সাত শাখা সাত ব্যবসা, প্রত্যেকের নিজের মাল — অথচ হেডারে শাখা বাছলেও পণ্যের
 * তালিকা আর বিক্রি-কেনার পিকার সব শাখার মাল দেখাত।
 *
 * ── ⭐ নিয়ম ────────────────────────────────────────────────────────────
 * পণ্যে কোনো শাখা বাঁধা না থাকলে সে সব শাখার; বাঁধা থাকলে কেবল সেগুলোর। পণ্য একটাই থাকে
 * (দাম, লট, খরচ) — কেবল তালিকা আর পিকার ছাঁকে; কাগজ পণ্যটা সবসময় খুঁজে পায়।
 */
final class EachBranchSellsItsOwnGoodsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Branch $mymensingh;

    private Branch $netrakona;

    /** @var array<string, Product> */
    private array $product = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->mymensingh = $this->branch('MMS');
        $this->netrakona = $this->branch('NTK');
        CompanyContext::set($this->company->id, $this->mymensingh->id);
        $this->actingAs($this->owner);

        [$a, $b, $c] = Product::query()->active()->orderBy('id')->take(3)->get()->all();
        $this->product = ['mms' => $a, 'ntk' => $b, 'every' => $c];

        // ⭐ আসল পথে — পণ্যের ফর্ম দিয়ে শাখা বাঁধা
        $this->bind($a, [$this->mymensingh->id]);
        $this->bind($b, [$this->netrakona->id]);
    }

    public function test_the_form_keeps_the_branches_and_an_empty_list_means_every_branch(): void
    {
        $this->assertSame([$this->mymensingh->id], $this->product['mms']->fresh()->branches->pluck('id')->all());

        $this->bind($this->product['mms'], []);
        $this->assertSame([], $this->product['mms']->fresh()->branches->pluck('id')->all(), 'খালি তালিকা "সব শাখা" হয়নি।');
    }

    public function test_each_list_and_picker_shows_the_picked_branchs_goods(): void
    {
        $screens = [
            'পণ্যের তালিকা' => [route('inventory.product.index'), 'products'],
            'বিক্রয় অর্ডারের পণ্য-পিকার' => [route('sales.order.create'), 'products'],
            'ক্রয়-বিলের পণ্য-পিকার' => [route('purchase.bill.create'), 'products'],
        ];

        $expect = ['mms' => ['mms', 'every'], 'ntk' => ['ntk', 'every'], 'all' => ['mms', 'ntk', 'every']];

        foreach (['mms' => $this->mymensingh->id, 'ntk' => $this->netrakona->id, 'all' => 'all'] as $pick => $branch) {
            $this->choose($branch);

            foreach ($screens as $name => [$url, $key]) {
                $ids = $this->idsOn($url, $key);

                foreach (['mms', 'ntk', 'every'] as $which) {
                    $this->assertSame(
                        in_array($which, $expect[$pick], true),
                        in_array($this->product[$which]->id, $ids, true),
                        sprintf('⛔ %s — "%s" বেছে %s-এর পণ্য ভুল জায়গায়।', $name, $pick, $which),
                    );
                }
            }
        }
    }

    public function test_a_paper_still_finds_a_product_of_another_branch(): void
    {
        $this->choose($this->netrakona->id);

        $this->assertNotNull(Product::query()->find($this->product['mms']->id));
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  list<int>  $branches */
    private function bind(Product $product, array $branches): void
    {
        $this->actingAs($this->owner)
            ->from(route('inventory.product.edit', $product))
            ->put(route('inventory.product.update', $product), [
                'name_en' => $product->name_en,
                'name_bn' => $product->name_bn,
                'unit_id' => $product->unit_id,
                'sale_price' => (string) $product->sale_price,
                'purchase_price' => (string) $product->purchase_price,
                'branch_table' => '1',
                'branch_ids' => $branches,
            ])
            ->assertSessionHasNoErrors();
    }

    /** @return list<int> */
    private function idsOn(string $url, string $key): array
    {
        $rows = $this->actingAs($this->owner)->get($url)->assertOk()->viewData($key);
        $items = method_exists($rows, 'items') ? $rows->items() : $rows;

        return collect($items)->map(fn ($r) => (int) $r->id)->values()->all();
    }

    private function choose(int|string $branch): void
    {
        $this->actingAs($this->owner->fresh())
            ->post(route('branch.switch'), ['branch_id' => (string) $branch])
            ->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
