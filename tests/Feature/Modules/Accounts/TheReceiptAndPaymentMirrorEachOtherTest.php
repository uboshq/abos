<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\AmountInWords;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ রসিদ আর পরিশোধ একে অপরের আয়না — মালিক: *"রসিদের সঙ্গে পেমেন্ট ভাউচার ঠিক করে দিও"*, আর *"হিসাবের সারাংশ ভাউচারে এখন নেই
 * কেন?"* (২৪ অক্টোবর ২০২৬, fe; নকশা: টাকা-নেওয়া-দেওয়া-চূড়ান্ত-নকশা-১০০-সংশোধিত-২২-অক্টোবর.pdf, পাতা ২ আর ৪)।
 *
 * দাবি:
 *   ১. দুই পর্দায় ডান কলামে হিসাবের সারাংশ, তার নিচে খাতায় যা যাবে — একই কার্ড ([[x-money.summary]]), সরবরাহকারী-পরিশোধেও
 *   ২. দুই পর্দা একই ঘর একই ক্রমে চায় — কেবল দুই ইচ্ছাকৃত তফাত: পদ্ধতির পঞ্চম চিপ (নগদ জমা / চেক) আর উল্টো দিকের খাত
 *   ৩. নিচের সারি: বাতিল · খসড়া রাখুন · [মূল] — মূল শেষে (পুরো ERP-র এক ক্রম)
 *   ৪. নোট গোনা ভাঁজে, ঐচ্ছিক — খোলা নয়
 *   ৫. পরিশোধের ঐচ্ছিক খাতের নাম "যে খাতে বসবে", নিচে "না বাছলে পক্ষের দেনা" — "কাকে দেওয়া হল" নয়
 *   ৬. সারাংশের অঙ্ক = খাতার সারি, আর টাকা কথায় = [[AmountInWords]] — রসিদে খাতে টাকা − চার্জ, পরিশোধে খাত থেকে টাকা + চার্জ
 */
final class TheReceiptAndPaymentMirrorEachOtherTest extends TestCase
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
        app(StandardChart::class)->install();
    }

    /** ১ — সারাংশ ডান কলামে, খাতার উপরে */
    public function test_both_screens_carry_the_summary_above_the_ledger_in_the_right_column(): void
    {
        foreach ([Voucher::RECEIPT, Voucher::PAYMENT] as $type) {
            $xpath = $this->dom($this->screen($type));

            $aside = $xpath->query('//*[@data-voucher-aside]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $aside, "⛔ {$type}: ডানের কলামটাই নেই");
            $this->assertNotNull($xpath->query('ancestor::*[@data-voucher-columns]', $aside)->item(0), "⛔ {$type}: ডানের কলাম দুই কলামের গ্রিডে নেই");

            $children = array_values(array_filter(iterator_to_array($aside->childNodes), fn ($n) => $n instanceof DOMElement));
            $this->assertTrue(isset($children[0]) && $children[0]->hasAttribute('data-voucher-summary'), "⛔ {$type}: ডানের কলামের মাথায় হিসাবের সারাংশ নেই");
            $this->assertTrue(isset($children[1]) && $children[1]->hasAttribute('data-voucher-ledger'), "⛔ {$type}: সারাংশের নিচে খাতায় যা যাবে নেই");

            foreach (['data-sum-amount', 'data-sum-charge', 'data-sum-net', 'data-sum-words', 'data-sum-matches'] as $mark) {
                $this->assertSame(1, $xpath->query('.//*[@'.$mark.']', $children[0])->length, "⛔ {$type}: সারাংশে {$mark} নেই");
            }
        }

        $pay = $this->dom((string) $this->get(route('purchase.payment.create'))->assertOk()->getContent());
        $this->assertSame(1, $pay->query('//section[@data-pay-summary][@data-boxed]')->length, '⛔ সরবরাহকারী-পরিশোধের সারাংশ ভাগ করা কার্ডে নেই');
    }

    /** ২ — একই ঘর, একই ক্রম; কেবল দুই ইচ্ছাকৃত তফাত */
    public function test_the_two_screens_ask_the_same_fields_in_the_same_order(): void
    {
        $receipt = $this->fields($this->screen(Voucher::RECEIPT), 'to_account_id');
        $payment = $this->fields($this->screen(Voucher::PAYMENT), 'from_account_id');

        /*
         * ⓘ উল্টো দিকের খাত — ইচ্ছাকৃত তফাত: পরিশোধে ঐচ্ছিক "যে খাতে বসবে" (বিবরণের পাশে), রসিদে "টাকাটা কী বাবদ" (মালিকের নিজের লেখা,
         * ১৯ সেপ্টেম্বর ২০২৬; কেবল গ্রাহক ছাড়া অন্য পক্ষে, পক্ষের ঘরের নিচে)। দুটো বাদ দিয়ে বাকি সব এক।
         */
        $this->assertContains('to_account_id', $payment, 'দৃশ্যটাই ঠিক নয় — পরিশোধে "যে খাতে বসবে" নেই');
        $this->assertContains('from_account_id', $receipt, 'দৃশ্যটাই ঠিক নয় — রসিদে "টাকাটা কী বাবদ" নেই');
        $payment = array_values(array_diff($payment, ['to_account_id']));
        $receipt = array_values(array_diff($receipt, ['from_account_id']));

        $this->assertSame($receipt, $payment, "⛔ রসিদ আর পরিশোধ একই ঘর একই ক্রমে চায় না:\nরসিদ: ".implode(' · ', $receipt)."\nপরিশোধ: ".implode(' · ', $payment));

        $chips = fn (string $type) => array_map(fn (DOMElement $e) => $e->getAttribute('value'),
            iterator_to_array($this->dom($this->screen($type))->query('//input[@type="radio"][@name="instrument"]')));
        $this->assertSame(['cash', 'mfs', 'transfer', 'deposit'], $chips(Voucher::RECEIPT), '⛔ রসিদের পদ্ধতির চিপ বদলেছে');
        $this->assertSame(['cash', 'mfs', 'transfer', 'cheque'], $chips(Voucher::PAYMENT), '⛔ পরিশোধের পদ্ধতির চিপ বদলেছে — কেবল পঞ্চমটা আলাদা হওয়ার কথা');
    }

    /** ৩ — বাতিল · খসড়া রাখুন · [মূল] */
    public function test_the_primary_button_comes_last_after_cancel_and_draft(): void
    {
        foreach ([Voucher::RECEIPT, Voucher::PAYMENT] as $type) {
            $xpath = $this->dom($this->screen($type));
            $row = $xpath->query('//*[@data-save-buttons]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $row, "দৃশ্যটাই ঠিক নয় — {$type}: বোতামের সারি নেই");

            $buttons = array_values(array_filter(iterator_to_array($row->childNodes), fn ($n) => $n instanceof DOMElement));
            $this->assertCount(3, $buttons, "⛔ {$type}: বোতাম তিনটা নয়");
            $this->assertSame('a', $buttons[0]->tagName, "⛔ {$type}: প্রথমে বাতিল (লিংক) নয়");
            $this->assertSame('save_as_draft', $buttons[1]->getAttribute('name'), "⛔ {$type}: মাঝে খসড়া রাখুন নয়");
            $this->assertTrue($buttons[2]->hasAttribute('data-page-primary'), "⛔ {$type}: মূল বোতাম শেষে নয় — বাতিল · খসড়া রাখুন · [মূল]");
        }

        $pay = $this->dom((string) $this->get(route('purchase.payment.create'))->assertOk()->getContent());
        $footer = iterator_to_array($pay->query('//*[@data-pay-footer]//a | //*[@data-pay-footer]//button'));
        $this->assertTrue(end($footer) instanceof DOMElement && end($footer)->hasAttribute('data-page-primary'), '⛔ সরবরাহকারী-পরিশোধে মূল বোতাম শেষে নয়');
    }

    /** ৪ — নোট গোনা ভাঁজে */
    public function test_note_counting_is_folded_and_optional(): void
    {
        foreach ([Voucher::RECEIPT, Voucher::PAYMENT] as $type) {
            $html = $this->screen($type);
            $this->assertMatchesRegularExpression('/<details\b(?=[^>]*\bdata-notes-fold\b)(?![^>]*\bopen\b)[^>]*>/', $html,
                "⛔ {$type}: নোট গোনা ভাঁজে নয়, বা শুরুতেই খোলা");
            $said = array_filter(['bn', 'en'], fn (string $l) => str_contains($html, e(trans('money.counting.fold', [], $l))));
            $this->assertNotEmpty($said, "⛔ {$type}: ভাঁজের মাথায় \"নোট গুনে মেলান — ঐচ্ছিক\" নেই");
        }
    }

    /** ৫ — "যে খাতে বসবে", "কাকে দেওয়া হল" নয় */
    public function test_the_payments_optional_account_says_it_is_an_account(): void
    {
        $xpath = $this->dom($this->screen(Voucher::PAYMENT));
        $label = trim((string) $xpath->query('//label[@for="to_account_id"]')->item(0)?->textContent);
        $hint = trim((string) $xpath->query('//*[@id="to_account_id-hint"]')->item(0)?->textContent);

        $this->assertContains(preg_replace('/\s+/u', ' ', $label), [trans('accounts::field.lands_in', [], 'bn'), trans('accounts::field.lands_in', [], 'en')],
            '⛔ পরিশোধের ঐচ্ছিক খাতের নাম "যে খাতে বসবে" নয়: '.$label);
        $this->assertContains($hint, [trans('accounts::field.lands_in_hint', [], 'bn'), trans('accounts::field.lands_in_hint', [], 'en')],
            '⛔ নিচে "না বাছলে পক্ষের দেনা" নেই: '.$hint);
    }

    /** ৬ — সারাংশ = খাতা, কথা = AmountInWords */
    public function test_the_summary_is_the_ledgers_own_figures_and_words(): void
    {
        $bank = $this->bank();
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();

        foreach ([
            Voucher::RECEIPT => [['party_type' => 'customer', 'party_id' => $customer->id, 'to_account_id' => $bank->id], '975.00', 'debit'],
            Voucher::PAYMENT => [['party_type' => 'supplier', 'party_id' => $supplier->id, 'from_account_id' => $bank->id], '1,025.00', 'credit'],
        ] as $type => [$head, $net, $side]) {
            $answer = $this->postJson(route('accounts.voucher.simulate'), [
                'type' => $type, 'narration' => 'সারাংশের দাবি', 'trx_date' => now()->toDateString(), 'amount' => '1000',
                'instrument' => 'transfer', 'instrument_no' => 'SUM-'.$type, 'charge_amount' => '25', ...$head,
            ])->assertOk()->json();

            $this->assertNotEmpty($answer['rows'] ?? [], "দৃশ্যটাই ঠিক নয় — {$type}: প্রিভিউর সারি নেই: ".json_encode($answer));
            $summary = $answer['summary'] ?? [];
            $moneyRow = collect($answer['rows'])->first(fn (array $r) => str_starts_with((string) $r['account'], $bank->code.' '));

            $this->assertSame('1,000.00', $summary['amount'] ?? null, "⛔ {$type}: সারাংশের টাকা কাগজের টাকা নয়");
            $this->assertSame('25.00', $summary['charge'] ?? null, "⛔ {$type}: সারাংশের চার্জ খাতার চার্জের সারি নয়");
            $this->assertSame($net, $summary['net'] ?? null, "⛔ {$type}: সারাংশের নিট ভুল — রসিদে টাকা − চার্জ, পরিশোধে টাকা + চার্জ");
            $this->assertSame($moneyRow[$side] ?? null, $summary['net'] ?? null, "⛔ {$type}: সারাংশের নিট খাতার টাকার সারির সমান নয়");
            $this->assertTrue($summary['matches'] ?? false, "⛔ {$type}: সারাংশ বলে মেলেনি, অথচ খাতা মেলে");
            $this->assertContains($summary['words'] ?? null, [AmountInWords::of('1000.0000', 'bn'), AmountInWords::of('1000.0000', 'en')],
                "⛔ {$type}: টাকা কথায় AmountInWords-এর লেখা নয়");
        }
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    private function screen(string $type): string
    {
        $this->bank();

        return (string) $this->get(route('accounts.voucher.create', ['type' => $type]))->assertOk()->getContent();
    }

    private function dom(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    /**
     * জমা যায় এমন, দেখা-যায় এমন ঘরের নাম, পাতার ক্রমে — `<template>`-এর ভিতরের (পদ্ধতির ঘর, চিপ ধরে আলাদা), লুকানো, বন্ধ আর
     * `type="hidden"` বাদ; একই নামের পরপর ঘর একবার (চিপ)। টাকার খাতের নাম দুই পর্দায় আলাদা, তাই একই নামে ("money")।
     *
     * @return list<string>
     */
    private function fields(string $html, string $money): array
    {
        $xpath = $this->dom($html);
        $out = [];

        foreach ($xpath->query('//form[@method="POST"]//*[self::input or self::select or self::textarea][@name]') as $el) {
            /** @var DOMElement $el */
            if ($el->getAttribute('type') === 'hidden' || $el->hasAttribute('disabled')
                || $xpath->query('ancestor::template | ancestor-or-self::*[@hidden]', $el)->length > 0) {
                continue;
            }

            $name = $el->getAttribute('name');
            $name = $name === $money && $el->hasAttribute('data-money-account') ? 'money' : $name;

            if (end($out) !== $name) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** ⓘ একটা ব্যাংক খাত — ডেমোর ছকে না থাকলে বানানো ([[TheMethodComesFirstAndOffersOnlyItsAccountsTest::bank()]]-এর একই পথ) */
    private function bank(): Account
    {
        return Account::query()->where('money_kind', Account::BANK)->postable()->active()->orderBy('code')->first()
            ?? app(AccountService::class)->create([
                'code' => StandardChart::BANK.'-01',
                'name_en' => 'Test bank account',
                'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'),
            ]);
    }
}
