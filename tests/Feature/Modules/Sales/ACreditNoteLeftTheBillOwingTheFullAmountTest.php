<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Contracts\PartyOpenBills;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CreditExposure;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ বিলের বিপরীতে ক্রেডিট নোট খাতায় পাওনা কমাত, অথচ বিলের বাকি পুরোটাই থাকত — রসিদে টাকা দুবার নেওয়ার দরজা (মিল-তালিকা দফা
 * ৩৫; fe, ২৫ অক্টোবর ২০২৬; [[SalesInvoice::notedAmount()]])।
 *
 * দাবি:
 *  - বাঁধা ক্রেডিট নোট বিলের বাকি কমায়, বাঁধা ডেবিট নোট বাড়ায়; আদায়ের সাথে মিশিয়েও বিলের বাকি = খাতায় গ্রাহকের পাওনা;
 *  - একক বিল, তালিকা (withCollected), SQL-এর সূত্র ([[SalesInvoice::dueSql()]]), রসিদের খোলা বিল আর ঋণ নিয়ন্ত্রণ — সবাই এক অঙ্ক;
 *  - বাঁধন কেবল খুঁজে পাওয়া বিল থেকে: পাঠানো `against_type`/`against_id` দিয়ে অন্য কারো বিল কমানো যায় না;
 *  - বিলে না-বাঁধা নোট (লাইভে UB-র "Damage Bill"-এর মতো লেখা) কোনো বিলের বাকি ছোঁয় না, আর খোলা বিলের তালিকায় নিজের সারি পায়
 *    কেবল যে পর্দা চায় (ক্রেডিট ঋণাত্মক); আজকের রসিদের ফর্ম আগের মতো কেবল বিল পায়।
 */
final class ACreditNoteLeftTheBillOwingTheFullAmountTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 11:00:00'));
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->customer = $this->newCustomer('Note Bill Customer');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_tied_credit_note_lowers_the_bill_and_every_reader_agrees_with_the_books(): void
    {
        $invoice = $this->confirmedInvoice($this->customer, '3', '100');
        $total = (string) $invoice->total;

        $this->note($invoice, Note::CREDIT, '100');
        $this->receiptAgainst($invoice, '50');
        $this->note($invoice, Note::DEBIT, '30');

        $expected = bcadd(bcsub(bcsub($total, '100', 4), '50', 4), '30', 4);
        $fresh = SalesInvoice::query()->findOrFail($invoice->id);

        $this->assertSame(0, bccomp($fresh->dueAmount(), $expected, 4),
            "⛔ ক্রেডিট নোট (100) আর ডেবিট নোট (30) বিলের বাকিতে পড়েনি: বাকি {$fresh->dueAmount()}, হওয়ার কথা {$expected}।");
        $this->assertSame(0, bccomp($fresh->dueAmount(), $this->customer->fresh()->outstanding(), 4),
            '⛔ বিলের বাকি আর খাতায় গ্রাহকের পাওনা দুই কথা বলে: '.$fresh->dueAmount().' বনাম '.$this->customer->fresh()->outstanding());

        $listed = SalesInvoice::query()->withCollected()->whereKey($invoice->id)->firstOrFail();
        $this->assertSame(0, bccomp($listed->dueAmount(), $expected, 4), '⛔ তালিকার বাকি (withCollected) একক বিলের সাথে মেলে না।');

        $sql = (string) DB::query()->fromSub(SalesInvoice::query()->withCollected()->whereKey($invoice->id), 'i')
            ->selectRaw(SalesInvoice::dueSql('i').' as due')->value('due');
        $this->assertSame(0, bccomp($sql, $expected, 4), '⛔ SQL-এর বাকির সূত্র (dueSql) নোট বাদ দেয়: '.$sql);

        $bill = collect(app(PartyOpenBills::class)->openBills('customer', (int) $this->customer->id))->firstWhere('id', $invoice->id);
        $this->assertSame(0, bccomp((string) ($bill['outstanding'] ?? '0'), $expected, 4), '⛔ রসিদের খোলা বিল নোটের আগের বাকি দেখায়।');

        $exposed = app(CreditExposure::class)->openBills([(int) $this->customer->id => $this->customer->fresh()]);
        $unpaid = collect($exposed[(int) $this->customer->id] ?? [])->firstWhere('id', $invoice->id)['unpaid'] ?? '0';
        $this->assertSame(0, bccomp((string) $unpaid, $expected, 4), '⛔ ঋণ নিয়ন্ত্রণের বাকি নোটের আগের অঙ্ক দেখায়: '.$unpaid);
    }

    public function test_a_sent_bill_id_ties_nothing_and_lowers_no_ones_bill(): void
    {
        $other = $this->newCustomer('Somebody Else');
        $theirs = $this->confirmedInvoice($other, '2', '100');
        $before = $theirs->dueAmount();

        $note = app(NoteService::class)->create($this->noteData((int) $this->customer->id, Note::CREDIT, '150') + [
            'against_type' => SalesInvoice::drillSourceType(),
            'against_id' => $theirs->id,
        ]);
        app(NoteService::class)->confirm($note);

        $this->assertNull($note->fresh()->against_id, '⛔ পাঠানো against_id নোটে বসে গেল — বিল খোঁজা ছাড়াই বাঁধন।');
        $this->assertSame(0, bccomp(SalesInvoice::query()->findOrFail($theirs->id)->dueAmount(), $before, 4),
            '⛔ অন্য গ্রাহকের বিলের বাকি একটা পাঠানো আইডিতেই কমে গেল।');
    }

    public function test_an_untied_note_lowers_no_bill_and_gets_its_own_row_only_where_asked(): void
    {
        $invoice = $this->confirmedInvoice($this->customer, '2', '100');
        $before = $invoice->dueAmount();

        $note = app(NoteService::class)->create($this->noteData((int) $this->customer->id, Note::CREDIT, '80'));
        app(NoteService::class)->confirm($note);
        // ⓘ লাইভের পুরনো নোটের মতো — নম্বরের ঘরে মুক্ত লেখা, বাঁধন নেই
        $note->forceFill(['against_no' => 'Damage Bill', 'against_type' => null, 'against_id' => null])->save();

        $this->assertSame(0, bccomp(SalesInvoice::query()->findOrFail($invoice->id)->dueAmount(), $before, 4),
            '⛔ কোনো বিলে না-বাঁধা নোট একটা বিলের বাকি কমাল।');

        $plain = app(PartyOpenBills::class)->openBills('customer', (int) $this->customer->id);
        $this->assertSame(['invoice'], array_values(array_unique(array_column($plain, 'kind'))),
            '⛔ নোট চাওয়া ছাড়াই খোলা বিলের তালিকায় এল — আজকের রসিদের ফর্ম সেটাকে "কোন বিলের বিপরীতে" বাছাই ভাবত।');

        $rows = app(PartyOpenBills::class)->openBills('customer', (int) $this->customer->id, 50, true);
        $row = collect($rows)->firstWhere('kind', 'credit_note');
        $this->assertNotNull($row, '⛔ বিলে না-বাঁধা ক্রেডিট নোট খোলা বিলের তালিকায় নিজের সারি পায়নি।');
        $this->assertSame(0, bccomp((string) $row['outstanding'], '-80', 4), '⛔ ক্রেডিট নোটের সারি ঋণাত্মক নয়: '.$row['outstanding']);
        $this->assertSame((string) $note->public_id, $row['public_id'], '⛔ নোটের সারিতে নোটের public_id নেই।');
        $this->assertSame(0, $row['days_late'], '⛔ ক্রেডিট নোট "দেরি" দেখাচ্ছে।');
    }

    public function test_the_note_page_calls_untied_text_a_reference(): void
    {
        $note = app(NoteService::class)->create($this->noteData((int) $this->customer->id, Note::CREDIT, '40'));
        $note->forceFill(['against_no' => 'Damage Bill', 'against_id' => null, 'against_type' => null])->save();

        $page = (string) $this->get(route('accounts.note.show', $note))->assertOk()->getContent();

        $this->assertStringContainsString(e(__('accounts::note.reference')), $page,
            '⛔ বিলে না-বাঁধা নোটের লেখা পাতায় "কোন কাগজের বিপরীতে" নামে দেখায় — বাঁধন মনে হয়।');
    }

    private function note(SalesInvoice $invoice, string $direction, string $amount): Note
    {
        $notes = app(NoteService::class);
        $note = $notes->create($this->noteData((int) $invoice->customer_id, $direction, $amount) + ['against_no' => (string) $invoice->document_no]);
        $notes->confirm($note);
        $this->assertSame((int) $invoice->id, (int) $note->fresh()->against_id, 'দৃশ্যটাই ভুল — নোট বিলে বাঁধা হয়নি।');
        $this->assertContains($note->fresh()->status, \App\Core\Support\DocumentStatus::POSTED, 'দৃশ্যটাই ভুল — নোট খাতায় বসেনি (সইয়ের অপেক্ষায়?)।');

        return $note;
    }

    /** @return array<string, mixed> */
    private function noteData(int $customerId, string $direction, string $amount): array
    {
        return [
            'direction' => $direction,
            'party_kind' => Note::KIND_CUSTOMER,
            'party_id' => $customerId,
            'other_account_id' => StandardChart::find(StandardChart::DISCOUNT_GIVEN)?->id,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
            'tax_amount' => '0',
            'reason' => 'price_correction',
        ];
    }

    private function newCustomer(string $name): Customer
    {
        $customer = app(CustomerService::class)->create(['name_en' => $name, 'credit_limit' => 0, 'credit_days' => 0]);
        $customer->forceFill(['credit_limit' => '1000000000'])->save();

        return $customer;
    }

    private function confirmedInvoice(Customer $customer, string $qty, string $rate): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create(
            ['customer_id' => $customer->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->firstOrFail()->id, 'qty' => $qty, 'rate' => $rate]],
        ))->fresh();
    }

    private function receiptAgainst(SalesInvoice $invoice, string $amount): void
    {
        $this->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), [
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'narration' => 'আংশিক শোধ',
            'amount' => $amount,
            'party_type' => 'customer',
            'party_id' => $invoice->customer_id,
            'from_account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id,
            'to_account_id' => Account::query()->where('money_kind', Account::CASH)->where('is_group', false)->orderBy('code')->firstOrFail()->id,
            'against_type' => SalesInvoice::drillSourceType(),
            'against_id' => $invoice->id,
        ]);
    }
}
