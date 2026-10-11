<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\DocumentDelivery;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\CollectionLine;
use App\Modules\Sales\Models\PrintJob;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ গ্রাহকের বিলের ঘর — গ্রাহক পোর্টাল, ধাপ ১ (১১ অক্টোবর ২০২৬)।
 *
 * ── দাবিগুলো ─────────────────────────────────────────────────────────────
 *   · তালিকায় কেবল নিজের **পাকা** বিল — অন্যের নয়, খসড়া নয়, বাতিল নয়
 *   · বাকি / শোধ / সব ছাঁকনি, আর তারিখের ছাঁকনি
 *   · প্রতিটা বিলের বাকি = অফিসের হিসাব ([[SalesInvoice::dueAmount()]]), নতুন কোনো যোগ-বিয়োগ নয়
 *   · বিলের পাতায় দাম আছে (মালিকের নিয়ম: বিলটা গ্রাহকের নিজের কাগজ)
 *   · অন্যের বিল — পাতা, PDF — public id জানলেও ৪০৪
 *   · PDF নামানো খাতায় ওঠে গ্রাহকের নামে, আর অফিসের ছাপার সারিতে ওঠে না
 */
final class ThePortalBillCentreShowsOnlyMyOwnBillsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $me;

    private Customer $other;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();

        $this->me = $this->customer('BILLS-ME');
        $this->other = $this->customer('BILLS-OTHER');
        $this->bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-BILLCENTRE', 'name_en' => 'Bill centre bank', 'name_bn' => 'ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET, 'nature' => Account::DEBIT, 'money_kind' => Account::BANK,
        ]);
    }

    public function test_the_list_holds_only_my_posted_bills_with_the_offices_due_and_the_filters_work(): void
    {
        $open = $this->bill('INV-P-1', '600', now()->subDays(9));
        $part = $this->bill('INV-P-2', '400', now()->subDays(5));
        $this->paid($part, '150');
        $paid = $this->bill('INV-P-3', '300', now()->subDays(2));
        $this->paid($paid, '300');
        $this->bill('INV-P-4', '700', now()->subDays(1), DocumentStatus::DRAFT);
        $this->bill('INV-P-5', '800', now()->subDays(3), DocumentStatus::CANCELLED);
        $this->bill('INV-P-6', '900', now()->subDays(4), customer: $this->other);

        $all = $this->rows(['state' => 'all']);
        $this->assertSame(['INV-P-3', 'INV-P-2', 'INV-P-1'], $all->pluck('document_no')->all(),
            '⛔ বিলের তালিকায় অন্যের, খসড়া বা বাতিল বিল এল — বা নিজের পাকা বিল বাদ পড়ল।');

        $this->assertSame(['INV-P-2', 'INV-P-1'], $this->rows([])->pluck('document_no')->all(), '⛔ ডিফল্ট ঘর "বাকি আছে" নয়।');
        $this->assertSame(['INV-P-3'], $this->rows(['state' => 'paid'])->pluck('document_no')->all());
        $this->assertSame(['INV-P-2'], $this->rows([
            'state' => 'all', 'from' => now()->subDays(6)->toDateString(), 'to' => now()->subDays(4)->toDateString(),
        ])->pluck('document_no')->all(), '⛔ তারিখের ছাঁকনি মানা হলো না।');

        // ⓘ বাকির অঙ্ক অফিসের হিসাবের সাথে হুবহু
        foreach ($all as $row) {
            $this->assertSame(
                SalesInvoice::query()->whereKey($row->id)->firstOrFail()->dueAmount(),
                $row->dueAmount(),
                "⛔ {$row->document_no}-এর বাকি অফিসের হিসাব থেকে আলাদা।",
            );
        }
        $this->asMe()->get(route('sales.portal.bill.index', ['state' => 'all']))->assertOk()
            ->assertSee('data-portal-bill-row="INV-P-2"', false)->assertSee('250.00')
            ->assertDontSee('INV-P-6')->assertDontSee('dealer', false);
        $this->assertSame('0.0000', $paid->fresh()->dueAmount());
        $this->assertSame('600.0000', $open->fresh()->dueAmount());
    }

    public function test_my_bill_shows_its_prices_and_payments_and_another_customers_bill_is_404(): void
    {
        $mine = $this->bill('INV-Q-1', '1250', now()->subDays(3), lines: true);
        $this->paid($mine, '500');
        $theirs = $this->bill('INV-Q-2', '900', now()->subDays(3), customer: $this->other);
        $draft = $this->bill('INV-Q-3', '100', now()->subDays(1), DocumentStatus::DRAFT);

        $this->asMe()->get(route('sales.portal.bill.show', $mine->public_id))->assertOk()
            ->assertSee('data-portal-bill-lines', false)
            ->assertSee('125.00')               // দর — বিলে দাম আছে
            ->assertSee('COL-INV-Q-1')          // কোন আদায়ে শোধ
            ->assertSee('750.00');              // বাকি

        foreach (['অন্যের বিল' => $theirs, 'নিজের খসড়া' => $draft] as $why => $bill) {
            $this->asMe()->get(route('sales.portal.bill.show', $bill->public_id))->assertNotFound();
            $this->asMe()->get(route('sales.portal.bill.pdf', $bill->public_id))->assertNotFound();
            $this->assertSame(0, DocumentDelivery::query()->where('document_id', $bill->id)->count(), "⛔ {$why}: খাতায় নামানো উঠল।");
        }
    }

    public function test_the_pdf_is_my_bill_logged_in_my_name_and_leaves_the_office_print_queue_alone(): void
    {
        $mine = $this->bill('INV-R-1', '1250', now()->subDays(3), lines: true);

        $response = $this->asMe()->get(route('sales.portal.bill.pdf', [$mine->public_id, 'download' => 1]));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="INV-R-1.pdf"', (string) $response->headers->get('Content-Disposition'));

        $this->asMe()->get(route('sales.portal.bill.pdf', $mine->public_id))->assertOk();

        $log = DocumentDelivery::query()->where('document_type', PrintJob::INVOICE)->where('document_id', $mine->id)->orderBy('id')->get();
        $this->assertSame([DocumentDelivery::DOWNLOADED, DocumentDelivery::PRINTED], $log->pluck('how')->all(),
            '⛔ গ্রাহকের নামানো/খোলা খাতায় উঠল না।');
        $this->assertSame([$this->me->id, $this->me->id], $log->pluck('customer_id')->map(fn ($id) => (int) $id)->all(),
            '⛔ খাতায় "কোন গ্রাহক" নেই।');
        $this->assertSame([null, null], $log->pluck('created_by')->all());

        $this->assertSame(0, PrintJob::query()->withoutGlobalScopes()->where('document_id', $mine->id)->count(),
            '⛔ গ্রাহকের কপি অফিসের ছাপার সারিতে উঠল — পরের ছাপায় DUPLICATE বসত।');
    }

    // ── খুঁটিনাটি ────────────────────────────────────────────────────

    private function asMe(): static
    {
        return $this->actingAs($this->me->fresh(), 'portal');
    }

    /** @param  array<string, string>  $query */
    private function rows(array $query): \Illuminate\Support\Collection
    {
        return collect($this->asMe()->get(route('sales.portal.bill.index', $query))->assertOk()->viewData('bills')->items());
    }

    private function customer(string $code): Customer
    {
        $customer = Customer::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id, 'code' => $code,
            'name_en' => 'Bill centre '.$code, 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
        $customer->forceFill(['portal_enabled' => true, 'portal_password' => 'shop-pass-2026'])->save();

        return $customer;
    }

    private function bill(string $no, string $total, Carbon $on, string $status = DocumentStatus::CONFIRMED, ?Customer $customer = null, bool $lines = false): SalesInvoice
    {
        $bill = SalesInvoice::query()->create([
            'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => $no,
            'customer_id' => ($customer ?? $this->me)->id, 'trx_date' => $on->toDateString(), 'due_on' => $on->copy()->addDays(30)->toDateString(),
            'subtotal' => $total, 'discount' => '0', 'tax' => '0', 'total' => $total, 'status' => $status,
        ]);

        if ($lines) {
            SalesInvoiceLine::query()->create([
                'sales_invoice_id' => $bill->id, 'line_no' => 1, 'product_id' => Product::query()->orderBy('id')->value('id'),
                'qty' => '10', 'rate' => '125', 'discount' => '0', 'tax' => '0', 'amount' => '1250',
            ]);
        }

        return $bill;
    }

    private function paid(SalesInvoice $invoice, string $amount): void
    {
        $collection = Collection::query()->create([
            'branch_id' => $invoice->branch_id, 'document_no' => 'COL-'.$invoice->document_no, 'customer_id' => $invoice->customer_id,
            'account_id' => $this->bank->id, 'trx_date' => now()->toDateString(), 'amount' => $amount, 'status' => DocumentStatus::CONFIRMED,
        ]);
        CollectionLine::query()->create(['collection_id' => $collection->id, 'line_no' => 1, 'sales_invoice_id' => $invoice->id, 'amount' => $amount]);
    }
}
