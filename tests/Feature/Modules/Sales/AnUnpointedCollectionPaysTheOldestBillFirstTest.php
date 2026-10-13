<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ বিল না বাছা আদায় সবচেয়ে পুরনো বিল আগে শোধ করে (FIFO) — টাকার পরিকল্পনা ৬ (মালিক, ৭ অক্টোবর ২০২৬)।
 *
 * ⓘ আগে বিল না বাছা আদায় খালি বসত: গ্রাহকের পাওনা কমত, অথচ প্রতিটা বিল "বাকি" দেখাত — তাগাদার তালিকায় শোধ হওয়া বিল উঠত,
 * আর বিলের বয়সের হিসাব ভুল বলত। ⓘ বাছা থাকলে আগের মতোই; বাড়তি টাকা গ্রাহকের খাতায় Cr, কোনো বিলে নয়।
 */
final class AnUnpointedCollectionPaysTheOldestBillFirstTest extends TestCase
{
    use RefreshDatabase;

    private Customer $dealer;

    private SalesInvoice $old;

    private SalesInvoice $newer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->dealer = Customer::query()->create(['code' => 'FIFO-1', 'name_en' => 'FIFO Dealer', 'name_bn' => 'ফিফো ডিলার', 'is_active' => true]);

        // ⓘ নতুনটা আগে বানানো — ক্রম যেন তারিখ ধরে হয়, id ধরে নয়
        $this->newer = $this->bill('INV-FIFO-NEW', 5, '500');
        $this->old = $this->bill('INV-FIFO-OLD', 20, '300');
    }

    public function test_money_with_no_bill_named_pays_the_oldest_bill_first_and_the_rest_stays_on_account(): void
    {
        $collection = $this->collect('1000', []);

        $paid = $collection->lines->mapWithKeys(fn ($l) => [(int) $l->sales_invoice_id => (string) $l->amount]);

        $this->assertSame(0, bccomp((string) $paid->get($this->old->id), '300', 4), '⛔ পুরনো বিলটা পুরো শোধ হয়নি');
        $this->assertSame(0, bccomp((string) $paid->get($this->newer->id), '500', 4), '⛔ পরের বিলটা পুরো শোধ হয়নি');
        $this->assertSame(0, bccomp($this->old->fresh()->dueAmount(), '0', 4));
        $this->assertSame(0, bccomp($this->newer->fresh()->dueAmount(), '0', 4));
    }

    public function test_short_money_stops_at_the_oldest_bills(): void
    {
        $collection = $this->collect('400', []);

        $paid = $collection->lines->mapWithKeys(fn ($l) => [(int) $l->sales_invoice_id => (string) $l->amount]);

        $this->assertSame(0, bccomp((string) $paid->get($this->old->id), '300', 4), '⛔ পুরনো বিল আগে নয়');
        $this->assertSame(0, bccomp((string) $paid->get($this->newer->id), '100', 4), '⛔ বাকি ১০০ পরের বিলে বসেনি');
        $this->assertSame(0, bccomp($this->newer->fresh()->dueAmount(), '400', 4));
    }

    public function test_a_named_bill_is_kept_as_named(): void
    {
        $collection = $this->collect('200', [['sales_invoice_id' => $this->newer->id, 'amount' => '200']]);

        $this->assertSame([(int) $this->newer->id], $collection->lines->pluck('sales_invoice_id')->map(fn ($i) => (int) $i)->all(),
            '⛔ বাছা বিল থাকতেও টাকা অন্য বিলে গেল');
        $this->assertSame(0, bccomp($this->old->fresh()->dueAmount(), '300', 4));
    }

    private function bill(string $no, int $daysAgo, string $total): SalesInvoice
    {
        return SalesInvoice::query()->create(['document_no' => $no, 'customer_id' => $this->dealer->id,
            'branch_id' => CompanyContext::branchId(),
            'trx_date' => now()->subDays($daysAgo)->toDateString(), 'due_on' => now()->addDays(10)->toDateString(),
            'subtotal' => $total, 'discount' => '0', 'tax' => '0', 'total' => $total, 'status' => DocumentStatus::CONFIRMED]);
    }

    /** @param list<array<string, mixed>> $lines */
    private function collect(string $amount, array $lines): Collection
    {
        $service = app(CollectionService::class);
        $collection = $service->create([
            'customer_id' => $this->dealer->id, 'trx_date' => now()->toDateString(), 'amount' => $amount,
        ], $lines);
        $service->confirm($collection);

        return $collection->fresh(['lines']);
    }
}
