<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Http\Controllers\SalesPrintController;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\CollectionLine;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * বিলটা বলত কত পরিশোধ হয়েছে, কিন্তু **কোন জমাগুলো** তা নয়।
 *
 * ── ⓘ মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * বিলের বাঁ-নিচে একটা ছোট ছক: ক্রম · লেনদেন নম্বর · তারিখ · কোন পথে ·
 * বিবরণ · টাকা। ⚠️ উদ্দেশ্য একটাই — গ্রাহক যেন ফোন করে জিজ্ঞেস না করেন
 * *"আমার ঐ জমাটা বসেছে কি না"*।
 *
 * ── ⛔ এই ফাইলের আসল পাহারা ──────────────────────────────────────────
 * *"ছকে সারি আছে"* দাবিটা সহজ। ⚠️ আসল ঝুঁকি একটাই, আর সেটা নীরব:
 *
 *   **ছকের যোগফল আর উপরের "পরিশোধ" লাইনটা এক থাকতে হবে।**
 *
 * ⛔ গ্রাহকের **সব** জমা ছকে তুললে দুইটা সংখ্যা দুই কথা বলত, আর পাঠক
 * ভাবতেন কোথাও টাকা দুইবার গোনা হয়েছে। ⓘ ঠিক এই পরিবারের ভুল এই
 * কাগজেই আগে একবার হয়েছে — *"আগের বকেয়া"*-র ভিতরে এই বিলটাও ধরা পড়ত,
 * আর প্রতিটা সংখ্যা আলাদা করে ঠিক থেকেও যোগফলটা বেশি দেখাত।
 */
final class TheBillDidNotSayWhichDepositsItCountedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    // ── আসল দাবি ──────────────────────────────────────────────────────

    /**
     * ⛔ ছকের যোগফল = উপরের "পরিশোধ" লাইন।
     *
     * ⚠️ দুইটা আলাদা হলে কাগজটা নিজের সাথে ঝগড়া করত, আর গ্রাহক ধরেই
     * নিতেন কোথাও গোলমাল আছে — এমনকি যদি টাকাটা ঠিকও থাকে।
     */
    public function test_the_table_adds_up_to_the_paid_line(): void
    {
        $invoice = $this->anInvoice();

        $this->aCollectionOf($invoice, '300.0000', 'COL-A');
        $this->aCollectionOf($invoice, '250.0000', 'COL-B');

        $rows = $this->paymentsOf($invoice);

        $this->assertCount(2, $rows, 'দুইটা জমা বসানো হলো, ছকে সব আসেনি।');

        $sum = array_reduce(
            $rows,
            fn (string $carry, array $row) => bcadd($carry, $this->plain($row['amount']), 4),
            '0'
        );

        $this->assertSame(
            0,
            bccomp($sum, $invoice->fresh()->collectedAmount(), 4),
            'ছকের যোগফল আর বিলের "পরিশোধ" আলাদা — কাগজটা নিজের সাথেই মিলছে না।'
        );
    }

    /**
     * ⛔ অন্য বিলের জমা এই বিলের ছকে আসে না।
     *
     * ⚠️ এটাই সেই ফাঁদ: গ্রাহকের সব জমা তুলে আনলে ছকটা ভরে যেত, আর
     * যোগফল উপরের লাইনটার চেয়ে বেশি দেখাত।
     */
    public function test_a_deposit_against_another_bill_stays_out(): void
    {
        $mine = $this->anInvoice('INV-MINE-0001');
        $other = $this->anInvoice('INV-OTHER-0001');

        $this->aCollectionOf($mine, '100.0000', 'COL-MINE');
        $this->aCollectionOf($other, '900.0000', 'COL-OTHER');

        $refs = array_column($this->paymentsOf($mine), 'ref');

        $this->assertSame(['COL-MINE'], $refs,
            'অন্য বিলের জমাও এই বিলের ছকে উঠেছে — যোগফল তখন উপরের লাইনটার সাথে মিলত না।');
    }

    /**
     * ⛔ খসড়া আদায় টাকা নয়।
     *
     * ⓘ [[SalesInvoice::collectedAmount()]] কেবল খাতায় বসা আদায় গোনে,
     * আর ছকটাকেও হুবহু সেই শর্তই মানতে হয়। ⚠️ নাহলে কাগজে এমন একটা
     * জমা দেখা যেত যেটা খাতায় নেই — আর গ্রাহক ওটা প্রমাণ হিসেবে ধরতেন।
     */
    public function test_a_draft_deposit_is_not_on_the_paper(): void
    {
        $invoice = $this->anInvoice();

        $this->aCollectionOf($invoice, '500.0000', 'COL-DRAFT', DocumentStatus::DRAFT);

        $this->assertSame([], $this->paymentsOf($invoice),
            'খসড়া আদায় কাগজে উঠেছে — টাকা হাতে আসার আগেই প্রমাণ ছাপা হয়ে গেল।');
    }

    /** ⓘ কোনো জমা না থাকলে ছকটা আঁকাই হয় না — কাগজ আগের মতোই। */
    public function test_a_bill_with_no_deposits_has_no_table(): void
    {
        $this->assertSame([], $this->paymentsOf($this->anInvoice()));
    }

    /** ⓘ সারিগুলো তারিখের ক্রমে — গ্রাহক কাগজ উপর থেকে নিচে পড়েন। */
    public function test_the_rows_run_oldest_first(): void
    {
        $invoice = $this->anInvoice();

        $this->aCollectionOf($invoice, '100.0000', 'COL-NEW', on: now()->toDateString());
        $this->aCollectionOf($invoice, '100.0000', 'COL-OLD', on: now()->subWeek()->toDateString());

        $this->assertSame(['COL-OLD', 'COL-NEW'], array_column($this->paymentsOf($invoice), 'ref'));

        // ⓘ ক্রমের সংখ্যাটাও সাজানো ক্রমেই বসে, তোলার ক্রমে নয়।
        $this->assertSame([1, 2], array_column($this->paymentsOf($invoice), 'no'));
    }

    // ── হাতিয়ার ────────────────────────────────────────────────────────

    /**
     * ⓘ ডেমোতে একটাও `SalesInvoice` নেই, তাই বিলটা নিজে বানানো।
     */
    private function anInvoice(string $no = 'INV-PAY-0001'): SalesInvoice
    {
        $here = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        return SalesInvoice::query()->create([
            'branch_id' => $here->defaultBranch()?->id,
            'document_no' => $no,
            'customer_id' => Customer::query()->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'due_on' => now()->addDays(30)->toDateString(),
            'subtotal' => '1000.0000',
            'discount' => '0.0000',
            'tax' => '0.0000',
            'total' => '1000.0000',
            'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    private function aCollectionOf(
        SalesInvoice $invoice,
        string $amount,
        string $no,
        /*
         * ⚠️ [[DocumentStatus]] enum নয় — `POSTED` একটা **তালিকা**
         * (`confirmed` + `closed`)। ⓘ তাই ঘরটা `string`, আর ডিফল্ট
         * `CONFIRMED` — খাতায় বসা অবস্থার সবচেয়ে সাধারণ রূপ।
         */
        string $status = DocumentStatus::CONFIRMED,
        ?string $on = null,
    ): void {
        $collection = Collection::query()->create([
            'branch_id' => $invoice->branch_id,
            'document_no' => $no,
            'customer_id' => $invoice->customer_id,

            /*
             * ⓘ টাকাটা কোন খাতে এল — ঘরটা বাধ্যতামূলক, আর ছকের
             * "কোন পথে" কলামটা ঠিক এই খাতার নামই দেখায়।
             */
            'account_id' => $this->aTill()->id,

            'trx_date' => $on ?? now()->toDateString(),
            'amount' => $amount,
            'status' => $status,
            'narration' => 'হাতে নগদ',
        ]);

        CollectionLine::query()->create([
            'collection_id' => $collection->id,

            // ⓘ ঘরটা বাধ্যতামূলক — একটা আদায়ে কয়টা বিল কাটা হলো, তার ক্রম।
            'line_no' => 1,

            'sales_invoice_id' => $invoice->id,
            'amount' => $amount,
        ]);
    }

    /**
     * ⓘ একটা নগদ খাত — ছকের "কোন পথে" কলামটা এর নামই দেখায়।
     *
     * ⚠️ `postable()` ছাড়া দল-খাতও উঠে আসত, আর দলে টাকা বসলে সেটা
     * কোনো রিপোর্টে আসে না।
     */
    private function aTill(): Account
    {
        $account = Account::query()->postable()->first();

        $this->assertNotNull($account, 'ডেমোতে একটাও পোস্টযোগ্য খাত নেই।');

        return $account;
    }

    /**
     * ⚠️ `private` মেথডটা reflection দিয়ে — পাবলিক করার চেয়ে ভালো।
     *
     * ⓘ পাবলিক করলে ওটা একটা চুক্তি হয়ে যেত, আর কাল কেউ অন্য কোথাও
     * থেকে ডাকত; তখন *"ছকটা কেবল এই কাগজের"* নিয়মটা দ্বিতীয় জায়গায়
     * ভাঙত।
     *
     * @return list<array<string, mixed>>
     */
    private function paymentsOf(SalesInvoice $invoice): array
    {
        $controller = app(SalesPrintController::class);

        $method = (new ReflectionClass($controller))->getMethod('paymentsAgainst');
        $method->setAccessible(true);

        return $method->invoke($controller, $invoice->fresh());
    }

    /** ⓘ ছাপার জন্য সাজানো অঙ্ক থেকে কমা তুলে নেওয়া। */
    private function plain(string $money): string
    {
        return str_replace(',', '', $money);
    }
}
