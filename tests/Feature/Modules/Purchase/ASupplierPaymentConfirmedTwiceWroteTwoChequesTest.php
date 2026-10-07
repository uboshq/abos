<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\SignsMoneyOff;
use Tests\TestCase;

/**
 * সরবরাহকারীর পরিশোধ দুইবার নিশ্চিত হলো — দুইটা চেক, দুইবার দায় — ২৯ সেপ্টেম্বর ২০২৬।
 *
 * ── কী ঘটত ───────────────────────────────────────────────────────────
 * `confirm()` খসড়া-কি-না আর বিলে-আঁটে-কি-না দেখত **লেনদেনের বাইরে**,
 * হাতে ধরা মডেল থেকে, কোনো তালা ছাড়া। দুই ট্যাবে একই পরিশোধ খোলা
 * (বা দুইবার চাপা বোতাম) — দুইজনের হাতেই মডেলটা "খসড়া"।
 *
 * ⛔ চেকের পথে দ্বিতীয় ডাক আরেকটা চেক লিখত: নতুন চেকের নিজের দাখিলা
 * (আলাদা উৎস, তাই খাতার এক-দাখিলা-এক-কাগজ পাহারা চুপ), প্রদেয় দুইবার
 * ডেবিট। নগদের পথে দ্বিতীয় ডাক খাতার unique-এ ধাক্কা খেয়ে ৫০০ দিত।
 *
 * ⓘ একই বিলে দুইটা আলাদা খসড়া, **পরপর** নিশ্চিত — এটা আগেই আটকাত
 * (বাকিটা প্রতিবার খাতা থেকে নতুন করে গোনা হয়)। খোলা ছিল কেবল একই
 * মুহূর্তের দৌড়, আর সেটা বন্ধ হয় পরিশোধ ও বিলের সারিতে তালা দিয়ে।
 */
final class ASupplierPaymentConfirmedTwiceWroteTwoChequesTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;
    use SignsMoneyOff;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($user);

        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();

        $this->putMoneyIn($this->till(), '100000');
    }

    /**
     * দুইটা খসড়া, ৭০০ + ৭০০, ১,০০০-এর বিলে — পরপর নিশ্চিত করলে দ্বিতীয়টা থামে।
     */
    public function test_two_drafts_confirmed_one_after_the_other_cannot_overpay_the_bill(): void
    {
        $bill = $this->confirmedBill('1000');

        $first = $this->draft($bill, '700');
        $second = $this->draft($bill, '700');

        $this->confirmPayment($first);

        try {
            $this->payments()->confirm($second);
            $this->fail('দ্বিতীয় ৭০০ বসে গেল — ১,০০০-এর বিলে ১,৪০০ শোধ।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors(), 'থামল, কিন্তু "বাকির চেয়ে বেশি" বলে নয়।');
        }

        $this->assertSame(DocumentStatus::DRAFT, $second->fresh()->status);
        $this->assertSame('300.0000', $bill->fresh()->dueAmount());
    }

    /**
     * চেকে দেওয়া পরিশোধ, হাতে ধরা পুরনো মডেলে দ্বিতীয়বার নিশ্চিত — একটাই চেক।
     */
    public function test_a_cheque_payment_confirmed_twice_writes_one_cheque(): void
    {
        $bill = $this->confirmedBill('1000');

        $draft = $this->draft($bill, '400', ['instrument' => 'cheque', 'instrument_no' => 'CHQ-7701']);

        // দ্বিতীয় ট্যাব — একই সারি, আলাদা মডেল, তখনো "খসড়া"
        $stale = Payment::query()->findOrFail($draft->id);

        $this->confirmPayment($draft);

        try {
            $this->payments()->confirm($stale);
            $this->fail('নিশ্চিত পরিশোধ আবার নিশ্চিত হলো — দ্বিতীয় চেক লেখা হয়েছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors(), 'থামল, কিন্তু "আর খসড়া নয়" বলে নয়।');
        }

        $this->assertSame(
            1,
            Cheque::query()->where('party_type', 'supplier')->where('cheque_no', 'CHQ-7701')->count(),
            'একই পরিশোধে দুইটা চেক — প্রদেয় দুইবার ডেবিট।',
        );

        $this->assertSame('600.0000', $bill->fresh()->dueAmount());
    }

    /**
     * নগদে দেওয়া পরিশোধ, পুরনো মডেলে দ্বিতীয়বার — বোধগম্য না, একবারই খাতায়।
     */
    public function test_a_cash_payment_confirmed_twice_is_refused_and_posts_once(): void
    {
        $bill = $this->confirmedBill('1000');

        $draft = $this->draft($bill, '400');
        $stale = Payment::query()->findOrFail($draft->id);

        $this->confirmPayment($draft);

        try {
            $this->payments()->confirm($stale);
            $this->fail('নিশ্চিত পরিশোধ আবার নিশ্চিত হলো।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors(), 'থামল, কিন্তু "আর খসড়া নয়" বলে নয়।');
        } finally {
            $this->assertSame(
                2,
                LedgerEntry::query()
                    ->where('source_type', Payment::drillSourceType())
                    ->where('source_id', $draft->id)
                    ->count(),
                'পরিশোধ খাতায় একবারের বেশি বসেছে।',
            );
        }
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $extra */
    private function draft(PurchaseBill $bill, string $amount, array $extra = []): Payment
    {
        return $this->payments()->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'amount' => $amount] + $extra,
            [['purchase_bill_id' => $bill->id, 'amount' => $amount]],
        );
    }

    private function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    private function confirmedBill(string $total): PurchaseBill
    {
        $bills = app(PurchaseBillService::class);

        $bill = $bills->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '1', 'rate' => $total]],
        );

        try {
            return $bills->confirm($bill);
        } catch (HeldForApproval) {
            $this->signOffAsSecondPerson($bill, 'bill');
        }

        $bill = $bill->fresh();

        return $bill->status === DocumentStatus::CONFIRMED ? $bill : $bills->confirm($bill);
    }

    /**
     * ⓘ সইয়ের ছক বসানো থাকলে আসল পথে দ্বিতীয় মানুষের সই, তারপর আবার
     * নিশ্চিত — ছক না থাকলে প্রথম ডাকেই হয়। নিয়ম বন্ধ করা হয় না।
     */
    private function confirmPayment(Payment $payment): Payment
    {
        try {
            return $this->payments()->confirm($payment);
        } catch (HeldForApproval) {
            $this->signOffAsSecondPerson($payment, 'payment');
        }

        return $payment->fresh()->status === DocumentStatus::CONFIRMED
            ? $payment->fresh()
            : $this->payments()->confirm($payment);
    }

    private function till(): Account
    {
        return app(CashTillService::class)->ensurePrimaryTill()->account;
    }
}
