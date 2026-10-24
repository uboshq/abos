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
 *   ৭–৯. পক্ষের ধরন চিপে · খোলা বিল খোলা, পক্ষ ছাড়া কাগজে কারণ · চার টাকার পর্দায় Enter কিছু পাঠায় না
 *   ১০. উৎসে কর্তন দুই দিকে: খাতার দুই পাশ সমান, সারাংশ = খাতা (পরিশোধে ২১২১ / ২১২২ ক্রেডিট, রসিদে ১১৩৮ / ১১৩৫ ডেবিট)
 *   ১১–১২. কর্তনের খাত কোডে অন্য খাত হলে থামা, নিজের কারণে (পরিশোধে ২১২১, রসিদে ব্যাংক হয়ে যাওয়া ১১৩৫)
 *   ১৩. নাম বাছা ভাগ করা পিকারে ([[x-ui.party-search]] stacked addable), নতুন নামের ঘর আগের নামে
 *   ১৪–২২. নকশার পুরো মিল-তালিকা (২৫ অক্টোবর ২০২৬): মাথা (শিরোনাম · চিপ · নম্বরের উপসর্গ · বানালেন · সই দেবেন · মাস খোলা, ডানে ধরনের
 *       নামে তিন অঙ্ক) · "সাধারণ তথ্য" কার্ডে পক্ষ থেকে উৎসে কাটা পর্যন্ত, খোলা বিল আলাদা কার্ডে · সংযুক্তি ও বিবরণ · রসিদে চেকের চিপ
 *       চেকের খাতায় · পদ্ধতির চিপ ভাঁজ হয় (min-w-0) · রসিদের খাতায় শাখার কলাম আর VDS-এর "যদি থাকে" · স্থির বোতাম-সারি "পোস্ট" /
 *       "সইয়ে পাঠান" · এক চাপে অঙ্ক · সরবরাহকারী-পরিশোধের মাথায় কাঁচা চাবি নয়
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

            // ⭐ ডানের খণ্ড: সারাংশ → খাতায় যা যাবে → নিয়ন্ত্রণ; তিন অঙ্ক পাতার মাথায়, ডান কোণে (নকশা পাতা ২ আর ৪; ২৫ অক্টোবর ২০২৬)
            $children = array_values(array_filter(iterator_to_array($aside->childNodes), fn ($n) => $n instanceof DOMElement));
            $this->assertSame('right', $aside->getAttribute('data-part'), "⛔ {$type}: ডানের কলাম একটা খণ্ড (data-part=\"right\") নয়");
            $this->assertTrue(isset($children[0]) && $children[0]->hasAttribute('data-voucher-summary'), "⛔ {$type}: ডানের কলামের মাথায় হিসাবের সারাংশ নেই");
            $this->assertTrue(isset($children[1]) && $children[1]->hasAttribute('data-voucher-ledger'), "⛔ {$type}: সারাংশের নিচে খাতায় যা যাবে নেই");
            $this->assertTrue(isset($children[2]) && $children[2]->hasAttribute('data-voucher-controls'), "⛔ {$type}: খাতার নিচে নিয়ন্ত্রণের কার্ড নেই");

            foreach (['data-sum-amount', 'data-sum-charge', 'data-sum-net', 'data-sum-words', 'data-sum-matches'] as $mark) {
                $this->assertSame(1, $xpath->query('.//*[@'.$mark.']', $children[0])->length, "⛔ {$type}: সারাংশে {$mark} নেই");
            }
            $figures = $xpath->query('//*[@data-voucher-header]//*[@data-voucher-head-figures]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $figures, "⛔ {$type}: তিন অঙ্ক পাতার মাথায় নেই");
            $this->assertNull($xpath->query('ancestor::*[@data-voucher-aside]', $figures)->item(0), "⛔ {$type}: তিন অঙ্ক আবার ডানের কলামে");
            foreach (['data-head-owed', 'data-head-this', 'data-head-left', 'data-head-none'] as $mark) {
                $this->assertSame(1, $xpath->query('.//*[@'.$mark.']', $figures)->length, "⛔ {$type}: মাথার তিন অঙ্কে {$mark} নেই");
            }
            $this->assertSame(1, $xpath->query('.//*[@data-control="signatures"]', $children[2])->length, "⛔ {$type}: নিয়ন্ত্রণে সইয়ের লাইন নেই");
            $this->assertSame('left', $xpath->query('//*[@data-voucher-main]')->item(0)?->getAttribute('data-part'), "⛔ {$type}: বাঁয়ের খণ্ড (data-part=\"left\") নেই");
            $this->assertSame(1, $xpath->query('//*[@data-part="bar"]//*[@data-save-buttons]')->length, "⛔ {$type}: নিচের বোতাম-সারি আলাদা খণ্ডে (data-part=\"bar\") নেই");
        }

        $pay = $this->dom((string) $this->get(route('purchase.payment.create'))->assertOk()->getContent());
        $this->assertSame(1, $pay->query('//section[@data-pay-summary][@data-boxed]')->length, '⛔ সরবরাহকারী-পরিশোধের সারাংশ ভাগ করা কার্ডে নেই');
        $this->assertSame(1, $pay->query('//section[@data-pay-controls][@data-boxed]//*[@data-control="bank"]')->length,
            '⛔ সরবরাহকারী-পরিশোধের নিয়ন্ত্রণ ভাগ করা কার্ডে নেই, বা প্রাপকের ব্যাংকের লাইন হারাল');
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

        /*
         * ⭐ উৎসে কর্তনের ঘর (VDS, AIT) দুই পর্দাতেই — রসিদে পক্ষ কেটে রাখেন (১১৩৮ / ১১৩৫ ডেবিট), পরিশোধে আমরা কাটি (২১২২ / ২১২১
         * ক্রেডিট); ২৪ অক্টোবর ২০২৬। ⓘ টাকার ঘর পাঠায় লুকানো ঘরে (x-ui.field type="money"), তাই উপরের তালিকায় আসে না — আলাদা করে মাপা
         */
        foreach ([Voucher::RECEIPT, Voucher::PAYMENT] as $type) {
            $xpath = $this->dom($this->screen($type));
            foreach (['ait_amount', 'vds_amount'] as $name) {
                $this->assertSame(1, $xpath->query('//*[@data-voucher-withheld]//input[@name="'.$name.'"]')->length, "⛔ {$type}: উৎসে কর্তনের ঘর {$name} নেই");
            }
        }

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

    /** ৭ — পক্ষের ধরন চিপে, "অন্য (সরাসরি খাত)"-সহ */
    public function test_the_party_type_is_chips_with_other(): void
    {
        foreach ([Voucher::RECEIPT => 'customer', Voucher::PAYMENT => 'supplier'] as $type => $start) {
            $xpath = $this->dom($this->screen($type));

            // ⓘ পুরনো নমুনা-বিন্যাসের ধরনের ঘর লুকানো আর বন্ধ হয়ে আঁকা থাকে, জমা যায় না — চালু ড্রপডাউন গোনা
            $this->assertSame(0, $xpath->query('//select[@name="party_type"][not(@disabled)]')->length, "⛔ {$type}: পক্ষের ধরন এখনো ড্রপডাউন — নকশায় চিপ");
            $chips = iterator_to_array($xpath->query('//input[@type="radio"][@name="party_type"]'));
            $this->assertGreaterThanOrEqual(4, count($chips), "⛔ {$type}: পক্ষের ধরনের চিপ নেই");
            $this->assertSame(1, $xpath->query('//input[@type="radio"][@name="party_type"][@value=""][@data-party-type-chip="other"]')->length,
                "⛔ {$type}: \"অন্য (সরাসরি খাত)\" চিপ নেই");
            $checked = array_values(array_filter($chips, fn (DOMElement $c) => $c->hasAttribute('checked')));
            $this->assertSame($start, $checked[0]?->getAttribute('value'), "⛔ {$type}: শুরুর ধরন {$start} বাছা নেই");
        }
    }

    /** ৮ — খোলা বিলের টেবিল খোলা, ভাঁজ নয়; বিল না থাকলে বা পক্ষ না থাকলে টেবিলটাই নেই, আর এক লাইনে কারণ */
    public function test_open_bills_show_open_and_a_paper_without_a_party_says_why(): void
    {
        foreach ([Voucher::RECEIPT => 'no_party_note_in', Voucher::PAYMENT => 'no_party_note_out'] as $type => $note) {
            $html = $this->screen($type);
            $xpath = $this->dom($html);

            $bills = $xpath->query('//*[@data-open-bills]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $bills, "⛔ {$type}: খোলা বিলের অংশটাই নেই");
            $this->assertSame('section', $bills->tagName, "⛔ {$type}: খোলা বিল ভাঁজে (details) — নকশায় খোলা টেবিল");
            $this->assertSame('! noParty && bills.length > 0', $bills->getAttribute('x-show'),
                "⛔ {$type}: খোলা বিলের টেবিল সবসময় দেখায় — বিল না থাকলে বা পক্ষ না থাকলে থাকার কথা নয়");

            $why = $xpath->query('//*[@data-no-party-note]')->item(0);
            $this->assertSame('noParty', $why?->getAttribute('x-show'), "⛔ {$type}: পক্ষ ছাড়া কাগজে কারণের লাইন নেই");
            $this->assertContains(trim((string) $why?->textContent), [trans('accounts::message.'.$note, [], 'bn'), trans('accounts::message.'.$note, [], 'en')],
                "⛔ {$type}: পক্ষ ছাড়া কাগজের কারণের লেখা ভুল");
        }
    }

    /** ৯ — চার টাকার পর্দায় Enter কিছু পাঠায় না (`x-money-keys`; আচরণ money-keys.test.js-এ) */
    public function test_the_four_money_screens_carry_the_enter_key_guard(): void
    {
        foreach ([
            'receipt' => route('accounts.voucher.create', ['type' => Voucher::RECEIPT]),
            'payment' => route('accounts.voucher.create', ['type' => Voucher::PAYMENT]),
            'expense' => route('accounts.voucher.create', ['type' => Voucher::EXPENSE]),
            'supplier payment' => route('purchase.payment.create'),
        ] as $screen => $url) {
            $this->bank();
            $html = (string) $this->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<form\b(?=[^>]*\bmethod="POST")(?=[^>]*\bx-money-keys\b)[^>]*>/', $html,
                "⛔ {$screen}: ফর্মে x-money-keys নেই — Enter চাপলে ফর্ম জমা হয়ে যেত");
        }
    }

    /** ১০ — পরিশোধে উৎসে কর্তন: খাতার দুই পাশ সমান, সারাংশ = খাতা; রসিদ আর কর্তন ছাড়া পরিশোধও */
    public function test_withholding_keeps_both_sides_equal_and_the_summary_is_the_ledger(): void
    {
        $bank = $this->bank();
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();

        foreach ([
            'receipt' => [Voucher::RECEIPT, ['party_type' => 'customer', 'party_id' => $customer->id, 'to_account_id' => $bank->id], '0', '0'],
            'payment' => [Voucher::PAYMENT, ['party_type' => 'supplier', 'party_id' => $supplier->id, 'from_account_id' => $bank->id], '0', '0'],
            'payment with AIT and VDS' => [Voucher::PAYMENT, ['party_type' => 'supplier', 'party_id' => $supplier->id, 'from_account_id' => $bank->id], '50', '75'],
            'receipt with VDS and AIT' => [Voucher::RECEIPT, ['party_type' => 'customer', 'party_id' => $customer->id, 'to_account_id' => $bank->id], '60', '40'],
        ] as $case => [$type, $head, $ait, $vds]) {
            $answer = $this->postJson(route('accounts.voucher.simulate'), [
                'type' => $type, 'narration' => 'কর্তনের দাবি', 'trx_date' => now()->toDateString(), 'amount' => '1000',
                'instrument' => 'transfer', 'instrument_no' => 'WH-'.md5($case), 'charge_amount' => '25', 'ait_amount' => $ait, 'vds_amount' => $vds, ...$head,
            ])->assertOk()->json();

            $rows = collect($answer['rows'] ?? []);
            $this->assertNotEmpty($rows->all(), "দৃশ্যটাই ঠিক নয় — {$case}: প্রিভিউর সারি নেই: ".json_encode($answer));
            $this->assertTrue($answer['balanced'] ?? false, "⛔ {$case}: খাতার ডেবিট আর ক্রেডিট সমান নয়");

            $amountOf = fn (string $code, string $side) => (string) ($rows->first(fn (array $r) => str_starts_with((string) $r['account'], $code.' '))[$side] ?? '');
            $summary = $answer['summary'] ?? [];
            $out = $type === Voucher::PAYMENT;

            $this->assertSame($amountOf($bank->code, $out ? 'credit' : 'debit'), $summary['net'] ?? null, "⛔ {$case}: সারাংশের নিট খাতার টাকার সারি নয়");
            if ($out && $ait !== '0') {
                $this->assertSame('50.00', $amountOf(StandardChart::TAX_DEDUCTED_AT_SOURCE, 'credit'), "⛔ {$case}: AIT ২১২১-এ বসেনি");
                $this->assertSame('75.00', $amountOf(StandardChart::VDS_PAYABLE, 'credit'), "⛔ {$case}: VDS ২১২২-এ বসেনি");
                $this->assertSame('50.00', $summary['ait'] ?? null, "⛔ {$case}: সারাংশের AIT খাতার ২১২১ নয়");
                $this->assertSame('75.00', $summary['vds'] ?? null, "⛔ {$case}: সারাংশের VDS খাতার ২১২২ নয়");
                $this->assertSame('1,125.00', $summary['gross'] ?? null, "⛔ {$case}: পক্ষের খাতে মোট দেওয়া + AIT + VDS নয়");
                $this->assertSame('1,125.00', $amountOf(StandardChart::PAYABLE, 'debit'), "⛔ {$case}: সরবরাহকারীর দেনা মোটে কমেনি");
                $this->assertSame('1,025.00', $summary['net'] ?? null, "⛔ {$case}: টাকার খাত থেকে দেওয়া + চার্জের বাইরে কিছু গেল");
            } elseif ($ait !== '0') {
                // ⭐ রসিদ: পক্ষ কেটে রাখলেন — ১১৩৮ আর ১১৩৫ ডেবিট, গ্রাহকের পাওনা কমে টাকা + VDS + AIT-এ, টাকার খাতে কেবল টাকা − চার্জ
                $this->assertSame('40.00', $amountOf(StandardChart::VDS_RECEIVABLE, 'debit'), "⛔ {$case}: VDS ১১৩৮-এ ডেবিট হয়নি");
                $this->assertSame('60.00', $amountOf(StandardChart::ADVANCE_INCOME_TAX, 'debit'), "⛔ {$case}: AIT ১১৩৫-এ ডেবিট হয়নি");
                $this->assertSame('40.00', $summary['vds'] ?? null, "⛔ {$case}: সারাংশের VDS খাতার ১১৩৮ নয়");
                $this->assertSame('60.00', $summary['ait'] ?? null, "⛔ {$case}: সারাংশের AIT খাতার ১১৩৫ নয়");
                $this->assertSame('1,100.00', $summary['gross'] ?? null, "⛔ {$case}: পক্ষের খাতে মোট পাওয়া + VDS + AIT নয়");
                $this->assertSame('1,100.00', $amountOf(StandardChart::RECEIVABLE, 'credit'), "⛔ {$case}: গ্রাহকের পাওনা মোটে কমেনি");
                $this->assertSame('975.00', $summary['net'] ?? null, "⛔ {$case}: টাকার খাতে পাওয়া − চার্জের বাইরে কিছু এল");
                $this->assertSame('', $amountOf(StandardChart::TAX_DEDUCTED_AT_SOURCE, 'credit'), "⛔ {$case}: রসিদের কর্তন আমাদের দেনায় (২১২১) বসল");
            } else {
                $this->assertSame('0.00', $summary['ait'] ?? null, "⛔ {$case}: কর্তন ছাড়া সারাংশে AIT");
                $this->assertSame('0.00', $summary['vds'] ?? null, "⛔ {$case}: কর্তন ছাড়া সারাংশে VDS");
                $this->assertSame('', $amountOf(StandardChart::TAX_DEDUCTED_AT_SOURCE, 'credit'), "⛔ {$case}: কর্তন ছাড়া খাতায় ২১২১-এর সারি");
                $this->assertSame('', $amountOf(StandardChart::VDS_RECEIVABLE, 'debit'), "⛔ {$case}: কর্তন ছাড়া খাতায় ১১৩৮-এর সারি");
            }
            $this->assertTrue($summary['matches'] ?? false, "⛔ {$case}: সারাংশ বলে মেলেনি");
        }
    }

    /** ১১ — ছকের ২১২১ কোডে অন্য খাত বসে থাকলে কর্তন থামে, বাংলায় কারণসহ */
    public function test_a_withholding_account_that_lost_its_meaning_refuses(): void
    {
        $bank = $this->bank();
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $ait = Account::query()->where('code', StandardChart::TAX_DEDUCTED_AT_SOURCE)->firstOrFail();
        $ait->forceFill(['type' => Account::ASSET])->save();

        $vouchers = Voucher::query()->count();

        // ⓘ প্রিভিউ থামে ৪২২-এ, কারণ ঘর ধরে JSON-এর `errors`-এ (cb-ma-2-এ মাপা, ২৪ অক্টোবর ২০২৬)
        $response = $this->postJson(route('accounts.voucher.simulate'), [
            'type' => Voucher::PAYMENT, 'narration' => 'অর্থ হারানো খাত', 'trx_date' => now()->toDateString(), 'amount' => '1000',
            'instrument' => 'transfer', 'instrument_no' => 'WH-LOST', 'ait_amount' => '50',
            'party_type' => 'supplier', 'party_id' => $supplier->id, 'from_account_id' => $bank->id,
        ]);

        $this->assertSame(422, $response->status(), '⛔ অর্থ হারানো ২১২১-এ কর্তন থামেনি: '.$response->getContent());
        $this->assertArrayNotHasKey('rows', (array) $response->json(), '⛔ অর্থ হারানো ২১২১-এ কর্তন খাতায় বসতে যাচ্ছিল');
        $this->assertSame($vouchers, Voucher::query()->count(), '⛔ থামা প্রিভিউ ভাউচার লিখল');
        $said = (string) $response->json('errors.ait_amount.0');
        $expected = array_map(fn (string $l) => trans('accounts::validation.withheld_account_not_its_meaning',
            ['code' => StandardChart::TAX_DEDUCTED_AT_SOURCE, 'account' => $ait->fresh()->label()], $l), ['bn', 'en']);
        $this->assertContains($said, $expected, '⛔ কর্তন থামল, কিন্তু খাতের অর্থের কারণে নয়: '.$said);
    }

    /** ১২ — রসিদে ছকের ১১৩৫ কোডে ব্যাংক বসে থাকলে পক্ষের কাটা AIT থামে, নিজের কারণে — নাহলে কাটা কর "ব্যাংকে টাকা" হয়ে দেখা দিত */
    public function test_a_receipt_whose_ait_account_turned_into_a_bank_refuses(): void
    {
        $bank = $this->bank();
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $ait = Account::query()->where('code', StandardChart::ADVANCE_INCOME_TAX)->firstOrFail();
        $ait->forceFill(['money_kind' => Account::BANK])->save();

        $vouchers = Voucher::query()->count();

        $response = $this->postJson(route('accounts.voucher.simulate'), [
            'type' => Voucher::RECEIPT, 'narration' => 'ব্যাংক হয়ে যাওয়া ১১৩৫', 'trx_date' => now()->toDateString(), 'amount' => '1000',
            'instrument' => 'transfer', 'instrument_no' => 'WH-1135', 'ait_amount' => '60', 'vds_amount' => '40',
            'party_type' => 'customer', 'party_id' => $customer->id, 'to_account_id' => $bank->id,
        ]);

        $this->assertSame(422, $response->status(), '⛔ ব্যাংক হয়ে যাওয়া ১১৩৫-এ পক্ষের কাটা AIT থামেনি: '.$response->getContent());
        $this->assertArrayNotHasKey('rows', (array) $response->json(), '⛔ ব্যাংক হয়ে যাওয়া ১১৩৫-এ পক্ষের কাটা AIT খাতায় বসতে যাচ্ছিল');
        $this->assertSame($vouchers, Voucher::query()->count(), '⛔ থামা প্রিভিউ ভাউচার লিখল');
        $said = (string) $response->json('errors.ait_amount.0');
        $expected = array_map(fn (string $l) => trans('accounts::validation.withheld_by_party_not_its_meaning',
            ['code' => StandardChart::ADVANCE_INCOME_TAX, 'account' => $ait->fresh()->label()], $l), ['bn', 'en']);
        $this->assertContains($said, $expected, '⛔ রসিদ থামল, কিন্তু ১১৩৫-এর অর্থের কারণে নয়: '.$said);
    }

    /** ১৩ — দুই পর্দায় নাম বাছা ভাগ করা পিকারে, দুই লাইনে আর "+ নতুন নাম"-সহ; নতুন নামের ঘর আগের নামেই */
    public function test_both_vouchers_pick_the_party_with_the_shared_stacked_addable_picker(): void
    {
        foreach ([Voucher::RECEIPT, Voucher::PAYMENT] as $type) {
            $xpath = $this->dom($this->screen($type));
            $cell = $xpath->query('//*[@data-party-name-cell]//*[@data-party-picker-cell]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $cell, "⛔ {$type}: নামের ঘরে ভাগ করা পিকারের বাক্স নেই");
            $this->assertSame('partyPicked($event)', $cell->getAttribute('x-on:change'), "⛔ {$type}: পিকারের বদল বকেয়া / বিল / মাথার অঙ্কে পৌঁছায় না");

            // ⓘ ধরন ধরে একটা করে পিকার, `<template x-if>`-এ — DOMDocument টেমপ্লেটের ভিতরও পড়ে
            $pickers = $xpath->query('.//template//button[@data-party-picker]', $cell);
            $this->assertGreaterThanOrEqual(4, $pickers->length, "⛔ {$type}: প্রতিটা পক্ষের ধরনের নিজের পিকার নেই");
            foreach ($pickers as $button) {
                /** @var DOMElement $button */
                $this->assertTrue($button->hasAttribute('data-party-stacked'), "⛔ {$type}: পিকার দুই লাইনের (stacked) নয় — সরু ঘরে নাম কাটে");
            }
            foreach (['party_pick', 'party_new', 'party_mobile'] as $name) {
                $this->assertSame($pickers->length, $xpath->query('.//template//input[@name="'.$name.'"]', $cell)->length,
                    "⛔ {$type}: প্রতিটা পিকারে {$name} ঘর নেই — নতুন নাম আগের নামে সার্ভারে যায় না");
            }

            // ⛔ cb-ma-2: `party_id` কেবল টেমপ্লেটের ভিতরে থাকলে সার্ভারের আঁকা ফর্মে ঘরটাই নেই — একটাই, পাতায়, বাক্সের `partyId`-এ বাঁধা
            $sent = $xpath->query('//form//input[@name="party_id"][not(ancestor::template)]');
            $this->assertSame(1, $sent->length, "⛔ {$type}: পাতায় ঠিক একটা party_id নেই — না থাকলে পক্ষ যায় না, দুইটা থাকলে একটা আরেকটাকে ঢাকে");
            $this->assertSame('partyId', $sent->item(0)?->getAttribute('x-bind:value'), "⛔ {$type}: party_id পিকারের বাছাই ধরে না");
            $this->assertSame(0, $xpath->query('//input[@name="party_id"][ancestor::template]')->length,
                "⛔ {$type}: পিকারের ভিতরেও party_id — দুই ঘর একই নামে জমা যেত");
        }
    }

    /** ১৪ — মাথা: শিরোনাম · চিপ · "নম্বর: সংরক্ষণে বসবে (RCV-…) · বানালেন · মাস খোলা ✓", ডানে ধরনের নামে তিন অঙ্ক (নকশা পাতা ২ আর ৪) */
    public function test_the_head_names_the_paper_its_number_series_its_maker_and_the_open_month(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        foreach ([Voucher::RECEIPT => ['title_receive', 'owed_by_in', 'customer'], Voucher::PAYMENT => ['title_pay', 'owed_by_out', 'supplier']] as $type => [$title, $owed, $start]) {
            $xpath = $this->dom($this->screen($type));
            $head = $xpath->query('//form//*[@data-voucher-header]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $head, "⛔ {$type}: নকশার মাথা ফর্মে নেই");

            $h1 = trim((string) $xpath->query('.//h1', $head)->item(0)?->textContent);
            $this->assertContains($h1, [trans('money.screen.'.$title, [], 'bn'), trans('money.screen.'.$title, [], 'en')], "⛔ {$type}: শিরোনাম নকশার নয়: {$h1}");
            $this->assertSame(1, $xpath->query('.//*[@data-paper-state="draft"]', $head)->length, "⛔ {$type}: মাথায় \"খসড়া\" চিপ নেই");

            $meta = preg_replace('/\s+/u', ' ', trim((string) $xpath->query('.//*[@data-voucher-meta]', $head)->item(0)?->textContent));
            $prefix = rtrim(app(\App\Core\Engines\NumberSeries\NumberSeriesEngine::class)->prefixOf(Voucher::DOC_TYPES[$type]), '-');
            $this->assertNotSame('', $prefix, 'দৃশ্যটাই ঠিক নয় — নম্বর সিরিজের উপসর্গ নেই');
            $said = array_filter(['bn', 'en'], fn (string $l) => str_contains($meta, trans('money.screen.meta.number_on_save_as', ['prefix' => $prefix], $l)));
            $this->assertNotEmpty($said, "⛔ {$type}: মাথায় নম্বর সিরিজের উপসর্গ ({$prefix}-…) নেই: {$meta}");
            $this->assertStringContainsString((string) $owner->name, $meta, "⛔ {$type}: মাথায় \"বানালেন\" নেই");
            $month = array_filter(['bn', 'en'], fn (string $l) => str_contains($meta, trans('money.screen.meta.month_open', [], $l)));
            $this->assertNotEmpty($month, "⛔ {$type}: খোলা মাসে মাথায় \"মাস খোলা ✓\" নেই: {$meta}");
            $this->assertSame(0, $xpath->query('.//*[@data-voucher-signer]', $head)->length, "⛔ {$type}: সইয়ের ছক ছাড়াই \"সই দেবেন\" লেখা");

            $label = trim((string) $xpath->query('.//*[@data-voucher-head-figures]//dt', $head)->item(0)?->textContent);
            $this->assertContains($label, [trans("money.screen.head.{$owed}.{$start}", [], 'bn'), trans("money.screen.head.{$owed}.{$start}", [], 'en')],
                "⛔ {$type}: প্রথম অঙ্কের নাম পক্ষের ধরনে নয় (\"গ্রাহকের বকেয়া\" / \"সরবরাহকারীর পাওনা\"): {$label}");
        }
    }

    /** ১৫ — সইয়ের ছক থাকলে মাথায় "সই দেবেন: … (ধাপ 1/N)", আর মূল বোতাম "সইয়ে পাঠান" */
    public function test_with_a_signing_flow_the_head_names_the_first_signer_and_the_button_sends_for_signing(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $flow = \App\Models\ApprovalFlow::create(['company_id' => $this->company->id, 'module' => \App\Modules\Accounts\Services\VoucherApproval::MODULE,
            'action' => Voucher::RECEIPT, 'is_active' => true]);
        \App\Models\ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $owner->id]);
        \App\Models\ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 2, 'approver_type' => 'user', 'approver_id' => $owner->id]);

        $xpath = $this->dom($this->screen(Voucher::RECEIPT));
        $signer = preg_replace('/\s+/u', ' ', trim((string) $xpath->query('//*[@data-voucher-header]//*[@data-voucher-signer]')->item(0)?->textContent));
        $expected = array_map(fn (string $l) => trans('money.screen.meta.signer', ['who' => $owner->name, 'levels' => 2], $l), ['bn', 'en']);
        $this->assertContains($signer, $expected, '⛔ সইয়ের ছক থাকলেও মাথায় প্রথম সইদাতা আর ধাপ নেই: '.$signer);

        $primary = trim((string) $xpath->query('//*[@data-part="bar"]//*[@data-page-primary]')->item(0)?->textContent);
        $this->assertContains($primary, [trans('money.screen.send', [], 'bn'), trans('money.screen.send', [], 'en')], '⛔ সই চালু, অথচ মূল বোতাম "সইয়ে পাঠান" নয়: '.$primary);

        // ⭐ ছক থাকলে খসড়াতেই "সইয়ের অপেক্ষায় হবে" (Core-এর will_wait, cb-core4 e35e7099e)
        $this->assertSame(1, $xpath->query('//*[@data-voucher-header]//*[@data-paper-state="will_wait"]')->length,
            '⛔ সইয়ের ছক থাকলেও মাথার চিপ "সইয়ের অপেক্ষায় হবে" বলে না');

        $plain = $this->dom($this->screen(Voucher::PAYMENT));
        $post = trim((string) $plain->query('//*[@data-part="bar"]//*[@data-page-primary]')->item(0)?->textContent);
        $this->assertContains($post, [trans('money.screen.post', [], 'bn'), trans('money.screen.post', [], 'en')], '⛔ সই বন্ধ, অথচ মূল বোতাম "পোস্ট" নয়: '.$post);
    }

    /** ১৬ — "সাধারণ তথ্য" এক কার্ডে: পক্ষের ধরন · পক্ষ · তারিখ · টাকা · পদ্ধতি · খাত · উৎসে কাটা; খোলা বিল তার পরে নিজের কার্ডে; সংযুক্তি ও বিবরণ */
    public function test_the_general_card_holds_party_to_withholding_and_the_bills_follow_in_their_own_card(): void
    {
        foreach ([Voucher::RECEIPT => ['to_account_id', 'attach_hint_in', 'open_bills_in'], Voucher::PAYMENT => ['from_account_id', 'attach_hint', 'open_bills']] as $type => [$money, $attach, $bills]) {
            $xpath = $this->dom($this->screen($type));
            $card = $xpath->query('//*[@data-voucher-main]//section[@data-voucher-general]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $card, "⛔ {$type}: \"সাধারণ তথ্য\" কার্ড নেই");
            $this->assertContains(trim((string) $xpath->query('./h2', $card)->item(0)?->textContent), [trans('money.screen.general', [], 'bn'), trans('money.screen.general', [], 'en')],
                "⛔ {$type}: কার্ডের মাথায় \"সাধারণ তথ্য\" নেই");

            foreach ([
                'পক্ষের ধরন' => './/input[@name="party_type"]', 'পক্ষ' => './/*[@data-party-picker-cell]', 'তারিখ' => './/input[@name="trx_date"]',
                'টাকা' => './/*[@data-voucher-amount]', 'পদ্ধতি' => './/*[@data-voucher-money]//input[@name="instrument"]',
                'খাত' => './/*[@data-voucher-money]//select[@name="'.$money.'"]', 'উৎসে কাটা' => './/*[@data-voucher-withheld]',
            ] as $what => $path) {
                $this->assertGreaterThan(0, $xpath->query($path, $card)->length, "⛔ {$type}: {$what} \"সাধারণ তথ্য\" কার্ডে নেই");
            }

            // ⭐ নকশার "খাতে আছে ৳8,42,000 ✓" — যথেষ্ট থাকলে ✓, কম থাকলে লাল কারণ (টাকা দেওয়ার পর্দার একই লাইন)
            $this->assertSame('! short', $xpath->query('.//*[@data-account-balance]//*[@data-balance-ok]', $card)->item(0)?->getAttribute('x-show'),
                "⛔ {$type}: খাতের স্থিতির পাশে ✓ নেই — যথেষ্ট আছে কি না এক নজরে বোঝা যায় না");

            $open = $xpath->query('//section[@data-open-bills]')->item(0);
            $this->assertNull($xpath->query('ancestor::*[@data-voucher-general]', $open)->item(0), "⛔ {$type}: খোলা বিল \"সাধারণ তথ্য\" কার্ডের ভিতরে — নকশায় নিজের কার্ডে, তার পরে");
            $this->assertTrue($open->hasAttribute('data-boxed'), "⛔ {$type}: খোলা বিল নিজের কার্ডে নয়");
            $this->assertContains(trim((string) $xpath->query('.//h3', $open)->item(0)?->firstChild?->textContent), [trans('money.screen.'.$bills, [], 'bn'), trans('money.screen.'.$bills, [], 'en')],
                "⛔ {$type}: খোলা বিলের মাথা নকশার নয়");

            $details = $xpath->query('//section[@data-voucher-details]')->item(0);
            $this->assertContains(trim((string) $xpath->query('./h2', $details)->item(0)?->textContent), [trans('money.screen.attach_and_note', [], 'bn'), trans('money.screen.attach_and_note', [], 'en')],
                "⛔ {$type}: \"সংযুক্তি ও বিবরণ\" মাথা নেই");
            $this->assertContains(trim((string) $xpath->query('.//label[@for="attachment"]', $details)->item(0)?->textContent), [trans('money.screen.'.$attach, [], 'bn'), trans('money.screen.'.$attach, [], 'en')],
                "⛔ {$type}: সংযুক্তির নাম নকশার নয়");
        }
    }

    /** ১৭ — রসিদে নকশার পঞ্চম চিপ "চেক" চেকের খাতায় নিয়ে যায় (গৃহীত চেক রসিদে নয়, মালিকের নিয়ম); পরিশোধে চেক আসল পদ্ধতি */
    public function test_the_receipts_cheque_chip_leads_to_the_cheque_register(): void
    {
        $receipt = $this->dom($this->screen(Voucher::RECEIPT));
        $link = $receipt->query('//*[@data-method-chips]//a[@data-method-elsewhere="cheque"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $link, '⛔ রসিদে নকশার "চেক" চিপ নেই');
        $this->assertSame(route('accounts.cheque.create'), $link->getAttribute('href'), '⛔ রসিদের "চেক" চিপ চেকের খাতায় নিয়ে যায় না');
        $this->assertContains($link->getAttribute('title'), [trans('money.screen.cheque_elsewhere', [], 'bn'), trans('money.screen.cheque_elsewhere', [], 'en')],
            '⛔ রসিদের "চেক" চিপ কেন অন্য পাতায় নেয়, তা বলে না');
        $this->assertSame(0, $receipt->query('//input[@name="instrument"][@value="cheque"]')->length, '⛔ রসিদে চেক আবার পদ্ধতি — গৃহীত চেক কেবল চেকের খাতা দিয়ে');

        $payment = $this->dom($this->screen(Voucher::PAYMENT));
        $this->assertSame(0, $payment->query('//a[@data-method-elsewhere]')->length, '⛔ পরিশোধে চেক আবার লিংক — সেখানে চেক আসল পদ্ধতি');
        $this->assertSame(1, $payment->query('//input[@name="instrument"][@value="cheque"]')->length, '⛔ পরিশোধে চেক পদ্ধতি হারাল');
    }

    /** ১৮ — পদ্ধতির চিপ জায়গা না পেলে ভাঁজ হয়, খাতের ঘরে ঢোকে না (fieldset `min-w-0`; a6, আদায়ে ৩৬৩px চিপ বনাম ৩৩৫px অর্ধেক) */
    public function test_the_method_chips_wrap_inside_their_half_instead_of_pushing_into_the_account(): void
    {
        foreach ([
            'receipt' => route('accounts.voucher.create', ['type' => Voucher::RECEIPT]),
            'payment' => route('accounts.voucher.create', ['type' => Voucher::PAYMENT]),
            'supplier payment' => route('purchase.payment.create'),
        ] as $screen => $url) {
            $this->bank();
            $xpath = $this->dom((string) $this->get($url)->assertOk()->getContent());
            $fieldset = $xpath->query('//fieldset[@data-method-chips]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $fieldset, "দৃশ্যটাই ঠিক নয় — {$screen}: পদ্ধতির চিপ নেই");
            $this->assertContains('min-w-0', preg_split('/\s+/', $fieldset->getAttribute('class')),
                "⛔ {$screen}: পদ্ধতির fieldset-এ min-w-0 নেই — পাঁচ চিপ নিজের মাপে দাঁড়িয়ে খাতের ঘরে ঢুকে যায়");
            $row = $xpath->query('./div[@role="radiogroup"]', $fieldset)->item(0);
            $this->assertContains('flex-wrap', preg_split('/\s+/', (string) $row?->getAttribute('class')), "⛔ {$screen}: চিপের সারি ভাঁজ হয় না — জায়গা না পেলে কাটা পড়ে");
        }
    }

    /** ১৯ — রসিদের খাতায় শাখার নিজের কলাম, আর সারাংশে VDS-এর পাশে "যদি থাকে" (নকশা পাতা ২); পরিশোধে নয় (পাতা ৪) */
    public function test_the_receipts_ledger_has_a_branch_column_and_its_vds_row_says_if_any(): void
    {
        $receipt = $this->dom($this->screen(Voucher::RECEIPT));
        $this->assertSame(1, $receipt->query('//*[@data-voucher-ledger]//th[@data-ledger-branch-col]')->length, '⛔ রসিদের খাতায় শাখার কলাম নেই');
        $this->assertSame(1, $receipt->query('//*[@data-voucher-ledger]//td[@x-text="row.branch"]')->length, '⛔ রসিদের খাতার সারিতে শাখা নিজের ঘরে নয়');
        $tag = trim((string) $receipt->query('//*[@data-voucher-summary]//*[@data-sum-vds-tag]')->item(0)?->textContent);
        $this->assertContains($tag, [trans('money.screen.tag.if_any', [], 'bn'), trans('money.screen.tag.if_any', [], 'en')], '⛔ রসিদের VDS সারিতে "যদি থাকে" নেই');

        $payment = $this->dom($this->screen(Voucher::PAYMENT));
        $this->assertSame(0, $payment->query('//th[@data-ledger-branch-col]')->length, '⛔ পরিশোধের খাতায় শাখার কলাম — নকশা পাতা ৪-এ নেই');
    }

    /** ২০ — বোতামের সারি স্থির, বারের ঠিক উপরে (টাকা দেওয়ার পর্দার একই সারি); "পোস্টের পরে" লাইন নকশার হুবহু */
    public function test_the_button_row_stays_in_sight_and_says_what_comes_after_posting(): void
    {
        foreach ([Voucher::RECEIPT, Voucher::PAYMENT] as $type) {
            $bar = $this->dom($this->screen($type))->query('//*[@data-part="bar"]')->item(0);
            $classes = preg_split('/\s+/', (string) $bar?->getAttribute('class'));
            $this->assertContains('sticky', $classes, "⛔ {$type}: বোতামের সারি স্থির নয় — লম্বা পাতায় নিচে হারিয়ে যায়");
            $this->assertContains('md:bottom-(--spacing-status-bar)', $classes, "⛔ {$type}: বোতামের সারি নিচের বারের উপরে বসে না");
        }

        $this->assertSame('পোস্টের পরে: টাকার রসিদ (টাকা কথায়) ছাপা · PDF · WhatsApp', trans('accounts::message.after_post_receipt', [], 'bn'),
            '⛔ রসিদের "পোস্টের পরে" লাইন নকশার হুবহু নয়');
    }

    /** ২১ — রসিদের টাকার ঘরের নিচে "পুরো বকেয়া · নির্বাচিত বিল" (নকশা পাতা ২); পরিশোধে নেই (পাতা ৪) — আচরণ party-voucher.test.js-এ */
    public function test_the_receipt_offers_the_two_quick_fills_under_its_amount(): void
    {
        $receipt = $this->dom($this->screen(Voucher::RECEIPT));
        $fill = $receipt->query('//*[@data-voucher-general]//*[@data-amount-fill]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $fill, '⛔ রসিদের টাকার নিচে এক চাপের অঙ্ক নেই');
        $this->assertSame('fillWholeDue()', $receipt->query('.//button[@data-fill-due]', $fill)->item(0)?->getAttribute('x-on:click'), '⛔ "পুরো বকেয়া" অঙ্ক বসায় না');
        $this->assertSame('fillPickedBill()', $receipt->query('.//button[@data-fill-bill]', $fill)->item(0)?->getAttribute('x-on:click'), '⛔ "নির্বাচিত বিল" অঙ্ক বসায় না');
        $this->assertMatchesRegularExpression('/<form\b[^>]*@fill-amount="heardFill\(\$event\.detail\)"/', $this->screen(Voucher::RECEIPT),
            '⛔ এক চাপের অঙ্ক ফর্মের টাকায় পৌঁছায় না');

        $this->assertSame(0, $this->dom($this->screen(Voucher::PAYMENT))->query('//*[@data-amount-fill]')->length, '⛔ পরিশোধে এক চাপের অঙ্ক — নকশায় নেই');
    }

    /** ২২ — ⛔ সরবরাহকারী-পরিশোধের মাথায় কাঁচা চাবি ("money.screen.head.owed") — 'head' দুবার থাকায় শেষেরটা আগেরটাকে মুছত (1806c40b2 থেকে) */
    public function test_the_supplier_payment_head_reads_words_not_translation_keys(): void
    {
        $this->bank();
        $html = (string) $this->get(route('purchase.payment.create'))->assertOk()->getContent();
        $this->assertStringNotContainsString('money.screen.', $html, '⛔ সরবরাহকারী-পরিশোধের পাতায় অনুবাদ না হওয়া চাবি দেখা যায়');

        $xpath = $this->dom($html);
        $labels = array_map(fn (DOMElement $dt) => trim($dt->textContent), iterator_to_array($xpath->query('//*[@data-pay-head]//dt')));
        $this->assertContains($labels[0] ?? null, [trans('money.screen.head.owed', [], 'bn'), trans('money.screen.head.owed', [], 'en')], '⛔ মাথার প্রথম অঙ্কের নাম নেই');
        $this->assertContains($labels[1] ?? null, [trans('money.screen.head.this', [], 'bn'), trans('money.screen.head.this', [], 'en')], '⛔ মাথার দ্বিতীয় অঙ্কের নাম নেই');
    }

    /** ২৪ — বকেয়ার উত্তরে পক্ষের মালিক মডিউলের তিন কথা (Core চুক্তি; cb-ma-4): বাকির সীমা, BIN/TIN, প্রাপকের ব্যাংক */
    public function test_the_due_answer_carries_the_partys_credit_tax_ids_and_payee_bank(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $this->app->instance(\App\Core\Contracts\PartyCreditLimit::class, new class implements \App\Core\Contracts\PartyCreditLimit {
            public function creditOf(string $partyType, int $partyId): ?array { return ['limit' => '100000.00', 'used' => '25000.00', 'available' => '75000.00']; }
        });
        $this->app->instance(\App\Core\Contracts\PartyTaxIds::class, new class implements \App\Core\Contracts\PartyTaxIds {
            public function taxIdsOf(string $partyType, int $partyId): ?array { return ['bin' => '000123456-0101', 'tin' => '1234567890']; }
        });
        $this->app->instance(\App\Core\Contracts\PayeeBankView::class, new class implements \App\Core\Contracts\PayeeBankView {
            public function bankOf(string $partyType, int $partyId): ?array { return ['state' => 'pending', 'line' => 'সোনালী ব্যাংক · মতিঝিল · ••••4521']; }
        });

        $answer = $this->getJson(route('accounts.voucher.due', ['party_type' => 'customer', 'party_id' => $customer->id]))->assertOk()->json();

        $this->assertSame('100000.00', $answer['credit']['limit'] ?? null, '⛔ বকেয়ার উত্তরে বাকির সীমা নেই — রসিদের পিকারের নিচে আসবে না');
        $this->assertSame('000123456-0101', $answer['tax_ids']['bin'] ?? null, '⛔ বকেয়ার উত্তরে BIN/TIN নেই — পরিশোধে আসবে না');
        $this->assertSame('pending', $answer['payee_bank']['state'] ?? null, '⛔ বকেয়ার উত্তরে প্রাপকের ব্যাংক নেই — পরিশোধে লাইন আসবে না');
    }

    /** ২৫ — পর্দা: পিকারের নিচে বাকির সীমা আর BIN/TIN, পরিশোধে প্রাপকের ব্যাংকের লাইন আর লাল নিয়ম; রসিদে ব্যাংকের লাইন নেই */
    public function test_the_screens_show_credit_tax_ids_and_the_payee_bank_where_the_design_puts_them(): void
    {
        $receipt = $this->dom($this->screen(Voucher::RECEIPT));
        $hint = $receipt->query('//*[@data-party-name-cell]//*[@data-party-hint]')->item(0);
        $this->assertSame('creditText', $receipt->query('.//*[@data-party-credit]', $hint)->item(0)?->getAttribute('x-text'), '⛔ রসিদের পিকারের নিচে বাকির সীমা নেই');
        $this->assertSame('taxText', $receipt->query('.//*[@data-party-tax]', $hint)->item(0)?->getAttribute('x-text'), '⛔ পিকারের নিচে BIN/TIN নেই');
        $this->assertSame(0, $receipt->query('//*[@data-payee-bank-row]')->length, '⛔ রসিদে প্রাপকের ব্যাংকের লাইন — নকশায় কেবল পরিশোধে');

        $payment = $this->dom($this->screen(Voucher::PAYMENT));
        $row = $payment->query('//*[@data-voucher-general]//*[@data-payee-bank-row]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $row, '⛔ পরিশোধের "সাধারণ তথ্য"-তে প্রাপকের ব্যাংকের লাইন নেই');
        $this->assertSame('bankBlocks', $payment->query('.//*[@data-payee-bank-blocks]', $row)->item(0)?->getAttribute('x-show'),
            '⛔ সই-না-পাওয়া ব্যাংকে ট্রান্সফারের লাল নিয়ম নেই');
        $said = trim((string) $payment->query('.//*[@data-payee-bank-blocks]', $row)->item(0)?->textContent);
        $this->assertContains($said, [trans('money.screen.payee_bank_unsigned', [], 'bn'), trans('money.screen.payee_bank_unsigned', [], 'en')], '⛔ লাল নিয়মের কথা নিজের নয়');
    }

    /** ২৬ — "শাখা: গ্রাহকের শাখা (…)" কেবল তথ্য; "পোস্ট হবে" খাতার নিজের শাখা — বাক্সের (মালিকের ১৯ অক্টোবরের নিয়ম; cb-ma-4) */
    public function test_the_head_tells_the_partys_branch_and_where_the_money_posts(): void
    {
        $bank = $this->bank();
        // ⓘ ডেমোর গ্রাহকের শাখা বসানো থাকে না — দাবির জন্য একটা বসানো (কোম্পানির মূল শাখা)
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $branch = $this->company->defaultBranch();
        $customer->forceFill(['branch_id' => $branch->id])->save();

        $due = $this->getJson(route('accounts.voucher.due', ['party_type' => 'customer', 'party_id' => $customer->id]))->assertOk()->json();
        $this->assertSame($branch->name(), $due['party_branch'] ?? null, '⛔ বকেয়ার উত্তরে পক্ষের নিজের শাখা নেই — মাথার "গ্রাহকের শাখা" আসবে না');

        $answer = $this->postJson(route('accounts.voucher.simulate'), [
            'type' => Voucher::RECEIPT, 'narration' => 'শাখার দাবি', 'trx_date' => now()->toDateString(), 'amount' => '100',
            'instrument' => 'transfer', 'instrument_no' => 'BR-1', 'party_type' => 'customer', 'party_id' => $customer->id, 'to_account_id' => $bank->id,
        ])->assertOk()->json();
        $this->assertNotEmpty($answer['rows'] ?? [], 'দৃশ্যটাই ঠিক নয় — প্রিভিউ খালি: '.json_encode($answer, JSON_UNESCAPED_UNICODE));
        $this->assertSame($answer['rows'][0]['branch'] ?? null, $answer['branch'] ?? null, '⛔ "পোস্ট হবে" খাতার নিজের শাখা নয় — মাথা আর খাতা দুই শাখা বলে');

        $html = $this->screen(Voucher::RECEIPT);
        $this->assertMatchesRegularExpression('/data-voucher-meta.*?data-voucher-branch.*?x-text="partyBranchText".*?x-text="postBranchText"/s', $html,
            '⛔ মাথার তথ্যের সারিতে পক্ষের শাখা আর "পোস্ট হবে" নেই');
    }

    /**
     * ২৭ — নকশার তিন লাইন সত্যি করে (cb-ma-4): রসিদে "বাউন্স চিহ্ন দিলে নিজে উল্টাবে" চেকের খাতায় নিয়ে যায়; পরিশোধে "VDS সার্টিফিকেট তালিকা"
     * করণীয়ে নিয়ে যায়; হাতে লেখা AIT/VDS-এ হারের ব্যাজ নয়; সরবরাহকারী-পরিশোধ আর অস্তিত্বহীন "অ্যাডভাইস (ইমেইল/PDF)" বলে না
     */
    public function test_the_designs_promises_point_at_what_really_happens(): void
    {
        $receipt = $this->dom($this->screen(Voucher::RECEIPT));
        $bounce = $receipt->query('//*[@data-voucher-controls]//*[@data-control="bounce"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $bounce, '⛔ রসিদের নিয়ন্ত্রণে নকশার বাউন্সের লাইন নেই');
        $this->assertSame(route('accounts.cheque.index'), $receipt->query('.//a[@data-bounce-register]', $bounce)->item(0)?->getAttribute('href'),
            '⛔ বাউন্সের লাইন চেকের খাতায় নিয়ে যায় না — গৃহীত চেক সেখানেই উল্টায়');
        $this->assertSame(0, $receipt->query('//*[@data-after-post-vds]')->length, '⛔ রসিদে VDS সার্টিফিকেটের লিংক — পক্ষের কাটা আমাদের সনদ নয়');

        $paymentHtml = $this->screen(Voucher::PAYMENT);
        $payment = $this->dom($paymentHtml);
        $this->assertSame(route('accounts.obligation.index'), $payment->query('//*[@data-after-post]//a[@data-after-post-vds]')->item(0)?->getAttribute('href'),
            '⛔ পরিশোধের "পোস্টের পরে" লাইনে VDS সার্টিফিকেট তালিকার লিংক নেই');
        $this->assertSame(0, $payment->query('//*[@data-control="bounce"]')->length, '⛔ পরিশোধে গৃহীত চেকের বাউন্সের লাইন');
        foreach (['ait', 'vds'] as $tag) {
            $said = array_filter(['bn', 'en'], fn (string $l) => str_contains($paymentHtml, e(trans('money.screen.tag.'.$tag, [], $l))));
            $this->assertSame([], $said, "⛔ পরিশোধ ভাউচারে হাতে লেখা {$tag}-এর পাশে হারের ব্যাজ — হার থেকে গোনা নয়, মিথ্যা");
        }

        $this->bank();
        $supplier = (string) $this->get(route('purchase.payment.create'))->assertOk()->getContent();
        $this->assertStringNotContainsString('অ্যাডভাইস (সরবরাহকারীকে ইমেইল/PDF)', $supplier, '⛔ সরবরাহকারী-পরিশোধ এখনো এমন অ্যাডভাইসের কথা বলে যা ব্যবস্থায় নেই');
        $this->assertStringNotContainsString('payment advice (email/PDF', $supplier, '⛔ সরবরাহকারী-পরিশোধ এখনো এমন অ্যাডভাইসের কথা বলে যা ব্যবস্থায় নেই');
    }

    /** ২৩ — নতুন নাম আর মোবাইল পাশাপাশি কেবল ঘরের নিজের জায়গায় কুলালে; সরু পক্ষের ঘরে (১৩৬৬-এ ~২৪০px) একটার নিচে আরেকটা */
    public function test_the_new_name_and_mobile_stack_in_a_narrow_party_cell(): void
    {
        foreach ([Voucher::RECEIPT, Voucher::PAYMENT] as $type) {
            $xpath = $this->dom($this->screen($type));
            $box = $xpath->query('//*[@data-party-picker-cell]//*[@data-party-new]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $box, "দৃশ্যটাই ঠিক নয় — {$type}: নতুন নামের ঘর নেই");
            $this->assertContains('@container', preg_split('/\s+/', $box->getAttribute('class')), "⛔ {$type}: নতুন নামের ঘর নিজের চওড়া মাপে না — পর্দার মাপে দুই কলাম হয়");

            $grid = preg_split('/\s+/', (string) $xpath->query('./div', $box)->item(0)?->getAttribute('class'));
            $this->assertContains('@xs:grid-cols-2', $grid, "⛔ {$type}: জায়গা থাকলেও নাম আর মোবাইল পাশাপাশি নয়");
            $this->assertNotContains('sm:grid-cols-2', $grid, "⛔ {$type}: সরু পক্ষের ঘরেও নাম আর মোবাইল পাশাপাশি — \"নতুন দোকান রহিম\" কাটা পড়ে");
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
