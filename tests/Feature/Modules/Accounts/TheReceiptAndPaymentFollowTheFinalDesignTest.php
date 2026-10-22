<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ⭐ রসিদ ও পরিশোধ ভাউচার চূড়ান্ত নকশার মতো — fe, ২৩ অক্টোবর ২০২৬; মালিক: *"নকশা অনুযায়ী, লাইভের আগে মিলিয়ে নাও"*
 * (টাকা-নেওয়া-দেওয়া-চূড়ান্ত-নকশা-১০০-সংশোধিত-২২-অক্টোবর.pdf, পাতা ২ আর ৪)।
 *
 * মিল-তালিকার আটটা ✗, প্রতিটার দাবি:
 *   ১. ক্রম: পক্ষের ধরন → পক্ষ → তারিখ → টাকা → টাকার খাত → পদ্ধতি → সংযুক্তি · কার মাধ্যমে · বিবরণ
 *   ২. টাকা লাখ-কমায় লেখা যায় ("45,000.00") আর ঠিক অঙ্কে বসে
 *   ৩. পাঁচ ঘরের এক সারি নেই — নিচের ঘরগুলো আলাদা বাক্সে, পূর্ণ চওড়ায় (মাপ ১৩৬৬-এ, কমিটে)
 *   ৪. পরিশোধে "যে খাত থেকে", আর খাতের স্থিতি পর্দায় যায়
 *   ৫. "খাতায় যা যাবে" = পোস্টের খাতা, আর প্রিভিউ কিছুই লেখে না
 *   ৬. রেফারেন্সের চলতি উত্তর — পোস্টের একই প্রশ্ন, কেবল নিজের কোম্পানিতে
 *   ৭. রসিদ আর পরিশোধে সংযুক্তি, আর সেটা কাগজের সাথে বসে
 *   ৮. পোস্টের পরে ছাপা · PDF · WhatsApp — ফর্মে কথাটা, আর দেখার পাতায় সত্যিই পাঠানোর পথ
 */
final class TheReceiptAndPaymentFollowTheFinalDesignTest extends TestCase
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
        $this->assertNotNull($this->bank(), 'প্রস্তুতিটাই ভুল — ব্যাংক খাত নেই');
        $this->assertGreaterThan(0, Customer::query()->count(), 'প্রস্তুতিটাই ভুল — গ্রাহক নেই');
    }

    /** ১ — নকশার ক্রম */
    public function test_the_receipt_and_the_payment_ask_in_the_designs_order(): void
    {
        foreach ([Voucher::RECEIPT => 'to_account_id', Voucher::PAYMENT => 'from_account_id'] as $type => $money) {
            $html = $this->screen($type);
            $order = ['name="party_type"', 'data-party-picker', 'name="trx_date"', 'data-voucher-amount',
                'name="'.$money.'" data-money-account', 'name="instrument"', 'name="attachment"', 'name="carried_by"', 'name="narration"'];

            $at = [];
            foreach ($order as $mark) {
                $pos = strpos($html, $mark);
                $this->assertNotFalse($pos, "⛔ {$type}: পর্দায় {$mark} নেই");
                $at[$mark] = $pos;
            }
            $sorted = $at;
            asort($sorted);
            $this->assertSame($order, array_keys($sorted), "⛔ {$type}: ঘরের ক্রম নকশার মতো নয় (পক্ষ → তারিখ → টাকা → খাত → পদ্ধতি → সংযুক্তি · কার মাধ্যমে · বিবরণ)");
        }
    }

    /** ২ — লাখ-কমার অঙ্ক */
    public function test_the_amount_is_written_with_lakh_commas_and_lands_exactly(): void
    {
        $html = $this->screen(Voucher::RECEIPT);
        $this->assertMatchesRegularExpression('/<input[^>]*type="text"[^>]*name="amount"|<input[^>]*name="amount"[^>]*type="text"/', $html,
            '⛔ টাকার ঘর আবার type="number" — কমা দেখাতে পারে না');
        $this->assertStringContainsString('@change="tidyAmount()"', File::get(base_path('app/Modules/Accounts/Resources/views/voucher/partials/party-fields.blade.php')),
            '⛔ ঘর ছাড়লে অঙ্ক লাখ-কমায় সাজে না');

        $before = Voucher::query()->count();
        $this->postReceipt(['amount' => '45,000.00']);
        $this->assertSame($before + 1, Voucher::query()->count(), '⛔ "45,000.00" লেখা রসিদ জমাই হল না — কমা ছাঁটা হয় না');
        $voucher = Voucher::query()->latest('id')->firstOrFail();
        $this->assertSame(0, bccomp((string) $voucher->amount, '45000', 2), '⛔ "45,000.00" ঠিক অঙ্কে বসল না: '.$voucher->amount);
    }

    /** ৩ আর ৪ — পাঁচ ঘরের সারি নেই, "যে খাত থেকে", খাতের স্থিতি */
    public function test_no_crowded_row_and_the_paying_account_shows_its_balance(): void
    {
        $form = File::get(base_path('app/Modules/Accounts/Resources/views/voucher/simple-form.blade.php'));
        $this->assertStringNotContainsString('lg:grid-cols-[1.4fr_1.4fr_1fr_1.6fr_1.6fr]', $form,
            '⛔ পরিশোধের পাঁচ ঘরের এক সারি ফিরেছে — ১৩৬৬-এ লম্বা খাতের নাম কাটা পড়ে');
        $this->assertMatchesRegularExpression('/data-voucher-details.*?<div class="flex flex-col gap-3">.*?name="to_account_id".*?name="carried_by".*?name="narration"/s', $form,
            '⛔ কাকে দেওয়া হল · কার মাধ্যমে · বিবরণ আর আলাদা পূর্ণ-চওড়া স্তম্ভে নয়');

        $payment = $this->screen(Voucher::PAYMENT);
        // ⓘ উৎস ধরে — পরীক্ষা ইংরেজিতে আঁকে, আর সেখানে পুরনো আর নতুন দুই লেখাই "Paid from"
        $this->assertMatchesRegularExpression('/name="from_account_id" data-money-account x-model="account"\s+:label="__\(\'money\.screen\.from_account\'\)"/', $form,
            '⛔ পরিশোধে "যে খাত থেকে" লেখা নেই (নকশার লেখা; আগে "কোথা থেকে দেওয়া হল")');
        $this->assertStringContainsString('data-account-balance', $payment, '⛔ খাতের স্থিতির লাইন নেই');
        $balance = bcadd($this->bank()->balanceInView(), '0', 2);
        $this->assertStringContainsString('&quot;'.$this->bank()->id.'&quot;:&quot;'.$balance.'&quot;', $payment,
            '⛔ ব্যাংক খাতের স্থিতি পর্দায় পাঠানো হয় না');
        $this->assertSame('যে খাত থেকে', trans('money.screen.from_account', [], 'bn'), '⛔ নকশার লেখা বদলেছে');
    }

    /** ৫ — প্রিভিউ = পোস্ট, আর প্রিভিউ কিছু লেখে না */
    public function test_the_preview_is_the_ledger_the_post_writes_and_writes_nothing_itself(): void
    {
        $payload = $this->receiptPayload(['instrument' => 'transfer', 'instrument_no' => 'NPPREVIEW001', 'to_account_id' => $this->bank()->id]);

        $vouchers = Voucher::query()->count();
        $entries = LedgerEntry::query()->count();
        $people = DB::table('mdm_people')->count();

        $preview = $this->post(route('accounts.voucher.simulate'), [...$payload, 'party_new' => 'নতুন মানুষ', 'party_id' => ''])->assertOk()->json();
        $this->assertSame($people, DB::table('mdm_people')->count(), '⛔ প্রিভিউ হাতে লেখা নাম থেকে মানুষ বানাল — টাইপের প্রতিটা থামায় একজন');

        $preview = $this->post(route('accounts.voucher.simulate'), $payload)->assertOk()->json();
        $this->assertSame($vouchers, Voucher::query()->count(), '⛔ প্রিভিউ ভাউচার লিখল');
        $this->assertSame($entries, LedgerEntry::query()->count(), '⛔ প্রিভিউ খাতায় লিখল');
        $this->assertNotEmpty($preview['rows'], '⛔ সম্পূর্ণ রসিদের প্রিভিউ খালি');
        $this->assertTrue($preview['balanced'], '⛔ প্রিভিউর ডেবিট-ক্রেডিট মেলে না');

        $this->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), $payload)->assertSessionHasNoErrors();
        $voucher = Voucher::query()->latest('id')->firstOrFail();
        if (! $voucher->isPosted()) {
            app(VoucherService::class)->post($voucher->fresh(), byHand: true);
        }

        $posted = LedgerEntry::query()->where('source_id', $voucher->id)->where('source_type', Voucher::SOURCE_TYPES[Voucher::RECEIPT])
            ->orderBy('id')->get()
            ->map(fn (LedgerEntry $e) => [(int) $e->account_id, bcadd((string) $e->debit, '0', 2), bcadd((string) $e->credit, '0', 2)])->all();
        $shown = collect($preview['rows'])->map(function (array $row) {
            $id = (int) Account::query()->where('code', strtok((string) $row['account'], ' '))->value('id');

            return [$id, bcadd(str_replace(',', '', $row['debit'] ?: '0'), '0', 2), bcadd(str_replace(',', '', $row['credit'] ?: '0'), '0', 2)];
        })->all();
        sort($posted);
        sort($shown);

        $this->assertNotEmpty($posted, 'প্রস্তুতিটাই ভুল — রসিদ খাতায় ওঠেনি');
        $this->assertSame($posted, $shown, '⛔ "খাতায় যা যাবে" আর পোস্টের খাতা আলাদা — প্রিভিউ মিথ্যা বলছে');
    }

    /** ৫ক — পোস্ট যা ফেরাত, প্রিভিউ সারি দেখায় না, সেই কারণটাই বলে */
    public function test_the_preview_refuses_what_the_post_refuses(): void
    {
        $cash = Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
        $payload = $this->receiptPayload(['to_account_id' => $cash->id, 'instrument' => 'mfs', 'instrument_no' => 'BKPREVIEW9']);

        $preview = $this->post(route('accounts.voucher.simulate'), $payload)->assertOk()->json();
        $this->assertSame([], $preview['rows'], '⛔ নগদের খাতে "MFS" — পোস্ট ফেরাবে, অথচ প্রিভিউ খাতার সারি দেখায়');
        $this->assertNotSame('', (string) ($preview['message'] ?? ''), '⛔ প্রিভিউ চুপ — পোস্ট কেন ফেরাবে তা বলে না');

        $before = Voucher::query()->count();
        $this->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), $payload);
        $this->assertSame($before, Voucher::query()->count(), 'প্রস্তুতিটাই ভুল — পোস্টও এটা মেনে নিল, তাহলে প্রিভিউর থামাটাই ভুল');
    }

    /** ⛔ নগদ জমার রসিদ — স্লিপই ব্যাংকের রেফারেন্স; আগে কখনো পোস্টই হত না (চূড়ান্ত ছবি নিতে গিয়ে ধরা, ২৩ অক্টোবর ২০২৬) */
    public function test_a_cash_deposit_receipt_posts_on_its_slip_and_the_same_slip_cannot_post_twice(): void
    {
        $bank = $this->bank();
        $deposit = ['to_account_id' => $bank->id, 'instrument' => 'deposit', 'deposit_slip_no' => 'DS-55120'];

        $before = Voucher::query()->count();
        $this->postReceipt($deposit);
        $this->assertSame($before + 1, Voucher::query()->count(), '⛔ নগদ জমার রসিদ জমা হল না — স্লিপ নম্বর ব্যাংকের রেফারেন্স হিসেবে গোনা হয় না');
        $voucher = Voucher::query()->latest('id')->firstOrFail();
        if (! $voucher->isPosted()) {
            app(VoucherService::class)->post($voucher->fresh(), byHand: true);
        }
        $this->assertTrue($voucher->fresh()->isPosted(), '⛔ নগদ জমার রসিদ খাতায় উঠল না');
        $this->assertSame('DS-55120', $voucher->fresh()->deposit_slip_no, '⛔ স্লিপের নিজের কলাম ফাঁকা');

        $again = $this->post(route('accounts.voucher.simulate'), $this->receiptPayload($deposit))->assertOk()->json();
        $this->assertSame([], $again['rows'], '⛔ একই খাতে একই স্লিপ আবার — প্রিভিউ আটকায় না');
        $this->assertStringContainsString((string) $voucher->fresh()->document_no, (string) $again['message'], '⛔ একই স্লিপ কোন রসিদে আগে বসেছে তা বলে না');
    }

    /** ৬ — রেফারেন্সের চলতি উত্তর; সব শাখা জুড়ে, কিন্তু কেবল নিজের কোম্পানিতে */
    public function test_the_reference_answer_is_the_posts_question_and_stays_inside_the_company(): void
    {
        $bank = $this->bank();
        $ask = fn (int $account, string $no) => $this->getJson(route('accounts.voucher.reference_check', ['account_id' => $account, 'instrument_no' => $no]));

        $this->assertSame('free', $ask($bank->id, 'NPREF0001')->assertOk()->json('state'), '⛔ নতুন নম্বর "আগে ব্যবহার হয়নি" বলে না');

        $this->postReceipt(['to_account_id' => $bank->id, 'instrument' => 'transfer', 'instrument_no' => 'NPREF0001'])->assertSessionHasNoErrors();
        $mine = Voucher::query()->latest('id')->firstOrFail();
        if (! $mine->isPosted()) {
            app(VoucherService::class)->post($mine->fresh(), byHand: true);
        }
        $used = $ask($bank->id, 'NPREF0001')->assertOk();
        $this->assertSame('used', $used->json('state'), '⛔ পোস্ট হওয়া নম্বর চলতি উত্তরে "আগে ব্যবহার হয়েছে" বলে না — পোস্ট আটকাবে অথচ পর্দা ✓ দেখাবে');
        $this->assertStringContainsString((string) $mine->fresh()->document_no, (string) $used->json('message'), '⛔ কোন ভাউচারে আগে বসেছে তা বলে না');

        // ⓘ নগদে নম্বরের প্রশ্নই নেই
        $cash = Account::query()->where('money_kind', Account::CASH)->postable()->active()->firstOrFail();
        $this->assertSame('empty', $ask($cash->id, 'NPREF0001')->json('state'), '⛔ নগদ খাতেও নম্বরের উত্তর দেয়');

        // ⛔ অন্য কোম্পানি — একই নম্বর তাদের খাতায়
        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($other->id, $other->defaultBranch()?->id);
        app(StandardChart::class)->install();
        $theirBank = Account::query()->where('money_kind', Account::BANK)->postable()->first()
            ?? Account::query()->where('code', StandardChart::BANK)->firstOrFail();
        $theirs = app(VoucherService::class)->create([
            'type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'narration' => 'অন্য কোম্পানি',
            'instrument' => 'transfer', 'instrument_no' => 'NPOTHER777',
        ], [
            ['account_id' => $theirBank->id, 'debit' => '100', 'credit' => '0'],
            ['account_id' => (int) Account::query()->where('type', Account::INCOME)->postable()->value('id'), 'debit' => '0', 'credit' => '100'],
        ]);
        $theirs->forceFill(['money_account_id' => $theirBank->id])->save();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $answer = $ask($bank->id, 'NPOTHER777')->assertOk();
        $this->assertSame('free', $answer->json('state'), '⛔ অন্য কোম্পানির একই নম্বর এখানে "আগে ব্যবহার হয়েছে" বলে');
        $this->assertStringNotContainsString((string) $theirs->document_no, $answer->getContent(), '⛔ অন্য কোম্পানির কাগজের নম্বর ফাঁস হল');
        $ask($theirBank->id, 'NPOTHER777')->assertNotFound();
    }

    /** ৭ — সংযুক্তি */
    public function test_a_receipt_and_a_payment_take_an_attachment_and_keep_it(): void
    {
        foreach ([Voucher::RECEIPT, Voucher::PAYMENT] as $type) {
            $html = $this->screen($type);
            $this->assertStringContainsString('name="attachment"', $html, "⛔ {$type}-এ সংযুক্তির ঘর নেই");
            $this->assertStringContainsString('enctype="multipart/form-data"', $html, "⛔ {$type}-এর ফর্ম ফাইল বইতে পারে না");
        }

        $before = DB::table('attachments')->count();
        $this->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), [
            ...$this->receiptPayload(),
            'attachment' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $this->assertSame($before + 1, DB::table('attachments')->count(), '⛔ রসিদের সংযুক্তি কাগজের সাথে বসল না');
    }

    /** ৭ক — সংযুক্তির বোতাম বাংলায়, ব্রাউজারের "Choose File" নয় (fe, ২৩ অক্টোবর ২০২৬) */
    public function test_the_attachment_button_speaks_bengali(): void
    {
        $field = File::get(base_path('app/Modules/Accounts/Resources/views/voucher/partials/attachment-field.blade.php'));
        $this->assertStringContainsString('<x-ui.file-input id="attachment" name="attachment"', $field, '⛔ ভাউচারের সংযুক্তি আবার কাঁচা ফাইল-ঘর — "Choose File / No file chosen" ইংরেজিতে');
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*type="file"/', $field, '⛔ ভাউচারের সংযুক্তি আবার কাঁচা ফাইল-ঘর');
        $this->assertStringContainsString('data-file-input', $this->screen(Voucher::RECEIPT), '⛔ রসিদের পাতায় বাংলা ফাইল-বোতাম নেই');
    }

    /** ⭐ পক্ষের পাশের অঙ্ক চিহ্ন ধরে শব্দে — তিন অবস্থা, দুই দিক ([[party-voucher.test.js]]-এ আচরণ) */
    public function test_the_partys_balance_is_named_by_its_sign_never_by_a_minus(): void
    {
        $words = ['due_in_owed' => 'বকেয়া', 'due_in_advance' => 'অগ্রিম', 'due_in_none' => 'বকেয়া নেই',
            'due_out_owed' => 'দেনা', 'due_out_advance' => 'অগ্রিম দেওয়া', 'due_out_none' => 'দেনা নেই'];
        foreach ($words as $key => $bn) {
            $this->assertSame($bn, trans("accounts::field.{$key}", [], 'bn'), "⛔ {$key}: পাওনার শব্দ নকশার নয়");
        }

        $fields = File::get(base_path('app/Modules/Accounts/Resources/views/voucher/partials/party-fields.blade.php'));
        $this->assertStringContainsString('x-text="dueWord"', $fields, '⛔ পক্ষের পাশে আবার একটাই শব্দ ("পাওনা") — চিহ্নটা শব্দে বলা হয় না');
        $this->assertStringContainsString('x-text="dueAmount"', $fields, '⛔ অঙ্কটা আবার চিহ্নসহ ("-45,000.00")');
        $this->assertStringNotContainsString('x-text="texts.owed"', $fields, '⛔ পুরনো "পাওনা" লেখা ফিরেছে');
        $this->assertStringContainsString("out: @js(! \$isReceipt)", $fields, '⛔ পরিশোধের দিক ("দেনা / অগ্রিম দেওয়া") পাতা থেকে যায় না');
    }

    /** ৮ — পোস্টের পরে ছাপা · PDF · WhatsApp */
    public function test_the_form_says_what_comes_after_posting_and_the_paper_can_really_be_sent(): void
    {
        $this->assertStringContainsString('data-after-post', $this->screen(Voucher::RECEIPT), '⛔ ফর্মে "পোস্টের পরে" লাইন নেই');
        $this->assertStringContainsString('WhatsApp', trans('accounts::message.after_post_receipt', [], 'bn'), '⛔ "পোস্টের পরে" লাইনে WhatsApp নেই');

        $this->postReceipt()->assertSessionHasNoErrors();
        $voucher = Voucher::query()->latest('id')->firstOrFail();
        if (! $voucher->isPosted()) {
            app(VoucherService::class)->post($voucher->fresh(), byHand: true);
        }

        $page = (string) $this->get(route('accounts.voucher.show', $voucher))->assertOk()->getContent();
        $this->assertStringContainsString(route('paper.share'), $page, '⛔ দেখার পাতা থেকে কাগজ পাঠানোর (WhatsApp) পথ নেই');
        $this->assertMatchesRegularExpression('/name="document_type" value="accounts_voucher"/', $page, '⛔ পাঠানোর ফর্ম ভাউচারের নয়');
        $this->assertStringContainsString('name="download" value="1"', $page, '⛔ PDF হিসেবে নামানোর বোতাম নেই');
    }

    // ── সহায়ক ──────────────────────────────────────────────────────

    private function screen(string $type): string
    {
        return (string) $this->get(route('accounts.voucher.create', ['type' => $type]))->assertOk()->getContent();
    }

    private function bank(): Account
    {
        return Account::query()->where('money_kind', Account::BANK)->postable()->active()->orderBy('code')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function receiptPayload(array $extra = []): array
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();

        return [
            'type' => Voucher::RECEIPT,
            'narration' => 'নকশার দাবি',
            'trx_date' => now()->toDateString(),
            'amount' => '1000',
            'party_type' => 'customer',
            'party_id' => $customer->id,
            'to_account_id' => Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->value('id'),
            'instrument' => 'cash',
            ...$extra,
        ];
    }

    /** ⚠️ নাম `post()` নয় — [[TestCase::post()]] ঢেকে দিত */
    private function postReceipt(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), $this->receiptPayload($extra));
    }
}
