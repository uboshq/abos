<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Search\SearchEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নতুন কাগজের নম্বর INV-0154 / CHA-0154, বিক্রির নম্বর S-0154 — খোঁজায় দুটোই মেলে, আর পুরনো S-নম্বরের কাগজও (মালিক "ক", ৬ অক্টোবর ২০২৬)।
 */
final class ASaleIsFoundByItsSaleNumberTest extends TestCase
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

    public function test_a_new_paper_is_found_by_its_own_number_and_by_the_sale_number_and_an_old_one_by_its_own(): void
    {
        $customer = \App\Modules\Customer\Models\Customer::query()->orderBy('id')->firstOrFail();
        $warehouse = \App\Modules\Inventory\Models\Warehouse::query()->where('is_default', true)->firstOrFail();
        $paper = fn (string $model, string $no, ?string $sale) => $model::query()->forceCreate(array_filter([
            'company_id' => CompanyContext::id(), 'branch_id' => CompanyContext::branchId(), 'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString(), 'status' => 'draft',
            'document_no' => $no, 'sale_no' => $sale,
        ], fn ($v) => $v !== null));

        // ⓘ নতুন বিক্রি: কাগজের নম্বর নিজের অক্ষরে, বিক্রির নম্বর sale_no-তে; পুরনো: কাগজের নম্বরই S-নম্বর
        $invoices = [$paper(SalesInvoice::class, 'INV-7777', 'S-7777'), $paper(SalesInvoice::class, 'S-7700', null)];
        $challans = [$paper(DeliveryChallan::class, 'CHA-7777', 'S-7777'), $paper(DeliveryChallan::class, 'S-7700', null)];

        $found = fn (string $model, string $term) => $model::query()->search($term)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([(int) $invoices[0]->id], $found(SalesInvoice::class, 'S-7777'), '⛔ নতুন বিল তার বিক্রির নম্বরে মেলেনি।');
        $this->assertSame([(int) $invoices[0]->id], $found(SalesInvoice::class, 'INV-7777'));
        $this->assertSame([(int) $challans[0]->id], $found(DeliveryChallan::class, 'S-7777'), '⛔ নতুন চালান তার বিক্রির নম্বরে মেলেনি।');
        $this->assertSame([(int) $challans[0]->id], $found(DeliveryChallan::class, 'CHA-7777'));
        $this->assertSame([(int) $invoices[1]->id], $found(SalesInvoice::class, 'S-7700'), '⛔ পুরনো বিল তার S-নম্বরে মেলেনি।');
        $this->assertSame([(int) $challans[1]->id], $found(DeliveryChallan::class, 'S-7700'));

        // ⭐ Ctrl+K-ও বিক্রির নম্বর চেনে
        $titles = collect(app(SearchEngine::class)->search('S-7777', auth()->user()))->map(fn ($hit) => $hit->documentNo)->implode(' | ');
        $this->assertStringContainsString('INV-7777', $titles, '⛔ সাধারণ খোঁজায় বিক্রির নম্বরে নতুন বিল আসেনি: '.$titles);
    }
}
