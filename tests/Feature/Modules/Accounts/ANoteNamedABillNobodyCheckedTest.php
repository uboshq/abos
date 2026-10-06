<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * নোট একটা বিলের নাম বলত, আর কেউ বিলটা দেখত না — বিক্রয় পরিকল্পনা §৬, ৬ অক্টোবর ২০২৬: "দামে ভুল: বেশি ধরা →
 * ক্রেডিট নোট; কম ধরা → ডেবিট নোট"।
 *
 * ⛔ "কোন কাগজের বিপরীতে" ছিল খালি লেখা: ভুল নম্বর, অন্য গ্রাহকের বিল, বিলের চেয়ে বড় ক্রেডিট, বিলের হারের চেয়ে বেশি ভ্যাট
 * — সব চলত। ⭐ এখন গ্রাহকের নোটে নম্বর দিলে সেটা এই গ্রাহকের পাকা বিল ([[\App\Core\Contracts\NoteTarget]]), ক্রেডিট বিলের
 * বাকি জায়গার মধ্যে (পাকা করার সময়ও আবার মাপা), আর ভ্যাট খালি হলে বিলের হারে, লেখা হলে হারের মধ্যে।
 */
final class ANoteNamedABillNobodyCheckedTest extends TestCase
{
    use RefreshDatabase;

    private SalesInvoice $bill;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        // ⓘ একটা পাকা বিল, তারপর অঙ্কগুলো সোজা করা — ভ্যাট ১৫%: ১০০০ + ১৫০ = ১১৫০ (নোটের যাচাই কেবল এগুলো পড়ে)
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $customer->forceFill(['credit_limit' => '1000000000'])->save();
        $draft = app(\App\Modules\Sales\Services\SalesInvoiceService::class)->create(
            ['customer_id' => $customer->id, 'warehouse_id' => \App\Modules\Inventory\Models\Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => \App\Modules\Inventory\Models\Product::query()->firstOrFail()->id, 'qty' => '2', 'rate' => '100']],
        );
        $this->bill = app(\App\Modules\Sales\Services\SalesInvoiceService::class)->confirm($draft);
        $this->bill->forceFill(['total' => '1150', 'tax' => '150', 'freight_charge' => '0', 'rounding_amount' => '0'])->save();
    }

    public function test_a_wrong_number_or_another_customers_bill_is_refused(): void
    {
        $this->assertRefused(['against_no' => 'INV-NO-SUCH'], 'against_no');

        $other = Customer::query()->whereKeyNot($this->bill->customer_id)->firstOrFail();
        $this->assertRefused(['against_no' => $this->bill->document_no, 'party_id' => $other->id], 'against_no');

        $this->bill->forceFill(['status' => DocumentStatus::CANCELLED])->save();
        $this->assertRefused(['against_no' => $this->bill->document_no], 'against_no');
    }

    public function test_the_right_bill_is_linked_and_a_blank_vat_follows_its_rate(): void
    {
        $note = app(NoteService::class)->create($this->data(['amount' => '200']));

        $this->assertSame('sales_invoice', $note->against_type);
        $this->assertSame((int) $this->bill->id, (int) $note->against_id, '⛔ নোট বিলের id চেনে না।');
        $this->assertSame($this->bill->document_no, $note->against_no);
        $this->assertSame(0, bccomp((string) $note->tax_amount, '30', 4), '⛔ খালি ভ্যাট বিলের হারে (২০০ × ১৫% = ৩০) বসেনি।');
    }

    public function test_vat_above_the_bill_rate_is_refused(): void
    {
        $this->assertRefused(['amount' => '200', 'tax_amount' => '31'], 'tax_amount');

        $ok = app(NoteService::class)->create($this->data(['amount' => '200', 'tax_amount' => '0']));
        $this->assertSame(0, bccomp((string) $ok->tax_amount, '0', 4), '⛔ ইচ্ছে করে লেখা শূন্য ভ্যাট বদলে গেল।');
    }

    public function test_a_credit_cannot_pass_what_is_left_of_the_bill_even_at_confirm(): void
    {
        // ⓘ বিল ১১৫০ — একটা নোটে ১২০০ নয়
        $this->assertRefused(['amount' => '1100'], 'amount');

        // ⓘ দুইটা খসড়া, প্রতিটা আলাদাভাবে চলে (৬০০ + ৯০ = ৬৯০); দুইটা মিলে ১৩৮০ > ১১৫০ — দ্বিতীয়টা পাকা হয় না
        $first = app(NoteService::class)->create($this->data(['amount' => '600']));
        $second = app(NoteService::class)->create($this->data(['amount' => '600']));

        app(NoteService::class)->confirm($first);

        try {
            app(NoteService::class)->confirm($second);
            $this->fail('⛔ বিলের বাকি জায়গা পেরিয়ে দ্বিতীয় ক্রেডিট পাকা হলো।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertSame(DocumentStatus::DRAFT, $second->fresh()->status);

        // ⓘ ডেবিট নোটের সীমা নেই — কম ধরা দাম যোগ করা
        $debit = app(NoteService::class)->create($this->data(['direction' => Note::DEBIT, 'amount' => '5000', 'reason' => Note::REASONS[0]]));
        $this->assertSame($this->bill->document_no, $debit->against_no);
    }

    public function test_a_supplier_note_keeps_its_free_text_number(): void
    {
        $note = app(NoteService::class)->create([
            'direction' => Note::DEBIT, 'party_kind' => Note::KIND_SUPPLIER, 'party_id' => (int) Supplier::query()->value('id'),
            'trx_date' => now()->toDateString(), 'amount' => '100', 'reason' => Note::REASONS[0], 'against_no' => 'BILL-FROM-THEM-7',
        ]);

        $this->assertSame('BILL-FROM-THEM-7', $note->against_no);
        $this->assertNull($note->against_id);
    }

    public function test_the_form_says_the_number_is_checked(): void
    {
        $this->get(route('accounts.note.create', ['direction' => Note::CREDIT, 'kind' => Note::KIND_CUSTOMER, 'party_id' => $this->bill->customer_id]))->assertOk()
            ->assertSee(__('accounts::note.against_hint'));
    }

    /** @param  array<string, mixed>  $override */
    private function data(array $override = []): array
    {
        return array_merge([
            'direction' => Note::CREDIT,
            'party_kind' => Note::KIND_CUSTOMER,
            'party_id' => (int) $this->bill->customer_id,
            'trx_date' => now()->toDateString(),
            'amount' => '100',
            'reason' => Note::REASONS[0],
            'against_no' => $this->bill->document_no,
        ], $override);
    }

    /** @param  array<string, mixed>  $override */
    private function assertRefused(array $override, string $field): void
    {
        try {
            app(NoteService::class)->create($this->data($override));
            $this->fail("⛔ নোটটা বসে গেল — {$field} থামানোর কথা।");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }
}
