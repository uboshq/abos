<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintableDocument;
use App\Core\Engines\Print\PrintProfile;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Sales\Support\InvoiceDesigns;
use App\Modules\Sales\Support\InvoicePaperView;
use App\Modules\Sales\Support\PaperDesigns;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Special for DB" — বিল + হিসাবের বিবরণী, মালিক, ৩ অক্টোবর ২০২৬ (`invoice-special_db`)।
 *
 * ⓘ দুই ভাগ: নমুনা কাগজে নকশাটা পুরো আঁকে — শিরোনাম, ড্রাইভারের নাম, টার্গেট রিমাইন্ডার, শেষ লাইন "Due"; আর
 * শেষ লাইনের ভাষা অঙ্কের চিহ্ন মেনে চলে — বাকি হলে Due, অগ্রিম হলে Advance, শূন্যে No Due, বেশি দিলে "Extra Paid"
 * ([[InvoicePaperView::balanceWord()]], [[billLeftWord()]])।
 */
final class TheSpecialForDbBillSpeaksTheDealersWordsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_the_design_is_listed_and_draws_the_whole_page(): void
    {
        $this->assertArrayHasKey('special_db', InvoiceDesigns::ALL, '⛔ নকশাটা তালিকায় নেই — বাছাই করা যেত না।');
        $this->assertContains('special_db', PaperDesigns::codes('invoice', 'a5'), '⛔ A5-এর তালিকায় নেই — মালিক A5-ও চেয়েছেন (৩ অক্টোবর ২০২৬)।');

        $paper = $this->get(route('sales.invoice_sample', ['design' => 'special_db']))->assertOk()->getContent();

        foreach (['INVOICE', 'With Accounts Statement', 'Karim', 'TARGET REMINDER', 'INVOICE SUMMARY', 'data-balance-word',
            // ⓘ নকশার নাম এখন ভাষা-ফাইল থেকে, মালিকের ভাষায় (পুনঃঅডিট ৯ অক্টোবর, ছাপা ১৯)
            trans('sales::settings.design.special_db', [], auth()->user()->locale ?? config('app.locale'))] as $must) {
            $this->assertStringContainsString($must, (string) $paper, "⛔ নকশায় «{$must}» নেই।");
        }

        $this->assertMatchesRegularExpression('/data-balance-word>\s*Due\s*</', (string) $paper, '⛔ নমুনার বকেয়া ধনাত্মক — শেষ লাইন "Due" হওয়ার কথা।');
        $this->assertStringNotContainsString('Outstanding', (string) $paper, '⛔ মালিক "Outstanding" চাননি — Due / Advance।');
    }

    public function test_the_last_line_follows_the_sign(): void
    {
        $this->assertSame(['Due', '12,500.00'], $this->bottom(['outstanding' => '12,500.00']));
        $this->assertSame(['Advance', '8,789.00'], $this->bottom(['outstanding' => '-8,789.00']), '⛔ অগ্রিমে "Advance", চিহ্ন ছাড়া অঙ্ক।');
        $this->assertSame(['No Due', '0.00'], $this->bottom(['outstanding' => '0.00']));
    }

    public function test_paying_more_than_the_bill_says_extra_paid(): void
    {
        $v = $this->paperView(['net_payable' => '14,500.00', 'paid' => '15,000.00', 'invoice_due' => '0.00']);
        $this->assertSame('Extra Paid', $v->billLeftWord());
        $this->assertSame('500.00', $v->billLeftAmount());

        $v = $this->paperView(['net_payable' => '14,500.00', 'paid' => '5,000.00', 'invoice_due' => '9,500.00']);
        $this->assertSame('Invoice Due', $v->billLeftWord());
        $this->assertSame('9,500.00', $v->billLeftAmount());
    }

    /**
     * ⭐ মালিকের ছবি, ৩ অক্টোবর ২০২৬ (সরকার এন্টারপ্রাইজ, S-0001): বিল ৩৯,১০৬.১২, দিলেন ৪০,০০০, আগের বকেয়া ৩০,৬৪২.১৫।
     * ⛔ "Previous Due" ঘরে বসেছিল ২৯,৭৪৮.২৭ — বাড়তি ৮৯৩.৮৮ কাটার পরের অঙ্ক, শেষ লাইনের সমান; যোগটা মিলত না।
     */
    public function test_the_previous_due_is_the_balance_before_this_bill(): void
    {
        // ⓘ ৬ অক্টোবর ২০২৬ থেকে `previous_due` খাতায় এই বিল বসার আগের জের ([[SalesPrintController::balanceBeforeBill()]]) — আসল ৩০,৬৪২.১৫
        $v = $this->paperView([
            'net_payable' => '39,106.12', 'paid' => '40,000.00', 'invoice_due' => '0.00',
            'previous_due' => '30,642.15', 'outstanding' => '29,748.27',
        ]);

        $this->assertSame('30,642.15', $v->previousBeforeBill(), '⛔ আগের বকেয়া কাটার পরের অঙ্কে বসেছে।');
        $this->assertSame(['Extra Paid', '893.88'], [$v->billLeftWord(), $v->billLeftAmount()]);
        $this->assertSame(['Due', '29,748.27'], [$v->balanceWord(), $v->balanceAmount()]);

        // ⓘ বিলের চেয়ে কম দিলে আগের কথাই — বাকি = প্রদেয় − পরিশোধ
        $v = $this->paperView([
            'net_payable' => '14,500.00', 'paid' => '5,000.00', 'invoice_due' => '9,500.00',
            'previous_due' => '3,000.00', 'outstanding' => '12,500.00',
        ]);
        $this->assertSame('3,000.00', $v->previousBeforeBill());

        /*
         * ⛔ ফেরতও — অডিট, ৬ অক্টোবর ২০২৬ (সমন্বয়ক): এই বিলের ২,০০০ টাকার মাল ফেরত এলে শেষ জের ১০,৫০০; আগে "আগের জের"
         * ছাপত শেষ জের − প্রদেয় + পরিশোধ = ১,০০০, যেন ফেরতটা আগের কোনো বিলের। আগের জের ৩,০০০-ই থাকে।
         */
        $v = $this->paperView([
            'net_payable' => '14,500.00', 'paid' => '5,000.00', 'invoice_due' => '7,500.00',
            'previous_due' => '3,000.00', 'outstanding' => '10,500.00',
        ]);
        $this->assertSame('3,000.00', $v->previousBeforeBill(), '⛔ এই বিলের ফেরত "আগের জের" কমিয়ে দিল।');
    }

    public function test_a_name_that_already_says_ms_is_not_prefixed_again(): void
    {
        $paper = (string) $this->get(route('sales.invoice_sample', ['design' => 'special_db']))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('~M/S\s+M/S~i', $paper);

        $once = view('sales::print.partials.invoice-bill-to', [
            'v' => $this->paperView([]),
            'facts' => ['bill_to' => ['name' => 'M/S. SARKAR ENTERPRISE', 'code' => '', 'point' => '', 'address' => '', 'phone' => '']],
        ])->render();
        $this->assertSame(1, preg_match_all('~M/S~', $once), "⛔ «M/S» দুবার: {$once}");

        $prefixed = view('sales::print.partials.invoice-bill-to', [
            'v' => $this->paperView([]),
            'facts' => ['bill_to' => ['name' => 'SARKAR ENTERPRISE', 'code' => '', 'point' => '', 'address' => '', 'phone' => '']],
        ])->render();
        $this->assertStringContainsString('M/S SARKAR', $prefixed, '⛔ নামে M/S না থাকলে কাগজ নিজে বসায় — সেটা হারিয়েছে।');
    }

    /**
     * ⭐ মালিকের ছবি, ৩ অক্টোবর ২০২৬: নাম ২–৪ লাইনে, অথচ QTY আর Total QTY কলামে ফাঁকা — Grand Total-এর "168 Ctn, 7 Mbag"
     * এক লাইনে কলামটা চওড়া করত। এখন প্রতিটা একক নিজের লাইনে।
     */
    public function test_the_grand_total_stacks_its_units_so_the_column_stays_narrow(): void
    {
        $html = view('sales::print.partials.invoice-items', [
            'v' => $this->paperView([]),
            'facts' => ['items' => ['rows' => [], 'totals' => ['qty' => '168 Ctn, 7 Mbag', 'free' => '2 Ctn', 'total_qty' => '170 Ctn, 7 Mbag', 'amount' => '39,106.12']]],
            'paper' => PaperSize::of('a4'),
        ])->render();

        $this->assertStringContainsString('168 Ctn<br>7 Mbag', $html);
        $this->assertStringContainsString('170 Ctn<br>7 Mbag', $html);
        $this->assertStringNotContainsString('168 Ctn, 7 Mbag', $html, '⛔ মোট পরিমাণ আবার এক লাইনে — কলাম চওড়া হয়ে নাম ভাঙবে।');
    }

    public function test_no_target_means_no_box(): void
    {
        $this->assertNull($this->paperView([], target: null)->target(), '⛔ লক্ষ্য নেই, অথচ বাক্সের অঙ্ক আছে।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string} */
    private function bottom(array $sums): array
    {
        $v = $this->paperView($sums);

        return [$v->balanceWord(), $v->balanceAmount()];
    }

    private function paperView(array $sums, ?array $target = null): InvoicePaperView
    {
        $facts = [
            'bill_to' => ['name' => 'X', 'point' => '', 'phone' => '', 'address' => ''],
            'transport' => ['carrier' => '', 'driver_name' => '', 'driver_phone' => '', 'vehicle' => '', 'delivery_date' => ''],
            'bill' => ['bill_date' => '', 'bill_no' => 'S-1', 'order_no' => '', 'type' => '', 'created_by' => ''],
            'items' => ['rows' => [], 'totals' => ['qty' => '', 'free' => '', 'total_qty' => '', 'amount' => '0.00']],
            'sums' => [
                'grand_total' => '0.00', 'discount' => '0.00', 'vat' => '0.00', 'rounding' => '0.00', 'net_payable' => '0.00',
                'paid' => '0.00', 'invoice_due' => '0.00', 'previous_due' => '0.00', 'outstanding' => '0.00', ...$sums,
            ],
            'target' => $target,
            'words' => '',
            'scan_url' => '',
        ];

        return new InvoicePaperView(new PrintableDocument(title: 'Invoice'), $facts, $this->company, PrintProfile::everything());
    }
}
