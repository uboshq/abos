<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Models\NoteLine;
use App\Modules\Accounts\Models\Reversal;
use App\Modules\Accounts\Services\AccountsReversalService;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\WritesNotesAgainstBills;
use Tests\TestCase;

/**
 * ⭐ নোটের তিন ফাঁক বন্ধ — সমন্বয়কের নির্দেশ, ২৩ নভেম্বর ২০২৬:
 *
 *  ১. সরবরাহকারীর ডেবিট নোটের মজুদে-থাকা ভাগ মজুদের দামের স্তরও কমায় — মজুদ-মূল্য সবসময় খাতার ১১২০-এর সমান, পাকা হলে, মাঝে
 *     বিক্রি হলে, আর বাতিলে ফেরার পরেও ([[\App\Core\Contracts\RevaluesStockForNotes]], [[NoteService::restoreTheStock()]])।
 *  ২. পরিমাণের সীমা থেকে পাকা ফেরত আর অন্য নোটের অপেক্ষার ফেরত বাদ — বেশি ফেরত লেখার মুহূর্তেই থামে, মালিকের সইয়ের পরে নয়।
 *  ৩. পাকা নোটের উল্টো কাগজ নোটের সব কিছু ফেরায় — নিজের দাখিলা আর বাঁধা ফেরত (টাকা আর মাল), এক লেনদেনে।
 */
final class ANoteLeavesTheStockTheBillAndTheBooksInAgreementTest extends TestCase
{
    use RefreshDatabase;
    use WritesNotesAgainstBills;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTheNoteScene();
    }

    public function test_a_supplier_debit_note_keeps_the_stock_valuation_equal_to_the_ledger_through_a_sale_and_its_reversal(): void
    {
        $bill = $this->purchaseBill('20', '100');
        $this->sell('4');

        $value = fn () => app(CostLayerService::class)->valueOnHand($this->product->fresh());
        $books = fn () => bcadd((string) LedgerEntry::query()->where('company_id', CompanyContext::id())
            ->where('account_id', $this->accountId(StandardChart::INVENTORY))->selectRaw('COALESCE(SUM(debit - credit), 0) as b')->value('b'), '0', 4);
        $gap = fn () => bcsub($value(), $books(), 4);

        $before = $gap();

        // ⓘ ২০টার দাম ৫ টাকা করে বেশি নেওয়া — ১৬টা এখনো মজুদে, ৪টা বিক্রি হয়ে গেছে
        $note = $this->postNote($this->draftNote(Note::TYPE_DN, (int) $this->supplier->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $bill->lines->first()->id, 'qty' => '20', 'rate' => '5'],
        ], ['against_no' => $bill->document_no]))->load('lines');

        $this->assertMoney('80', $this->sideOn('note', $note->id, StandardChart::INVENTORY, 'credit'), '⛔ মজুদে থাকা ১৬টার ভাগ Cr ১১২০ নয়।');
        $this->assertMoney('20', $this->sideOn('note', $note->id, StandardChart::PURCHASE_PRICE_VARIANCE, 'credit'), '⛔ বিক্রি হওয়া ৪টার ভাগ Cr ৫১৫০ নয়।');
        $this->assertMoney('80', (string) $note->lines->first()->stock_value);
        $this->assertSame(0, bccomp($before, $gap(), 4), '⛔ ডেবিট নোটের পরে মজুদ-মূল্য রিপোর্ট আর খাতার ১১২০ আলাদা কথা বলছে।');

        // ⓘ নোটের পরে আরও ৬টা বিক্রি — কম দামে খরচে যায়, তবু দুটো এক
        $this->sell('6');
        $this->assertSame(0, bccomp($before, $gap(), 4), '⛔ নোটের পরের বিক্রিতে মজুদ-মূল্য আর ১১২০ সরে গেছে।');

        // ⓘ বাতিল — তাকে যা আছে (১০টা) তার দাম ফেরে; মাঝে বিক্রি হওয়া ৬টার ভাগ (৩০) খরচে, যাতে মজুদ আর খাতা আবার এক
        app(AccountsReversalService::class)->reverseNote($note, $this->owner, 'সরবরাহকারী নোট ফিরিয়ে নিলেন');

        $this->assertTrue($note->fresh()->isCancelled());
        $this->assertMoney('30', $this->sideOn('note:restock', $note->id, StandardChart::PURCHASE_PRICE_VARIANCE, 'debit'), '⛔ মাঝে বিক্রি হওয়া মালের ভাগ খরচে যায়নি।');
        $this->assertSame(0, bccomp($before, $gap(), 4), '⛔ বাতিলের পরে মজুদ-মূল্য রিপোর্ট আর খাতার ১১২০ আলাদা।');
    }

    public function test_a_supplier_debit_note_without_a_bill_never_touches_the_stock_account(): void
    {
        $note = $this->postNote($this->draftNote(Note::TYPE_DN, (int) $this->supplier->id, [
            ['type' => NoteLine::PRODUCT, 'product_id' => $this->product->id, 'qty' => '10', 'rate' => '5'],
        ], ['reason' => 'agreed_discount']));

        $this->assertMoney('0', $this->sideOn('note', $note->id, StandardChart::INVENTORY, 'credit'), '⛔ বিল ছাড়া নোট মজুদের খাত কমাল, অথচ কোন স্তর জানা নেই।');
        $this->assertMoney('50', $this->sideOn('note', $note->id, StandardChart::PURCHASE_PRICE_VARIANCE, 'credit'));
    }

    public function test_an_over_return_is_refused_when_it_is_written_counting_returns_and_other_waiting_notes(): void
    {
        $invoice = $this->salesInvoice('4', '200');
        $line = $invoice->lines->first();

        // ⓘ ৩টা ফেরতের অপেক্ষায় (খসড়া নোট) — বিলের ৪টার মধ্যে আর ১টা জায়গা
        $first = $this->draftNote(Note::TYPE_CN, (int) $this->customer->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $line->id, 'qty' => '3', 'goods_moved' => true],
        ], ['against_no' => $invoice->document_no]);

        $this->assertRefused(fn () => $this->draftNote(Note::TYPE_CN, (int) $this->customer->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $line->id, 'qty' => '2', 'goods_moved' => true],
        ], ['against_no' => $invoice->document_no]), 'অন্য নোটের অপেক্ষার ফেরত গোনা হয়নি');

        // ⓘ একই নোটে দুই সারি মিলে জায়গা পেরোনোও নয়
        $this->assertRefused(fn () => $this->draftNote(Note::TYPE_CN, (int) $this->customer->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $line->id, 'qty' => '1', 'goods_moved' => true],
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $line->id, 'qty' => '1', 'goods_moved' => true],
        ], ['against_no' => $invoice->document_no]), 'একই নোটের দুই সারি মিলে বেশি নিল');

        // ⓘ প্রথমটা পাকা — এখন বিক্রয় ফেরতে ৩টা; বাকি ১টা ঠিক চলে, ২টা নয়
        $this->postNote($first);
        $this->assertSame(1, SalesReturn::query()->withoutGlobalScopes()->where('sales_invoice_id', $invoice->id)->whereIn('status', DocumentStatus::POSTED)->count());

        $this->assertRefused(fn () => $this->draftNote(Note::TYPE_CN, (int) $this->customer->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $line->id, 'qty' => '2', 'rate' => '5'],
        ], ['against_no' => $invoice->document_no]), 'পাকা ফেরত গোনা হয়নি (দামের সারিও ফেরত মালের উপর নয়)');

        $last = $this->draftNote(Note::TYPE_CN, (int) $this->customer->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $line->id, 'qty' => '1', 'goods_moved' => true],
        ], ['against_no' => $invoice->document_no]);

        // ⓘ খসড়ার পরে বিলের শেষ মালটাও অন্য পথে ফিরল — পাকা করতে গেলে সই চাওয়ার আগেই থামে
        $invoice->fresh()->noteReturnGoods([['line_id' => (int) $line->id, 'product_id' => (int) $line->product_id, 'qty' => '1']],
            now()->toDateString(), 'কাউন্টারে ফেরত', 'TEST', true);

        $this->assertRefused(fn () => app(NoteService::class)->confirm($last->fresh()), 'পাকা করার সময় জায়গা আবার মাপা হয়নি');
        $this->assertTrue($last->fresh()->isDraft());
        $this->assertSame(0, Approval::query()->where('approvable_type', $last->getMorphClass())->where('approvable_id', $last->id)->count(),
            '⛔ জায়গা নেই, তবু মালিকের কাছে সই চাওয়া হলো — সইয়ের পরে থামত।');
    }

    public function test_reversing_a_credit_note_undoes_its_own_entry_and_its_sales_return_money_and_stock_together(): void
    {
        $invoice = $this->salesInvoice('4', '200');
        $line = $invoice->lines->first();

        $balance = fn (string $code) => bcadd((string) LedgerEntry::query()->where('company_id', CompanyContext::id())
            ->where('account_id', $this->accountId($code))->selectRaw('COALESCE(SUM(debit - credit), 0) as b')->value('b'), '0', 4);
        $codes = [StandardChart::RECEIVABLE, StandardChart::INVENTORY, StandardChart::SALES, StandardChart::SALES_RETURN,
            StandardChart::VAT_PAYABLE, StandardChart::COST_OF_GOODS_SOLD];
        $snapshot = fn () => array_combine($codes, array_map($balance, $codes));
        $onHand = fn () => app(CostLayerService::class)->qtyOnHand($this->product->fresh());

        $booksBefore = $snapshot();
        $stockBefore = $onHand();
        $dueBefore = $invoice->fresh()->dueAmount();

        $note = $this->postNote($this->draftNote(Note::TYPE_CN, (int) $this->customer->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $line->id, 'qty' => '1', 'goods_moved' => true],
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $line->id, 'qty' => '3', 'rate' => '10'],
        ], ['against_no' => $invoice->document_no]))->load('lines');

        $return = SalesReturn::query()->withoutGlobalScopes()->findOrFail($note->lines->firstWhere('goods_moved', true)->return_id);
        $this->assertSame(0, bccomp(bcadd($stockBefore, '1', 4), $onHand(), 4), 'দৃশ্যটাই ভুল — ফেরতের মাল গুদামে ওঠেনি।');

        $paper = app(AccountsReversalService::class)->reverseNote($note, $this->owner, 'ভুল নোট');

        $this->assertTrue($note->fresh()->isCancelled(), '⛔ নোট বাতিল হয়নি।');
        $this->assertSame(DocumentStatus::CANCELLED, $return->fresh()->status, '⛔ নোটের বিক্রয় ফেরত বাতিল হয়নি।');
        $this->assertSame($booksBefore, $snapshot(), '⛔ উল্টো কাগজের পরে খাতা নোটের আগের জায়গায় ফেরেনি।');
        $this->assertSame(0, bccomp($stockBefore, $onHand(), 4), '⛔ ফেরতের মাল গুদাম থেকে বেরোয়নি।');
        $this->assertSame(0, bccomp((string) $dueBefore, (string) $invoice->fresh()->dueAmount(), 4), '⛔ বিলের বাকি আগের জায়গায় ফেরেনি।');

        // ⓘ উল্টো কাগজের অঙ্ক নোটের পুরো প্রভাব — নিজের মোট আর ফেরতের ভাগ
        $this->assertSame(Reversal::NOTE, $paper->reversible_type);
        $this->assertMoney($note->grandTotal(), (string) $paper->amount, '⛔ উল্টো কাগজের অঙ্কে ফেরতের ভাগ নেই।');
    }

    private function sell(string $qty): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => '200']],
        ));
    }

    private function assertRefused(callable $act, string $why): void
    {
        try {
            $act();
        } catch (ValidationException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('⛔ '.$why.' — বেশি ফেরত চলে গেল।');
    }
}
