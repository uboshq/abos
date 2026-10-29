<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * পর্দাটা সত্যিই আঠারোটা প্রশ্ন করে কি না।
 *
 * ── ⭐ কেন এটা আলাদা পরীক্ষা ─────────────────────────────────────────
 * [[MoneyMovementHasEveryFieldTheOwnerAskedForTest]] দেখে **কম্পোনেন্টে**
 * ঘরগুলো আছে কি না। ⓘ কিন্তু কম্পোনেন্ট থাকা আর **পর্দায় আসা** দুইটা
 * আলাদা সত্য — মাঝখানে কন্ট্রোলার, রুট, অনুমতি আর লেআউট।
 *
 * ⛔ আর ঠিক ঐ মাঝখানেই আজ একটা ভুল হয়েছিল: কম্পোনেন্ট বসানোর পর
 * `carriers` ও `transferModes` কন্ট্রোলার থেকে পাঠানো হয়নি। ⚠️ ফল হত
 * দুইটা **খালি ড্রপডাউন** — দেখা যেত, ভিতরে কিছু থাকত না, আর কোনো
 * ত্রুটিও আসত না, কারণ কম্পোনেন্টের ডিফল্ট `[]`।
 *
 * ── ⭐ ২১ অক্টোবর ২০২৬: মালিকের রায় — আঠারো নয়, পদ্ধতির নিজের ঘর ─────────────
 * মালিক ভাউচারের ছবি দেখিয়ে: *"এতগুলো দেওয়া কি int. standard?"* — নমুনা দেখে: *"ok ekoni kaj kore live e daw"*।
 * ⭐ এখন পর্দা টাকার বাক্স ([[x-money.method]]) — প্রতিটা পদ্ধতি কেবল নিজের ঘর চায়; মালিকের বাদ দেওয়া দশটা ঘর আর নেই,
 * কলামগুলো থাকে ([[MoneyMovementHasEveryFieldTheOwnerAskedForTest]])। "কার মাধ্যমে" থাকে — মালিকের আলাদা আদেশ।
 * ⛔ দাবি দুর্বল করা নয়: মালিকের রায়ে প্রশ্নের তালিকা বদলেছে; পাহারা দুই দিকেই — যা চাই তা আছে, যা বাদ তা ফেরেনি।
 *
 * ── ⚠️ আর কার্ল দিয়ে এটা যাচাই করা যায় না ────────────────────────────
 * প্রথমে `curl` দিয়ে পাতাটা টেনে দেখা হয়েছিল — **HTTP ২০০** এল, আর
 * একটাও ঘর পাওয়া গেল না। ⓘ কারণ কার্ল লগইন করা নয়, তাই ২০০-টা ছিল
 * **লগইন পাতার**। ⭐ সবুজ সংখ্যাটা সত্য ছিল, কেবল অন্য প্রশ্নের উত্তর।
 */
final class TheReceiptFormAsksAllEighteenTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> রসিদের পর্দা যা চায় — টাকার বাক্স (নগদ, MFS, ট্রান্সফার, নগদ জমা) আর "কার মাধ্যমে" */
    private const FIELDS = [
        'carried_by', 'instrument', 'note_counts',
        'instrument_no', 'charge_amount', 'transfer_mode_id', 'value_date', 'deposit_slip_no',
    ];

    /** @var list<string> মালিক ২১ অক্টোবর যা বাদ দিয়েছেন — কলাম থাকে, পর্দায় নয় */
    private const DROPPED = [
        'moved_at', 'wallet', 'wallet_medium', 'counterparty_phone', 'charge_borne_by',
        'from_branch', 'from_account_name', 'from_account_no', 'lands_on',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * ⛔ রসিদ যা চায় তা পর্দায় আছে।
     */
    public function test_the_receipt_form_asks_every_one_of_them(): void
    {
        $html = $this->get(route('accounts.voucher.create', ['type' => 'receipt']))
            ->assertOk()
            ->getContent();

        $this->assertIsString($html);

        $missing = [];

        foreach (self::FIELDS as $field) {
            if (! str_contains($html, 'name="'.$field.'"') && ! str_contains($html, 'name="'.$field.'[')) {
                $missing[] = $field;
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            '⛔ রসিদের ফর্মে এই ঘরগুলো নেই:',
            '',
            '⚠️ কম্পোনেন্টে থাকা আর পর্দায় আসা দুইটা আলাদা সত্য।',
            '',
            ...$missing,
        ]));
    }

    /**
     * ⭐ মালিকের ২১ অক্টোবরের রায়: বাদ দেওয়া দশটা ঘর রসিদ, পরিশোধ আর খরচের নতুন ফর্মে আর নেই।
     */
    public function test_the_ten_fields_the_owner_dropped_are_not_asked_again(): void
    {
        foreach (['receipt', 'payment', 'expense'] as $type) {
            $html = (string) $this->get(route('accounts.voucher.create', ['type' => $type]))->assertOk()->getContent();
            $asked = array_values(array_filter(self::DROPPED, fn (string $f) => str_contains($html, 'name="'.$f.'"')));

            $this->assertSame([], $asked, "⛔ {$type}: মালিকের বাদ দেওয়া ঘর আবার চাওয়া হচ্ছে — ".implode(', ', $asked));
        }

        $payment = (string) $this->get(route('accounts.voucher.create', ['type' => 'payment']))->assertOk()->getContent();
        foreach (['instrument_date', 'from_bank'] as $chequeField) {
            $this->assertStringContainsString('name="'.$chequeField.'"', $payment, "⛔ পরিশোধের চেকে {$chequeField} নেই");
        }
        $this->assertStringContainsString('name="carried_by"', $payment, '⛔ পরিশোধে "কার মাধ্যমে" নেই — মালিকের আলাদা আদেশ');
    }

    /**
     * ⭐ দুইটা তালিকা সত্যিই ভরা — খালি ড্রপডাউন নয়।
     *
     * ⛔ এটাই আজকের ভুলটা ছিল: ঘর দুইটা এল, ভিতরে কিছু নেই। ⓘ পর্দা
     * দেখতে ঠিক, আর ব্যবহারকারী বেছে নিতেই পারেন না — কোনো ত্রুটি ছাড়া।
     */
    public function test_the_two_lists_are_not_empty(): void
    {
        $html = (string) $this->get(route('accounts.voucher.create', ['type' => 'receipt']))
            ->assertOk()
            ->getContent();

        /*
         * ⓘ বাহকের তালিকায় অন্তত ডেমোর ব্যবহারকারীরা থাকার কথা, আর
         * ট্রান্সফার মোডে BEFTN/RTGS — দুইটাই সিডারে বসানো।
         */
        foreach (['carried_by', 'transfer_mode_id'] as $name) {
            $at = strpos($html, 'name="'.$name.'"');
            $this->assertNotFalse($at, "{$name} ঘরটাই নেই।");

            $chunk = substr($html, $at, 4000);
            $end = strpos($chunk, '</select>');
            $this->assertNotFalse($end, "{$name} একটা select নয়।");

            $options = substr_count(substr($chunk, 0, $end), '<option');

            $this->assertGreaterThan(1, $options, implode("\n", [
                "{$name} ড্রপডাউনে কেবল {$options}টা বিকল্প — কার্যত খালি।",
                '',
                '⛔ কন্ট্রোলার তালিকাটা পাঠায়নি, আর কম্পোনেন্টের ডিফল্ট `[]` বলে',
                'কোনো ত্রুটিও আসেনি। ⓘ পর্দা দেখতে ঠিক, বাছাই করা যায় না।',
            ]));
        }
    }

    /**
     * পাঁচটা মাধ্যমই বাছা যায়, আর নামগুলো ব্যবস্থার নিজের।
     *
     * ⚠️ আজ কম্পোনেন্টে `online` লেখা হয়েছিল যেখানে ব্যবস্থার নাম
     * `transfer` — ⛔ ভ্যালিডেশন ওটা ফিরিয়ে দিত, আর ব্যবহারকারী বুঝতেন
     * না কেন ব্যাংক ট্রান্সফারের রসিদ কিছুতেই সেভ হচ্ছে না।
     */
    public function test_the_new_form_offers_four_ways_and_hides_the_card(): void
    {
        $html = (string) $this->get(route('accounts.voucher.create', ['type' => 'receipt']))
            ->assertOk()
            ->getContent();

        foreach (['cash', 'mfs', 'transfer', 'deposit'] as $way) {
            $this->assertStringContainsString('value="'.$way.'"', $html,
                "মাধ্যম '{$way}' পর্দায় নেই, অথচ ভ্যালিডেশন সেটা মানে।");
        }

        /*
         * ⚠️ বদলানো দাবি, ২৬ সেপ্টেম্বর ২০২৬: আগে রসিদে চারটা চিপ ছিল,
         * চেকসহ। ⭐ মালিকের নিয়মে গৃহীত চেক ঢোকে কেবল চেকের খাতা দিয়ে,
         * আর সার্ভার রসিদে চেক ফিরিয়ে দেয় — তাই পর্দাও আর দেখায় না।
         * ⓘ পরিশোধে (নিজের দেওয়া চেক) চিপটা আছে।
         */
        $this->assertStringNotContainsString('value="cheque"', $html,
            'রসিদের নতুন ফর্মে চেকের চিপ দেখা যাচ্ছে — সার্ভার তো ওটা ফিরিয়ে দেবে।');

        $this->assertStringContainsString('value="cheque"',
            (string) $this->get(route('accounts.voucher.create', ['type' => 'payment']))->assertOk()->getContent(),
            'পরিশোধের ফর্ম থেকেও চেক হারিয়েছে — নিয়মটা কেবল গৃহীত চেকের।');

        /*
         * ⛔ কার্ড নতুন ভাউচারে দেখানো হয় না — নকশায় চারটা চিপ।
         *
         * ⚠️ আগে এই দাবিটা পাঁচটাই খুঁজত, আর **চিরকাল লাল ছিল**। ⓘ সে
         * কোডের ভুল ধরছিল না, ধরছিল নিজের ভুল প্রত্যাশা — আর লাল হয়ে
         * বসে থাকায় এই ফাইলের বাকি দাবিগুলোর দিকে কেউ তাকায়নি।
         */
        $this->assertStringNotContainsString('value="card"', $html,
            'নতুন ভাউচারে কার্ডের চিপটা দেখা যাচ্ছে।');
    }

    /**
     * ⭐ তবু `card` ধ্রুবকে থেকে যায়, আর সেটাই আসল চুক্তি।
     *
     * ── ⛔ ১৪ সেপ্টেম্বর ২০২৬-এর ভুলটা ─────────────────────────────────
     * পুরনো ভাউচারে `card` বসা আছে। ⓘ ধ্রুবক থেকে সরালে `Rule::in`
     * ওগুলোকে **সম্পাদনা করতে দিত না** — কাগজটা খোলা যেত, সংরক্ষণ নয়।
     *
     * ⚠️ তাই লুকানোটা কেবল **নতুন** ভাউচারে। যে ভাউচার ইতিমধ্যে কার্ড,
     * তার চিপটা ফিরে আসে, নাহলে সম্পাদনা করলেই মাধ্যমটা হারাত।
     */
    public function test_a_voucher_already_on_card_still_shows_the_chip(): void
    {
        $this->assertContains('card', Voucher::INSTRUMENTS,
            'কার্ড ধ্রুবক থেকে সরানো হয়েছে — পুরনো ভাউচার আর সম্পাদনা করা যাবে না।');

        /*
         * ⓘ ভাউচারটা এখানেই বানানো হয়, খুঁজে নেওয়া হয় না — আর খসড়া,
         * কারণ পোস্ট হওয়া ভাউচারের সম্পাদনা ৪০৩ দেয়
         * ([[VoucherController::assertEditable]])। ⚠️ ওটা ভুলে গেলে
         * দাবিটা ব্যর্থ হত **চিপের কারণে নয়, দরজার কারণে**।
         *
         * ⚠️ প্রথমে ডেমো থেকে একটা তুলে আনার চেষ্টা হয়েছিল, আর দুইবারই
         * **"সারি পাওয়া গেল না"** — কারণ [[DemoSeeder]] একটাও ভাউচার
         * বানায় না। ⛔ ডেমোর গড়নের উপর দাঁড়ালে দাবিটা সিডার বদলানোর
         * দিনই মরত, আর মরার কারণটা হত "ডেটা নেই", "চিপ নেই" নয় —
         * অর্থাৎ ভুল জায়গায় আঙুল।
         */
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        $voucher = Voucher::create([
            'company_id' => $company->id,
            'branch_id' => $company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()
                ->where('is_current', true)
                ->orderByDesc('starts_on')
                ->firstOrFail()->id,
            'type' => Voucher::RECEIPT,
            'document_no' => 'RV-CARD-TEST',
            'trx_date' => now()->toDateString(),
            'narration' => 'পুরনো কার্ড-ভাউচার',
            'amount' => '100.0000',
            'instrument' => 'card',
            'status' => DocumentStatus::DRAFT,
        ]);

        $html = (string) $this->get(route('accounts.voucher.edit', $voucher))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="card"', $html,
            'কার্ডে বসা ভাউচার সম্পাদনায় চিপটা নেই — সংরক্ষণ করলে মাধ্যমটা হারাবে।');
    }

    /**
     * ⓘ প্রেরক/প্রাপকের মোবাইল (counterparty_phone) মালিকের ২১ অক্টোবরের রায়ে বাদ — আগে এখানে দাবি ছিল লেখাটা রসিদে
     * "প্রেরকের" আর পরিশোধে "প্রাপকের"। ⭐ এখন দাবি: দুই ফর্মের কোনোটাতেই ঘরটা নেই, কিন্তু পুরনো কাগজের মানটা কলামে থাকে।
     */
    public function test_the_phone_is_no_longer_asked_but_an_old_value_stays(): void
    {
        foreach (['receipt', 'payment'] as $type) {
            $this->assertStringNotContainsString('name="counterparty_phone"',
                (string) $this->get(route('accounts.voucher.create', ['type' => $type]))->assertOk()->getContent(),
                "⛔ {$type}: মালিকের বাদ দেওয়া মোবাইলের ঘর আবার চাওয়া হচ্ছে");
        }

        $this->assertContains('counterparty_phone', (new Voucher)->getFillable(), '⛔ পুরনো মোবাইলের মান আর রাখা যায় না — পুরনো ফোনের পাঠানো তথ্য হারাবে');
    }
}
