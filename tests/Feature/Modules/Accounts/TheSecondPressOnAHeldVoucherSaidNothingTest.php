<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "এখন পোস্ট করুন" চাপলাম — কিছুই হলো না, কোনো বার্তাও না।
 *
 * ── লাইভের অভিযোগ, ২৭ সেপ্টেম্বর ২০২৬ ────────────────────────────────
 * TCL, RCV-0001 — ব্যাংকে ৫,০০,০০০ টাকার আদায়, ইতিমধ্যে "অনুমোদনের
 * অপেক্ষায়", সইকারীরা সবাই কোম্পানির বাইরের মানুষ। পোস্ট চাপলে পাতা
 * নড়ে না, বার্তা আসে না।
 *
 * ⓘ [[AVoucherHeldForApprovalSaysSoTest]] কেবল **প্রথম** চাপ মাপে, খরচ
 * ভাউচারে, আর referer হাতে দিয়ে। এই ফাইলটা সার্ভারের দিকের প্রতিটা
 * সম্ভাব্য কারণ একটা একটা করে মাপে:
 *
 *   ১. দ্বিতীয় চাপ — অনুরোধ আগে থেকেই pending
 *   ২. ব্যাংকের রসিদে লেনদেন নম্বর খালি — পর্দা `required` ঘর আঁকে
 *   ৩. referer ছাড়া `back()` — শেষ পেছনের fetch-এ গিয়ে পড়ে
 *   ৪. সইকারী কোম্পানির বাইরে — কোনো ব্যতিক্রম গিলে ফেলা হচ্ছে কি না
 *   ৫. বাতিলের কারণ খালি — সার্ভার কী বলে
 *   ৬. সইয়ের পর নম্বর বসালে সইটা বাতিল হয় কি না
 */
final class TheSecondPressOnAHeldVoucherSaidNothingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        // ⓘ কোম্পানি ব্যবহারকারীর কাছ থেকে — HTTP অনুরোধও সেখান থেকেই নেয়
        $this->company = Company::query()->findOrFail($this->owner->current_company_id);

        CompanyContext::set($this->company->id, $this->owner->current_branch_id
            ?? $this->company->defaultBranch()?->id);

        $this->actingAs($this->owner);
    }

    /**
     * অনুমান ১ + ৪: দ্বিতীয় চাপেও বার্তা আসে, সইকারী বাইরের হলেও।
     */
    public function test_the_second_press_on_a_held_bank_receipt_still_says_why(): void
    {
        $voucher = $this->draftBankReceipt();
        $this->receiptFlowSignedByAnOutsider();

        $expected = e(__('accounts::message.voucher_approval_pending', ['no' => $voucher->document_no]));

        $first = $this->from(route('accounts.voucher.show', $voucher))
            ->followingRedirects()
            ->post(route('accounts.voucher.post', $voucher))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($expected, $first, 'প্রথম চাপে বার্তা আসেনি।');

        $second = $this->from(route('accounts.voucher.show', $voucher))
            ->followingRedirects()
            ->post(route('accounts.voucher.post', $voucher))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($expected, $second,
            '⛔ দ্বিতীয় চাপে (অনুরোধ আগে থেকেই pending) বার্তা আসেনি — অনুমান ১ সত্য।');

        $this->assertSame(1, Approval::query()
            ->where('approvable_type', Voucher::class)
            ->where('approvable_id', $voucher->id)
            ->pending()
            ->count(), 'দ্বিতীয় চাপে আরেকটা অনুরোধ তৈরি হয়েছে।');

        $this->assertSame(DocumentStatus::DRAFT, $voucher->fresh()->status);
    }

    /**
     * অনুমান ২ — প্রমাণিত, আর এখন সারানো (২৭ সেপ্টেম্বর ২০২৬, abos-10)।
     *
     * ⛔ আগে লেনদেন নম্বর খালি থাকলে পোস্টের ফর্মে একটা `required` ঘর বসত:
     * ঘর খালি রেখে বোতাম চাপলে ব্রাউজার জমাটাই পাঠাত না — সার্ভারে কিছু
     * পৌঁছাত না, কেবল ব্রাউজারের ক্ষণস্থায়ী বুদবুদ। ⚠️ এই দাবিটা আগে ঠিক
     * সেই `required`-এর উপস্থিতি মাপত; এখন উল্টো মাপে।
     *
     * ⭐ এখন: ঘরটা থাকে, কিন্তু `required` নেই — খালি পাঠালে সার্ভার স্পষ্ট
     * বলে কেন আটকাল ([[VoucherService::assertBankReferenceIsFree()]])। আর
     * অনুমোদনের অপেক্ষায় থাকলে পাতা নিজেই সেটা স্থায়ীভাবে বলে — একবারের
     * ফ্ল্যাশ বা `back()`-এর উপর নির্ভর না করে।
     */
    public function test_a_held_bank_receipt_says_it_waits_and_never_blocks_the_press_silently(): void
    {
        $voucher = $this->draftBankReceipt();
        $this->receiptFlowSignedByAnOutsider();

        // ⓘ লাইভের অবস্থা: একবার পাঠানো হয়েছে, এখন "অনুমোদনের অপেক্ষায়"
        $this->from(route('accounts.voucher.show', $voucher))
            ->post(route('accounts.voucher.post', $voucher));

        /*
         * ⓘ নতুন করে খোলা পাতা — কোনো ফ্ল্যাশ ছাড়া।
         *
         * ⛔ পাতা দুইবার খোলা হয়: পোস্টের ফ্ল্যাশটা প্রথম খোলাতেই দেখা যায়।
         * একবার খুললে দাবিটা ঐ ফ্ল্যাশ দেখেই সবুজ হত — মিউট্যান্টে ধরা পড়েছে
         * (স্থায়ী লেখা বন্ধ করেও দাবি সবুজ ছিল)। দ্বিতীয় খোলায় ফ্ল্যাশ নেই,
         * তাই বার্তা দেখা গেলে সেটা কেবল স্থায়ী লেখা থেকেই।
         */
        $this->get(route('accounts.voucher.show', $voucher))->assertOk();

        $body = $this->get(route('accounts.voucher.show', $voucher))
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/<input\b[^>]*\bname="instrument_no"[^>]*\srequired\b[^>]*>/s',
            $body,
            '⛔ পোস্টের ফর্মে এখনো `required` নম্বরের ঘর — খালি রেখে চাপলে ব্রাউজার নিঃশব্দে থামায়।',
        );

        $this->assertStringContainsString(
            e(__('accounts::message.voucher_approval_pending', ['no' => $voucher->document_no])),
            $body,
            '⛔ অনুমোদনের অপেক্ষায় আছে, অথচ নতুন করে খোলা পাতা সেটা বলে না।',
        );
    }

    /**
     * শর্ত ক: পোস্ট হওয়ার পরে নম্বরটা আর বদলায় না।
     *
     * ⚠️ আগে পোস্টের দরজা নম্বরটা **আগে সংরক্ষণ** করত, তারপর দেখত কাগজটা
     * আগেই পোস্ট হয়েছে কি না — ফলে পোস্ট-হওয়া ভাউচারেও নতুন নম্বর বসে যেত,
     * তারপর ভুলবার্তা আসত।
     */
    public function test_a_posted_voucher_keeps_its_reference(): void
    {
        $voucher = $this->draftBankReceipt();

        $this->from(route('accounts.voucher.show', $voucher))
            ->post(route('accounts.voucher.post', $voucher), ['instrument_no' => 'TRX-KEEP-1001'])
            ->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CONFIRMED, $voucher->fresh()->status, 'প্রস্তুতিটাই ভুল — রসিদ পোস্ট হয়নি।');

        $this->from(route('accounts.voucher.show', $voucher))
            ->post(route('accounts.voucher.post', $voucher), ['instrument_no' => 'TRX-CHANGED-2002']);

        $this->assertSame('TRX-KEEP-1001', $voucher->fresh()->instrument_no,
            '⛔ পোস্ট হওয়া ভাউচারের লেনদেন নম্বর বদলে গেছে।');
    }

    /**
     * ছাড়টা কেবল ভাউচারের — অন্য কোনো কাগজের নয় ([[DocumentFingerprint]])।
     *
     * ⚠️ সমন্বয়কারীর শর্ত, ২৭ সেপ্টেম্বর ২০২৬: ছাড় পায় কেবল যে কাগজ নিজে
     * `fingerprintIgnores()` দিয়ে বলে। ⓘ আদায়ের কাগজেও `instrument_no` ঘর
     * আছে — সেখানে ওটা বদলালে ছাপ বদলাতেই হবে, নাহলে সই-পরবর্তী বদল ধরা
     * পড়ত না।
     */
    public function test_only_the_voucher_leaves_its_reference_out_of_the_fingerprint(): void
    {
        $engine = app(\App\Core\Engines\Approval\DocumentFingerprint::class);

        /*
         * ⚠️ দুই ছাপই একই অবস্থা থেকে (`fresh()`, সারি না তুলে) — ছাপ তোলা
         * সারিগুলোও গোনে, তাই একদিকে সারি তোলা আর অন্যদিকে না থাকলে দুইটা
         * ছাপ নম্বরের কারণে নয়, সারির কারণে আলাদা হত। ⓘ প্রথম চালে ঠিক
         * সেটাই হয়েছিল — আর আদায়ের দিকে ওই একই অসমতা দাবিটাকে মিথ্যা
         * সবুজ করত।
         */
        $voucher = $this->draftBankReceipt()->fresh();
        $before = $engine->of($voucher);
        $voucher->forceFill(['instrument_no' => 'TRX-FP-4004'])->save();

        $this->assertSame($before, $engine->of($voucher->fresh()),
            'ভাউচারের লেনদেন নম্বর বসাতেই ছাপ বদলেছে — সই আবার বাতিল হবে।');

        $collection = app(\App\Modules\Sales\Services\CollectionService::class)->create([
            'customer_id' => \App\Modules\Customer\Models\Customer::query()->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'amount' => '500',
            'instrument' => 'cash',
        ], [])->fresh();

        $before = $engine->of($collection);
        $collection->forceFill(['instrument_no' => 'SLIP-FP-5005'])->save();

        $this->assertNotSame($before, $engine->of($collection->fresh()),
            '⛔ আদায়ের কাগজের নম্বর বদলালেও ছাপ একই — ছাড়টা ভাউচারের বাইরে ছড়িয়ে পড়েছে।');
    }

    /**
     * শর্ত খ: নম্বর বসানোটা অডিটে — কে, কখন, আগে কী ছিল।
     */
    public function test_setting_the_reference_is_in_the_audit_trail(): void
    {
        $voucher = $this->draftBankReceipt();

        $this->from(route('accounts.voucher.show', $voucher))
            ->post(route('accounts.voucher.post', $voucher), ['instrument_no' => 'TRX-AUDIT-3003'])
            ->assertSessionHasNoErrors();

        $change = \App\Models\AuditFieldChange::query()
            ->whereIn('audit_trail_id', $voucher->auditTrail()->pluck('id'))
            ->where('field', 'instrument_no')
            ->latest('id')
            ->first();

        $this->assertNotNull($change, '⛔ লেনদেন নম্বর বসানো অডিটে ওঠেনি।');
        $this->assertNull($change->old_value, 'অডিটে আগের মান ভুল।');
        $this->assertSame('TRX-AUDIT-3003', $change->new_value);
        $this->assertSame($this->owner->id, (int) $change->trail->user_id, 'অডিটে কে বসিয়েছেন তা নেই।');
    }

    /**
     * শর্ত গ: "0"-এর মতো অর্থহীন নম্বর আটকায় — আসল নম্বর চলে।
     *
     * ⓘ নম্বরটা পোস্টের মুহূর্তে চাওয়ার কারণই ছিল যে আগে চাইলে মানুষ `0`
     * বসিয়ে এগিয়ে যেতেন ([[show.blade.php]]-এর মাথা)। ⚠️ তাই পোস্টের
     * মুহূর্তেও সেই ফাঁকটা বন্ধ।
     */
    public function test_a_meaningless_reference_is_refused(): void
    {
        foreach (['0', '0000', '12', ' - '] as $junk) {
            $voucher = $this->draftBankReceipt();

            $this->from(route('accounts.voucher.show', $voucher))
                ->post(route('accounts.voucher.post', $voucher), ['instrument_no' => $junk])
                ->assertSessionHasErrors('instrument_no');

            $this->assertSame(DocumentStatus::DRAFT, $voucher->fresh()->status,
                "⛔ '{$junk}' লেনদেন নম্বর হিসেবে মেনে রসিদ পোস্ট হয়ে গেছে।");
        }

        $real = $this->draftBankReceipt();

        $this->from(route('accounts.voucher.show', $real))
            ->post(route('accounts.voucher.post', $real), ['instrument_no' => 'TRX8K2Q9'])
            ->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CONFIRMED, $real->fresh()->status, 'আসল নম্বরও ফেরানো হয়েছে।');
    }

    /**
     * অনুমান ৩: referer না থাকলে `back()` শেষ পেছনের GET-এ যায়।
     *
     * ⓘ প্যালেটের খোঁজ (`/search`) `fetch()` দিয়ে যায়, `X-Requested-With`
     * ছাড়া — তাই StartSession সেটাকেই "আগের পাতা" হিসেবে রাখে।
     */
    public function test_without_a_referer_back_lands_on_the_last_background_fetch(): void
    {
        $voucher = $this->draftBankReceipt();
        $this->receiptFlowSignedByAnOutsider();

        $this->get(route('accounts.voucher.show', $voucher))->assertOk();

        $search = route('search', ['q' => 'RCV']);
        $this->get($search);

        $this->post(route('accounts.voucher.post', $voucher))
            ->assertRedirect($search);

        // ⓘ ফ্ল্যাশটা JSON উত্তরেই খরচ হয়ে যায়
        $this->get($search);

        /*
         * ⚠️ উল্টানো দাবি, ২৭ সেপ্টেম্বর ২০২৬ (abos-10): আগে এখানে মাপা হত
         * যে বার্তাটা **হারায়** — ফ্ল্যাশ খরচ হলে পাতা আর কিছু বলত না।
         * ⭐ এখন পাতা নিজেই স্থায়ীভাবে বলে খসড়াটা সইয়ের অপেক্ষায়
         * ([[show.blade.php]] `$awaitingApproval`) — ফ্ল্যাশ বা `back()`-এর
         * উপর নির্ভর না করে। তাই ফ্ল্যাশ হারালেও বার্তাটা থাকে।
         */
        $this->get(route('accounts.voucher.show', $voucher))
            ->assertOk()
            ->assertSee(e(__('accounts::message.voucher_approval_pending', [
                'no' => $voucher->document_no,
            ])), false);
    }

    /**
     * অনুমান ৩-এর উল্টো দিক: referer থাকলে (লাইভের ব্রাউজার
     * strict-origin-when-cross-origin-এ একই সাইটে পুরো ঠিকানা পাঠায়)
     * পেছনের fetch কোনো ক্ষতি করে না।
     */
    public function test_with_a_referer_the_background_fetch_does_not_matter(): void
    {
        $voucher = $this->draftBankReceipt();
        $this->receiptFlowSignedByAnOutsider();

        $this->get(route('accounts.voucher.show', $voucher))->assertOk();
        $this->get(route('search', ['q' => 'RCV']));

        $this->from(route('accounts.voucher.show', $voucher))
            ->post(route('accounts.voucher.post', $voucher))
            ->assertRedirect(route('accounts.voucher.show', $voucher));
    }

    /**
     * অনুমান ৫ (সার্ভারের দিক): খালি কারণ পাঠালে সার্ভার ভুলটা বলে।
     *
     * ⓘ সবুজ হলে নীরবতাটা পুরোপুরি ব্রাউজারের।
     */
    public function test_an_empty_cancel_reason_is_explained_by_the_server(): void
    {
        $voucher = $this->draftBankReceipt();

        $body = $this->from(route('accounts.voucher.show', $voucher))
            ->followingRedirects()
            ->post(route('accounts.voucher.cancel', $voucher), ['cancel_reason' => ''])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('role="alert"', $body, 'খালি কারণের ভুল পাতায় দেখানো হয়নি।');
        $this->assertNotSame(DocumentStatus::CANCELLED, $voucher->fresh()->status);
    }

    /**
     * অনুমান ৬: সইয়ের পর পর্দা যে নম্বরটা বাধ্যতামূলক চায়, সেটাই সইটা বাতিল করে।
     *
     * ⓘ পথটা লাইভের হুবহু: রসিদ নম্বর ছাড়া পাঠানো হয় → পরের চাপে পর্দা
     * নম্বর চায় (required) → সই আসে → আবার চাপ। ⚠️ ছাপে
     * ([[DocumentFingerprint]]) `instrument_no` আছে, তাই সইয়ের দিনের ছাপ
     * আর এখনকার ছাপ মেলে না।
     */
    public function test_a_signed_bank_receipt_posts_on_the_next_press(): void
    {
        $voucher = $this->draftBankReceipt();
        $this->receiptFlowSignedByAnOutsider();

        // ⓘ প্রথম পাঠানো — নম্বর ছাড়া (রসিদের ফর্মে নম্বরের ঘর নেই)
        $this->from(route('accounts.voucher.show', $voucher))
            ->post(route('accounts.voucher.post', $voucher));

        // ⓘ দ্বিতীয় চাপ — পর্দা এখন নম্বর চায়, তাই নম্বরসহ
        $this->from(route('accounts.voucher.show', $voucher))
            ->post(route('accounts.voucher.post', $voucher), ['instrument_no' => 'TRX-79C-1']);

        $held = Approval::query()
            ->where('approvable_type', Voucher::class)
            ->where('approvable_id', $voucher->id)
            ->pending()
            ->firstOrFail();

        // ⓘ সই — সরাসরি সারিতে, যাতে সইকারীর অনুমতির প্রশ্ন এখানে না ঢোকে
        $held->forceFill(['status' => Approval::APPROVED, 'decided_at' => now()])->save();

        $this->from(route('accounts.voucher.show', $voucher))
            ->post(route('accounts.voucher.post', $voucher));

        $this->assertSame(
            DocumentStatus::CONFIRMED,
            $voucher->fresh()->status,
            '⛔ সই পাওয়ার পরও রসিদ পোস্ট হয়নি — নতুন অনুরোধ: '
            .Approval::query()->where('approvable_type', Voucher::class)
                ->where('approvable_id', $voucher->id)->count().' সারি।',
        );
    }

    /**
     * ব্যাংকে একটা খসড়া আদায় — লেনদেন নম্বর ছাড়া, রসিদের ফর্ম যেমন পাঠায়।
     */
    private function draftBankReceipt(): Voucher
    {
        $bank = $this->bankAccount();

        $other = Account::query()
            ->where('company_id', $this->company->id)
            ->postable()
            ->active()
            ->whereKeyNot($bank->id)
            ->whereNull('money_kind')
            ->orderBy('code')
            ->firstOrFail();

        $voucher = app(VoucherService::class)->create([
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'narration' => 'SECOND-PRESS-79C',
            'instrument_no' => null,
        ], [
            ['account_id' => $bank->id, 'debit' => '500000', 'credit' => '0'],
            ['account_id' => $other->id, 'debit' => '0', 'credit' => '500000'],
        ]);

        $this->assertSame(DocumentStatus::DRAFT, $voucher->status, 'হেল্পারটা খসড়া দেয়নি।');
        $this->assertSame((int) $this->company->id, (int) $voucher->company_id);

        return $voucher;
    }

    private function bankAccount(): Account
    {
        $existing = Account::query()
            ->where('company_id', $this->company->id)
            ->where('money_kind', Account::BANK)
            ->postable()
            ->active()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $sibling = Account::query()
            ->where('company_id', $this->company->id)
            ->where('money_kind', Account::CASH)
            ->postable()
            ->firstOrFail();

        $account = $sibling->replicate(['public_id']);
        $account->forceFill([
            'code' => 'BANK-79C',
            'name_en' => 'BANK-79C',
            'name_bn' => 'BANK-79C',
            'money_kind' => Account::BANK,
        ])->save();

        return $account->refresh();
    }

    /**
     * আদায়ে অনুমোদনের ছক — একমাত্র সইকারী কোম্পানির বাইরের একজন (অনুমান ৪)।
     */
    private function receiptFlowSignedByAnOutsider(): void
    {
        $outsider = User::factory()->create();

        $flow = ApprovalFlow::create([
            'company_id' => $this->company->id,
            'module' => 'accounts',
            'action' => 'receipt',
            'is_active' => true,
        ]);

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => 'user',
            'approver_id' => $outsider->id,
        ]);
    }
}
