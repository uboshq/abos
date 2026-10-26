<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\Concerns\ReadsTheRefusal;
use Tests\TestCase;

/**
 * ⭐ পরিশোধ ভাউচারে নকশার "মোট টাকা" — চুক্তি: E:\ABOS\পরিশোধে-মোট-টাকা-চুক্তি-২৫-অক্টোবর.md (fe-র সিদ্ধান্ত ক, ২৫ অক্টোবর ২০২৬;
 * a4 ফোনের দিক এই চুক্তিতেই বানাচ্ছে)।
 *
 * দাবি:
 *   ১. বিকাশের উদাহরণ হুবহু: মোট ৯০০, চার্জ ৫ → দেনা Dr ৯০০, ৫২১১ Dr ৫, বিকাশ Cr ৯০৫ — চার্জ মোটের বাইরে, কোম্পানির
 *   ২. মোট = নিট + কাটা: মোট ১,০০০, AIT ৫০, VDS ৭৫, চার্জ ২৫ → পক্ষ Dr পুরো ১,০০০, ২১২১ Cr ৫০, ২১২২ Cr ৭৫, টাকার খাত Cr ৯০০ (নিট
 *      ৮৭৫ + চার্জ ২৫); ভাউচারের নিট ৮৭৫
 *   ৩. পুরনো ফোন (`gross_amount` নেই, `amount` = নিট) আগের হুবহু খাতা পায়
 *   ৪. চার নিয়ম, নিজের ঘরে নিজের কথায় (চুক্তি §৪): মোট নেই · কাটা ≥ মোট · মোট আর নিট দুটোই
 *   ৫. ফোনের preview: নিট আর খাতা = পরে একই payload-এ লেখা ভাউচারের নিট আর খাতা; কিছুই লেখে না (ভাউচার, নম্বর, মানুষ);
 *      ভুলে খসড়ার একই ঘর আর চাবিতে ৪২২
 *   ৬. setup বলে `voucher_contract: 2`; ভাউচারের পাতা `net_amount` আর `ledger` দেয়
 *   ৭. ওয়েবের পরিশোধ ফর্ম মোট পাঠায় (`gross_amount`, "মোট টাকা (৳)"); রসিদ আগের মতোই `amount`
 */
final class ThePaymentIsWrittenAsItsGrossTest extends TestCase
{
    use PutsMoneyInTheTill;
    use ReadsTheRefusal;
    use RefreshDatabase;

    private User $owner;

    private Account $mfs;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->mfs = Account::query()->create([
            'company_id' => CompanyContext::id(), 'code' => '1105-GRS', 'name_en' => 'bKash gross', 'name_bn' => 'বিকাশ মোট',
            'parent_id' => StandardChart::find(StandardChart::MOBILE_MONEY)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::MFS, 'is_active' => true, 'status' => DocumentStatus::CONFIRMED,
        ]);
        // ⓘ সইয়ের সীমার উপরের preview (৬,০০০, ৯,০০,০০০) খাতে টাকা না থাকায় থামবে না — যথেষ্ট রাখা
        $this->putMoneyIn($this->mfs, '1000000');
        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
    }

    public function test_the_ground_is_there(): void
    {
        $this->assertNotNull(StandardChart::find(StandardChart::MFS_CHARGES), 'প্রস্তুতিটাই ভুল — ৫২১১ নেই');
        $this->assertNotNull(StandardChart::find(StandardChart::TAX_DEDUCTED_AT_SOURCE), 'প্রস্তুতিটাই ভুল — ২১২১ নেই');
    }

    /** ১ — বিকাশের উদাহরণ, হুবহু (চুক্তি §১, fe-র সম্মতি) */
    public function test_the_bkash_example_keeps_the_charge_outside_the_gross(): void
    {
        $this->accepted($this->pay(['gross_amount' => '900', 'charge_amount' => '5', 'instrument_no' => 'GRS-BK-1']), 'বিকাশের উদাহরণ');

        $voucher = $this->lastPayment();
        $book = $this->book($voucher);

        $this->moved($book, StandardChart::find(StandardChart::PAYABLE), debit: '900', why: '⛔ দেনা ঠিক ৯০০ কমেনি — চার্জ মোটে মিশে গেছে');
        $this->moved($book, StandardChart::find(StandardChart::MFS_CHARGES), debit: '5', why: '⛔ চার্জ ৫ টাকা ৫২১১-এ বসেনি');
        $this->moved($book, $this->mfs, credit: '905', why: '⛔ বিকাশ থেকে ৯০৫ বেরোয়নি — চার্জ মোটের বাইরে নয়');
        $this->assertCount(3, $book, '⛔ বিকাশের উদাহরণে ঠিক তিনটা খাত নড়ার কথা: '.json_encode($book));
        // ⓘ মাথার `amount` ব্যবস্থার পুরনো নিয়মে ডেবিটের যোগ (সেবা লেখে); নিট চুক্তির নিজের ঘরে — ভাউচারের পাতার `net_amount` ([[VoucherApiController::netOf()]])
        $this->assertSame('900.0000', $this->netOnThePage($voucher), '⛔ কাটা ছাড়া নিট মোটের সমান নয়');
    }

    /** ২ — মোট = নিট + কাটা; পক্ষ পুরো মোটে কমে */
    public function test_the_gross_is_the_net_plus_what_was_withheld_and_the_party_goes_down_by_all_of_it(): void
    {
        $this->accepted($this->pay(['gross_amount' => '1,000', 'ait_amount' => '50', 'vds_amount' => '75', 'charge_amount' => '25', 'instrument_no' => 'GRS-WH-1']), 'কাটাসহ মোট');

        $voucher = $this->lastPayment();
        $book = $this->book($voucher);

        $this->moved($book, StandardChart::find(StandardChart::PAYABLE), debit: '1000', why: '⛔ পক্ষ পুরো মোটে (১,০০০) কমেনি');
        $this->moved($book, StandardChart::find(StandardChart::TAX_DEDUCTED_AT_SOURCE), credit: '50', why: '⛔ AIT ২১২১-এ বসেনি');
        $this->moved($book, StandardChart::find(StandardChart::VDS_PAYABLE), credit: '75', why: '⛔ VDS ২১২২-এ বসেনি');
        $this->moved($book, $this->mfs, credit: '900', why: '⛔ টাকার খাত থেকে নিট + চার্জ (৮৭৫ + ২৫) বেরোয়নি');
        $this->moved($book, StandardChart::find(StandardChart::MFS_CHARGES), debit: '25', why: '⛔ চার্জ কোম্পানির খরচে বসেনি');
        $this->assertSame('875.0000', $this->netOnThePage($voucher), '⛔ নিট = মোট − AIT − VDS নয়: '.$this->netOnThePage($voucher));

        // ⭐ বিলে বসেনি এমন পুরো মোট সরবরাহকারীর নিজের খাতায় (২১১১) Dr, তাঁর নামে — আলাদা অগ্রিম খাত নেই (চুক্তি §৭.৬)
        $own = (string) LedgerEntry::query()->where('source_type', Voucher::SOURCE_TYPES[Voucher::PAYMENT])->where('source_id', $voucher->id)
            ->where('account_id', StandardChart::find(StandardChart::PAYABLE)->id)->where('party_type', 'supplier')->where('party_id', $this->supplier->id)
            ->sum('debit');
        $this->assertSame(0, bccomp($own, '1000', 4), '⛔ বিলে না বসা টাকা সরবরাহকারীর নিজের খাতায় (২১১১, তাঁর নামে) Dr হয়ে বসেনি: '.$own);
    }

    /** ৩ — পুরনো ফোন: `gross_amount` নেই, `amount` = নিট → আগের হুবহু খাতা */
    public function test_an_old_phone_without_the_gross_gets_the_same_books_as_before(): void
    {
        $this->accepted($this->pay(['gross_amount' => '1000', 'ait_amount' => '50', 'vds_amount' => '75', 'charge_amount' => '25', 'instrument_no' => 'GRS-NEW-1']), 'নতুন ফোনের মোট');
        $new = $this->book($this->lastPayment());

        $this->accepted($this->pay(['amount' => '875', 'ait_amount' => '50', 'vds_amount' => '75', 'charge_amount' => '25', 'instrument_no' => 'GRS-OLD-1'], withGross: false), 'পুরনো ফোনের নিট');
        $old = $this->book($this->lastPayment());

        $this->assertEquals($new, $old, '⛔ মোট ছাড়া পুরনো পথ (নিট) আর মোটের পথ একই খাতা বলে না');
    }

    /** ৪ — চুক্তি §৪-এর নিয়ম, নিজের ঘরে নিজের কথায় */
    public function test_the_gross_rules_refuse_in_their_own_field_and_words(): void
    {
        foreach ([
            'মোট নেই' => [['gross_amount' => ''], 'gross_amount', 'accounts::validation.gross_required', []],
            'কাটা ≥ মোট' => [['gross_amount' => '100', 'ait_amount' => '60', 'vds_amount' => '40'], 'gross_amount', 'accounts::validation.withheld_not_below_gross',
                ['gross' => '100.00', 'withheld' => '100.00']],
            'মোট আর নিট দুটোই' => [['gross_amount' => '100', 'amount' => '100'], 'amount', 'accounts::validation.gross_or_net_not_both', []],
        ] as $case => [$fields, $field, $key, $with]) {
            $before = Voucher::query()->count();
            $this->pay([...$fields, 'instrument_no' => 'GRS-NO-'.md5($case)]);
            $said = (string) ($this->refusalOn($field) ?? '');

            $this->assertContains($said, [trans($key, $this->formatted($with), 'bn'), trans($key, $this->formatted($with), 'en')],
                "⛔ {$case}: থামেনি, বা {$field}-এর ঘরে নিজের কথায় নয়: ".$said);
            $this->assertSame($before, Voucher::query()->count(), "⛔ {$case}: থামা কাগজ লেখা হলো");
        }
    }

    /** ৫ — ফোনের preview: নিট আর খাতা = পরের পোস্টের; কিছুই লেখে না; ভুলে খসড়ার একই ঘর আর চাবিতে ৪২২ */
    public function test_the_phone_preview_tells_the_net_and_books_the_post_will_write_and_writes_nothing(): void
    {
        $payload = $this->payload(['gross_amount' => '1000', 'ait_amount' => '50', 'vds_amount' => '75', 'charge_amount' => '25', 'instrument_no' => 'GRS-PRV-1']);
        $counts = fn () => [Voucher::query()->count(), DB::table('mdm_people')->count(), DB::table('number_series')->sum('next_number')];
        $before = $counts();

        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $preview = $this->postJson('/api/v1/accounts/vouchers/preview', [...$payload, 'party_new' => 'নতুন মানুষ'])->assertOk()->json();
        $this->assertSame($before, $counts(), '⛔ preview কিছু লিখল — ভাউচার, মানুষ বা নম্বর');
        $this->assertSame('875.0000', $preview['net_amount'] ?? null, '⛔ preview-এর নিট সার্ভারের মোট − AIT − VDS নয়');
        $this->assertSame($preview['total_dr'] ?? null, $preview['total_cr'] ?? null, '⛔ preview-এর খাতা মেলে না');

        // ⓘ তালিকায় নেই এমন নাম — খসড়া হলে মানুষ বানাত; preview-এ নয় (উত্তর যা-ই হোক)
        $this->postJson('/api/v1/accounts/vouchers/preview', [...$payload, 'party_id' => '', 'party_new' => 'প্রিভিউর নতুন মানুষ']);
        $this->assertSame($before, $counts(), '⛔ preview হাতে লেখা নাম থেকে মানুষ বানাল');

        $this->actingAs($this->owner);
        $this->accepted($this->pay($payload, raw: true), 'preview-এর পরের পোস্ট');
        $voucher = $this->lastPayment();

        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $page = $this->getJson('/api/v1/accounts/vouchers/'.$voucher->public_id)->assertOk()->json();
        $this->assertSame($preview['net_amount'], $page['net_amount'] ?? null, '⛔ preview-এর নিট পরের পোস্টের নিট নয়');
        $this->assertSame('1000.0000', $page['gross_amount'] ?? null, '⛔ ভাউচারের পাতায় মোট (নিট + AIT + VDS) নেই বা ভুল');
        // ⓘ a4-এর ঘরের নাম (চুক্তি §৭.৩): debit আর credit — dr/cr নয়
        $first = $preview['ledger'][0] ?? [];
        $this->assertSame(['account', 'debit', 'credit'], array_keys($first), '⛔ preview-এর খাতার সারির ঘর a4-এর নামে নয় (account, debit, credit)');
        $this->assertSame(['id', 'code', 'name'], array_keys($first['account'] ?? []), '⛔ preview-এর খাতের ঘর {id, code, name} নয়');
        $this->assertEqualsCanonicalizing($this->rows($preview['ledger'] ?? []), $this->rows($page['ledger'] ?? []),
            '⛔ preview-এর খাতা পরের পোস্টের খাতা নয়');

        $refused = $this->postJson('/api/v1/accounts/vouchers/preview', [...$payload, 'gross_amount' => ''])->assertStatus(422);
        $this->assertContains((string) $refused->json('errors.gross_amount.0'), [trans('accounts::validation.gross_required', [], 'bn'), trans('accounts::validation.gross_required', [], 'en')],
            '⛔ preview-এর ভুল খসড়ার একই ঘর আর চাবিতে নয়');
    }

    /** ৬ — setup বলে চুক্তির সংস্করণ */
    public function test_the_phone_setup_says_contract_two(): void
    {
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $this->assertSame(2, $this->getJson('/api/v1/accounts/vouchers/setup?type=payment')->assertOk()->json('voucher_contract'),
            '⛔ setup চুক্তির সংস্করণ ২ বলে না — নতুন ফোন বুঝবে না মোট পাঠানো যায়');
    }

    /** ৭ — ওয়েবের পরিশোধ ফর্ম মোট পাঠায়, রসিদ নয় */
    public function test_the_payment_form_sends_its_gross_and_the_receipt_its_amount(): void
    {
        $payment = (string) $this->get(route('accounts.voucher.create', ['type' => Voucher::PAYMENT]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*type="hidden"[^>]*name="gross_amount"/', $payment, '⛔ পরিশোধের ফর্ম মোট পাঠায় না');
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*type="hidden"[^>]*name="amount"/', $payment, '⛔ পরিশোধের ফর্ম মোট আর নিট দুটোই পাঠায় — সার্ভার থামাবে');
        $said = array_filter(['bn', 'en'], fn (string $l) => str_contains($payment, e(trans('money.screen.gross', [], $l))));
        $this->assertNotEmpty($said, '⛔ পরিশোধের টাকার ঘরের নাম "মোট টাকা (৳)" নয়');

        $receipt = (string) $this->get(route('accounts.voucher.create', ['type' => Voucher::RECEIPT]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*type="hidden"[^>]*name="amount"/', $receipt, '⛔ রসিদের টাকার ঘর বদলে গেল');
    }

    /** ৮ — `needs_signature`: পোস্টের নিজের প্রশ্ন — সইয়ের ছকের সীমার নিচে না, উপরে হ্যাঁ; ফোনের preview আর ওয়েবের simulate একই কথা */
    public function test_the_preview_says_whether_posting_will_wait_for_a_signature_at_the_flows_threshold(): void
    {
        $flow = \App\Models\ApprovalFlow::create(['company_id' => CompanyContext::id(), 'module' => \App\Modules\Accounts\Services\VoucherApproval::MODULE,
            'action' => Voucher::PAYMENT, 'threshold_amount' => '5000', 'is_active' => true]);
        \App\Models\ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner->id]);

        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $below = $this->postJson('/api/v1/accounts/vouchers/preview', $this->payload(['gross_amount' => '1000', 'instrument_no' => 'SIG-LO-1']))->assertOk();
        $above = $this->postJson('/api/v1/accounts/vouchers/preview', $this->payload(['gross_amount' => '6000', 'instrument_no' => 'SIG-HI-1']))->assertOk();

        $this->assertFalse($below->json('needs_signature'), '⛔ সইয়ের সীমার নিচের পরিশোধে preview "সই লাগবে" বলল');
        $this->assertTrue($above->json('needs_signature'), '⛔ সইয়ের সীমার উপরের পরিশোধে preview "সই লাগবে" বলল না — ফোনে "সইয়ের অপেক্ষায় হবে" চিপ আসবে না');

        $this->actingAs($this->owner);
        $web = $this->postJson(route('accounts.voucher.simulate'), $this->payload(['gross_amount' => '6000', 'instrument_no' => 'SIG-HI-2']))->assertOk();
        $this->assertTrue($web->json('needs_signature'), '⛔ ওয়েবের simulate আর ফোনের preview সইয়ের প্রশ্নে আলাদা কথা বলে');
    }

    /** ৯ — সই বন্ধ (কোনো ছক নেই) — সবসময় না */
    public function test_with_approvals_off_the_preview_never_says_a_signature_is_needed(): void
    {
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $answer = $this->postJson('/api/v1/accounts/vouchers/preview', $this->payload(['gross_amount' => '900000', 'instrument_no' => 'SIG-OFF-1']))->assertOk();

        $this->assertFalse($answer->json('needs_signature'), '⛔ সই বন্ধ, অথচ preview "সই লাগবে" বলল');
    }

    /** ১০ — পরিশোধ ভাউচারে কাটা কর আর ভ্যাট পোস্টে করণীয় হয় (সরবরাহকারী-পরিশোধের একই তিন সারি); বাতিলে খোলা করণীয় ফেরে */
    public function test_a_posted_payment_voucher_raises_its_tax_duties_and_a_cancel_withdraws_them(): void
    {
        $this->accepted($this->pay(['gross_amount' => '1000', 'ait_amount' => '50', 'vds_amount' => '75', 'instrument_no' => 'DUTY-1']), 'কাটাসহ পরিশোধ');
        $voucher = $this->lastPayment();
        $duties = fn () => \App\Modules\Accounts\Models\ComplianceObligation::query()
            ->where('source_type', Voucher::SOURCE_TYPES[Voucher::PAYMENT])->where('source_id', $voucher->id);

        $rows = $duties()->get()->map(fn ($o) => $o->kind.'|'.$o->subject.'|'.bcadd((string) $o->amount, '0', 2))->sort()->values()->all();
        $this->assertSame(['kosagar_deposit|ait|50.00', 'kosagar_deposit|vds|75.00', 'mushak_6_6_issue|vds|75.00'], $rows,
            '⛔ পরিশোধ ভাউচারের কাটা কর আর ভ্যাট করণীয় হয়নি — কোষাগারে জমা আর মূসক-৬.৬ কেউ মনে করাবে না: '.json_encode($rows));
        $this->assertSame(0, $duties()->whereNull('due_on')->count(), '⛔ করণীয়ের শেষ দিন নেই');

        app(\App\Modules\Accounts\Services\VoucherService::class)->cancel($voucher->fresh(), 'ভুল পরিশোধ');
        $this->assertSame(0, $duties()->whereNull('cancelled_at')->count(), '⛔ বাতিল পরিশোধের করণীয় খোলা রইল — না-কাটা কর জমার তাগাদা');
    }

    /** ১১ — কাটা না থাকলে করণীয় নেই; কমলা লাইন কেবল পরিশোধে, কাটা থাকলে */
    public function test_a_payment_without_withholding_raises_nothing_and_the_note_follows_the_withholding(): void
    {
        $this->accepted($this->pay(['gross_amount' => '1000', 'instrument_no' => 'DUTY-0']), 'কাটা ছাড়া পরিশোধ');
        $voucher = $this->lastPayment();
        $this->assertSame(0, \App\Modules\Accounts\Models\ComplianceObligation::query()
            ->where('source_type', Voucher::SOURCE_TYPES[Voucher::PAYMENT])->where('source_id', $voucher->id)->count(), '⛔ কাটা ছাড়া পরিশোধেও করণীয় উঠল');

        $payment = (string) $this->get(route('accounts.voucher.create', ['type' => Voucher::PAYMENT]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/x-show="hasWithheld"[^>]*data-sum-tax-note(?=[\s>])/', $payment, '⛔ পরিশোধের সারাংশে নকশার কমলা "করণীয় তালিকায় উঠবে" লাইন নেই');
        $receipt = (string) $this->get(route('accounts.voucher.create', ['type' => Voucher::RECEIPT]))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-sum-tax-note', $receipt, '⛔ রসিদে কমলা করণীয়ের লাইন — পক্ষের কাটা আমাদের জমার দায় নয়');
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /** @param  array<string, string>  $fields  @return array<string, mixed> */
    private function payload(array $fields): array
    {
        return [
            'type' => Voucher::PAYMENT, 'trx_date' => now()->toDateString(), 'narration' => 'মোটের দাবি',
            'party_type' => 'supplier', 'party_id' => $this->supplier->id, 'from_account_id' => $this->mfs->id, 'instrument' => 'mfs',
            ...$fields,
        ];
    }

    /** ⓘ ফোনের ভাউচারের পাতা যে নিট বলে — চুক্তির নিট (টাকার সারি − চার্জ) */
    private function netOnThePage(Voucher $voucher): string
    {
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);

        return (string) $this->getJson('/api/v1/accounts/vouchers/'.$voucher->public_id)->assertOk()->json('net_amount');
    }

    /** @param  array<string, string>  $fields */
    private function pay(array $fields, bool $withGross = true, bool $raw = false): \Illuminate\Testing\TestResponse
    {
        $body = $raw ? $fields : $this->payload($fields);
        if (! $withGross) {
            unset($body['gross_amount']);
        }

        return $this->from(route('accounts.voucher.create', ['type' => Voucher::PAYMENT]))
            ->post(route('accounts.voucher.store', ['type' => Voucher::PAYMENT]), $body);
    }

    /** ⓘ ফেরত এল কি না — নিজের ⛔ কথায় (Laravel-এর সাধারণ বার্তায় নয়), কোন ঘরে কী বলল সহ */
    private function accepted(\Illuminate\Testing\TestResponse $response, string $case): void
    {
        $said = $this->refusalMessages();
        $this->assertSame([], $said, "⛔ {$case}: মোট টাকার পরিশোধ ফিরে এল: ".json_encode($said, JSON_UNESCAPED_UNICODE));
    }

    private function lastPayment(): Voucher
    {
        $voucher = Voucher::query()->where('type', Voucher::PAYMENT)->latest('id')->firstOrFail();
        $this->assertSame(DocumentStatus::CONFIRMED, $voucher->status, 'দৃশ্যটাই ঠিক নয় — পরিশোধ খাতায় বসেনি: '.$voucher->status);

        return $voucher;
    }

    /** @return array<int, array{debit: string, credit: string}> */
    private function book(Voucher $voucher): array
    {
        $book = [];
        LedgerEntry::query()->where('source_type', Voucher::SOURCE_TYPES[Voucher::PAYMENT])->where('source_id', $voucher->id)->get()
            ->each(function (LedgerEntry $e) use (&$book): void {
                $id = (int) $e->account_id;
                $book[$id] ??= ['debit' => '0.0000', 'credit' => '0.0000'];
                $book[$id]['debit'] = bcadd($book[$id]['debit'], (string) $e->debit, 4);
                $book[$id]['credit'] = bcadd($book[$id]['credit'], (string) $e->credit, 4);
            });
        ksort($book);

        return $book;
    }

    /** @param  array<int, array{debit: string, credit: string}>  $book */
    private function moved(array $book, ?Account $account, string $debit = '0', string $credit = '0', string $why = ''): void
    {
        $this->assertNotNull($account, 'দৃশ্যটাই ঠিক নয় — খাত নেই');
        $row = $book[(int) $account->id] ?? null;
        $this->assertNotNull($row, $why.' — খাত '.$account->code.' নড়েইনি: '.json_encode($book));
        $this->assertSame(0, bccomp($row['debit'], $debit, 4), $why." — {$account->code} ডেবিট {$row['debit']}, চাওয়া {$debit}");
        $this->assertSame(0, bccomp($row['credit'], $credit, 4), $why." — {$account->code} ক্রেডিট {$row['credit']}, চাওয়া {$credit}");
    }

    /** @param  list<array<string, mixed>>  $ledger  @return list<string> */
    private function rows(array $ledger): array
    {
        return array_map(fn (array $r) => ($r['account']['id'] ?? '').'|'.($r['debit'] ?? '').'|'.($r['credit'] ?? ''), $ledger);
    }

    /** @param  array<string, string>  $with  @return array<string, string> */
    private function formatted(array $with): array
    {
        return array_map(fn (string $v) => \App\Core\Support\Money::format($v), $with);
    }
}
