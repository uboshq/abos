<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * সরবরাহকারীকে পরিশোধ।
 *
 * ── কেন এই ডকুমেন্টটা দরকার ছিল ─────────────────────────────────────
 * বিক্রয়ে আদায় ছিল, ক্রয়ে তার আয়না ছিল না। টাকা দিতে হলে জার্নাল
 * ভাউচার কাটতে হত, আর তাতে খতিয়ানে প্রদেয় কমত ঠিকই — কিন্তু কোন
 * বিলটা শোধ হলো তা কোথাও লেখা থাকত না। ফলে সরবরাহকারীর সাথে বসে
 * মেলানোর সময় কোন চালানটা বাকি তা দুই পক্ষই আন্দাজ করত।
 *
 * এখানকার পরীক্ষাগুলো তাই দুই দিকেই: খতিয়ানের অঙ্ক, আর বিল ধরে ধরে বাকি।
 */
class PurchasePaymentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    /**
     * টাকা দিলে প্রদেয় কমে, আর নগদও কমে।
     *
     * দুই দিক আলাদা করে মিলিয়ে দেখা হয়: শুধু প্রদেয় দেখলে টাকাটা
     * কোথা থেকে গেল তা যাচাই হত না, আর কেউ ভুল খাত বসালেও ধরা পড়ত না।
     */
    public function test_paying_a_supplier_lowers_both_the_payable_and_the_cash(): void
    {
        $bill = $this->confirmedBill('1000');

        $payableBefore = $this->balanceOf(StandardChart::PAYABLE);
        $cashBefore = $this->balanceOf(StandardChart::CASH_IN_HAND);

        $this->payments()->confirm(
            $this->payments()->create(
                ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '400'],
                [['purchase_bill_id' => $bill->id, 'amount' => '400']],
            )
        );

        // প্রদেয় ক্রেডিট প্রকৃতির, তাই শোধ দিলে ব্যালেন্স ডেবিটের দিকে সরে
        $this->assertSame(
            bcadd($payableBefore, '400.0000', 4),
            $this->balanceOf(StandardChart::PAYABLE),
        );

        $this->assertSame(
            bcsub($cashBefore, '400.0000', 4),
            $this->balanceOf(StandardChart::CASH_IN_HAND),
        );
    }

    /**
     * বিলের বাকি কমে — বিল ধরে ধরে।
     *
     * এটাই পুরো ডকুমেন্টটার কারণ।
     */
    public function test_the_bill_knows_how_much_of_it_is_still_owed(): void
    {
        $bill = $this->confirmedBill('1000');

        $this->assertSame('1000.0000', $bill->dueAmount());

        $this->payments()->confirm(
            $this->payments()->create(
                ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '300'],
                [['purchase_bill_id' => $bill->id, 'amount' => '300']],
            )
        );

        $this->assertSame('700.0000', $bill->fresh()->dueAmount());
    }

    /**
     * খসড়া পরিশোধে বিলের বাকি কমে না।
     *
     * ── কেন এটা আসল ভুল ছিল ─────────────────────────────────────────
     * ধরা পড়েছে পর্দা চালিয়ে: একটা খসড়া পরিশোধ তৈরি করেই দেখা গেল
     * ১,০০০ টাকার বিলের বাকি ৪০০ দেখাচ্ছে। অর্থাৎ কেউ একটা পরিশোধ
     * লিখে রেখে দিলেই বিলটা শোধ দেখাত — টাকা যাওয়ার আগেই বিলটা
     * বকেয়ার তালিকা থেকে হারিয়ে যেত, আর সরবরাহকারী ঠিকই আবার চাইতেন।
     */
    public function test_a_draft_payment_does_not_lower_the_due(): void
    {
        $bill = $this->confirmedBill('1000');

        $this->payments()->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '600'],
            [['purchase_bill_id' => $bill->id, 'amount' => '600']],
        );

        $this->assertSame('1000.0000', $bill->fresh()->dueAmount());
    }

    /**
     * একই বিলের দুইটা খসড়া — দ্বিতীয়টা খাতায় বসতে পারে না।
     *
     * তৈরির সময় দুইটাই বৈধ ছিল, কারণ তখন কোনোটাই খাতায় বসেনি। পাহারা
     * না থাকলে দুইটাই নিশ্চিত হয়ে বিলে তার মোটের দ্বিগুণ টাকা বসত।
     */
    public function test_two_drafts_cannot_both_settle_the_same_bill(): void
    {
        $bill = $this->confirmedBill('1000');

        $first = $this->payments()->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '1000'],
            [['purchase_bill_id' => $bill->id, 'amount' => '1000']],
        );

        $second = $this->payments()->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '1000'],
            [['purchase_bill_id' => $bill->id, 'amount' => '1000']],
        );

        $this->payments()->confirm($first);

        $this->expectException(ValidationException::class);

        $this->payments()->confirm($second);
    }

    /**
     * বকেয়ার চেয়ে বেশি বসানো যায় না।
     *
     * বসালে ওই বিলটা "অতিরিক্ত শোধ" দেখাত আর অন্যটা বাকি থেকে যেত, অথচ
     * মোট মিলে যেত — ভুলটা মোটে ধরা পড়ত না।
     */
    public function test_a_bill_cannot_take_more_than_it_owes(): void
    {
        $bill = $this->confirmedBill('500');

        $this->expectException(ValidationException::class);

        $this->payments()->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '900'],
            [['purchase_bill_id' => $bill->id, 'amount' => '900']],
        );
    }

    /**
     * অন্য সরবরাহকারীর বিলে টাকা বসানো যায় না।
     *
     * বসালে একজনের টাকা আরেকজনের খাতায় শোধ হিসেবে বসত, আর দুইটা হিসাবই
     * ভুল হত — অথচ মোট প্রদেয় ঠিকই থাকত।
     */
    public function test_a_payment_cannot_settle_another_suppliers_bill(): void
    {
        $bill = $this->confirmedBill('500');

        $other = Supplier::query()->where('id', '<>', $this->supplier->id)->firstOrFail();

        $this->expectException(ValidationException::class);

        $this->payments()->create(
            ['supplier_id' => $other->id, 'trx_date' => now()->toDateString(), 'amount' => '100'],
            [['purchase_bill_id' => $bill->id, 'amount' => '100']],
        );
    }

    /**
     * খসড়া বিলে টাকা বসানো যায় না।
     *
     * খসড়া বিল কোনো হিসাবে নেই — ওটার বিপরীতে শোধ দেখালে প্রদেয় ঋণাত্মক
     * হয়ে যেত।
     */
    public function test_a_draft_bill_cannot_be_paid(): void
    {
        $bill = $this->bills()->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '50']],
        );

        $this->expectException(ValidationException::class);

        $this->payments()->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '100'],
            [['purchase_bill_id' => $bill->id, 'amount' => '100']],
        );
    }

    /**
     * বাতিল করলে দাখিলা উল্টে যায়, আর বিলটা আবার বাকি হয়।
     *
     * সারিটা মোছা হয় না (নিয়ম ৫) — টাকা গিয়েছিল, তারপর ফেরত এসেছে,
     * ব্যাংকের কাগজে দুইটাই থাকবে।
     */
    public function test_cancelling_puts_the_money_and_the_due_back(): void
    {
        $bill = $this->confirmedBill('1000');

        $payment = $this->payments()->confirm(
            $this->payments()->create(
                ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '400'],
                [['purchase_bill_id' => $bill->id, 'amount' => '400']],
            )
        );

        $payableAfterPaying = $this->balanceOf(StandardChart::PAYABLE);

        $this->payments()->cancel($payment, 'চেকটা ফেরত এসেছে');

        $this->assertSame(DocumentStatus::CANCELLED, $payment->fresh()->status);

        // উল্টো দাখিলা — প্রদেয় আবার আগের জায়গায়
        $this->assertSame(
            bcsub($payableAfterPaying, '400.0000', 4),
            $this->balanceOf(StandardChart::PAYABLE),
        );

        // আর বিলটা আবার পুরোটাই বাকি
        $this->assertSame('1000.0000', $bill->fresh()->dueAmount());
    }

    /**
     * অগ্রিম — কোনো বিলের বিপরীতে নয়।
     *
     * সরবরাহকারীকে আগাম টাকা দেওয়া হলে ভাগ বসে না, কিন্তু খতিয়ানে
     * প্রদেয় ঠিকই কমে। লাইন বাধ্যতামূলক করলে অগ্রিম দেওয়ার পথটাই
     * বন্ধ হয়ে যেত।
     */
    public function test_an_advance_needs_no_bill(): void
    {
        $payableBefore = $this->balanceOf(StandardChart::PAYABLE);

        $payment = $this->payments()->confirm(
            $this->payments()->create(
                ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '250'],
                [],
            )
        );

        $this->assertCount(0, $payment->lines);

        $this->assertSame(
            bcadd($payableBefore, '250.0000', 4),
            $this->balanceOf(StandardChart::PAYABLE),
        );
    }

    /**
     * নিশ্চিত হয়ে যাওয়া পরিশোধ আর সম্পাদনা করা যায় না।
     */
    public function test_a_confirmed_payment_cannot_be_edited(): void
    {
        $bill = $this->confirmedBill('500');

        $payment = $this->payments()->confirm(
            $this->payments()->create(
                ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '100'],
                [['purchase_bill_id' => $bill->id, 'amount' => '100']],
            )
        );

        $this->expectException(ValidationException::class);

        $this->payments()->update(
            $payment,
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '200'],
            [],
        );
    }

    /**
     * পর্দাগুলো খোলে।
     */
    public function test_the_screens_open(): void
    {
        $bill = $this->confirmedBill('500');

        $payment = $this->payments()->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '100'],
            [['purchase_bill_id' => $bill->id, 'amount' => '100']],
        );

        $this->get(route('purchase.payment.index'))->assertOk()->assertSee($payment->document_no);
        $this->get(route('purchase.payment.create'))->assertOk();
        $this->get(route('purchase.payment.show', $payment))->assertOk()->assertSee($bill->document_no);
        $this->get(route('purchase.payment.edit', $payment))->assertOk();
    }

    /**
     * তালিকার তারিখের ছাঁকনি সত্যিই ছাঁকে।
     */
    public function test_the_date_filter_narrows_the_list(): void
    {
        $bill = $this->confirmedBill('500');

        $today = $this->payments()->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '100'],
            [['purchase_bill_id' => $bill->id, 'amount' => '100']],
        );

        $older = $this->payments()->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->subDays(10)->toDateString(), 'amount' => '50'],
            [],
        );

        $this->get(route('purchase.payment.index', [
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
        ]))
            ->assertOk()
            ->assertSee($today->document_no)
            ->assertDontSee($older->document_no);
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    private function bills(): PurchaseBillService
    {
        return app(PurchaseBillService::class);
    }

    /** ওই অঙ্কের একটা নিশ্চিত ক্রয় বিল। */
    /**
     * তালিকার সাব-কোয়েরি আর বিলপ্রতি যোগফল — একই উত্তর দিতে হবে।
     *
     * ── কেন এই পরীক্ষাটা আলাদা করে দরকার ────────────────────────────
     * পরিশোধের পর্দায় ২০০টা বকেয়া বিল দেখানো হয়, আর প্রতিটার পাশে
     * বাকি টাকা লেখা থাকে। আগে ওই অঙ্কটা বিলপ্রতি একটা করে কোয়েরিতে
     * আসত; এখন withPaid() একটা সাব-কোয়েরিতে সবগুলো আনে।
     *
     * দুইটা এখন আলাদা কোড, একই শর্ত হাতে নকল করা — আর ঠিক এখানেই
     * বিপদ। কেউ একটা পথের শর্ত বদলে অন্যটা ভুলে গেলে তালিকায় এক অঙ্ক
     * আর বিল খুলে আরেক অঙ্ক দেখা যেত, আর কেউ টেরও পেত না। তাই দুইটা
     * পথ একই উত্তরে পৌঁছায় কি না সেটা পরীক্ষা করা হয়, শুধু অঙ্কটা ঠিক
     * কি না তা নয়।
     *
     * খসড়া পরিশোধটাও ইচ্ছে করে রাখা: সাব-কোয়েরিতে স্ট্যাটাসের ছাঁকনি
     * বাদ পড়লে ওইটাই ধরিয়ে দেবে।
     */
    public function test_the_list_subquery_and_the_per_bill_sum_agree(): void
    {
        $bill = $this->confirmedBill('1000');

        $this->payments()->confirm(
            $this->payments()->create(
                ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '300'],
                [['purchase_bill_id' => $bill->id, 'amount' => '300']],
            )
        );

        // খসড়াটা গোনা চলবে না — টাকা এখনো যায়নি
        $this->payments()->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '250'],
            [['purchase_bill_id' => $bill->id, 'amount' => '250']],
        );

        $fromList = PurchaseBill::query()->withPaid()->whereKey($bill->id)->firstOrFail();
        $fromBill = PurchaseBill::query()->whereKey($bill->id)->firstOrFail();

        $this->assertSame('300.0000', $fromBill->paidAmount());
        $this->assertSame($fromBill->paidAmount(), $fromList->paidAmount());
        $this->assertSame($fromBill->dueAmount(), $fromList->dueAmount());
        $this->assertSame('700.0000', $fromList->dueAmount());
    }

    /**
     * তালিকা থেকে আসা বিলটা সেভ করা যায়।
     *
     * paid_total টেবিলের কোনো কলাম নয়, সাব-কোয়েরি থেকে আসা একটা ঘর।
     * Eloquent ওটাকে "বদলে গেছে" ধরলে save() ওই নামে একটা কলাম লিখতে
     * যেত আর SQL ভেঙে পড়ত — অর্থাৎ তালিকা দিয়ে আনা কোনো বিল আর
     * সম্পাদনা করা যেত না।
     */
    public function test_a_bill_from_the_list_can_still_be_saved(): void
    {
        $bill = $this->confirmedBill('1000');

        $fromList = PurchaseBill::query()->withPaid()->whereKey($bill->id)->firstOrFail();
        $fromList->narration = 'তালিকা থেকে সম্পাদনা';
        $fromList->save();

        $this->assertSame('তালিকা থেকে সম্পাদনা', $bill->fresh()->narration);
    }

    private function confirmedBill(string $total): PurchaseBill
    {
        return $this->bills()->confirm(
            $this->bills()->create(
                ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString()],
                [['product_id' => $this->product->id, 'qty' => '1', 'rate' => $total]],
            )
        );
    }

    private function balanceOf(string $code): string
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        return LedgerEntry::query()
            ->where('account_id', $account->id)
            ->get()
            ->reduce(
                fn (string $sum, LedgerEntry $e) => bcadd($sum, bcsub((string) $e->debit, (string) $e->credit, 4), 4),
                '0.0000',
            );
    }
}
