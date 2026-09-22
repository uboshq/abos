<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Http\Controllers\SalesPrintController;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বিলটা বলত না আর কত পাওনা।
 *
 * ── ⭐ মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * তিনি একটা চালু ERP-র বিল পাঠিয়ে বললেন *"এরকম করো"*। ⓘ ঐ কাগজের
 * নিচে টাকার পুরো গল্প: পরিশোধ · এই বিলের বকেয়া · আগের বকেয়া · সব
 * মিলিয়ে পাওনা।
 *
 * ⛔ ABOS-এর বিলে ছিল কেবল উপ-মোট, ছাড়, ভ্যাট আর মোট। ⚠️ গ্রাহক বিল
 * হাতে নিয়ে সবার আগে যে প্রশ্নটা করেন — *"আমার মোট কত পাওনা?"* — তার
 * উত্তর কাগজে ছিলই না, আর জানতে হলে ফোন করতে হত।
 *
 * ── ⚠️ এই ফাইলের সবচেয়ে দামি দাবি ──────────────────────────────────
 * *"সংখ্যাগুলো কাগজে আছে"* নয় — ⛔ **"আজকের বিলটা দুইবার গোনা হয়নি"**।
 * ⓘ গ্রাহকের মোট পাওনার ভিতরে এই বিলটাও থাকে, তাই সরাসরি ছাপলে
 * প্রতিটা সংখ্যা আলাদা করে ঠিক, অথচ যোগফলটা বেশি — আর ঐ ভুল কেউ
 * ধরতে পারত না, কারণ কাগজটা নিজের সাথে মিলে যেত।
 */
final class TheBillNeverSaidWhatWasStillOwedTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    /**
     * ⛔ সবচেয়ে দামি দাবি — আজকের বিলটা দুইবার গোনা হয় না।
     *
     * ⓘ "সব মিলিয়ে পাওনা" = আগের বকেয়া + এই বিলের বকেয়া। ⚠️ আর
     * "আগের বকেয়া" বের করতে গ্রাহকের মোট থেকে এই বিলটা বাদ দিতে হয়,
     * নাহলে ওটা দুই সারিতেই বসে।
     */
    public function test_todays_bill_is_not_counted_twice(): void
    {
        $invoice = $this->anInvoice();

        $rows = $this->paperRows($invoice);

        $earlier = $rows[__('sales::print.previous_due')] ?? '0';
        $due = $rows[__('sales::print.invoice_due')] ?? '0';
        $total = $rows[__('sales::print.outstanding')] ?? null;

        $this->assertNotNull($total, 'ⓘ "সব মিলিয়ে পাওনা" সারিটাই কাগজে নেই।');

        $this->assertSame(
            $this->digits($earlier) + $this->digits($due),
            $this->digits($total),
            implode("\n", [
                '⛔ "সব মিলিয়ে পাওনা" ≠ আগের বকেয়া + এই বিলের বকেয়া।',
                '',
                '⚠️ প্রায় নিশ্চিতভাবে আজকের বিলটা দুইবার গোনা হয়েছে:',
                'গ্রাহকের মোট পাওনার ভিতরে এই বিলটাও আছে।',
                '',
                'ⓘ ভুলটা নীরব — প্রতিটা সংখ্যা আলাদা করে ঠিক, কেবল',
                'যোগফলটা বেশি, আর কাগজটা নিজের সাথে মিলে যায়।',
            ]),
        );
    }

    /** ⭐ আর সংখ্যাগুলো সত্যিই কাগজে ওঠে। */
    public function test_the_paper_says_what_is_still_owed(): void
    {
        $rows = $this->paperRows($this->anInvoice());

        $this->assertArrayHasKey(__('sales::print.invoice_due'), $rows,
            '⛔ এই বিলের বকেয়াটাই কাগজে নেই — গ্রাহককে ফোন করে জানতে হবে।');

        $this->assertArrayHasKey(__('sales::print.outstanding'), $rows,
            '⛔ সব মিলিয়ে পাওনা কাগজে নেই — অথচ ওটাই প্রথম প্রশ্ন।');
    }

    /**
     * ⭐ আর পুরনো সারিগুলো হারায়নি।
     *
     * ⛔ এই দাবিটা ছাড়া কেউ একদিন `totals()`-এর বদলে পুরোটা নতুন করে
     * লিখে ফেলতে পারতেন, আর উপ-মোট বা ভ্যাট নীরবে কাগজ থেকে উধাও হত।
     */
    public function test_the_old_rows_are_still_there(): void
    {
        $rows = $this->paperRows($this->anInvoice());

        $this->assertArrayHasKey(__('core.print.subtotal'), $rows, '⛔ উপ-মোট সারিটা হারিয়েছে।');
        $this->assertArrayHasKey(__('core.print.total'), $rows, '⛔ মোট সারিটা হারিয়েছে।');
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ⓘ উপরের দাবিগুলো সবুজ থাকত যদি কাগজটা **একটা সারিও** না আঁকত —
     * তখন সব চাবি অনুপস্থিত, আর `assertArrayHasKey` লাল হত। ⚠️ কিন্তু
     * যোগফলের দাবিটা `0 + 0 === 0` বলে সবুজ থেকে যেত।
     */
    public function test_the_paper_really_has_rows(): void
    {
        $this->assertGreaterThan(2, count($this->paperRows($this->anInvoice())),
            'কাগজে প্রায় কোনো সারিই নেই — তাহলে যোগফলের দাবিটা কিছুই মাপছে না।');
    }

    /** ⓘ পয়সা-সহ অঙ্কটা পূর্ণসংখ্যায় — বাংলা অঙ্ক ও কমা বাদ দিয়ে। */
    private function digits(string $money): int
    {
        $ascii = strtr($money, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
            '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);

        return (int) round((float) str_replace(',', '', preg_replace('/[^0-9.,]/', '', $ascii) ?? '0') * 100);
    }

    /** @return array<string, string> */
    private function paperRows(SalesInvoice $invoice): array
    {
        $controller = app(SalesPrintController::class);

        $method = (new \ReflectionClass($controller))->getMethod('invoiceTotals');
        $method->setAccessible(true);

        /*
         * ⓘ চাবিগুলো অনুবাদ করা হয়, কারণ কাগজে মানুষ ওগুলোই পড়েন —
         * আর দাবিগুলোও তখন পর্দার ভাষায় কথা বলে।
         *
         * @var array<string, string> $rows
         */
        $rows = $method->invoke($controller, $invoice);

        $out = [];

        foreach ($rows as $key => $value) {
            $out[__($key)] = $value;
        }

        return $out;
    }

    /**
     * ⭐ বিলটা পরীক্ষা নিজে বানায়, সিডারে খুঁজে পাওয়ার আশায় থাকে না।
     *
     * ── ⛔ প্রথম চালে খোঁজা হয়েছিল, আর চারটাই লাল ─────────────────
     * *"No query results for model SalesInvoice"* — ⓘ [[DemoSeeder]]-এ
     * একটাও বিল নেই।
     *
     * ⚠️ ধরা পড়ল বলেই ভালো। যে পরীক্ষা ডেটা **খুঁজে পাওয়ার** উপর
     * দাঁড়ায়, সে একদিন সারিটা বদলে গেলে নীরবে অন্য কিছু মাপে — ⛔ আর
     * আজ রাতেই ঠিক ওটা ঘটেছে [[TheRolePageShowsEveryPermissionTest]]-এ:
     * দাবিটা মাসের পর মাস সবুজ ছিল ভুল জিনিস মেপে, আর লাল হলো কোড
     * বদলানোয় নয়, **ডেটা বদলানোয়**।
     *
     * ⓘ নিজে বানালে দৃশ্যটা প্রতিবার এক, আর দাবিটা যা বলে তাই মাপে।
     */
    private function anInvoice(): SalesInvoice
    {
        $here = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        $invoice = SalesInvoice::query()->create([
            'branch_id' => $here->defaultBranch()?->id,
            'document_no' => 'INV-TEST-0001',
            'customer_id' => Customer::query()->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'due_on' => now()->addDays(30)->toDateString(),
            'subtotal' => '1000.0000',
            'discount' => '0.0000',
            'tax' => '0.0000',
            'total' => '1000.0000',
            'status' => DocumentStatus::CONFIRMED,
        ]);

        return $invoice->load(['lines.challanLine', 'customer']);
    }
}
