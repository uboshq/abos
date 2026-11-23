<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Models\NoteLine;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WritesNotesAgainstBills;
use Tests\TestCase;

/**
 * ⭐ "মাল ফেরত" সারি — ফেরতের কাগজ নিজে বানায় আর তোলে, নোট আবার তোলে না (মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬;
 * [[\App\Core\Contracts\ReturnsGoodsForNotes]])।
 *
 * দাবি:
 *  - ক্রেডিট নোটের মাল ফেরতের সারি → বিক্রয় ফেরত (SR), বিলে বাঁধা, পাকা; মাল গুদামে ফেরে; সারিতে ফেরতের ধরন/id/নম্বর,
 *    নোটে `returned_total`;
 *  - নোটের নিজের দাখিলায় ঐ সারি নেই (৪১১০ নেই) — নোট আর ফেরত মিলে গ্রাহকের Cr ১১১০ = সারির মোটের যোগফল, একবারই;
 *  - সরবরাহকারীর ডেবিট নোটে একই — ক্রয় ফেরত (PR), নোটে ১১২০ নেই, Dr ২১১১ একবারই;
 *  - নোট বাতিল করলে তার ফেরতের কাগজও বাতিল।
 */
final class AGoodsReturnedLineIsPostedOnlyByItsReturnTest extends TestCase
{
    use RefreshDatabase;
    use WritesNotesAgainstBills;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTheNoteScene();
    }

    public function test_a_credit_note_goods_line_makes_a_sales_return_and_nothing_is_posted_twice(): void
    {
        $invoice = $this->salesInvoice('4', '200');
        $billLine = $invoice->lines->first();

        $note = $this->draftNote(Note::TYPE_CN, (int) $this->customer->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $billLine->id, 'qty' => '1', 'goods_moved' => true],
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $billLine->id, 'qty' => '2', 'rate' => '10'],
        ], ['against_no' => $invoice->document_no]);

        $this->assertMoney('20', (string) $note->amount, '⛔ নোটের নিজের অঙ্কে মাল ফেরতের সারি ঢুকেছে।');
        $this->assertGreaterThan(0, bccomp((string) $note->returned_total, '0', 4), '⛔ ফেরতের ভাগ নোটে নেই।');

        $note = $this->postNote($note)->load('lines');
        $moved = $note->lines->firstWhere('goods_moved', true);

        $this->assertSame(SalesReturn::drillSourceType(), $moved->return_type, '⛔ সারিতে ফেরতের ধরন নেই।');
        $this->assertNotNull($moved->return_id);
        $return = SalesReturn::query()->withoutGlobalScopes()->findOrFail($moved->return_id);
        $this->assertSame((string) $return->document_no, (string) $moved->return_no);
        $this->assertSame((int) $invoice->id, (int) $return->sales_invoice_id, '⛔ ফেরত বিলে বাঁধা নয়।');
        $this->assertSame(DocumentStatus::CONFIRMED, $return->status, '⛔ ফেরত পাকা হয়নি।');
        $this->assertMoney((string) $moved->total, (string) $return->total, '⛔ ফেরতের অঙ্ক আর নোটের সারির অঙ্ক আলাদা।');
        $this->assertMoney((string) $moved->total, (string) $note->returned_total);

        $this->assertMoney('1', (string) DB::table('inv_stock_movements')->where('source_type', SalesReturn::STOCK_SOURCE)
            ->where('source_id', $return->id)->sum('floor_change'), '⛔ মাল গুদামে ফেরেনি।');

        // ⛔ নোট মাল ফেরতের সারি আবার তোলে না
        $this->assertMoney('0', $this->sideOn('note', $note->id, StandardChart::SALES_RETURN, 'debit'), '⛔ নোট নিজেও ৪১১০ তুলল — টাকা দুবার।');
        $customer = ['customer', (int) $this->customer->id];
        $this->assertMoney((string) $note->total, $this->sideOn('note', $note->id, StandardChart::RECEIVABLE, 'credit', $customer));

        $lineTotals = $note->lines->reduce(fn (string $sum, NoteLine $l) => bcadd($sum, (string) $l->total, 4), '0');
        $credited = bcadd($this->sideOn('note', $note->id, StandardChart::RECEIVABLE, 'credit', $customer),
            $this->sideOn(SalesReturn::drillSourceType(), (int) $return->id, StandardChart::RECEIVABLE, 'credit', $customer), 4);
        $this->assertMoney($lineTotals, $credited, '⛔ নোট আর ফেরত মিলে গ্রাহকের পাওনা সারির মোটের চেয়ে আলাদা কমাল।');

        app(NoteService::class)->cancel($note->fresh(), 'ভুল নোট');

        $this->assertTrue($note->fresh()->isCancelled());
        $this->assertSame(DocumentStatus::CANCELLED, SalesReturn::query()->withoutGlobalScopes()->findOrFail($return->id)->status,
            '⛔ নোট বাতিল, অথচ তার বিক্রয় ফেরত পাকাই রইল — মাল আর টাকা দুটোই ভুল।');
    }

    public function test_a_supplier_debit_note_goods_line_makes_a_purchase_return_and_nothing_is_posted_twice(): void
    {
        $bill = $this->purchaseBill('10', '100');

        $note = $this->draftNote(Note::TYPE_DN, (int) $this->supplier->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $bill->lines->first()->id, 'qty' => '2', 'goods_moved' => true],
            ['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId('4300'), 'amount' => '100'],
        ], ['against_no' => $bill->document_no, 'supplier_note_no' => 'SUP-CN-9', 'supplier_note_date' => now()->toDateString()]);

        $note = $this->postNote($note)->load('lines');
        $moved = $note->lines->firstWhere('goods_moved', true);

        $this->assertSame(PurchaseReturn::drillSourceType(), $moved->return_type, '⛔ সারিতে ক্রয় ফেরতের ধরন নেই।');
        $return = PurchaseReturn::query()->withoutGlobalScopes()->findOrFail($moved->return_id);
        $this->assertSame((string) $return->document_no, (string) $moved->return_no);
        $this->assertSame((int) $bill->id, (int) $return->purchase_bill_id, '⛔ ফেরত বিলে বাঁধা নয়।');
        $this->assertSame(DocumentStatus::CONFIRMED, $return->status, '⛔ ক্রয় ফেরত পাকা হয়নি।');
        $this->assertMoney((string) $moved->total, (string) $return->total);
        $this->assertMoney((string) $moved->total, (string) $note->returned_total);

        $this->assertMoney('-2', (string) DB::table('inv_stock_movements')->where('source_type', PurchaseReturn::STOCK_SOURCE)
            ->where('source_id', $return->id)->sum('floor_change'), '⛔ মাল গুদাম থেকে বের হয়নি।');

        $this->assertMoney('0', $this->sideOn('note', $note->id, StandardChart::INVENTORY, 'credit'), '⛔ নোট নিজেও মজুদ (১১২০) কমাল — দুবার।');
        $supplier = ['supplier', (int) $this->supplier->id];
        $this->assertMoney('100', $this->sideOn('note', $note->id, StandardChart::PAYABLE, 'debit', $supplier));

        $lineTotals = $note->lines->reduce(fn (string $sum, NoteLine $l) => bcadd($sum, (string) $l->total, 4), '0');
        $debited = bcadd($this->sideOn('note', $note->id, StandardChart::PAYABLE, 'debit', $supplier),
            $this->sideOn(PurchaseReturn::drillSourceType(), (int) $return->id, StandardChart::PAYABLE, 'debit', $supplier), 4);
        $this->assertMoney($lineTotals, $debited, '⛔ নোট আর ক্রয় ফেরত মিলে দেনা সারির মোটের চেয়ে আলাদা কমাল।');

        app(NoteService::class)->cancel($note->fresh(), 'ভুল নোট');

        $this->assertSame(DocumentStatus::CANCELLED, PurchaseReturn::query()->withoutGlobalScopes()->findOrFail($return->id)->status,
            '⛔ নোট বাতিল, অথচ তার ক্রয় ফেরত পাকাই রইল।');
    }

    public function test_a_goods_line_needs_a_bill_to_return_against(): void
    {
        // ⓘ বিল ছাড়া পণ্যের সারিতে "মাল ফেরত" টিক টেকে না — ফেরতের কাগজ বিল ছাড়া হয় না
        $note = $this->draftNote(Note::TYPE_CN, (int) $this->customer->id, [
            ['type' => NoteLine::PRODUCT, 'product_id' => $this->product->id, 'qty' => '1', 'rate' => '200', 'goods_moved' => true],
        ]);

        $this->assertFalse((bool) $note->lines->first()->goods_moved, '⛔ বিল ছাড়া মাল ফেরতের সারি বসল।');
        $this->assertMoney('0', (string) $note->returned_total);
    }
}
