<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\MoneyMethod;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ পদ্ধতি আগে, তারপর কেবল সেই পদ্ধতির খাত (মালিকের প্রশ্ন, ২৪ অক্টোবর ২০২৬, ছবিসহ: "Paid into: 1107 — Roket" আর "Method: Cash /
 * MFS / …" — *"কোনটা আগে থাকে আর কোনটা পরে, international standard অনুযায়ী?"*; fe: SAP/Oracle-এর payment method → house bank)।
 *
 * ⛔ আগে খাত আগে ছিল, আর "Roket"-এর মতো MFS খাত বেছে "নগদ" চাপা যেত — দিনশেষে ড্রয়ারে কম, MFS-এ এমন টাকা যা কখনো আসেনি।
 *
 * দাবি:
 *   ১. ক্রম পাতায়: পদ্ধতি → খাত → রেফারেন্স → চার্জ — রসিদ, পরিশোধ আর সরবরাহকারী-পরিশোধে (Tab-এর ক্রমও এটাই, কারণ ক্রম DOM-এ)
 *   ২. MFS বাছলে পাতার তালিকায় নগদ বা ব্যাংকের একটাও খাত নেই — ধূসরও নয় (fe)
 *   ৩. পদ্ধতির একটাই খাত থাকলে সেটা নিজে বসে
 *   ৪. পদ্ধতির একটাও খাত না থাকলে তালিকার জায়গায় বাংলায় কারণ
 *   ৫. ভুল জোড়া পাঠালে সার্ভার ফেরায়, নিজের বার্তায় — ভাউচারে আর সরবরাহকারী-পরিশোধে
 *   ৬. পর্দা আর সার্ভার একই নিয়ম পড়ে ([[MoneyMethod::KIND_OF]] = [[money.js]] `KIND_OF`)
 * ⓘ পদ্ধতি বদলালে তালিকা নতুন করে আঁকা, আগের খাত থাকা বা সরা আর জমার বোতাম বন্ধ — আসল CSP-Alpine-এ, money-account.test.js।
 */
final class TheMethodComesFirstAndOffersOnlyItsAccountsTest extends TestCase
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

    public function test_the_ground_is_there(): void
    {
        $this->assertNotNull($this->cash(), 'প্রস্তুতিটাই ভুল — নগদের খাত নেই');
        $this->assertNotNull($this->bank(), 'প্রস্তুতিটাই ভুল — ব্যাংক খাত নেই');
        $this->assertNotNull($this->mfs(), 'প্রস্তুতিটাই ভুল — MFS খাত নেই');
    }

    /** ১ — পদ্ধতি → খাত → রেফারেন্স → চার্জ */
    public function test_the_method_comes_before_the_account_on_every_money_screen(): void
    {
        $this->bank();
        $this->mfs();

        foreach ([
            'receipt' => [route('accounts.voucher.create', ['type' => Voucher::RECEIPT]), 'to_account_id'],
            'payment' => [route('accounts.voucher.create', ['type' => Voucher::PAYMENT]), 'from_account_id'],
            'supplier payment' => [route('purchase.payment.create'), 'account_id'],
        ] as $screen => [$url, $name]) {
            $html = (string) $this->get($url)->assertOk()->getContent();

            $method = strpos($html, 'name="instrument"');
            $account = preg_match('/<select\b(?=[^>]*\bname="'.$name.'")(?=[^>]*\bdata-money-account\b)[^>]*>/', $html, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : false;
            $reference = strpos($html, 'data-method-block=');

            $this->assertNotFalse($method, "⛔ {$screen}: পদ্ধতির চিপ নেই");
            $this->assertNotFalse($account, "⛔ {$screen}: পদ্ধতির বাক্সে খাতের ঘর নেই");
            $this->assertNotFalse($reference, "⛔ {$screen}: পদ্ধতির ঘর (রেফারেন্স, চার্জ) নেই");
            $this->assertTrue($method < $account && $account < $reference,
                "⛔ {$screen}: ক্রম পদ্ধতি → খাত → রেফারেন্স → চার্জ নয় (পদ্ধতি {$method}, খাত {$account}, রেফারেন্স {$reference})");
            // ⓘ জমা যায় এমন ঘর গোনা — রসিদের পুরনো নমুনা-বিন্যাসের ঘর `hidden` আর `disabled` হয়ে আঁকা থাকে, জমা যায় না (cb-ma-1)
            $this->assertSame(1, preg_match_all('/<select\b(?=[^>]*\bname="'.$name.'")(?![^>]*\bdisabled\b)[^>]*>/', $html),
                "⛔ {$screen}: জমা যায় এমন খাতের ঘর দুবার — হোস্টের পুরনো ঘরটা রয়ে গেছে");
        }
    }

    /** ২ — MFS বাছলে তালিকায় কেবল MFS-এর খাত */
    public function test_with_mfs_chosen_no_cash_or_bank_account_is_in_the_list(): void
    {
        $this->bank();
        $mfs = $this->mfs();
        $this->secondMfs();

        foreach ([Voucher::RECEIPT => 'to_account_id', Voucher::PAYMENT => 'from_account_id'] as $type => $name) {
            $kinds = $this->listed($this->screenWith($type, ['instrument' => 'mfs']), $name);

            // ⓘ চাবি ধরে — PHP সংখ্যার মতো চাবি ("690") পূর্ণসংখ্যা করে, আর assertContains কড়া তুলনা করে (cb-ma-1-এ এতেই লাল হয়েছিল)
            $this->assertArrayHasKey((int) $mfs->id, $kinds, "দৃশ্যটাই ঠিক নয় — {$type}: MFS-এর খাতই তালিকায় নেই");
            $this->assertSame(['mfs'], array_values(array_unique($kinds)),
                "⛔ {$type}: MFS বাছার পরেও তালিকায় নগদ বা ব্যাংকের খাত আছে: ".json_encode($kinds));
        }

        $start = $this->listed((string) $this->get(route('accounts.voucher.create', ['type' => Voucher::PAYMENT]))->assertOk()->getContent(), 'from_account_id');
        $this->assertNotSame([], $start, 'দৃশ্যটাই ঠিক নয় — নতুন পরিশোধে কোনো খাতই নেই');
        $this->assertCount(1, array_unique($start), '⛔ নতুন পরিশোধের প্রথম পদ্ধতির তালিকায় একাধিক ধরনের খাত: '.json_encode($start));
    }

    /** ৩ — একটাই খাত থাকলে নিজে বসে */
    public function test_the_only_account_of_a_method_is_picked_by_itself(): void
    {
        $mfs = $this->mfs();
        Account::query()->where('money_kind', Account::MFS)->whereKeyNot($mfs->id)->update(['is_active' => false]);

        $html = $this->screenWith(Voucher::RECEIPT, ['instrument' => 'mfs']);

        $this->assertMatchesRegularExpression('/<option value="'.$mfs->id.'"[^>]*\bselected\b/', $html,
            '⛔ MFS-এর একটাই খাত, অথচ নিজে বসল না — মানুষকে একটা ঘর খামোখা বাছতে হত');
    }

    /** ৪ — একটাও খাত না থাকলে বাংলায় কারণ */
    public function test_a_method_without_any_account_says_why_in_bengali(): void
    {
        Account::query()->where('money_kind', Account::MFS)->update(['is_active' => false]);

        $html = $this->screenWith(Voucher::RECEIPT, ['instrument' => 'mfs']);

        $this->assertSame([], $this->listed($html, 'to_account_id'), '⛔ MFS-এর খাত নেই, অথচ তালিকায় অন্য খাত');
        $bn = e(trans('money.account.none', ['method' => trans('money.method.mfs', [], 'bn')], 'bn'));
        $en = e(trans('money.account.none', ['method' => trans('money.method.mfs', [], 'en')], 'en'));
        $this->assertSame('এই কোম্পানিতে MFS-এর কোনো খাত নেই — হিসাবরক্ষককে বলুন।', trans('money.account.none', ['method' => 'MFS'], 'bn'),
            '⛔ বাংলা কারণটা বদলেছে (fe-র লেখা)');
        $this->assertTrue(str_contains($html, $bn) || str_contains($html, $en), '⛔ খাত নেই, অথচ পাতায় কারণ লেখা নেই');
        $this->assertMatchesRegularExpression('/<p\b[^>]*data-account-none\b(?![^>]*display: none)[^>]*>/', $html, '⛔ কারণের লাইন লুকানো');
    }

    /** ৫ক — ভাউচার: MFS বেছে নগদের খাত পাঠালে ফেরে */
    public function test_a_receipt_with_mfs_into_a_cash_account_is_refused(): void
    {
        $cash = $this->cash();
        $before = Voucher::query()->count();

        $response = $this->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), [
            'type' => Voucher::RECEIPT, 'narration' => 'ভুল জোড়া', 'trx_date' => now()->toDateString(), 'amount' => '1000',
            'party_type' => 'customer', 'party_id' => Customer::query()->orderBy('id')->value('id'),
            'to_account_id' => $cash->id, 'instrument' => 'mfs', 'instrument_no' => 'TRXWRONGPAIR1',
        ]);

        $this->assertSame($before, Voucher::query()->count(), '⛔ MFS বেছে নগদের খাতে রসিদ জমা হয়ে গেল');
        $said = $this->refusal($response, 'instrument');
        $expected = array_map(fn (string $locale) => trans('accounts::validation.way_does_not_fit_account', [
            'way' => trans('accounts::instrument.mfs', [], $locale), 'account' => $cash->label($locale),
        ], $locale), ['bn', 'en']);
        $this->assertContains($said, $expected, '⛔ রসিদ ফিরল, কিন্তু পদ্ধতি-খাতের অমিলে নয়: '.$said);
    }

    /** ৫খ — সরবরাহকারী-পরিশোধ: MFS বেছে ব্যাংকের খাত পাঠালে ফেরে */
    public function test_a_supplier_payment_with_mfs_from_a_bank_account_is_refused(): void
    {
        $bank = $this->bank();
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();

        try {
            app(PaymentService::class)->create([
                'supplier_id' => $supplier->id, 'account_id' => $bank->id, 'trx_date' => now()->toDateString(), 'amount' => '500',
                'instrument' => 'mfs', 'instrument_no' => 'TRXWRONGPAIR2',
            ], []);
            $this->fail('⛔ MFS বেছে ব্যাংকের খাত থেকে সরবরাহকারী-পরিশোধ জমা হয়ে গেল');
        } catch (ValidationException $e) {
            $said = $e->errors()['account_id'][0] ?? json_encode($e->errors());
        }

        $expected = array_map(fn (string $locale) => trans('money.validation.account_not_for_method', [
            'account' => $bank->label(), 'method' => trans('money.method.mfs', [], $locale), 'kind' => trans('money.kind.mfs', [], $locale),
        ], $locale), ['bn', 'en']);
        $this->assertContains($said, $expected, '⛔ পরিশোধ ফিরল, কিন্তু পদ্ধতি-খাতের অমিলে নয়: '.$said);
        $this->assertSame(0, DB::table('pur_payments')->where('instrument_no', 'TRXWRONGPAIR2')->count(), '⛔ ফেরানো পরিশোধের সারি রয়ে গেল');
    }

    /** ৬ — পর্দা আর সার্ভার একই নিয়ম */
    public function test_the_screen_and_the_server_read_the_same_kinds(): void
    {
        $js = File::get(resource_path('js/components/money.js'));
        $this->assertSame(1, preg_match('/export const KIND_OF = \{([^}]*)\}/', $js, $m), '⛔ money.js-এ KIND_OF নেই');
        preg_match_all("/(\\w+): '(\\w+)'/", $m[1], $pairs, PREG_SET_ORDER);
        $screen = collect($pairs)->mapWithKeys(fn (array $p) => [$p[1] => $p[2]])->all();

        $this->assertSame(MoneyMethod::KIND_OF, $screen, '⛔ পর্দা আর সার্ভারের পদ্ধতি-খাতের নিয়ম আলাদা — পর্দা যা দেখায় সার্ভার তা ফেরাত');
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /** আগের জমার মান নিয়ে পাতা — ভুল নিয়ে ফেরার মতো, `old('instrument')` */
    private function screenWith(string $type, array $old): string
    {
        return (string) $this->withSession(['_old_input' => $old])
            ->get(route('accounts.voucher.create', ['type' => $type]))->assertOk()->getContent();
    }

    /** @return array<string, string> তালিকায় আঁকা খাত — id => ধরন */
    private function listed(string $html, string $name): array
    {
        // ⓘ পদ্ধতির বাক্সের ঘর (`data-money-account`) — রসিদে একই নামের পুরনো, লুকানো আর বন্ধ ঘরও আঁকা থাকে (cb-ma-1)
        $this->assertSame(1, preg_match('/<select\b(?=[^>]*\bname="'.$name.'")(?=[^>]*\bdata-money-account\b)[^>]*>(.*?)<\/select>/s', $html, $select),
            "দৃশ্যটাই ঠিক নয় — {$name}-এর বাক্সের ঘর নেই");
        preg_match_all('/<option value="(\d+)"[^>]*data-kind="([a-z]*)"/', $select[1], $rows, PREG_SET_ORDER);

        return collect($rows)->mapWithKeys(fn (array $r) => [$r[1] => $r[2]])->all();
    }

    /** ⓘ পরীক্ষায় json সেশন ভুলগুলো অ্যারে করে রাখে — দুই রূপই পড়া (json-session-turns-errors-into-an-array-in-tests) */
    private function refusal(\Illuminate\Testing\TestResponse $response, string $field): string
    {
        $errors = $response->getSession()?->get('errors');
        $messages = is_array($errors) ? ($errors['default']['messages'][$field] ?? []) : ($errors?->getBag('default')->get($field) ?? []);

        $this->assertNotEmpty($messages, "⛔ {$field}-এ কোনো ভুলের কথা নেই: ".json_encode($errors));

        return (string) $messages[0];
    }

    private function cash(): ?Account
    {
        return Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->first();
    }

    /** ⓘ ডেমোর ছকে ব্যাংক খাত না থাকলে বানানো ([[TheReceiptAndPaymentFollowTheFinalDesignTest::bank()]]-এর একই পথ) */
    private function bank(): Account
    {
        return Account::query()->where('money_kind', Account::BANK)->postable()->active()->orderBy('code')->first()
            ?? app(AccountService::class)->create([
                'code' => StandardChart::BANK.'-01',
                'name_en' => 'Test bank account',
                'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'),
            ]);
    }

    private function mfs(): Account
    {
        return Account::query()->where('money_kind', Account::MFS)->postable()->active()->orderBy('code')->first()
            ?? Account::query()->create([
                'company_id' => $this->company->id, 'code' => StandardChart::MOBILE_MONEY.'-T1',
                'parent_id' => Account::query()->where('code', StandardChart::MOBILE_MONEY)->value('id'),
                'name_en' => 'Test bKash', 'name_bn' => 'পরীক্ষার বিকাশ', 'type' => Account::ASSET, 'nature' => Account::DEBIT,
                'is_group' => false, 'money_kind' => Account::MFS,
            ]);
    }

    /** ⓘ দুটো MFS — একটা থাকলে নিজে বসে, তখন "তালিকায় কেবল MFS" দাবিটা একটার উপর দাঁড়াত */
    private function secondMfs(): Account
    {
        return Account::query()->firstOrCreate(['code' => StandardChart::MOBILE_MONEY.'-T2'], [
            'company_id' => $this->company->id, 'parent_id' => Account::query()->where('code', StandardChart::MOBILE_MONEY)->value('id'),
            'name_en' => 'Test Nagad', 'name_bn' => 'পরীক্ষার নগদ অ্যাপ', 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'is_group' => false, 'money_kind' => Account::MFS,
        ]);
    }
}
