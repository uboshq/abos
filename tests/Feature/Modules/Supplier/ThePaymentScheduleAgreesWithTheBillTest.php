<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PaymentLine;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Reports\PartyReports;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সময়সূচির সংখ্যা আর বিলের সংখ্যা এক কথা বলে।
 *
 * ── কেন এই পাহারাটা লাগল ────────────────────────────────────────────
 * "কত বাকি" -র আসল সংজ্ঞা [[PurchaseBill::dueAmount]] — **মোট বাদ শোধ,
 * ঋণাত্মক হলে শূন্য**। অতিরিক্ত শোধ বিলের বাকি নয়, সরবরাহকারীর অগ্রিম।
 *
 * ⚠️ কিন্তু রিপোর্ট-ইঞ্জিন কলামে কেবল **চাবি** পড়ে, কোনো মেথড ডাকে না —
 * তাই [[PartyReports::paymentSchedule]] সংখ্যাটা SQL-এ গুনতে বাধ্য।
 *
 * ⭐ **অর্থাৎ সংজ্ঞাটা দুই জায়গায় আছে, আর সেটা এড়ানো যায়নি।** তখন
 * একটাই উপায়: **দুইটা মিলিয়ে দেখা**, প্রতিটা বিলে। আলাদা হলে লাল।
 *
 * ⓘ নাহলে একদিন কেউ মডেলের নিয়ম বদলাত (যেমন ফেরত বাদ দেওয়া) আর
 * রিপোর্ট পুরনো সংখ্যাই দেখাত — **দুইটা পর্দা, দুইটা সত্য**, আর কোনটা
 * ঠিক তা বলার উপায় থাকত না।
 */
final class ThePaymentScheduleAgreesWithTheBillTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->supplier = Supplier::query()->firstOrFail();
    }

    /**
     * প্রতিটা বিলে SQL-এর সংখ্যা আর মডেলের সংখ্যা এক।
     */
    public function test_the_report_and_the_model_agree_on_every_bill(): void
    {
        $full = $this->bill('T-FULL', '3000', now()->addDay());
        $part = $this->bill('T-PART', '10000', now()->addDays(3));
        $none = $this->bill('T-NONE', '2000', null);

        $this->payFor($part, '4000');
        $this->payFor($full, '3000');

        $rows = collect($this->schedule())->keyBy('document_no');

        foreach ([$part, $none] as $bill) {
            $this->assertArrayHasKey(
                $bill->document_no,
                $rows->all(),
                "{$bill->document_no} বকেয়া আছে, অথচ সময়সূচিতে নেই।",
            );

            $this->assertSame(
                $bill->fresh()->dueAmount(),
                (string) $rows[$bill->document_no]->due_amount,
                "{$bill->document_no}: রিপোর্ট আর বিল দুই সংখ্যা বলছে।",
            );
        }

        $this->assertArrayNotHasKey(
            $full->document_no,
            $rows->all(),
            'পুরো শোধ করা বিলটা সময়সূচিতে রয়ে গেছে।',
        );
    }

    /**
     * ⚠️ তারিখহীন বিল লুকানো হয় না — কিন্তু সবার শেষে।
     *
     * ⓘ নীরবে বাদ দিলে যোগফল প্রদেয়ের তালিকার সাথে মিলত না, আর কেউ
     * বুঝত না কেন। ⭐ আর উপরে রাখলে রোজকার প্রশ্নটা ("এই সপ্তাহে কার
     * টাকা") চাপা পড়ত।
     */
    public function test_a_bill_with_no_due_date_is_last_but_not_lost(): void
    {
        $this->bill('T-SOON', '1000', now()->addDay());
        $this->bill('T-LATER', '1000', now()->addDays(30));
        $this->bill('T-NODATE', '1000', null);

        $order = array_column($this->schedule(), 'document_no');

        $this->assertContains('T-NODATE', $order, 'তারিখহীন বিলটা তালিকা থেকে হারিয়ে গেছে।');
        $this->assertSame('T-NODATE', end($order), 'তারিখহীন বিলটা শেষে থাকার কথা।');
        $this->assertSame(['T-SOON', 'T-LATER'], array_slice($order, 0, 2), 'তারিখ ধরে সাজানো হয়নি।');
    }

    /**
     * ⚠️ বাতিল পরিশোধ বকেয়া কমায় না।
     *
     * ⓘ `Payment::posted()` কেবল পোস্ট হওয়া অবস্থাগুলো গোনে, আর
     * রিপোর্টের সাব-কোয়েরিও হুবহু সেই নিয়ম ধরে — এই পরীক্ষাটা সেটাই
     * পাহারা দেয়।
     */
    public function test_a_cancelled_payment_does_not_reduce_the_due(): void
    {
        $bill = $this->bill('T-CANCEL', '5000', now()->addDays(2));

        $payment = $this->payFor($bill, '5000');
        $payment->update(['status' => DocumentStatus::CANCELLED]);

        $rows = collect($this->schedule())->keyBy('document_no');

        $this->assertArrayHasKey('T-CANCEL', $rows->all(), 'বাতিল পরিশোধেও বিলটা তালিকা থেকে গেছে।');
        $this->assertSame('5000.0000', (string) $rows['T-CANCEL']->due_amount);
    }

    // ── সহায়ক ────────────────────────────────────────────────────────

    /** @return list<object> */
    private function schedule(): array
    {
        $definition = PartyReports::paymentSchedule();

        return ($definition->query)([
            'company_id' => CompanyContext::id(),
            'branch_id' => null,
            'party_type_id' => null,
            'from' => now()->subYear()->toDateString(),
            'to' => now()->addYears(2)->toDateString(),
        ])->get()->all();
    }

    private function bill(string $no, string $total, mixed $dueOn): PurchaseBill
    {
        return PurchaseBill::query()->create([
            'branch_id' => Branch::query()->firstOrFail()->id,
            'financial_year_id' => FinancialYear::query()->firstOrFail()->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
            'document_no' => $no,
            'trx_date' => now()->subDays(5)->toDateString(),
            'due_on' => $dueOn?->toDateString(),
            'subtotal' => $total,
            'discount' => '0',
            'tax' => '0',
            'total' => $total,
            'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    private function payFor(PurchaseBill $bill, string $amount): Payment
    {
        $payment = Payment::query()->create([
            'branch_id' => $bill->branch_id,
            'financial_year_id' => $bill->financial_year_id,
            'supplier_id' => $this->supplier->id,
            'account_id' => \App\Modules\Accounts\Services\StandardChart::find('1101')->id,
            'document_no' => 'PAY-'.$bill->document_no,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
            'instrument' => 'cash',
            'status' => DocumentStatus::CONFIRMED,
        ]);

        PaymentLine::query()->create([
            'payment_id' => $payment->id,
            'purchase_bill_id' => $bill->id,
            'amount' => $amount,
            'line_no' => 1,
        ]);

        return $payment;
    }
}
