<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\SettingsService;
use App\Core\Services\StatusNotices;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\ComplianceObligation;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\ComplianceObligations;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ করণীয় — পরিশোধে কাটা AIT/VDS কোষাগারে জমা আর মূসক-৬.৬-এর দায় হয়ে বসে, শেষ তারিখসহ (টাকা দেওয়ার মিল-তালিকা #৩; fe, ২২
 * অক্টোবর ২০২৬)।
 *
 * ⓘ fe-র দাবিগুলো: সত্যি কাটলে তবেই বসে; দুবার পোস্টে দুবার বসে না; উল্টালে (বাতিল, চেক ফেরত) বাতিল হয়; কোষাগারে জমার অঙ্কের যোগ =
 * পরিশোধের কাটা AIT + VDS; শেষ তারিখ সেটিং থেকে (ডিফল্ট পরের মাসের ১৫); "করা হয়েছে" নম্বরসহ, নিরীক্ষায়; সময় পেরোলে লাল নোটিশ।
 */
final class TheTaxWithheldOnAPaymentBecomesADutyWithADueDateTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->supplier = app(SupplierService::class)->create([
            'name_en' => 'Duty Mill', 'branch_id' => $this->company->defaultBranch()?->id, 'credit_limit' => 0, 'credit_days' => 0,
            'party_type_id' => Supplier::query()->orderBy('id')->value('party_type_id'),
        ]);
        app(SettingsService::class)->set('purchase.vat_enabled', true);
        $this->putMoneyIn($this->till(), '50000');
    }

    public function test_a_real_cut_raises_two_deposits_and_one_mushak_6_6_whose_deposits_sum_to_the_cut(): void
    {
        $payment = $this->paidWithRates('5', '15', on: '2026-10-22');

        $duties = $this->dutiesOf($payment);
        $this->assertSame(['kosagar_deposit|ait', 'kosagar_deposit|vds', 'mushak_6_6_issue|vds'], $duties->keys()->sort()->values()->all(),
            '⛔ কাটা AIT আর VDS-এ ঠিক এই তিনটা করণীয় বসেনি');
        $this->assertSame('500.00', (string) $duties['kosagar_deposit|ait']->amount, '⛔ AIT জমার অঙ্ক কাটার সমান নয়');
        $this->assertSame('225.00', (string) $duties['kosagar_deposit|vds']->amount, '⛔ VDS জমার অঙ্ক কাটার সমান নয়');

        // ⭐ fe: কোষাগারে জমার যোগফল = পরিশোধে কাটা AIT + VDS (bcmath)
        $deposits = $duties->filter(fn ($o) => $o->kind === ComplianceObligation::KOSAGAR_DEPOSIT)
            ->reduce(fn (string $sum, $o) => bcadd($sum, (string) $o->amount, 2), '0');
        $this->assertSame(0, bccomp($deposits, bcadd((string) $payment->ait_amount, (string) $payment->vds_amount, 2), 2),
            '⛔ কোষাগারে জমার যোগফল পরিশোধে কাটা AIT + VDS-এর সমান নয়');

        foreach (['kosagar_deposit|ait', 'kosagar_deposit|vds'] as $key) {
            $this->assertSame('2026-11-15', $duties[$key]->due_on->toDateString(), "⛔ {$key}: কোষাগারে জমার শেষ তারিখ পরের মাসের ১৫ নয় (ডিফল্ট)");
        }
        // ⭐ fe: মূসক-৬.৬ কর্তনের দিন থেকে গোনা — পোস্টের তারিখ + ৩ দিন (ডিফল্ট)
        $this->assertSame('2026-10-25', $duties['mushak_6_6_issue|vds']->due_on->toDateString(), '⛔ মূসক-৬.৬-এর শেষ তারিখ কর্তনের দিন + ৩ নয়');

        foreach ($duties as $duty) {
            $this->assertSame((string) $payment->document_no, (string) $duty->source_no, '⛔ করণীয়ে কাগজের নম্বর নেই');
        }
    }

    public function test_nothing_cut_means_no_duty(): void
    {
        $payment = $this->paidWithRates('0', '0');

        $this->assertSame(0, $this->dutiesOf($payment)->count(), '⛔ কিছু কাটা হয়নি, তবু করণীয় বসল');
    }

    public function test_a_second_post_or_a_second_raise_never_makes_a_second_row(): void
    {
        $payment = $this->paidWithRates('5', '15');
        $before = ComplianceObligation::query()->count();

        try {
            app(PaymentService::class)->confirm($payment->fresh());
        } catch (\Illuminate\Validation\ValidationException) {
            // ⓘ পোস্ট হয়ে যাওয়া পরিশোধ আবার পোস্ট হয় না — প্রত্যাশিত
        }
        app(ComplianceObligations::class)->raise((int) $this->company->id, null, Payment::drillSourceType(), (int) $payment->id,
            (string) $payment->document_no, ComplianceObligation::KOSAGAR_DEPOSIT, ComplianceObligation::AIT, '500', now());

        $this->assertSame($before, ComplianceObligation::query()->count(), '⛔ একই পরিশোধের একই দায় দুবার বসল');
    }

    public function test_a_cancel_cancels_the_open_duties_but_keeps_one_already_done(): void
    {
        $payment = $this->paidWithRates('5', '15');
        $ait = $this->dutiesOf($payment)['kosagar_deposit|ait'];
        app(ComplianceObligations::class)->markDone($ait, $this->owner, 'CH-2026-77');

        app(PaymentService::class)->cancel($payment->fresh(), 'Wrong supplier');

        $after = $this->dutiesOf($payment);
        $this->assertNotNull($after['kosagar_deposit|vds']->cancelled_at, '⛔ বাতিল পরিশোধের VDS জমার করণীয় খোলা রইল');
        $this->assertNotNull($after['mushak_6_6_issue|vds']->cancelled_at, '⛔ বাতিল পরিশোধের মূসক-৬.৬ করণীয় খোলা রইল');
        $this->assertNull($after['kosagar_deposit|ait']->cancelled_at, '⛔ সত্যিই জমা হয়ে যাওয়া করণীয় বাতিল দেখাল');
        $this->assertSame('CH-2026-77', $after['kosagar_deposit|ait']->ref_no);
    }

    public function test_a_bounced_cheque_cancels_its_duties(): void
    {
        $this->supplier->forceFill(['ait_rate' => '5', 'vds_rate' => '15'])->save();
        $bill = $this->bill('10000', '1500');
        $bank = \App\Modules\Accounts\Models\Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-DUTY', 'name_en' => 'Duty bank', 'name_bn' => 'দায়ের ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => \App\Modules\Accounts\Models\Account::ASSET,
            'nature' => \App\Modules\Accounts\Models\Account::DEBIT, 'money_kind' => \App\Modules\Accounts\Models\Account::BANK,
        ]);
        $payments = app(PaymentService::class);
        $payment = $payments->confirm($payments->create([
            'supplier_id' => $this->supplier->id, 'account_id' => $bank->id, 'trx_date' => now()->toDateString(), 'amount' => '11500',
            'instrument' => 'cheque', 'instrument_no' => 'CHQ-DUTY', 'instrument_date' => now()->toDateString(),
        ], [['purchase_bill_id' => $bill->id, 'amount' => '11500']]));
        $this->assertSame(3, $this->dutiesOf($payment)->count(), 'প্রস্তুতিটাই ভুল — চেকের পরিশোধে করণীয় বসেনি');

        app(\App\Modules\Accounts\Services\ChequeService::class)->bounce($payment->cheque()->firstOrFail(), 'তহবিল নেই');

        foreach ($this->dutiesOf($payment) as $key => $duty) {
            $this->assertNotNull($duty->cancelled_at, "⛔ চেক ফেরতের পরেও করণীয় {$key} খোলা — টাকা যায়নি, অথচ জমার তাড়া");
        }
    }

    public function test_the_due_day_comes_from_the_setting_and_stays_inside_the_month(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('accounts.tax.ait_deposit_day', 10);
        $settings->set('accounts.tax.vds_deposit_day', 31);
        $duties = app(ComplianceObligations::class);

        $this->assertSame('2026-11-10', $duties->dueOn(ComplianceObligation::KOSAGAR_DEPOSIT, ComplianceObligation::AIT, '2026-10-31')->toDateString(),
            '⛔ AIT জমার শেষ দিন সেটিং মানল না');
        $this->assertSame('2027-02-28', $duties->dueOn(ComplianceObligation::KOSAGAR_DEPOSIT, ComplianceObligation::VDS, '2027-01-31')->toDateString(),
            '⛔ ৩১ লিখলে ফেব্রুয়ারির বাইরে বা মার্চে গেল — কিনারা ২৮ নয়');
        $this->assertSame('2026-10-04', $duties->dueOn(ComplianceObligation::MUSHAK_66_ISSUE, ComplianceObligation::VDS, '2026-10-01')->toDateString(),
            '⛔ মূসক-৬.৬-এর শেষ দিন কর্তনের দিন + ৩ (ডিফল্ট) নয়');
        $settings->set('accounts.tax.mushak_66_issue_days', 7);
        $this->assertSame('2026-11-04', $duties->dueOn(ComplianceObligation::MUSHAK_66_ISSUE, ComplianceObligation::VDS, '2026-10-28')->toDateString(),
            '⛔ মূসক-৬.৬-এর দিনের সেটিং মানল না, বা মাস পেরোতে পারল না');
    }

    public function test_the_list_marks_done_only_with_a_number_and_the_audit_keeps_it(): void
    {
        $payment = $this->paidWithRates('5', '15');
        $duty = $this->dutiesOf($payment)['kosagar_deposit|vds'];

        $this->get(route('accounts.obligation.index'))->assertOk()->assertSee((string) $payment->document_no)
            ->assertSee(__('accounts::obligation.kind.kosagar_deposit_vds'));

        $this->from(route('accounts.obligation.index'))->post(route('accounts.obligation.done', $duty->id), ['ref_no' => ' ']);
        $this->assertNull($duty->fresh()->done_at, '⛔ নম্বর ছাড়াই "করা হয়েছে" হল');

        $this->from(route('accounts.obligation.index'))->post(route('accounts.obligation.done', $duty->id), ['ref_no' => 'CH-1102-55']);
        $duty = $duty->fresh();
        $this->assertNotNull($duty->done_at, '⛔ নম্বরসহ "করা হয়েছে" বসল না');
        $this->assertSame([$this->owner->id, 'CH-1102-55'], [(int) $duty->done_by, $duty->ref_no], '⛔ কে করলেন বা কোন নম্বরে, রাখা হয়নি');
        $this->assertTrue(AuditTrail::query()->where('action', 'obligation_done')->where('auditable_id', $duty->id)->exists(),
            '⛔ "করা হয়েছে" নিরীক্ষায় ওঠেনি');

        $this->from(route('accounts.obligation.index'))->post(route('accounts.obligation.done', $duty->id), ['ref_no' => 'CH-AGAIN']);
        $this->assertSame('CH-1102-55', $duty->fresh()->ref_no, '⛔ করা হয়ে যাওয়া করণীয়ে আবার "করা হয়েছে" বসল');
    }

    public function test_without_the_key_the_list_and_the_mark_are_shut(): void
    {
        $payment = $this->paidWithRates('5', '15');
        $duty = $this->dutiesOf($payment)['kosagar_deposit|ait'];
        $clerk = $this->someone(['accounts.view']);

        $this->actingAs($clerk);
        $this->get(route('accounts.obligation.index'))->assertForbidden();
        $this->post(route('accounts.obligation.done', $duty->id), ['ref_no' => 'X'])->assertForbidden();

        $viewer = $this->someone(['accounts.obligation.view']);
        $this->actingAs($viewer);
        $this->get(route('accounts.obligation.index'))->assertOk();
        $this->post(route('accounts.obligation.done', $duty->id), ['ref_no' => 'X'])->assertForbidden();
        $this->assertNull($duty->fresh()->done_at, '⛔ দেখার চাবিতেই "করা হয়েছে" বসল');
    }

    /** ⭐ fe: সর্বমোট ছাঁকা সব দায়ের, পাতার নয় — ৫৫টা দায় দুই পাতায় (৫০ + ৫), প্রথম পাতাতেই ৫৫০.০০ */
    public function test_the_grand_total_sums_every_page_not_only_the_one_shown(): void
    {
        $duties = app(ComplianceObligations::class);
        for ($i = 1; $i <= 55; $i++) {
            $duties->raise((int) $this->company->id, $this->company->defaultBranch()?->id, 'test:paper', $i, 'TP-'.$i,
                ComplianceObligation::KOSAGAR_DEPOSIT, ComplianceObligation::AIT, '10', now());
        }

        $html = (string) $this->get(route('accounts.obligation.index'))->assertOk()->getContent();

        $this->assertSame(50, substr_count($html, 'data-obligation-done-form'), 'প্রস্তুতিটাই ভুল — প্রথম পাতায় ৫০টা সারি নেই');
        $this->assertMatchesRegularExpression('/data-grand-total-row[\s\S]*?550\.00/', $html,
            '⛔ সর্বমোট সব পাতার (৫৫ × ১০ = ৫৫০) নয় — কেবল দেখানো পাতার যোগ, বা নেই');
    }

    public function test_an_overdue_duty_is_red_on_the_list_and_on_the_bar(): void
    {
        $payment = $this->paidWithRates('5', '15');
        $this->dutiesOf($payment)->each(fn ($o) => $o->forceFill(['due_on' => Carbon::yesterday()->toDateString()])->save());
        Cache::flush();

        // ⓘ করণীয়ের নোটিশটা তার ঠিকানা ধরে খোঁজা — বারে অন্য লাল নোটিশও থাকে (যেমন ব্যাকআপ আর খাতা একই ডিস্কে), প্রথম লালটা আমাদের নাও হতে পারে
        $notice = collect(app(StatusNotices::class)->all())
            ->first(fn (array $n) => str_contains((string) ($n['url'] ?? ''), route('accounts.obligation.index', [], false)));
        $this->assertNotNull($notice, '⛔ সময় পেরোনো করণীয়ের নোটিশ নেই');
        $this->assertSame('danger', $notice['tone'], '⛔ সময় পেরোনো করণীয়ের নোটিশ লাল নয় — বারে ওঠে না');
        $this->assertSame(trans_choice('accounts::obligation.notice_overdue', 3, ['count' => 3]), $notice['text'], '⛔ নোটিশ সময় পেরোনো করণীয় গোনে না');

        $this->get(route('accounts.obligation.index', ['tab' => 'overdue']))->assertOk()->assertSee('data-obligation-overdue', false);
    }

    // ── সহায়ক ────────────────────────────────────────────────────────────

    private function paidWithRates(string $ait, string $vds, ?string $on = null): Payment
    {
        $this->supplier->forceFill(['ait_rate' => $ait, 'vds_rate' => $vds])->save();
        $bill = $this->bill('10000', '1500', $on);
        $payments = app(PaymentService::class);

        return $payments->confirm($payments->create([
            'supplier_id' => $this->supplier->id, 'account_id' => $this->till()->id, 'trx_date' => $on ?? now()->toDateString(),
            'amount' => '11500', 'instrument' => 'cash',
        ], [['purchase_bill_id' => $bill->id, 'amount' => '11500']]))->fresh();
    }

    private function bill(string $rate, string $tax, ?string $on = null): PurchaseBill
    {
        $product = Product::query()->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)
            ->where('qc_required', false)->orderBy('id')->firstOrFail();
        $bills = app(PurchaseBillService::class);

        return $bills->confirm($bills->create([
            'supplier_id' => $this->supplier->id, 'trx_date' => $on ?? now()->toDateString(), 'due_on' => now()->addDays(5)->toDateString(),
        ], [['product_id' => $product->id, 'qty' => '1', 'rate' => $rate, 'tax' => $tax]])->fresh())->fresh();
    }

    /** @return \Illuminate\Support\Collection<string, ComplianceObligation> "ধরন|বিষয়" → সারি */
    private function dutiesOf(Payment $payment): \Illuminate\Support\Collection
    {
        return ComplianceObligation::query()->where('source_type', Payment::drillSourceType())->where('source_id', $payment->id)->get()
            ->keyBy(fn ($o) => $o->kind.'|'.$o->subject);
    }

    private function till(): \App\Modules\Accounts\Models\Account
    {
        return app(CashTillService::class)->ensurePrimaryTill()->account;
    }

    /** @param  list<string>  $keys */
    private function someone(array $keys): User
    {
        $user = User::factory()->create([
            'current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id, 'is_active' => true,
        ]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, function () use ($user, $keys): void {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
