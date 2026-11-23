<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Models\NoteLine;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Purchase\Models\PurchaseBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WritesNotesAgainstBills;
use Tests\TestCase;

/**
 * ⭐ সারির নোটের দাখিলা — মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬ ([[NotePosting]])।
 *
 * দাবি:
 *  - ক্রেডিট নোটের দাম ঠিক করার সারি: Dr ৪১০০ বিক্রয় · Dr ২১২০ ভ্যাট (বিলের হারে) / Cr ১১১০ (গ্রাহক) — ৪১১০ নয়;
 *  - বিল ছাড়া খাতের সারি (৫৩০০ ছাড়): ভ্যাট ০, পাঠানো ভ্যাটের কোড থাকলেও;
 *  - গ্রাহকের ডেবিট নোট: Dr ১১১০ / Cr ৪১০০ (+ Cr ২১২০ বিল থাকলে);
 *  - সরবরাহকারীর ডেবিট নোটের দামের ছাড়: মজুদে থাকা ভাগ Cr ১১২০, বিক্রি হয়ে যাওয়া ভাগ Cr ৫১৫০, Dr ২১১১ (সরবরাহকারী);
 *  - সরবরাহকারীর ডেবিট নোটের জরিমানার সারি: Dr ২১১১ / Cr ৪৩০০;
 *  - পাকা সরবরাহকারীর ডেবিট নোট বিলের বাকি কমায় ([[PurchaseBill::returnedAmount()]])।
 */
final class ANoteLinePostsToTheAccountItsKindNeedsTest extends TestCase
{
    use RefreshDatabase;
    use WritesNotesAgainstBills;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTheNoteScene();
    }

    public function test_a_credit_note_price_line_reverses_sales_and_its_vat(): void
    {
        $invoice = $this->salesInvoice('4', '200');
        $billLine = $invoice->lines->first();

        $note = $this->draftNote(Note::TYPE_CN, (int) $this->customer->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $billLine->id, 'qty' => '4', 'rate' => '10'],
        ], ['against_no' => $invoice->document_no]);

        $line = $note->lines->first();
        $this->assertSame($this->accountId(StandardChart::SALES), (int) $line->account_id, '⛔ দাম ঠিক করার সারি ৪১০০-এ নয়।');
        $this->assertMoney('15', (string) $line->vat_rate, '⛔ সারির ভ্যাটের হার বিলের নয়।');
        $this->assertMoney('40', (string) $line->amount);
        $this->assertMoney('6', (string) $line->vat_amount);

        $note = $this->postNote($note);
        $customer = ['customer', (int) $this->customer->id];

        $this->assertMoney('40', $this->sideOn('note', $note->id, StandardChart::SALES, 'debit'), '⛔ Dr ৪১০০ বসেনি।');
        $this->assertMoney('6', $this->sideOn('note', $note->id, StandardChart::VAT_PAYABLE, 'debit'), '⛔ Dr ২১২০ বসেনি।');
        $this->assertMoney('46', $this->sideOn('note', $note->id, StandardChart::RECEIVABLE, 'credit', $customer), '⛔ গ্রাহকের Cr ১১১০ ভুল।');
        $this->assertMoney('0', $this->sideOn('note', $note->id, StandardChart::SALES_RETURN, 'debit'), '⛔ দামের সারি বিক্রয় ফেরতে (৪১১০) বসল।');
        $this->assertSame([StandardChart::RECEIVABLE, StandardChart::VAT_PAYABLE, StandardChart::SALES], $this->codesOn('note', $note->id));
    }

    public function test_a_lump_sum_credit_note_on_an_account_without_a_bill_carries_no_vat(): void
    {
        $note = $this->draftNote(Note::TYPE_CN, (int) $this->customer->id, [
            ['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId(StandardChart::DISCOUNT_GIVEN), 'amount' => '300', 'tax_id' => $this->vat15->id],
        ]);

        $this->assertMoney('0', (string) $note->tax_amount, '⛔ মূল বিল ছাড়া নোটে ভ্যাট বসল — মূসক আইনে সমন্বয় নয়।');
        $this->assertMoney('300', (string) $note->total);

        $note = $this->postNote($note);

        $this->assertMoney('300', $this->sideOn('note', $note->id, StandardChart::DISCOUNT_GIVEN, 'debit'));
        $this->assertMoney('300', $this->sideOn('note', $note->id, StandardChart::RECEIVABLE, 'credit', ['customer', (int) $this->customer->id]));
        $this->assertSame([StandardChart::RECEIVABLE, StandardChart::DISCOUNT_GIVEN], $this->codesOn('note', $note->id), '⛔ ভ্যাটের খাত ছোঁয়া হলো।');
    }

    public function test_a_customer_debit_note_raises_sales_and_the_bills_vat(): void
    {
        $invoice = $this->salesInvoice('4', '200');

        $note = $this->postNote($this->draftNote(Note::TYPE_CDN, (int) $this->customer->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $invoice->lines->first()->id, 'qty' => '4', 'rate' => '5'],
        ], ['against_no' => $invoice->document_no]));

        $this->assertSame(Note::DEBIT, $note->direction);
        $this->assertMoney('23', $this->sideOn('note', $note->id, StandardChart::RECEIVABLE, 'debit', ['customer', (int) $this->customer->id]));
        $this->assertMoney('20', $this->sideOn('note', $note->id, StandardChart::SALES, 'credit'));
        $this->assertMoney('3', $this->sideOn('note', $note->id, StandardChart::VAT_PAYABLE, 'credit'));
    }

    public function test_a_customer_debit_note_without_a_bill_raises_sales_only(): void
    {
        $note = $this->postNote($this->draftNote(Note::TYPE_CDN, (int) $this->customer->id, [
            ['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId(StandardChart::SALES), 'amount' => '50', 'tax_id' => $this->vat15->id],
        ]));

        $this->assertMoney('50', $this->sideOn('note', $note->id, StandardChart::RECEIVABLE, 'debit', ['customer', (int) $this->customer->id]));
        $this->assertMoney('50', $this->sideOn('note', $note->id, StandardChart::SALES, 'credit'));
        $this->assertSame([StandardChart::RECEIVABLE, StandardChart::SALES], $this->codesOn('note', $note->id), '⛔ বিল ছাড়া ভ্যাট বসল।');
    }

    public function test_a_supplier_debit_note_price_cut_splits_between_stock_and_what_was_sold(): void
    {
        $bill = $this->purchaseBill('10', '100');
        $layers = DB::table('inv_cost_layers')->where('source_type', PurchaseBill::STOCK_SOURCE)->where('source_id', $bill->id);
        $this->assertGreaterThan(0, (clone $layers)->count(), 'দৃশ্যটাই ভুল — বিলের দামের স্তর নেই।');

        // ⓘ ১০টার ৬টা বিক্রি হয়ে গেছে — মজুদে ৪টা (০.৪)
        (clone $layers)->update(['qty_remaining' => '4']);

        $note = $this->draftNote(Note::TYPE_DN, (int) $this->supplier->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $bill->lines->first()->id, 'qty' => '10', 'rate' => '5'],
        ], ['against_no' => $bill->document_no, 'supplier_note_no' => 'SUP-CN-7', 'supplier_note_date' => now()->toDateString()]);

        $this->assertSame(0, bccomp((string) $note->lines->first()->stock_share, '0.4', 6), '⛔ মজুদের ভাগ ভুল: '.$note->lines->first()->stock_share);

        $note = $this->postNote($note);

        $this->assertMoney('57.5', $this->sideOn('note', $note->id, StandardChart::PAYABLE, 'debit', ['supplier', (int) $this->supplier->id]), '⛔ Dr ২১১১ ভুল।');
        $this->assertMoney('20', $this->sideOn('note', $note->id, StandardChart::INVENTORY, 'credit'), '⛔ মজুদে থাকা ভাগ Cr ১১২০ নয়।');
        $this->assertMoney('30', $this->sideOn('note', $note->id, StandardChart::PURCHASE_PRICE_VARIANCE, 'credit'), '⛔ বিক্রি হয়ে যাওয়া ভাগ Cr ৫১৫০ নয়।');
        $this->assertMoney('7.5', $this->sideOn('note', $note->id, StandardChart::VAT_PAYABLE, 'credit'));
    }

    public function test_a_supplier_debit_note_penalty_line_goes_to_other_income(): void
    {
        $note = $this->postNote($this->draftNote(Note::TYPE_DN, (int) $this->supplier->id, [
            ['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId('4300'), 'amount' => '500', 'description' => 'দেরিতে মাল — জরিমানা'],
        ], ['reason' => 'other']));

        $this->assertMoney('500', $this->sideOn('note', $note->id, StandardChart::PAYABLE, 'debit', ['supplier', (int) $this->supplier->id]));
        $this->assertMoney('500', $this->sideOn('note', $note->id, '4300', 'credit'));
        $this->assertSame([StandardChart::PAYABLE, '4300'], $this->codesOn('note', $note->id));
    }

    public function test_a_posted_supplier_debit_note_lowers_what_the_bill_still_owes(): void
    {
        $bill = $this->purchaseBill('10', '100');
        $this->assertMoney('0', $bill->returnedAmount());

        $this->postNote($this->draftNote(Note::TYPE_DN, (int) $this->supplier->id, [
            ['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId('4300'), 'amount' => '100'],
        ], ['against_no' => $bill->document_no]));

        $this->assertMoney('100', PurchaseBill::query()->findOrFail($bill->id)->returnedAmount(),
            '⛔ পাকা ডেবিট নোট বিলের বাকি কমায়নি — রসিদে সরবরাহকারীকে পুরো টাকা আবার দেওয়া যেত।');
    }
}
