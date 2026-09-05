<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Purchase\Services\PaymentService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * আমাদের লেখা চেক পাশ হওয়ার আগেই ব্যাংক থেকে টাকা কমিয়ে দিত।
 *
 * ── কী ঘটত ───────────────────────────────────────────────────────────
 * সরবরাহকারীকে চেক দিলে পরিশোধ দাখিলা বসাত **Dr প্রদেয় / Cr যে খাত
 * বাছা হয়েছে** — `instrument` যা-ই হোক। অর্থাৎ চেক লিখলেই ব্যাংক কমত।
 *
 * ⛔ কিন্তু চেক লেখা আর টাকা যাওয়া এক জিনিস নয়। কাগজটা তিন দিন পকেটে
 * থাকতে পারে, ব্যাংকে গিয়ে ফেরতও আসতে পারে। ততক্ষণ ব্যাংকের খাতা
 * **বাস্তবের চেয়ে কম** দেখাত, আর ব্যাংকের কাগজের সাথে মেলাতে গেলে
 * প্রতিটা অমিলের কারণ হাতে খুঁজতে হত।
 *
 * ── এই ভুলটা উল্টো দিকে একবার ধরা পড়েছে ──────────────────────────────
 * বিক্রয়ে: *"হাতে চেক ইতিমধ্যেই ব্যাংকের টাকা ছিল"* — গৃহীত চেক সরাসরি
 * ব্যাংকে বসত। সারাই হয়েছিল ১১০৪ দিয়ে। ⚠️ **ক্রয়ের দিকে একই ভুল রয়ে
 * গিয়েছিল**, আর কেউ জানত না, কারণ ক্রয়ের পর্দায় "কীভাবে দেওয়া হলো"
 * ঘরটাই ছিল না — তাই চেক বাছার সহজ পথও ছিল না।
 *
 * ── এই পাহারাটা কী দেখে ──────────────────────────────────────────────
 * ⚠️ "চেকের কাগজ তৈরি হয়েছে" নয় — **কোন খাতে কত বসল**। ⓘ কেবল কাগজ
 * গুনলে গার্ডটা অন্ধ হত: কাগজ তৈরি হয়েও টাকার খাত কমতে পারত, আর তখন
 * দুই জায়গায় দুইবার হিসাব হত।
 */
final class AChequeIsNotMoneyUntilItClearsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $company = Company::query()->findOrFail($this->owner->current_company_id);

        CompanyContext::set(
            $company->id,
            $this->owner->current_branch_id ?? $company->defaultBranch()?->id,
        );

        $this->actingAs($this->owner);
    }

    /**
     * চেকে দিলে টাকার খাত ছোঁয়া হয় না — দায়টা ২১১৫-তে বসে।
     */
    public function test_a_cheque_does_not_touch_the_money_account(): void
    {
        $payment = $this->pay(['instrument' => 'cheque', 'instrument_no' => 'CHQ-9001']);

        $this->assertNotNull($payment->cheque_id, 'চেকের কাগজটাই তৈরি হয়নি।');

        $this->assertSame(
            [],
            $this->ledger('purchase_payment', $payment->id),
            'পরিশোধ নিজেই দাখিলা বসিয়েছে — চেকের দাখিলার সাথে মিলে প্রদেয় দুইবার ডেবিট হত।',
        );

        $this->assertSame(
            [
                [$this->code(StandardChart::PAYABLE), '200.0000', '0.0000'],
                [StandardChart::CHEQUES_ISSUED, '0.0000', '200.0000'],
            ],
            $this->ledger('cheque', $payment->cheque_id),
            'চেকের দাখিলা প্রদেয় থেকে ইস্যু করা চেকে যায়নি।',
        );
    }

    /**
     * নগদে দিলে আগের মতোই — টাকার খাত এখনই কমে।
     *
     * ⓘ এটা না থাকলে উপরের টেস্টটা "কোনো পরিশোধই কিছু পোস্ট করবে না"
     * লিখেও সবুজ হত।
     */
    public function test_cash_still_posts_exactly_as_before(): void
    {
        $payment = $this->pay(['instrument' => 'cash']);

        $this->assertNull($payment->cheque_id, 'নগদের পরিশোধে চেকের কাগজ তৈরি হয়েছে।');

        $rows = $this->ledger('purchase_payment', $payment->id);

        $this->assertCount(2, $rows, 'নগদের পরিশোধ খাতায় দুইটা সারি লেখেনি।');

        $this->assertSame(
            $this->code(StandardChart::PAYABLE),
            $rows[0][0],
            'প্রদেয় ডেবিট হয়নি।',
        );

        $this->assertSame(
            ['0.0000', '200.0000'],
            [$rows[1][1], $rows[1][2]],
            'টাকার খাত ক্রেডিট হয়নি — ক্রয়ে টাকা কমার কথা, বাড়ার নয়।',
        );
    }

    /**
     * নম্বর ছাড়া চেক হয় না।
     *
     * ⓘ একই সরবরাহকারীকে তিনটা চেক দিলে নম্বরটাই একমাত্র পার্থক্য, আর
     * ব্যাংকের কাগজের সাথে মেলাতেও ওটাই লাগে।
     */
    public function test_a_cheque_without_a_number_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->pay(['instrument' => 'cheque']);
    }

    /**
     * চেকে দেওয়া পরিশোধ বাতিল করলে চেকটাও বাতিল হয়, আর দায় ফিরে যায়।
     *
     * ⚠️ এটা না থাকলে বাতিলের সময় `reverse()` খুঁজত পরিশোধের নিজের
     * দাখিলা — যা নেই — আর ব্যতিক্রম ছুড়ত।
     */
    public function test_cancelling_a_cheque_payment_undoes_the_liability(): void
    {
        $payment = $this->pay(['instrument' => 'cheque', 'instrument_no' => 'CHQ-9002']);
        $chequeId = $payment->cheque_id;

        app(PaymentService::class)->cancel($payment, 'ভুল হয়েছিল');

        $this->assertSame(
            DocumentStatus::CANCELLED,
            $payment->fresh()->status,
            'পরিশোধটা বাতিল হয়নি।',
        );

        $this->assertSame(
            Cheque::CANCELLED,
            Cheque::query()->findOrFail($chequeId)->status,
            'পরিশোধ বাতিল হলো অথচ চেকটা এখনো চালু — কাগজটা কারো হাতে থেকে যেত।',
        );

        $this->assertSame(
            [
                [StandardChart::CHEQUES_ISSUED, '200.0000', '0.0000'],
                [$this->code(StandardChart::PAYABLE), '0.0000', '200.0000'],
            ],
            $this->ledger('cheque:bounced', $chequeId),
            'বাতিলের দাখিলা দায় মোছেনি বা দেনা ফেরায়নি।',
        );
    }

    /**
     * একটা নিশ্চিত করা পরিশোধ — বিলের বাকির ভিতরেই।
     *
     * @param  array<string, mixed>  $extra
     */
    private function pay(array $extra): \App\Modules\Purchase\Models\Payment
    {
        $bill = $this->aBillWithBalance();

        $money = (int) \App\Modules\Accounts\Models\Account::query()
            ->money()->postable()->active()->orderBy('code')->value('id');

        $service = app(PaymentService::class);

        return $service->confirm($service->create([
            'supplier_id' => $bill->supplier_id,
            'account_id' => $money,
            'trx_date' => now()->toDateString(),
            'amount' => '200',
        ] + $extra, [['purchase_bill_id' => $bill->id, 'amount' => '200']]));
    }

    /**
     * বাকি আছে এমন একটা বিল।
     *
     * ⚠️ ডেমো ডেটার বিলগুলো শোধ হয়ে থাকতে পারে, আর তখন পরিশোধ
     * *"বাকির চেয়ে বেশি"* বলে আটকাত — একটা ভুল যার সাথে এই পরীক্ষার
     * কোনো সম্পর্ক নেই। তাই বাকি আছে এমন একটাই বাছা হয়।
     */
    private function aBillWithBalance(): \App\Modules\Purchase\Models\PurchaseBill
    {
        foreach (\App\Modules\Purchase\Models\PurchaseBill::query()
            ->where('status', DocumentStatus::CONFIRMED)->orderByDesc('id')->get() as $bill) {
            if (bccomp((string) $bill->dueAmount(), '200', 4) >= 0) {
                return $bill;
            }
        }

        return $this->aFreshBill();
    }

    /**
     * নিজের বিল নিজে বানানো — কারণ ডেমো ডেটায় একটাও নেই।
     *
     * ── কেন এটা যোগ করতে হলো ────────────────────────────────────────
     * উপরের খোঁজাটা লেখা হয়েছিল ধরে নিয়ে যে সিডার কিছু ক্রয় বিল রেখে
     * যায়। ⛔ রাখে না — `DemoSeeder`-এ `PurchaseBill` শব্দটা **শূন্যবার**।
     * যে বিলগুলো দেখা যাচ্ছিল সেগুলো ছিল হাতে করা পরীক্ষার অবশেষ, আর
     * ওগুলো ছিল কেবল উন্নয়নের ডাটাবেসে।
     *
     * ⚠️ ফলটা আজকের চেনা আকৃতি: পরীক্ষাটা **পরিবেশের উপর দাঁড়িয়ে
     * ছিল**, নিজের গড়া দৃশ্যের উপর নয় — আর তাই পরিষ্কার ডাটাবেসে
     * চারটাই থেমে যেত, কোনোটা কিছু প্রমাণ না করেই।
     *
     * ⭐ তাই খোঁজাটা রাখা হলো (থাকলে সেটাই বেশি বাস্তব), কিন্তু না
     * পেলে দৃশ্যটা নিজেই গড়া হয়।
     */
    private function aFreshBill(): \App\Modules\Purchase\Models\PurchaseBill
    {
        $supplier = \App\Modules\Supplier\Models\Supplier::query()->firstOrFail();
        $product = \App\Modules\Inventory\Models\Product::query()->firstOrFail();

        $bills = app(\App\Modules\Purchase\Services\PurchaseBillService::class);

        return $bills->confirm($bills->create(
            ['supplier_id' => $supplier->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '10', 'rate' => '100']],
        ));
    }

    /** কোড থেকে খাতের কোড — নেস্টেড ছকেও ঠিক সারিটাই মেলে। */
    private function code(string $code): string
    {
        return StandardChart::find($code)->code;
    }

    /**
     * এই উৎসের দাখিলাগুলো — [কোড, ডেবিট, ক্রেডিট] হিসেবে।
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function ledger(string $sourceType, int $sourceId): array
    {
        return DB::table('ledger_entries as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.source_type', $sourceType)
            ->where('l.source_id', $sourceId)
            ->orderBy('l.id')
            ->get(['a.code', 'l.debit', 'l.credit'])
            ->map(fn ($row) => [$row->code, $row->debit, $row->credit])
            ->all();
    }
}
