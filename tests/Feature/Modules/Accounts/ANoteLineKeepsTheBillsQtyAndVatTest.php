<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Models\NoteLine;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\MasterData\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\WritesNotesAgainstBills;
use Tests\TestCase;

/**
 * ⛔ নোটের সারি মূল বিলের বাইরে যায় না — মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬ ([[NoteLineBuilder]])।
 *
 * দাবি:
 *  - বিল ছাড়া ভ্যাট ০ (মূসক আইন ২০১২, ধারা ৪৪) — পাঠানো ভ্যাটের কোড থাকলেও;
 *  - পরিমাণ বিলের পরিমাণের বেশি নয় — ফেরে, কিছুই বসে না;
 *  - ভ্যাটের হার বিলের সারির — অন্য কোড পাঠালেও তালাবদ্ধ;
 *  - বিল বাছা নোটে পণ্যের সারি বিলেরই সারি হতে হবে;
 *  - সরবরাহকারীর ডেবিট নোট ক্রয়-ভ্যাট উল্টায় কেবল তাঁদের মূসক-৬.৭ নম্বর আর তারিখ থাকলে — না থাকলে ভ্যাট ০, Cr ২১২০ নেই।
 */
final class ANoteLineKeepsTheBillsQtyAndVatTest extends TestCase
{
    use RefreshDatabase;
    use WritesNotesAgainstBills;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTheNoteScene();
    }

    public function test_without_a_bill_there_is_no_vat(): void
    {
        $note = $this->draftNote(Note::TYPE_CN, (int) $this->customer->id, [
            ['type' => NoteLine::PRODUCT, 'product_id' => $this->product->id, 'qty' => '2', 'rate' => '50', 'tax_id' => $this->vat15->id],
            ['type' => NoteLine::SERVICE, 'item_name' => 'পরিবহন ফেরত', 'account_id' => $this->accountId(StandardChart::DISCOUNT_GIVEN),
                'qty' => '1', 'rate' => '30', 'tax_id' => $this->vat15->id],
        ]);

        $this->assertMoney('0', (string) $note->tax_amount, '⛔ বিল ছাড়া নোটে ভ্যাট বসল।');
        $this->assertMoney('130', (string) $note->total);

        foreach ($note->lines as $line) {
            $this->assertMoney('0', (string) $line->vat_rate);
            $this->assertMoney('0', (string) $line->vat_amount);
        }
    }

    public function test_the_qty_cannot_pass_what_the_bill_had(): void
    {
        $invoice = $this->salesInvoice('4', '200');
        $before = Note::query()->count();

        try {
            $this->draftNote(Note::TYPE_CN, (int) $this->customer->id, [
                ['type' => NoteLine::PRODUCT, 'source_line_id' => $invoice->lines->first()->id, 'qty' => '5', 'rate' => '10'],
            ], ['against_no' => $invoice->document_no]);
            $this->fail('⛔ বিলের ৪টার বিপরীতে ৫টার নোট বসল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines.0', $e->errors());
        }

        $this->assertSame($before, Note::query()->count(), '⛔ ফেরানো নোট বসে গেছে।');
    }

    public function test_the_vat_rate_is_the_bill_lines_whatever_the_screen_sends(): void
    {
        $invoice = $this->salesInvoice('4', '200');
        $five = Tax::query()->create(['company_id' => $this->company->id, 'code' => 'NOTE-VAT5', 'name_en' => 'VAT 5%',
            'name_bn' => 'ভ্যাট ৫%', 'rate' => '5', 'kind' => 'vat', 'is_inclusive' => false, 'is_active' => true]);

        $note = $this->draftNote(Note::TYPE_CN, (int) $this->customer->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $invoice->lines->first()->id, 'qty' => '2', 'rate' => '10',
                'tax_id' => $five->id, 'vat_rate' => '5'],
        ], ['against_no' => $invoice->document_no]);

        $line = $note->lines->first();
        $this->assertMoney('15', (string) $line->vat_rate, '⛔ পর্দার পাঠানো হার বিলের হারকে হারাল।');
        $this->assertMoney('3', (string) $line->vat_amount);
    }

    public function test_a_product_line_on_a_billed_note_must_be_one_of_the_bills_lines(): void
    {
        $invoice = $this->salesInvoice('4', '200');

        foreach ([['source_line_id' => 99999999], ['product_id' => $this->product->id]] as $i => $pointer) {
            try {
                $this->draftNote(Note::TYPE_CN, (int) $this->customer->id, [
                    ['type' => NoteLine::PRODUCT, 'qty' => '1', 'rate' => '10'] + $pointer,
                ], ['against_no' => $invoice->document_no]);
                $this->fail("⛔ বিলের বাইরের পণ্যের সারি (#{$i}) বিল বাছা নোটে বসল।");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('lines.0', $e->errors());
            }
        }
    }

    public function test_a_supplier_debit_note_reverses_vat_only_with_their_mushak_number_and_date(): void
    {
        $bill = $this->purchaseBill('10', '100');
        $line = ['type' => NoteLine::PRODUCT, 'source_line_id' => $bill->lines->first()->id, 'qty' => '10', 'rate' => '5'];
        $supplier = (int) $this->supplier->id;

        foreach ([[], ['supplier_note_no' => 'SUP-CN-1'], ['supplier_note_date' => now()->toDateString()]] as $i => $half) {
            $note = $this->postNote($this->draftNote(Note::TYPE_DN, $supplier, [$line], ['against_no' => $bill->document_no] + $half));

            $this->assertMoney('0', (string) $note->tax_amount, "⛔ মূসক-৬.৭ ছাড়া (#{$i}) ডেবিট নোটে ভ্যাট বসল।");
            $this->assertMoney('0', $this->sideOn('note', $note->id, StandardChart::VAT_PAYABLE, 'credit'), "⛔ মূসক-৬.৭ ছাড়া (#{$i}) Cr ২১২০ বসল।");
            $this->assertNull($note->supplier_note_no, '⛔ অর্ধেক মূসক-তথ্য নোটে রয়ে গেল।');
        }

        $note = $this->postNote($this->draftNote(Note::TYPE_DN, $supplier, [$line],
            ['against_no' => $bill->document_no, 'supplier_note_no' => 'SUP-CN-2', 'supplier_note_date' => now()->toDateString()]));

        $this->assertSame('SUP-CN-2', $note->supplier_note_no);
        $this->assertMoney('7.5', (string) $note->tax_amount);
        $this->assertMoney('7.5', $this->sideOn('note', $note->id, StandardChart::VAT_PAYABLE, 'credit'), '⛔ মূসক-৬.৭ থাকা সত্ত্বেও Cr ২১২০ নেই।');
        $this->assertMoney('57.5', $this->sideOn('note', $note->id, StandardChart::PAYABLE, 'debit', ['supplier', $supplier]));
    }
}
