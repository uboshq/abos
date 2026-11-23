<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\CreditControl\Services\ReceivableCreditLoss;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⛔ গ্রাহকের পাওনায় সম্ভাব্য ক্ষতির কোনো ভাতা ছিল না, আর অনাদায়ী পাওনা বাদ দেওয়ার কাগজ ছিল না (ERP ১০০%-এর হিসাবের ফাঁক #১৮,
 * ফ্রিজের পরের অংশ)।
 *
 * <p>IFRS 9.5.5.15 (সরল পদ্ধতি — বয়সভিত্তিক হার), 9.5.4.4 (বাদ দেওয়া)। এখন: বয়সের ভাগ × মালিকের হার = ভাতার লক্ষ্য, ভাতার খাতের
 * সাথে তফাতের খসড়া জাবেদা; আর একজন গ্রাহকের অনাদায়ী বাদের খসড়া — Cr তাঁর পাওনা, Dr ভাতা যতটা আছে, বাকি Dr ক্ষতির খরচ।
 * দুটোই খসড়া, পাকা হয় ভাউচারের নিজের পথে (লেখক ≠ পাকাকারী)।
 */
final class TheReceivablesCarriedNoExpectedLossTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $accountant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        foreach (['current' => '1', '30' => '5', '60' => '20', '90' => '50'] as $bucket => $rate) {
            app(SettingsService::class)->set('credit_control.ecl_rate_'.$bucket, $rate);
        }

        $this->customer = Customer::query()->orderBy('id')->firstOrFail();
        $this->accountant = $this->staff(['credit_control.credit_loss', 'credit_control.view', 'accounts.voucher.create', 'accounts.voucher.view']);
    }

    public function test_the_target_is_each_age_bucket_times_its_rate_and_the_draft_brings_the_allowance_to_it(): void
    {
        $loss = app(ReceivableCreditLoss::class);
        $today = now()->toDateString();
        $before = $loss->position($today);

        $this->owe(10, '10000');   // 0–30
        $this->owe(45, '2000');    // 31–60
        $this->owe(100, '1000');   // 90+

        $after = $loss->position($today);
        $delta = fn (string $key) => bcsub($this->bucket($after, $key)['due'], $this->bucket($before, $key)['due'], 2);
        $this->assertSame(0, bccomp($delta('bucket_current'), '10000', 2), 'প্রস্তুতিটাই ভুল — 0–30-এর বকেয়া বাড়েনি');
        $this->assertSame(0, bccomp($delta('bucket_30'), '2000', 2));
        $this->assertSame(0, bccomp($delta('bucket_90'), '1000', 2));

        // ⛔ প্রতিটা ভাগ: বকেয়া × হার ÷ ১০০, আর লক্ষ্য তাদের যোগ
        $sum = '0';
        foreach ($after['buckets'] as $bucket) {
            $expected = bcdiv(bcmul($bucket['due'], $bucket['rate'], 8), '100', 2);
            $this->assertSame(0, bccomp($bucket['loss'], $expected, 2), '⛔ '.$bucket['key'].'-এ হার খাটেনি');
            $sum = bcadd($sum, $bucket['loss'], 2);
        }
        $this->assertSame(0, bccomp($after['target'], $sum, 2));
        $this->assertSame(0, bccomp($this->bucket($after, 'bucket_90')['rate'], '50', 2), '⛔ 90+ ভাগে মালিকের হার নেই');

        // খসড়া — হিসাবরক্ষক লেখেন, পাতা দেখে
        $this->actingAs($this->accountant);
        $this->get(route('credit_control.loss.show'))->assertOk()->assertSee(\App\Core\Support\Money::format($after['target']));
        $this->post(route('credit_control.loss.provision'), ['as_of' => $today])->assertSessionHasNoErrors()->assertRedirect();
        $voucher = Voucher::query()->latest('id')->firstOrFail();
        $this->assertSame(Voucher::JOURNAL, $voucher->type);
        $this->assertSame(\App\Core\Support\DocumentStatus::DRAFT, $voucher->status, '⛔ সংরক্ষণ সরাসরি পাকা হলো — দ্বিতীয় হাত ছাড়া');
        $allowance = (int) StandardChart::find(StandardChart::LOSS_ALLOWANCE)->id;
        $expense = (int) StandardChart::find(StandardChart::IMPAIRMENT_LOSS)->id;
        $this->assertSame(0, bccomp((string) $voucher->lines()->where('account_id', $expense)->sum('debit'), $after['adjustment'], 2), '⛔ ক্ষতির খরচে তফাতটুকু Dr নয়');
        $this->assertSame(0, bccomp((string) $voucher->lines()->where('account_id', $allowance)->sum('credit'), $after['adjustment'], 2), '⛔ ভাতায় তফাতটুকু Cr নয়');

        // অন্য হাত পাকা করেন — ভাতা লক্ষ্যে পৌঁছায়, আর তখন নতুন খসড়ার দরকার নেই
        $this->actingAs($this->owner);
        app(VoucherService::class)->post($voucher->fresh());
        $this->assertSame(0, bccomp($loss->allowanceHeld($today), $after['target'], 2), '⛔ পাকা হওয়ার পরেও ভাতা লক্ষ্যে নেই');

        $this->actingAs($this->accountant);
        $this->post(route('credit_control.loss.provision'), ['as_of' => $today])->assertSessionHasErrors('as_of');
    }

    public function test_a_write_off_credits_the_customers_receivable_and_draws_on_the_allowance_first(): void
    {
        $today = now()->toDateString();
        $this->owe(100, '4000');
        $loss = app(ReceivableCreditLoss::class);

        // ⓘ ভাতায় ১,০০০ — ১,৫০০ বাদ দিলে ১,০০০ ভাতা থেকে, ৫০০ খরচে
        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: $today, lines: [
            ['account_id' => (int) StandardChart::find(StandardChart::IMPAIRMENT_LOSS)->id, 'debit' => '1000'],
            ['account_id' => (int) StandardChart::find(StandardChart::LOSS_ALLOWANCE)->id, 'credit' => '1000'],
        ]);
        $held = $loss->allowanceHeld($today);
        $this->assertSame(0, bccomp($held, '1000', 2), 'প্রস্তুতিটাই ভুল — ভাতায় ১,০০০ নেই');

        $this->actingAs($this->accountant);
        $this->post(route('credit_control.loss.write_off'), [
            'customer_id' => $this->customer->id, 'amount' => '1500', 'reason' => 'Shop closed, owner untraceable', 'as_of' => $today,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $voucher = Voucher::query()->latest('id')->firstOrFail();
        $receivable = (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
        $credit = $voucher->lines()->where('account_id', $receivable)->firstOrFail();
        $this->assertSame(0, bccomp((string) $credit->credit, '1500', 2));
        $this->assertSame(Customer::drillSourceType(), $credit->party_type, '⛔ বাদ গ্রাহকের নামে নয়');
        $this->assertSame((int) $this->customer->id, (int) $credit->party_id);
        $this->assertSame(0, bccomp((string) $voucher->lines()->where('account_id', (int) StandardChart::find(StandardChart::LOSS_ALLOWANCE)->id)->sum('debit'), '1000', 2), '⛔ আগে ভাতা থেকে নয়');
        $this->assertSame(0, bccomp((string) $voucher->lines()->where('account_id', (int) StandardChart::find(StandardChart::IMPAIRMENT_LOSS)->id)->sum('debit'), '500', 2), '⛔ ভাতার বাইরের অংশ খরচে নয়');
        $this->assertStringContainsString('Shop closed', (string) $voucher->narration, '⛔ কারণ কাগজে নেই');
        $this->assertSame(\App\Core\Support\DocumentStatus::DRAFT, $voucher->status, '⛔ বাদ সরাসরি পাকা হলো');
    }

    public function test_more_than_the_due_no_reason_or_a_branch_limited_hand_is_refused(): void
    {
        $today = now()->toDateString();
        $due = app(ReceivableCreditLoss::class)->dueOf($this->customer, $today);
        $count = Voucher::query()->count();

        $this->actingAs($this->accountant);
        $this->post(route('credit_control.loss.write_off'), [
            'customer_id' => $this->customer->id, 'amount' => bcadd($due, '1', 2), 'reason' => 'x', 'as_of' => $today,
        ])->assertSessionHasErrors('amount');
        $this->post(route('credit_control.loss.write_off'), [
            'customer_id' => $this->customer->id, 'amount' => '1', 'reason' => '', 'as_of' => $today,
        ])->assertSessionHasErrors('reason');

        $limited = $this->staff(['credit_control.credit_loss', 'accounts.voucher.create']);
        \App\Models\UserDataScope::query()->create([
            'user_id' => $limited->id, 'company_id' => $this->company->id, 'scope_type' => \App\Models\UserDataScope::BRANCH,
            'scope_id' => $this->company->defaultBranch()->id,
        ]);
        app(\App\Core\Services\DataScope::class)->forget();
        $this->actingAs($limited);
        $this->post(route('credit_control.loss.provision'), ['as_of' => $today])->assertSessionHasErrors('as_of');

        $this->assertSame($count, Voucher::query()->count(), '⛔ ফেরানো অনুরোধেও ভাউচার লেখা হলো');
    }

    private function owe(int $daysAgo, string $amount): void
    {
        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: now()->subDays($daysAgo)->toDateString(), lines: [
            ['account_id' => (int) StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => $amount,
                'party_type' => Customer::drillSourceType(), 'party_id' => $this->customer->id],
            ['account_id' => (int) StandardChart::find(StandardChart::SALES)->id, 'credit' => $amount],
        ]);
    }

    /** @return array<string, string> */
    private function bucket(array $position, string $key): array
    {
        return collect($position['buckets'])->firstWhere('key', $key);
    }

    /** @param  list<string>  $keys */
    private function staff(array $keys): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id])->save();
        foreach ($keys as $key) {
            $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
        }

        return $user;
    }
}
