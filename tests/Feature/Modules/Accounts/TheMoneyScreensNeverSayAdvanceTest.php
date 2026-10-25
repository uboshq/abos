<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ টাকার পর্দায় "অগ্রিম" নয় — আলাদা অগ্রিম খাত নেই, শূন্য পেরোনো টাকা পক্ষের নিজের খাতেই থাকে (মালিকের নিয়ম; fe, ২৫ অক্টোবর ২০২৬;
 * ফোনের "বিলে প্রয়োগ হয়নি — সরবরাহকারীর খাতায় থাকবে")।
 *
 * ⓘ কেবল টাকার তিন পর্দা (রসিদ, পরিশোধ, সরবরাহকারী-পরিশোধ) আর তাদের বকেয়ার উত্তর। ছকের খাতের নাম (১১৩০ "অগ্রিম ও অগ্রিম পরিশোধ" …)
 * খাতের বাছাইয়ে আসে — ওটা তথ্য, পর্দার কথা নয়, তাই `<option>` বাদ দিয়ে মাপা। স্থিতিপত্রের দুই সারি (`accounts::field.customer_advance`,
 * `supplier_advance`) IFRS-এর উপস্থাপন — fe-র সিদ্ধান্তে থাকে, এখানে মাপা হয় না। ⓘ রসিদের টাকার উৎসের দল `accounts::field.source_returned`
 * ("ফেরত পাওয়া (অগ্রিম, জামানত…)") সত্যিকারের খাত বোঝায় — কর্মীর অগ্রিম (১১৩১) বা জামানত ফেরত — তাই চাবি ধরে বাদ (fe-কে জানানো, ২৫ অক্টোবর ২০২৬)।
 *
 * দাবি:
 *   ১. তিন পর্দার ফর্মে (খাতের তালিকা বাদে) "অগ্রিম" নেই — কাঁচা বা JSON-এ ঢাকা কোনো রূপে
 *   ২. নতুন কথা আছে: পরিশোধে "বিলে প্রয়োগ হয়নি — … খাতায় থাকবে (Dr)", সরবরাহকারী-পরিশোধে খোলা বিল না থাকার কথা
 *   ৩. পর্দার প্রতিটা চাবি — বাংলায় "অগ্রিম" নেই, ইংরেজিতে "advance" নেই; পক্ষের ধরনে বসার :party আছে
 *   ৪. শূন্য পেরোনো দুই পক্ষের বকেয়ার উত্তরে (আগে থেকে জমা থাকা গ্রাহক, আগে থেকে দেওয়া সরবরাহকারী) "অগ্রিম" নেই
 */
final class TheMoneyScreensNeverSayAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private const WORD = 'অগ্রিম';

    /** ⓘ পর্দায় যে চাবিগুলো দেখা যায় */
    private const KEYS = [
        'money.screen.head.owed_in_credit', 'money.screen.head.owed_out_debit', 'money.screen.head.left_in_credit', 'money.screen.head.left_out_debit',
        'money.screen.no_bills', 'money.screen.sum.unapplied', 'money.screen.bills_unapplied',
        'accounts::field.due_in_advance', 'accounts::field.due_out_advance',
    ];

    private Account $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        $this->till = Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
    }

    /** ১ আর ২ */
    public function test_the_three_money_screens_never_say_advance(): void
    {
        foreach ([
            'রসিদ' => [route('accounts.voucher.create', ['type' => Voucher::RECEIPT]), 'money.screen.head.left_in_credit'],
            'পরিশোধ' => [route('accounts.voucher.create', ['type' => Voucher::PAYMENT]), 'money.screen.head.left_out_debit'],
            'সরবরাহকারী-পরিশোধ' => [route('purchase.payment.create'), 'money.screen.no_bills'],
        ] as $screen => [$url, $said]) {
            $form = $this->formOf((string) $this->get($url)->assertOk()->getContent());

            foreach ($this->spellings(self::WORD) as $spelling) {
                $this->assertStringNotContainsString($spelling, $form, "⛔ {$screen}: পর্দায় এখনো \"অগ্রিম\" — অগ্রিম খাত নেই, বাড়তি পক্ষের নিজের খাতে");
            }
            $text = trans($said, [], 'bn');
            $this->assertTrue(collect($this->spellings(explode(':party', $text)[0]))->contains(fn (string $s) => str_contains($form, $s)),
                "⛔ {$screen}: পক্ষের খাতে থাকার নতুন কথা পর্দায় নেই ({$said})");
        }
    }

    /** ৩ */
    public function test_every_key_these_screens_show_says_the_partys_own_account(): void
    {
        foreach (self::KEYS as $key) {
            $bn = trans($key, [], 'bn');
            $en = trans($key, [], 'en');

            $this->assertStringNotContainsString(self::WORD, $bn, "⛔ {$key}: বাংলায় এখনো \"অগ্রিম\"");
            $this->assertStringNotContainsStringIgnoringCase('advance', $en, "⛔ {$key}: ইংরেজিতে এখনো \"advance\"");
            $this->assertMatchesRegularExpression('/\((Cr|Dr|:side)\)/', $bn, "⛔ {$key}: কোন পাশে থাকে (Cr/Dr) বলে না");
        }

        foreach (['money.screen.head.owed_in_credit', 'money.screen.head.left_out_debit', 'accounts::field.due_in_advance', 'accounts::field.due_out_advance'] as $key) {
            $this->assertStringContainsString(':party', trans($key, [], 'bn'), "⛔ {$key}: পক্ষের ধরন বসে না — \"সরবরাহকারী\" লেখা থাকত গ্রাহকের কাগজেও");
        }
    }

    /** ৪ */
    public function test_the_due_answers_past_zero_never_say_advance(): void
    {
        // ⓘ গ্রাহকের আগে থেকে জমা — বিল ছাড়া রসিদ; সরবরাহকারীকে আগে থেকে দেওয়া — দেনার বেশি পরিশোধ
        $customer = app(CustomerService::class)->create(['name_en' => 'Credit Customer', 'credit_limit' => 0, 'credit_days' => 0]);
        $this->posted(Voucher::RECEIPT, StandardChart::RECEIVABLE, 'customer', (int) $customer->id, '500');
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $owed = abs((float) $this->getJson(route('accounts.voucher.due', ['party_type' => 'supplier', 'party_id' => $supplier->id]))->json('amount'));
        $this->posted(Voucher::PAYMENT, StandardChart::PAYABLE, 'supplier', (int) $supplier->id, number_format($owed + 300, 2, '.', ''));

        foreach (['customer' => $customer->id, 'supplier' => $supplier->id] as $type => $id) {
            $answer = $this->getJson(route('accounts.voucher.due', ['party_type' => $type, 'party_id' => $id]))->assertOk();
            $this->assertTrue((bool) $answer->json('known'), "দৃশ্যটাই ঠিক নয় — {$type}-এর বকেয়া জানা নেই");
            foreach ($this->spellings(self::WORD) as $spelling) {
                $this->assertStringNotContainsString($spelling, (string) $answer->getContent(), "⛔ {$type}: শূন্য পেরোনো বকেয়ার উত্তরে \"অগ্রিম\"");
            }
        }
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /** ⓘ পাতার টাকার ফর্ম, খাতের তালিকা (`<option>`) বাদে — মেনু আর অন্য মডিউলের লিংক মাপা নয় */
    private function formOf(string $html): string
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        $form = $xpath->query('//form[.//*[@data-voucher-header] or .//*[@data-pay-footer]]')->item(0);
        $this->assertNotNull($form, 'দৃশ্যটাই ঠিক নয় — টাকার ফর্ম পাওয়া গেল না');

        foreach (iterator_to_array($xpath->query('.//option', $form)) as $option) {
            $option->parentNode?->removeChild($option);
        }

        // ⓘ চাবি ধরে বাদ — সত্যিকারের খাতের দল (কর্মীর অগ্রিম ১১৩১, জামানত), পক্ষের বাড়তির কথা নয় (উপরের মন্তব্য)
        return str_replace(e(trans('accounts::field.source_returned', [], 'bn')), '', (string) $dom->saveHTML($form));
    }

    /**
     * ⓘ কাঁচা, আর JSON-এ ঢাকা (`@js` বাংলাকে \uXXXX করে) — দুই রূপেই
     *
     * @return list<string>
     */
    private function spellings(string $text): array
    {
        return array_values(array_unique([$text, trim((string) json_encode($text), '"'), str_replace('\\', '\\\\', trim((string) json_encode($text), '"'))]));
    }

    private function posted(string $type, string $partyHead, string $partyType, int $partyId, string $amount): void
    {
        $lines = $type === Voucher::RECEIPT
            ? [['account_id' => $this->till->id, 'debit' => $amount], ['account_id' => StandardChart::find($partyHead)->id, 'credit' => $amount, 'party_type' => $partyType, 'party_id' => $partyId]]
            : [['account_id' => StandardChart::find($partyHead)->id, 'debit' => $amount, 'party_type' => $partyType, 'party_id' => $partyId], ['account_id' => $this->till->id, 'credit' => $amount]];

        if ($type === Voucher::PAYMENT) {
            $fund = app(VoucherService::class)->create(['type' => Voucher::JOURNAL, 'trx_date' => now()->subDay()->toDateString(), 'narration' => 'মূলধন'],
                [['account_id' => $this->till->id, 'debit' => bcadd($amount, '1000', 2)], ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'credit' => bcadd($amount, '1000', 2)]]);
            app(VoucherService::class)->post($fund);
        }

        $voucher = app(VoucherService::class)->create(
            ['type' => $type, 'trx_date' => now()->toDateString(), 'narration' => 'শূন্য পেরোনো', 'party_type' => $partyType, 'party_id' => $partyId, 'instrument' => 'cash'],
            $lines,
        );
        app(VoucherService::class)->post($voucher);
    }
}
