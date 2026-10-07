<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Services\CreditExposure;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ডেলিভারি অর্ডারের হিসাবের যাচাই — সফটওয়্যার নিজে, ২ অক্টোবর ২০২৬ (বিক্রয়ের কাজের ধারা, ধাপ গ;
 * [[CreditExposure::check()]])।
 *
 * নতুন একজন গ্রাহক, যাঁর আগের কিছু নেই — অঙ্কগুলো তাই হুবহু:
 *   · সীমা ১০,০০০, বকেয়া ৪,০০০ → ৬,০০০-এর DO কুলোয় (১০০% ব্যবহার), ৬,০০১ কুলোয় না (কম ১)।
 *   · সীমা ০, অগ্রিম ৩,০০০ → ৩,০০০-এর DO কুলোয়, ৩,০০১ নয় — সীমা নেই, তবু জমা টাকায় চলে।
 *   · হাতে আসার দিনেই জমায় বসা চেক ২,০০০, এখনো ক্লিয়ার নয় → সেটা টাকা নয়: ঐ ২,০০০ ফেরত যোগ হয়।
 */
final class TheMoneyCheckForADeliveryOrderTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->customer = Customer::query()->create(['code' => 'DO-MONEY', 'name_en' => 'DO Money', 'name_bn' => 'DO Money', 'is_active' => true]);
    }

    public function test_the_limit_less_what_is_owed_is_the_room(): void
    {
        $this->limit('10000');
        $this->owes('4000');

        $this->assertFits('6000', '100.00');
        $this->assertShort('6001', '1');
    }

    public function test_no_limit_but_an_advance_still_buys(): void
    {
        $this->limit('0');
        $this->owes('-3000');

        $result = app(CreditExposure::class)->check($this->customer->fresh(), '3000');
        $this->assertTrue($result['fits'], '⛔ সীমা ০, অগ্রিম ৩,০০০ — ৩,০০০-এর DO আটকেছে।');
        $this->assertNull($result['used_percent'], '⛔ সীমা ০-এ শতাংশ হয় না।');

        $this->assertShort('3001', '1');
    }

    public function test_a_cheque_counted_on_receipt_is_not_money_until_it_clears(): void
    {
        $this->limit('10000');
        $this->owes('4000');

        // ⓘ আগের নিয়ম: হাতে আসার দিনেই গ্রাহকের জমা (Dr ১১০৪ / Cr পাওনা), দাখিলার উৎস চেক নিজে
        $cheque = $this->cheque('2000', 'pending');
        $this->postLines([
            ['account_id' => StandardChart::find(StandardChart::CHEQUES_IN_HAND)->id, 'debit' => '2000'],
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => '2000', 'party_type' => 'customer', 'party_id' => $this->customer->id],
        ], 'cheque', $cheque);

        // খাতায় বকেয়া এখন ২,০০০ — কিন্তু চেক ক্লিয়ার নয়, তাই জায়গা এখনো ৬,০০০-ই
        $this->assertFits('6000', '100.00');
        $this->assertShort('6001', '1');

        DB::table('acc_cheques')->where('id', $cheque)->update(['status' => 'cleared']);

        // ক্লিয়ার হলে টাকা — জায়গা ৮,০০০
        $this->assertFits('8000', '100.00');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function assertFits(string $adding, string $percent): void
    {
        $result = app(CreditExposure::class)->check($this->customer->fresh(), $adding);

        $this->assertTrue($result['fits'], "⛔ {$adding}-এর DO কুলোনোর কথা।");
        $this->assertSame(0, bccomp($result['short'], '0', 4), '⛔ কুলোলে কম শূন্য।');
        $this->assertSame(0, bccomp((string) $result['used_percent'], $percent, 2), "⛔ সীমার ব্যবহার {$percent}% হওয়ার কথা, এল {$result['used_percent']}।");
    }

    private function assertShort(string $adding, string $short): void
    {
        $result = app(CreditExposure::class)->check($this->customer->fresh(), $adding);

        $this->assertFalse($result['fits'], "⛔ {$adding}-এর DO কুলোনোর কথা নয়।");
        $this->assertSame(0, bccomp($result['short'], $short, 4), "⛔ কম {$short} হওয়ার কথা, এল {$result['short']}।");
    }

    private function limit(string $amount): void
    {
        $this->customer->forceFill(['credit_limit' => $amount])->save();
    }

    /** ঋণাত্মক মানে অগ্রিম — গ্রাহক আগে টাকা দিয়ে রেখেছেন। */
    private function owes(string $amount): void
    {
        $receivable = StandardChart::find(StandardChart::RECEIVABLE)->id;
        $sales = StandardChart::find(StandardChart::SALES)->id;
        $party = ['party_type' => 'customer', 'party_id' => $this->customer->id];

        if (bccomp($amount, '0', 4) >= 0) {
            $this->postLines([['account_id' => $receivable, 'debit' => $amount, ...$party], ['account_id' => $sales, 'credit' => $amount]]);

            return;
        }

        $advance = ltrim($amount, '-');
        $money = DB::table('accounts')->where('company_id', $this->company->id)->where('money_kind', 'cash')->where('is_group', false)->orderBy('id')->value('id');
        $this->postLines([['account_id' => $money, 'debit' => $advance], ['account_id' => $receivable, 'credit' => $advance, ...$party]]);
    }

    private function postLines(array $lines, string $source = 'test:do-money', ?int $sourceId = null): void
    {
        app(PostingEngine::class)->post(
            sourceType: $source, sourceId: $sourceId ?? random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: $lines,
            branchId: $this->company->defaultBranch()?->id,
        );
    }

    private function cheque(string $amount, string $status): int
    {
        return (int) DB::table('acc_cheques')->insertGetId([
            'public_id' => (string) \Illuminate\Support\Str::uuid7(),
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'direction' => 'received',
            'cheque_date' => now()->toDateString(),
            'received_on' => now()->toDateString(),
            'cheque_no' => 'DO-'.random_int(1000, 9999),
            'amount' => $amount,
            'party_type' => 'customer',
            'party_id' => $this->customer->id,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
