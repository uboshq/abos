<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Tax;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;

/**
 * ⭐ সারির নোটের দৃশ্য — মালিকের নকশা v5 (২৩ নভেম্বর ২০২৬): একটা টাটকা পণ্য (ভ্যাট ১৫%, দামের বাইরে), তার পাকা ক্রয় বিল
 * আর পাকা বিক্রয় বিল; নোট লেখা, মালিকের ছাড়ের সই, আর খাতার অঙ্ক পড়া।
 *
 * ⓘ ক্রেডিট নোট মালিকের ছাড়ের ছকে সই চায় ([[NoteService::waitsForASignature()]]) — ডেমোর TDEPOT-এ ছকটা আছে
 * ([[OwnerSignsDiscounts]]); তাই হিসাবের দাবিগুলো আসল পথে চলে: পাকা করা → মালিকের সই → শেষ সইয়ে নোট খাতায়।
 */
trait WritesNotesAgainstBills
{
    use SignsTheDiscountAsTheOwner;

    protected Company $company;

    protected User $owner;

    protected Customer $customer;

    protected Supplier $supplier;

    protected Product $product;

    protected Warehouse $warehouse;

    protected Tax $vat15;

    private int $billNo = 0;

    protected function setUpTheNoteScene(): void
    {
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $settings = app(SettingsService::class);
        $settings->set('sales.vat_enabled', true);
        $settings->set('purchase.vat_enabled', true);
        $settings->flush();

        $this->vat15 = Tax::query()->create(['company_id' => $this->company->id, 'code' => 'NOTE-VAT15', 'name_en' => 'VAT 15%',
            'name_bn' => 'ভ্যাট ১৫%', 'rate' => '15', 'kind' => 'vat', 'is_inclusive' => false, 'is_active' => true]);

        $this->product = Product::query()->create([
            'company_id' => $this->company->id, 'code' => 'NOTE-P1', 'name_en' => 'Note test rice', 'name_bn' => 'নোটের চাল',
            'unit_id' => Product::query()->value('unit_id'), 'purchase_price' => '100', 'sale_price' => '200',
            'tax_id' => $this->vat15->id, 'is_active' => true,
        ]);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->supplier = Supplier::query()->whereIn('id', Supplier::onlySuppliersIds())->orderBy('id')->firstOrFail();

        $this->customer = app(CustomerService::class)->create(['name_en' => 'Note Lines Customer', 'credit_limit' => 0, 'credit_days' => 0]);
        $this->customer->forceFill(['credit_limit' => '1000000000'])->save();
    }

    /** পাকা ক্রয় বিল — মাল গুদামে, দামের স্তর এই বিলের */
    protected function purchaseBill(string $qty = '10', string $rate = '100'): PurchaseBill
    {
        $bills = app(PurchaseBillService::class);

        $bill = $bills->confirm($bills->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString(),
                'supplier_bill_no' => 'NOTE-SUP-'.(++$this->billNo)],
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => $rate]],
        ));

        $bill = PurchaseBill::query()->findOrFail($bill->id)->load('lines');
        $this->assertGreaterThan(0, bccomp((string) $bill->tax, '0', 4), 'দৃশ্যটাই ভুল — ক্রয় বিলে ভ্যাট বসেনি।');

        return $bill;
    }

    /** পাকা বিক্রয় বিল — আগে মাল কেনা (২০টা), তারপর বিক্রি */
    protected function salesInvoice(string $qty = '4', string $rate = '200'): SalesInvoice
    {
        $this->purchaseBill('20', '100');

        $service = app(SalesInvoiceService::class);
        $invoice = $service->confirm($service->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => $rate]],
        ));

        $invoice = SalesInvoice::query()->findOrFail($invoice->id)->load('lines');
        $this->assertGreaterThan(0, bccomp((string) $invoice->tax, '0', 4), 'দৃশ্যটাই ভুল — বিক্রয় বিলে ভ্যাট বসেনি।');

        return $invoice;
    }

    /**
     * খসড়া সারির নোট।
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra
     */
    protected function draftNote(string $type, int $partyId, array $lines, array $extra = []): Note
    {
        return app(NoteService::class)->create([
            'note_type' => $type,
            'party_id' => $partyId,
            'reason' => 'price_correction',
            'narration' => 'নোটের সারির পরীক্ষা',
            'lines' => $lines,
        ] + $extra);
    }

    /** পাকা করা — ক্রেডিট নোট হলে মালিক ছাড়ে সই দেন, শেষ সইয়ে নোট খাতায় */
    protected function postNote(Note $note): Note
    {
        app(NoteService::class)->confirm($note);

        if ($note->type() === Note::TYPE_CN) {
            $this->ownerSignsTheDiscounts();
        }

        $note = Note::query()->findOrFail($note->id);
        $this->assertTrue($note->isConfirmed(), 'দৃশ্যটাই ভুল — নোট খাতায় ওঠেনি (সইয়ের অপেক্ষায়?)।');

        return $note;
    }

    protected function accountId(string $code): int
    {
        return (int) StandardChart::find($code)->id;
    }

    /**
     * এক কাগজের এক খাতের এক পাশের যোগফল।
     *
     * @param  'debit'|'credit'  $side
     * @param  array{0: string, 1: int}|null  $party
     */
    protected function sideOn(string $sourceType, int $sourceId, string $code, string $side, ?array $party = null): string
    {
        $query = LedgerEntry::query()->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->where('account_id', $this->accountId($code));

        if ($party !== null) {
            $query->where('party_type', $party[0])->where('party_id', $party[1]);
        }

        return bcadd((string) $query->sum($side), '0', 4);
    }

    /** @return list<string> এই কাগজের খাতার সারিগুলোর খাত-কোড */
    protected function codesOn(string $sourceType, int $sourceId): array
    {
        return LedgerEntry::query()->where('ledger_entries.source_type', $sourceType)->where('ledger_entries.source_id', $sourceId)
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->pluck('accounts.code')->map(fn ($c) => (string) $c)->unique()->sort()->values()->all();
    }

    protected function assertMoney(string $expected, string $actual, string $message = ''): void
    {
        $this->assertSame(0, bccomp($expected, $actual, 2), $message." (চাই {$expected}, পাওয়া {$actual})");
    }
}
