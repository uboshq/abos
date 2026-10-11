<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\DocumentDelivery;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Http\Controllers\PortalStatementController;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\CollectionLine;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ গ্রাহকের বিবরণী — গ্রাহক পোর্টাল, ধাপ ১ (১১ অক্টোবর ২০২৬)।
 *
 * ── দাবিগুলো ─────────────────────────────────────────────────────────────
 *   · সময়: আজ / এই মাস / নিজের তারিখ; কিছু না বললে চলতি অর্থবছর (পুরনো নিয়ম, [[ThePortalLedgerStartsThisYearTest]])
 *   · "বিল ধরে" দৃশ্যে বিলের ঘরের সেই একই বাকি
 *   · প্রতিটা সারি পোর্টালের ভিতরে খোলে — বিল বিলের ঘরে, আদায় নিজের পাতায় (কোন বিল শোধ হলো সহ)
 *   · অন্যের সারি ৪০৪; আদায়ের পাতায় অন্য পায়ের সারি নেই
 *   · জের গ্রাহকের পক্ষে হলে "আপনার জমা", খাতার জের থেকে
 *   · PDF — একই সারি, খাতায় গ্রাহকের নামে
 */
final class ThePortalStatementOpensItsOwnPapersTest extends TestCase
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

        $this->me = $this->customer('STMT-ME');
        $this->other = $this->customer('STMT-OTHER');
        $this->bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-STMT', 'name_en' => 'Statement bank', 'name_bn' => 'ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET, 'nature' => Account::DEBIT, 'money_kind' => Account::BANK,
        ]);
    }

    public function test_the_periods_pick_today_this_month_or_own_dates_and_default_to_this_year(): void
    {
        $this->entry($this->me, now()->toDateString(), '100', '0', 'TODAY-1');
        $this->entry($this->me, now()->startOfMonth()->toDateString(), '200', '0', 'MONTH-1');
        $this->entry($this->me, now()->subMonths(2)->startOfMonth()->toDateString(), '300', '0', 'EARLIER-1');

        $today = $this->asMe()->get(route('sales.portal.ledger', ['period' => 'today']))->assertOk();
        $this->assertSame(['today', now()->toDateString(), now()->toDateString()], [$today->viewData('period'), $today->viewData('from'), $today->viewData('to')]);
        $this->assertContains('TODAY-1', $today->viewData('rows')->pluck('document_no')->all());
        $this->assertNotContains('EARLIER-1', $today->viewData('rows')->pluck('document_no')->all(), '⛔ "আজ" আগের সারি আনল।');

        $month = $this->asMe()->get(route('sales.portal.ledger', ['period' => 'month']))->assertOk();
        $this->assertSame(now()->startOfMonth()->toDateString(), $month->viewData('from'));

        $custom = $this->asMe()->get(route('sales.portal.ledger', [
            'from' => now()->subMonths(2)->startOfMonth()->toDateString(), 'to' => now()->subMonths(2)->endOfMonth()->toDateString(),
        ]))->assertOk();
        $this->assertSame('custom', $custom->viewData('period'));
        $this->assertSame(['EARLIER-1'], $custom->viewData('rows')->pluck('document_no')->all());

        $plain = $this->asMe()->get(route('sales.portal.ledger'))->assertOk();
        $this->assertSame('year', $plain->viewData('period'));
        $this->assertNotSame('1970-01-01', $plain->viewData('from'));

        // ⓘ ভাঙা তারিখে পাতা ভাঙে না — চলতি অর্থবছরে ফেরে
        $this->asMe()->get(route('sales.portal.ledger', ['from' => 'kal', 'to' => '2026-02-31']))->assertOk()->assertViewHas('period', 'year');
    }

    public function test_every_row_opens_inside_the_portal_and_a_collection_shows_the_bills_it_paid(): void
    {
        $bill = $this->bill('INV-S-1', '1000');
        $billRow = $this->entry($this->me, now()->toDateString(), '1000', '0', 'INV-S-1', 'sales_invoice', $bill->id);
        $collection = $this->collection($bill, '400');
        $paidRow = $this->entry($this->me, now()->toDateString(), '0', '400', 'COL-S-1', 'collection', $collection->id);
        // ⓘ একই আদায়ের অন্য পা — ব্যাংকের সারি, কোনো পার্টি নয়; গ্রাহকের পাতায় আসবে না
        $this->entry(null, now()->toDateString(), '400', '0', 'COL-S-1', 'collection', $collection->id, 'BANK LEG');
        $theirs = $this->entry($this->other, now()->toDateString(), '999', '0', 'THEIRS-1');

        $page = $this->asMe()->get(route('sales.portal.ledger'))->assertOk()
            ->assertSee(route('sales.portal.bill.show', $bill->public_id), false)
            ->assertSee(route('sales.portal.statement.entry', $paidRow->public_id), false)
            ->assertDontSee('THEIRS-1');
        $this->assertStringNotContainsString('/sales/', (string) $page->getContent(), '⛔ বিবরণী কর্মীর পর্দায় পাঠাল।');

        $this->asMe()->get(route('sales.portal.statement.entry', $billRow->public_id))
            ->assertRedirect(route('sales.portal.bill.show', $bill->public_id));

        $this->asMe()->get(route('sales.portal.statement.entry', $paidRow->public_id))->assertOk()
            ->assertSee('data-portal-entry-bills', false)
            ->assertSee(route('sales.portal.bill.show', $bill->public_id), false)
            ->assertSee('400.00')
            ->assertDontSee('BANK LEG');

        $this->asMe()->get(route('sales.portal.statement.entry', $theirs->public_id))->assertNotFound();

        // ⛔ নিজের সারি অন্যের আদায়ের দিকে আঙুল তুললেও তার ভেতর (কোন বিল শোধ হলো) দেখা যায় না
        $theirCollection = $this->collection($this->bill('INV-S-9', '700', $this->other), '700');
        $odd = $this->entry($this->me, now()->toDateString(), '0', '1', 'ODD-1', 'collection', $theirCollection->id);
        $this->asMe()->get(route('sales.portal.statement.entry', $odd->public_id))->assertOk()
            ->assertDontSee('data-portal-entry-bills', false)->assertDontSee('INV-S-9');
    }

    public function test_the_by_bill_view_carries_the_bill_centres_own_due(): void
    {
        $bill = $this->bill('INV-T-1', '1000');
        $this->collection($bill, '350');
        $this->bill('INV-T-2', '500', $this->other);

        $response = $this->asMe()->get(route('sales.portal.ledger', ['view' => 'bill']))->assertOk()
            ->assertSee('data-portal-statement-bills', false)->assertSee('INV-T-1')->assertDontSee('INV-T-2');
        $this->assertSame(['INV-T-1'], $response->viewData('bills')->pluck('document_no')->all());
        $this->assertSame('650.0000', $response->viewData('bills')->first()->dueAmount());
        $response->assertSee('650.00');
    }

    public function test_a_balance_in_the_customers_favour_reads_your_credit_from_the_ledger(): void
    {
        $this->entry($this->me, now()->toDateString(), '1000', '0', 'BILL-C');
        $this->entry($this->me, now()->toDateString(), '0', '1250', 'PAY-C');

        foreach (['sales.portal.ledger', 'sales.portal.home'] as $route) {
            $this->asMe()->get(route($route))->assertOk()
                ->assertSee('data-portal-your-credit', false)
                ->assertSee('আপনার জমা')
                ->assertSee('৳250.00');
        }

        $this->entry($this->me, now()->toDateString(), '500', '0', 'BILL-D');
        $this->asMe()->get(route('sales.portal.ledger'))->assertOk()->assertDontSee('data-portal-your-credit', false);
    }

    public function test_the_statement_pdf_is_logged_in_the_customers_name(): void
    {
        $this->entry($this->me, now()->toDateString(), '1000', '0', 'PDF-1');

        $response = $this->asMe()->get(route('sales.portal.statement.pdf', ['period' => 'month', 'download' => 1]));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="statement-STMT-ME-', (string) $response->headers->get('Content-Disposition'));

        $log = DocumentDelivery::query()->where('document_type', PortalStatementController::PAPER)->sole();
        $this->assertSame([(int) $this->me->id, (int) $this->me->id, DocumentDelivery::DOWNLOADED, null],
            [(int) $log->document_id, (int) $log->customer_id, $log->how, $log->created_by]);
    }

    // ── খুঁটিনাটি ────────────────────────────────────────────────────

    private function asMe(): static
    {
        return $this->actingAs($this->me->fresh(), 'portal');
    }

    private function customer(string $code): Customer
    {
        $customer = Customer::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id, 'code' => $code,
            'name_en' => 'Statement '.$code, 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
        $customer->forceFill(['portal_enabled' => true, 'portal_password' => 'shop-pass-2026'])->save();

        return $customer;
    }

    private function entry(?Customer $customer, string $on, string $debit, string $credit, string $no, string $type = 'collection', int $sourceId = 1, string $narration = 'test'): LedgerEntry
    {
        return LedgerEntry::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('is_current', true)->value('id'),
            'source_type' => $type, 'source_id' => $sourceId, 'document_no' => $no,
            'trx_date' => $on, 'party_type' => $customer === null ? null : 'customer', 'party_id' => $customer?->id,
            'account_id' => $this->bank->id,
            'debit' => $debit, 'credit' => $credit, 'narration' => $narration,
        ]);
    }

    private function bill(string $no, string $total, ?Customer $customer = null): SalesInvoice
    {
        return SalesInvoice::query()->create([
            'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => $no, 'customer_id' => ($customer ?? $this->me)->id,
            'trx_date' => now()->toDateString(), 'subtotal' => $total, 'discount' => '0', 'tax' => '0', 'total' => $total,
            'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    private function collection(SalesInvoice $bill, string $amount): Collection
    {
        $collection = Collection::query()->create([
            'branch_id' => $bill->branch_id, 'document_no' => 'COL-'.$bill->document_no, 'customer_id' => $bill->customer_id,
            'account_id' => $this->bank->id, 'trx_date' => now()->toDateString(), 'amount' => $amount, 'status' => DocumentStatus::CONFIRMED,
        ]);
        CollectionLine::query()->create(['collection_id' => $collection->id, 'line_no' => 1, 'sales_invoice_id' => $bill->id, 'amount' => $amount]);

        return $collection;
    }
}
