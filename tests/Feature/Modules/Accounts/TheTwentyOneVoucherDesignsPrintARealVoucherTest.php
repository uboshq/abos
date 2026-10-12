<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Print\PaperSize;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Support\VoucherDesigns;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * মালিকের ২১ ভাউচার-নকশা কন্ট্রোল প্যানেলে — ১২ অক্টোবর ২০২৬: *"ei gulo control panel e bosaw sobgulo"*।
 *
 * দাবি:
 *   ① আসল ভাউচারের বাড়তি কথা কাগজে পৌঁছায় — পক্ষ, প্রাপক, মাধ্যম ও নম্বর, কোন কাগজের বিপরীতে, কে বানালেন ও কখন।
 *   ② প্রতিটা নতুন নকশা বাছলে আসল ভাউচারই সেই নকশায় ছাপা হয় (PDF) — নিজের মাপে।
 *   ③ পুরনো ২০টা আর ডিফল্ট অক্ষত।
 */
final class TheTwentyOneVoucherDesignsPrintARealVoucherTest extends TestCase
{
    use RefreshDatabase;

    /** নকশা → মাপ (ফাইল যে মাপের) */
    private const NEW = [
        'money_receipt' => 'a5', 'tally_box' => 'a5', 'debit_classic' => 'a5', 'credit_bank' => 'a5', 'contra_flow' => 'a5',
        'petty_cash' => 'a5', 'bilingual' => 'a5',
        'journal_grid' => 'a4', 'cheque_payment' => 'a4', 'minimal_teal' => 'a4', 'letterhead' => 'a4', 'two_copy' => 'a4',
        'sap_document' => 'a4', 'payment_advice' => 'a4', 'expense_claim' => 'a4', 'approval_trail' => 'a4',
        'posting_report' => 'a4', 'premium_paid' => 'a4', 'collection_receipt' => 'a4', 'audit_trail' => 'a4',
        'roll_receipt' => 'thermal',
    ];

    private Voucher $voucher;

    private Customer $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->dealer = Customer::query()->create(['code' => 'VX-21', 'name_en' => 'Rahim Traders', 'name_bn' => 'রহিম ট্রেডার্স', 'is_active' => true]);
        $receivable = (int) Account::query()->where('is_group', false)->where('code', 'like', StandardChart::RECEIVABLE.'%')->orderBy('code')->value('id');
        $income = (int) Account::query()->where('is_group', false)->where('type', 'income')->orderBy('code')->value('id');

        $this->voucher = app(VoucherService::class)->create([
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
            'narration' => 'Collected by SR Rafiq at Kawran Bazar',
        ], [
            ['account_id' => $receivable, 'debit' => '3000', 'credit' => '0', 'party_type' => 'customer', 'party_id' => $this->dealer->id],
            ['account_id' => $income, 'debit' => '0', 'credit' => '3000'],
        ]);

        // ⓘ মাথার ঘরগুলো — হাতে বসানো, যাতে প্রতিটা ঘর সত্যিই ভরা থাকে
        $this->voucher->forceFill([
            'party_type' => 'customer', 'party_id' => $this->dealer->id,
            'instrument' => 'mfs', 'instrument_no' => '9K7Q2M1XAB',
            'payee_name' => 'Karim Property', 'bill_no' => 'BILL-77',
            'against_type' => 'customer', 'against_id' => $this->dealer->id,
        ])->save();
    }

    /** ① বাড়তি কথা কাগজে */
    public function test_the_extra_facts_of_a_real_voucher_reach_the_paper(): void
    {
        $details = $this->paperOf($this->voucher)['details'] ?? null;

        $this->assertIsArray($details, '⛔ কাগজে বাড়তি ঘরই নেই — নতুন নকশাগুলো খালি ছাপত।');
        // ⓘ নাম চলতি ভাষায় — বাংলায় বাংলা নাম ([[Customer::name()]])
        $this->assertSame($this->dealer->name(), $details['party'], '⛔ পক্ষের নাম কাগজে পৌঁছায়নি।');
        $this->assertSame('Karim Property', $details['payee']);
        $this->assertSame('9K7Q2M1XAB', $details['instrument_no'], '⛔ TrxID কাগজে নেই।');
        $this->assertSame((string) __('accounts::instrument.mfs'), $details['method']);
        $this->assertSame((string) $this->dealer->drillDocumentNo(), $details['against'], '⛔ কোন কাগজের বিপরীতে, তা নেই।');
        $this->assertSame('BILL-77', $details['bill_no']);
        $this->assertNotSame('', $details['entered_on'], '⛔ এন্ট্রির সময় নেই।');
        $this->assertSame(User::query()->where('email', 'owner@abos.test')->value('name'), $details['prepared_by'], '⛔ কে বানালেন, তা নেই।');

        // ③ পুরনো ঘর অক্ষত — পুরনো ২০ নকশার কাগজ এক চুলও বদলায় না
        $this->assertSame('', $this->paperOf($this->voucher)['party'], '⛔ পুরনো `party` ঘর ভরে গেছে — পুরনো নকশার কাগজ বদলে যেত।');
    }

    /** ② প্রতিটা নতুন নকশায় আসল ভাউচার ছাপা হয় — বাছা নকশার ফাইলটাই আঁকে */
    public function test_each_new_design_prints_a_real_voucher_in_its_own_size(): void
    {
        $settings = app(SettingsService::class);
        $paper = ['a4' => PaperSize::A4, 'a5' => PaperSize::A5, 'thermal' => PaperSize::THERMAL_80];

        foreach (self::NEW as $code => $size) {
            $this->assertContains($code, VoucherDesigns::codes($size), "⛔ {$code}: {$size}-এর তালিকায় নেই — কন্ট্রোল প্যানেলে আসত না।");

            $settings->set('accounts.print.paper.voucher', $paper[$size]);
            $settings->set(VoucherDesigns::key($size), $code);
            $settings->flush();

            $drawn = [];
            View::composer('print.voucher*', function ($view) use (&$drawn) {
                $drawn[] = $view->getName();
            });

            $pdf = $this->get(route('accounts.voucher.print', $this->voucher))->assertOk()->getContent();

            $this->assertStringStartsWith('%PDF', (string) $pdf, "⛔ {$code}: PDF আসেনি।");
            $this->assertContains(VoucherDesigns::template($size, $code), $drawn, "⛔ {$code}: বাছা নকশা নয়, অন্য ছাঁচে ছাপা হলো।");
        }
    }

    /**
     * ⭐ A4-এ রসিদ এক বা দুই কপি — কন্ট্রোল প্যানেলের সেটিং (মালিক, ১২ অক্টোবর ২০২৬: *"control penel e set kora zay ekta
     * na duto"*)। ⓘ ২ = অফিস + গ্রাহক কপি একই কাগজে; ১ = কেবল গ্রাহকের। ডিফল্ট ২।
     */
    public function test_the_a4_receipt_prints_one_or_two_copies_as_the_control_panel_says(): void
    {
        $settings = app(SettingsService::class);
        $office = e((string) __('accounts::vx.office_copy'));
        $customer = e((string) __('accounts::vx.customer_copy'));
        $page = fn () => (string) $this->get(route('accounts.voucher.sample', ['design' => 'money_receipt', 'size' => 'a4', 'type' => 'receipt']))->assertOk()->getContent();

        $this->assertContains('money_receipt', VoucherDesigns::codes('a4'), '⛔ রসিদের A4 রূপ তালিকায় নেই।');
        $this->assertSame('2', (string) $settings->get(\App\Modules\Accounts\Support\VoucherPaper::COPIES), 'ডিফল্ট দুই কপি নয়।');

        $two = $page();
        $this->assertStringContainsString($office, $two, '⛔ দুই কপি বাছা, অথচ অফিস কপি নেই।');
        $this->assertStringContainsString($customer, $two);
        $this->assertSame(2, substr_count($two, 'data-signatures'), '⛔ দুই কপিতে দুই সইয়ের ঘর নেই।');

        $settings->set(\App\Modules\Accounts\Support\VoucherPaper::COPIES, '1');
        $settings->flush();
        $one = $page();
        $this->assertStringNotContainsString($office, $one, '⛔ এক কপি বাছা, তবু অফিস কপি ছাপা হলো।');
        $this->assertStringContainsString($customer, $one);
    }

    /** ③ পুরনো ২০টা তালিকায় আগের ক্রমে, আর ডিফল্ট ট্যালি ক্লাসিক */
    public function test_the_old_twenty_and_the_default_stay(): void
    {
        $this->assertSame('tally_classic', VoucherDesigns::codes('a4')[7]);
        $this->assertSame('aurora', VoucherDesigns::codes('a5')[0]);
        $this->assertSame('tally_classic', (string) app(SettingsService::class)->get(VoucherDesigns::key('a4')));
    }

    /** @return array<string, mixed> */
    private function paperOf(Voucher $voucher): array
    {
        $seen = [];
        View::composer('*', function ($view) use (&$seen) {
            $data = $view->getData();
            if (isset($data['voucher']['lines']) && $seen === []) {
                $seen = $data['voucher'];
            }
        });

        $this->get(route('accounts.voucher.print', $voucher))->assertOk();
        $this->assertNotSame([], $seen, 'ছাপার টেমপ্লেট ডাকাই হয়নি।');

        return $seen;
    }
}
