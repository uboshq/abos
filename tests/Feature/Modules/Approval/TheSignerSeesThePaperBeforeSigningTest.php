<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\MasterData\Models\Location;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * সইকারী কাগজটা দেখেই সই দেন — ২৮ সেপ্টেম্বর ২০২৬ ([[ShowsItselfForSigning]])।
 *
 * ── ⛔ কী ঘটত ────────────────────────────────────────────────────────
 * অনুমোদনের পাতায় ছিল পক্ষের নাম, অঙ্ক আর একটা লিংক — সারি, মাধ্যম,
 * লেনদেন নম্বর কিছুই না। ⚠️ সইকারী না দেখেই সই দিতেন, আর খাতায় থাকত
 * তাঁর নাম।
 *
 * ── ⓘ কী মাপা হয় ─────────────────────────────────────────────────────
 * পাতাটা সত্যিই আঁকা হয় (GET), আর HTML-এ খোঁজা হয় — ঘর ভরা থাকা আর পর্দায়
 * দেখা যাওয়া এক কথা নয়। ⚠️ প্রত্যাশিত লেখা __() আর Money::format() থেকে,
 * **অনুরোধের পরে** — মিডলওয়্যার ব্যবহারকারীর ভাষা বসায়, তাই আগে বানালে
 * অন্য ভাষার লেখা খোঁজা হত।
 */
final class TheSignerSeesThePaperBeforeSigningTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->company = Company::query()->findOrFail($this->owner->current_company_id);

        CompanyContext::set($this->company->id, $this->owner->current_branch_id
            ?? $this->company->defaultBranch()?->id);

        $this->signer = $this->memberOfTheCompany();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ১. ব্যাংকের রসিদ: লেনদেন নম্বর, সারি, যোগফল, জানালা ─────────────

    public function test_a_held_bank_receipt_shows_its_reference_lines_and_totals(): void
    {
        $this->flowFor(Voucher::RECEIPT, $this->signer);

        [$bank, $other] = $this->bankAndOther();
        $voucher = $this->draft(Voucher::RECEIPT, [
            ['account_id' => $bank->id, 'debit' => '500000', 'credit' => '0', 'narration' => 'SHEET-BANK-DR'],
            ['account_id' => $other->id, 'debit' => '0', 'credit' => '500000', 'narration' => 'SHEET-BANK-CR'],
        ], ['instrument' => 'transfer']);

        $approval = $this->hold($voucher, 'TRX-SHEET-7Q4K');

        $html = $this->page($this->signer, $approval);
        $sheet = $this->sheetOf($html);

        $this->assertFact($sheet, __('accounts::field.instrument_no'), 'TRX-SHEET-7Q4K');
        $this->assertStringContainsString('role="dialog"', $sheet, 'পুরো কাগজের জানালা পাতায় নেই।');

        foreach ([$bank, $other] as $account) {
            $this->assertStringContainsString(e($account->fresh()->label()), $sheet,
                "সারির খাত `{$account->code}` সইয়ের পাতায় নেই।");
        }

        $this->assertStringContainsString('SHEET-BANK-DR', $sheet);
        $this->assertStringContainsString('SHEET-BANK-CR', $sheet);

        /*
         * ⓘ অঙ্কটা সারিতে দুইবার (ডেবিট, ক্রেডিট) আর যোগফলে দুইবার — পাতায় ও
         * জানালায় একই, তাই অন্তত আটবার। ⚠️ কেবল "আছে" দেখলে যোগফলের সারি
         * হারালেও সবুজ থাকত।
         */
        $this->assertGreaterThanOrEqual(8, substr_count($sheet, e(Money::format('500000'))),
            'সারি আর যোগফল মিলিয়ে অঙ্কটা যতবার থাকার কথা ততবার নেই — যোগফলের সারি হারিয়েছে?');
        $this->assertStringContainsString(e(__('core.print.total')), $sheet, 'যোগফলের সারি নেই।');
    }

    /**
     * ⚠️ "টাকার খাত" ঘরটা — আলাদা দাবি, কারণ ব্যাংকের নাম সারিতেও আছে।
     *
     * ⓘ সারিতে খাতের নাম থাকলে উপরের দাবি সবুজ হত, অথচ মাথার ঘরটা খালি থাকতে
     * পারত। এটা ঘরটাকেই দেখে — লেবেলের ঠিক পরের মান।
     */
    public function test_a_held_bank_receipt_names_where_the_money_lands_in_its_facts(): void
    {
        $this->flowFor(Voucher::RECEIPT, $this->signer);

        [$bank, $other] = $this->bankAndOther();
        $voucher = $this->draft(Voucher::RECEIPT, [
            ['account_id' => $bank->id, 'debit' => '12000', 'credit' => '0'],
            ['account_id' => $other->id, 'debit' => '0', 'credit' => '12000'],
        ], ['instrument' => 'transfer']);

        $approval = $this->hold($voucher, 'TRX-WHERE-8M2P');

        $sheet = $this->sheetOf($this->page($this->signer, $approval));

        $this->assertFact($sheet, __('approval::field.where_money'), $bank->fresh()->label());
    }

    // ── ২. পক্ষ গ্রাহক: ফোন, পয়েন্ট, বকেয়া, সীমা ──────────────────────────

    public function test_a_customer_receipt_shows_the_customers_card(): void
    {
        $this->flowFor(Voucher::RECEIPT, $this->signer);

        $customer = $this->customer('01711000777', '25000', 'SHEET-PT');
        $approval = $this->hold($this->customerReceipt($customer, '3000'), 'TRX-CARD-5H1N');

        $sheet = $this->sheetOf($this->page($this->signer, $approval));
        $customer = $customer->fresh();

        $this->assertStringContainsString(e(__('approval::field.party_card')), $sheet, 'পক্ষের কার্ডটাই নেই।');
        $this->assertFact($sheet, __('customer::field.phone'), '01711000777');
        $this->assertFact($sheet, __('customer::field.point'), (string) $customer->location->name());
        $this->assertFact($sheet, __('customer::field.outstanding'), Money::format($customer->outstanding()));
        $this->assertFact($sheet, __('customer::field.credit_limit'), Money::format('25000'));
    }

    /** ⓘ সীমা শূন্য মানে "সীমা নেই" — "০ টাকা সীমা" দেখালে সইকারী ভুল বুঝতেন। */
    public function test_a_customer_without_a_credit_limit_shows_no_limit(): void
    {
        $this->flowFor(Voucher::RECEIPT, $this->signer);

        $customer = $this->customer('01711000888', '0', 'SHEET-P0');
        $approval = $this->hold($this->customerReceipt($customer, '4000'), 'TRX-NOLIM-3W6R');

        $sheet = $this->sheetOf($this->page($this->signer, $approval));

        // ⓘ কার্ডটা আছে — নাহলে "সীমা নেই" দাবিটা কিছুই মাপত না
        $this->assertFact($sheet, __('customer::field.phone'), '01711000888');
        $this->assertStringNotContainsString(e(__('customer::field.credit_limit')), $sheet,
            'সীমা শূন্য, তবু সীমার ঘর দেখানো হচ্ছে।');
        $this->assertStringNotContainsString(e(__('customer::field.available_limit')), $sheet,
            'সীমা শূন্য, তবু বাকি সীমার ঘর দেখানো হচ্ছে।');
    }

    // ── ৩. একই মানুষ, একই কাগজ — কেবল চাবি আলাদা ──────────────────────────

    public function test_an_auditor_sees_no_paper_until_the_same_person_may_decide(): void
    {
        $this->flowFor(Voucher::RECEIPT, $this->signer);

        [$bank, $other] = $this->bankAndOther();
        $approval = $this->hold($this->draft(Voucher::RECEIPT, [
            ['account_id' => $bank->id, 'debit' => '9000', 'credit' => '0'],
            ['account_id' => $other->id, 'debit' => '0', 'credit' => '9000'],
        ], ['instrument' => 'transfer']), 'TRX-AUDIT-9K7D');

        $auditor = $this->memberOfTheCompany();
        $auditor->givePermissionTo(Permission::firstOrCreate(['name' => 'approval.report', 'guard_name' => 'web']));

        $before = $this->page($auditor, $approval);

        $this->assertStringNotContainsString('data-signing-sheet', $before,
            '⛔ কেবল হিসাব দেখার চাবি নিয়ে নিরীক্ষক কাগজের সারি দেখছেন।');
        $this->assertStringNotContainsString('TRX-AUDIT-9K7D', $before,
            '⛔ নিরীক্ষক লেনদেন নম্বর দেখছেন।');

        // ⭐ চাবি: একই মানুষ এবার একই স্তরে সইকারী
        ApprovalFlowStep::create([
            'approval_flow_id' => ApprovalFlow::query()->where('module', 'accounts')
                ->where('action', Voucher::RECEIPT)->value('id'),
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $auditor->id,
        ]);

        /*
         * ⚠️ ইঞ্জিন ছকগুলো জমিয়ে রাখে, আর রুট তার কন্ট্রোলারকে (ইঞ্জিনসহ) —
         * না ঝাড়লে দ্বিতীয় অনুরোধ নতুন ধাপটা দেখত না, আর লালটা বাগের মতো দেখাত।
         */
        $this->freshEngine();

        $after = $this->page($auditor, $approval);

        $this->assertStringContainsString('data-signing-sheet', $after,
            'সইকারী হওয়ার পরও কাগজের পাতা দেখানো হয়নি।');
        $this->assertStringContainsString('TRX-AUDIT-9K7D', $after);
    }

    // ── ৪. আট সারির জার্নাল: পাতায় ছয়, জানালায় আট ──────────────────────

    public function test_a_long_journal_previews_six_rows_and_the_window_holds_all(): void
    {
        $this->flowFor(Voucher::JOURNAL, $this->signer);

        $accounts = Account::query()
            ->where('company_id', $this->company->id)
            ->postable()->active()->whereNull('money_kind')
            ->orderBy('code')->limit(2)->get();

        $this->assertCount(2, $accounts, 'ডেমো ডেটায় দুইটা সাধারণ খাত নেই।');

        $lines = [];

        foreach (range(1, 8) as $i) {
            $lines[] = [
                'account_id' => $accounts[$i % 2]->id,
                'debit' => $i % 2 === 1 ? '100' : '0',
                'credit' => $i % 2 === 0 ? '100' : '0',
                'narration' => 'JRNL-SHEET-LINE-'.$i,
            ];
        }

        $approval = $this->hold($this->draft(Voucher::JOURNAL, $lines));

        $html = $this->page($this->signer, $approval);
        $sheet = $this->sheetOf($html);

        $this->assertStringContainsString(
            e(__('approval::message.more_rows_in_paper', ['count' => 2])),
            $sheet,
            'আট সারির কাগজে "আরও ২ সারি" লেখা নেই।',
        );

        /*
         * ⭐ প্রথম ছয়টা দুইবার (পাতা + জানালা), শেষ দুইটা একবার (কেবল জানালা)।
         * ⓘ গুনে দেখা — কেবল "আছে" দেখলে পাতা আটটাই দেখালেও সবুজ থাকত।
         */
        foreach (range(1, 8) as $i) {
            // ⓘ ১ থেকে ৮ — কোনোটা অন্যটার উপসর্গ নয় (১০ নেই), তাই সরাসরি গোনা চলে
            $this->assertSame($i <= 6 ? 2 : 1, substr_count($sheet, 'JRNL-SHEET-LINE-'.$i),
                "সারি {$i} যতবার থাকার কথা ততবার নেই।");
        }

        foreach ($accounts as $account) {
            $this->assertStringContainsString(e($account->fresh()->label()), $sheet);
        }
    }

    // ── ৫. যে কাগজ চুক্তিটা দেয় না ────────────────────────────────────────

    public function test_a_document_without_a_sheet_still_opens(): void
    {
        /*
         * ⚠️ DemoSeeder নিজেই `sales.discount` ছক বসায় (মালিক সইকারী, ১,০০০-এর
         * উপরে) — দ্বিতীয়টা বানালে unique সূচকে আটকাত। তাই ওটাতেই একটা ধাপ।
         */
        $flow = ApprovalFlow::query()->where('module', 'sales')->where('action', 'discount')->firstOrFail();

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->signer->id,
        ]);

        // ⓘ শাখা — পাশের পরীক্ষাগুলোর মতো; ShowsItselfForSigning দেয় না
        $document = Branch::create(['code' => 'B'.uniqid(), 'name_en' => 'No Sheet Doc']);

        $approval = app(ApprovalEngine::class)->request(
            document: $document,
            module: 'sales',
            action: 'discount',
            amount: '50000',
            userId: $this->owner->id,
        );

        $this->assertNotNull($approval, 'অনুরোধই তৈরি হয়নি — পরীক্ষাটা কিছু মাপছে না।');

        $html = $this->page($this->signer, $approval);

        $this->assertStringNotContainsString('data-signing-sheet', $html,
            'চুক্তি না দেওয়া কাগজে সইয়ের পাতা দেখানো হচ্ছে।');
    }

    // ── হাতিয়ার ────────────────────────────────────────────────────────

    private function memberOfTheCompany(): User
    {
        $user = User::factory()->create([
            'current_company_id' => $this->company->id,
            'current_branch_id' => $this->owner->current_branch_id,
        ]);
        $user->companies()->syncWithoutDetaching([$this->company->id]);

        return $user;
    }

    private function flowFor(string $action, User $signer): void
    {
        $flow = ApprovalFlow::create([
            'company_id' => $this->company->id,
            'module' => 'accounts',
            'action' => $action,
            'is_active' => true,
        ]);

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $signer->id,
        ]);
    }

    /** @return array{0: Account, 1: Account} */
    private function bankAndOther(): array
    {
        $bank = Account::query()
            ->where('company_id', $this->company->id)
            ->where('money_kind', Account::BANK)
            ->postable()->active()
            ->first();

        if ($bank === null) {
            $sibling = Account::query()
                ->where('company_id', $this->company->id)
                ->where('money_kind', Account::CASH)
                ->postable()
                ->firstOrFail();

            $bank = $sibling->replicate(['public_id']);
            $bank->forceFill([
                'code' => 'BANK-SHEET',
                'name_en' => 'Sheet Bank',
                'name_bn' => null,
                'money_kind' => Account::BANK,
            ])->save();
        }

        $other = Account::query()
            ->where('company_id', $this->company->id)
            ->postable()->active()
            ->whereKeyNot($bank->id)
            ->whereNull('money_kind')
            ->orderBy('code')
            ->firstOrFail();

        return [$bank, $other];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra
     */
    private function draft(string $type, array $lines, array $extra = []): Voucher
    {
        $this->actingAs($this->owner);

        $voucher = app(VoucherService::class)->create([
            'type' => $type,
            'trx_date' => now()->toDateString(),
            'narration' => 'SHEET-'.strtoupper($type),
        ] + $extra, $lines);

        $this->assertSame(DocumentStatus::DRAFT, $voucher->status, 'হেল্পারটা খসড়া দেয়নি।');

        return $voucher;
    }

    /**
     * পর্দা যেভাবে পাঠায় — "পোস্ট" চাপ, সাথে লেনদেন নম্বর; প্রবাহ থাকায় আটকায়।
     */
    private function hold(Voucher $voucher, ?string $reference = null): Approval
    {
        $this->actingAs($this->owner)
            ->from(route('accounts.voucher.show', $voucher))
            ->post(route('accounts.voucher.post', $voucher), array_filter(['instrument_no' => $reference]))
            ->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::DRAFT, $voucher->fresh()->status,
            'ভাউচারটা অনুমোদনে না আটকে খাতায় চলে গেছে — প্রবাহ বসেনি।');

        return Approval::query()
            ->where('approvable_type', Voucher::class)
            ->where('approvable_id', $voucher->id)
            ->pending()
            ->firstOrFail();
    }

    private function customer(string $phone, string $limit, string $pointCode): Customer
    {
        $this->actingAs($this->owner);

        $area = Location::query()->create([
            'company_id' => $this->company->id, 'code' => $pointCode.'-A', 'level' => Location::TERRITORY,
            'name_en' => 'Sheet Area '.$pointCode, 'name_bn' => null, 'is_active' => true,
        ]);
        $point = Location::query()->create([
            'company_id' => $this->company->id, 'code' => $pointCode, 'level' => Location::POINT,
            'parent_id' => $area->id, 'name_en' => 'Sheet Point '.$pointCode, 'name_bn' => null, 'is_active' => true,
        ]);

        $customer = app(CustomerService::class)->create([
            'name_en' => 'Sheet Store '.$pointCode,
            'owner_name' => 'Karim',
            'phone' => $phone,
            'address_en' => '7 Mill Road',
            'location_id' => $point->id,
            'credit_limit' => 0,
            'credit_days' => 0,
        ]);

        /*
         * ⚠️ নতুন গ্রাহক শূন্য সীমায় তৈরি হয় — সীমা বসাতে আলাদা সই লাগে
         * (CustomerService)। ⓘ এই পরীক্ষা সীমার অনুমোদন মাপে না, কার্ডে সীমা
         * দেখানো মাপে — তাই সই-পাওয়া সীমার অবস্থাটা সরাসরি বসানো।
         */
        $customer->forceFill(['credit_limit' => $limit])->save();

        return $customer->fresh();
    }

    private function customerReceipt(Customer $customer, string $amount): Voucher
    {
        [$bank, $other] = $this->bankAndOther();

        return $this->draft(Voucher::RECEIPT, [
            ['account_id' => $bank->id, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => $other->id, 'debit' => '0', 'credit' => $amount],
        ], [
            'instrument' => 'transfer',
            'party_type' => Customer::drillSourceType(),
            'party_id' => $customer->id,
        ]);
    }

    private function page(User $user, Approval $approval): string
    {
        return $this->actingAs($user)
            ->get(route('approval.inbox.show', $approval->id))
            ->assertOk()
            ->getContent();
    }

    /** ⓘ পাতার সইয়ের অংশটুকু — মাথার অঙ্ক বা ইতিহাস যেন দাবিকে সবুজ না করে। */
    private function sheetOf(string $html): string
    {
        $at = strpos($html, 'data-signing-sheet');

        $this->assertNotFalse($at, 'সইয়ের পাতাটাই আঁকা হয়নি (data-signing-sheet নেই)।');

        $end = strpos($html, 'role="dialog"', $at);
        // ⓘ জানালার শেষ পর্যন্ত — জানালার পরে একটা সীমারেখা খোঁজা ঝুঁকির, তাই বাকিটা রাখা
        $this->assertNotFalse($end, 'পুরো কাগজের জানালা নেই।');

        return substr($html, $at);
    }

    /** ঘরের লেবেল আর তার ঠিক পরের মান — ⚠️ কেবল "লেখাটা কোথাও আছে" নয়। */
    private function assertFact(string $html, string $label, string $value): void
    {
        $pattern = '~<dt[^>]*>\s*'.preg_quote(e($label), '~').'\s*</dt>\s*<dd[^>]*>\s*'
            .preg_quote(e($value), '~').'\s*</dd>~u';

        $this->assertMatchesRegularExpression($pattern, $html,
            "ঘর \"{$label}\"-এর পাশে \"{$value}\" নেই।");
    }

    private function freshEngine(): void
    {
        $this->app->forgetInstance(ApprovalEngine::class);
        $this->app->forgetScopedInstances();
        app('router')->getRoutes()->getByName('approval.inbox.show')?->flushController();
    }
}
