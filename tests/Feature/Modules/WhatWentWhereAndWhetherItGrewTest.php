<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * খরচ — কোন খাতে কত গেল, আর আগের চেয়ে বেশি না কম।
 *
 * ── কেন খরচ অর্থ মডিউলে ──────────────────────────────────────────────
 * ২৯ আগস্ট ২০২৬-এ দুই মডিউলের সীমা টানার সময় প্রথমে বলেছিলাম "ভাউচার
 * হিসাবে, ব্যবস্থাপনা অর্থে"। ওটা ব্যবহারকারীকে দুই দরজায় পাঠাত — ঠিক
 * সেই বিভ্রান্তি যেটা এড়াতে মডিউল ভাগ করা হচ্ছে।
 *
 * আসল প্রশ্ন কে করে: ডিপো ম্যানেজার রোজ খরচ লেখেন, হিসাবরক্ষক জাবেদা।
 *
 * ── আর এই পর্দাটা কেন আরেকটা তালিকা নয় ───────────────────────────────
 * ভাউচারের তালিকা হিসাবে আছেই। এখানকার প্রশ্ন আলাদা — **কোন খাতে কত** —
 * আর ওটাই একমাত্র প্রশ্ন যার উত্তরে খরচ কমানো শুরু হয়।
 */
class WhatWentWhereAndWhetherItGrewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($this->user);
    }

    /**
     * খরচের একটা খাত, নাম ধরে।
     *
     * ── কেন নামটা `head()` নয় ───────────────────────────────────────
     * `TestCase`-এ ওই নামে একটা public মেথড আগে থেকেই আছে (HTTP HEAD
     * অনুরোধ), আর private করে ঢাকতে গেলে PHP সরাসরি fatal দেয় —
     * ক্লাসটা লোডই হয় না। প্রথম রানেই ধরা পড়েছে।
     */
    private function expenseHead(string $like): Account
    {
        $operating = Account::query()
            ->where('code', StandardChart::OPERATING_EXPENSES)
            ->firstOrFail();

        return Account::query()
            ->where('parent_id', $operating->id)
            ->where('name_en', 'like', '%'.$like.'%')
            ->firstOrFail();
    }

    private function cash(): Account
    {
        return Account::query()->postable()->where('name_en', 'like', '%Cash%')->firstOrFail();
    }

    private function spend(Account $head, string $amount, string $on): Voucher
    {
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::EXPENSE, 'trx_date' => $on, 'narration' => 'test'],
            [
                ['account_id' => $head->id, 'debit' => $amount, 'credit' => '0'],
                ['account_id' => $this->cash()->id, 'debit' => '0', 'credit' => $amount],
            ],
        );

        return app(VoucherService::class)->post($voucher);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function headsOnScreen(?string $from = null, ?string $to = null): array
    {
        $rows = $this->get(route('finance.expense.index', array_filter([
            'from' => $from,
            'to' => $to,
        ])))->assertOk()->viewData('heads');

        return array_map(fn ($r) => [
            'code' => $r['account']->code,
            'now' => $r['now'],
            'before' => $r['before'],
        ], $rows);
    }

    /**
     * খাত ধরে যোগ হয়, আর সবচেয়ে বড়টা আগে।
     *
     * ম্যানেজার এক নজরে দেখেন কোথায় সবচেয়ে বেশি গেছে। বর্ণানুক্রমে
     * সাজালে সবচেয়ে বড় খরচটা তালিকার মাঝখানে হারাত।
     */
    public function test_the_biggest_head_comes_first(): void
    {
        $fuel = $this->expenseHead('Fuel');
        $rent = $this->expenseHead('Rent');

        $this->spend($fuel, '1200', now()->toDateString());
        $this->spend($rent, '9000', now()->toDateString());

        $rows = $this->headsOnScreen();

        $this->assertNotEmpty($rows);
        $this->assertSame($rent->code, $rows[0]['code'], 'সবচেয়ে বড় খরচটা আগে আসেনি।');
    }

    /**
     * আগের সমান সময়ের সংখ্যাটাও আসে।
     *
     * "জ্বালানিতে ১২,৪০০" একা কিছু বলে না। আগের সংখ্যাটা পাশে বসলেই
     * ওটা একটা প্রশ্ন হয়ে ওঠে।
     */
    public function test_the_period_before_is_counted_too(): void
    {
        $fuel = $this->expenseHead('Fuel');

        /* চলতি মাসে ৩,০০০ — আর ঠিক আগের সমান সময়ে ২,০০০ */
        $this->spend($fuel, '3000', now()->startOfMonth()->addDay()->toDateString());
        $this->spend($fuel, '2000', now()->startOfMonth()->subDays(3)->toDateString());

        $row = collect($this->headsOnScreen())->firstWhere('code', $fuel->code);

        $this->assertNotNull($row);
        $this->assertSame(0, bccomp($row['now'], '3000', 4));
        $this->assertSame(0, bccomp($row['before'], '2000', 4), 'আগের সময়ের সংখ্যাটা ভুল।');
    }

    /**
     * যে খাতে দুই সময়েই কিছু যায়নি সেটা পর্দায় ওঠে না।
     *
     * ষোলোটা খাতের বেশিরভাগ একটা ডিপোতে সারা বছরেও ছোঁয়া হয় না।
     * সবগুলো দেখালে পর্দাটা শূন্যে ভরে যেত, আর যেটা সত্যিই বেড়েছে
     * সেটা তার মধ্যে হারাত।
     */
    public function test_a_head_nobody_touched_stays_off_the_screen(): void
    {
        $this->spend($this->expenseHead('Fuel'), '500', now()->toDateString());

        $codes = collect($this->headsOnScreen())->pluck('code');

        $this->assertTrue($codes->contains($this->expenseHead('Fuel')->code));
        $this->assertFalse($codes->contains($this->expenseHead('Entertainment')->code),
            'যে খাতে কিছুই যায়নি সেটাও দেখানো হয়েছে।');
    }

    /**
     * খরচ ক্রয় বিলের পথেও আসে — আর সেটাও গোনা হয়।
     *
     * ── কেন খতিয়ান থেকে, ভাউচার থেকে নয় ────────────────────────────
     * একই খাতে খরচ আসে অন্য পথেও: ক্রয় বিলের হাম্মালি, চালানের ভাড়া।
     * ভাউচার গুনলে ওগুলো বাদ পড়ত, আর ম্যানেজার এমন একটা সংখ্যা
     * দেখতেন যা খাতার সাথে মেলে না — আর দুইটার কোনটা সত্যি তা কেউ
     * বলতে পারত না।
     */
    public function test_expense_that_arrived_by_another_road_is_counted(): void
    {
        $fuel = $this->expenseHead('Fuel');

        /* জাবেদা দিয়ে — অর্থাৎ খরচ ভাউচার নয় */
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'freight'],
            [
                ['account_id' => $fuel->id, 'debit' => '700', 'credit' => '0'],
                ['account_id' => $this->cash()->id, 'debit' => '0', 'credit' => '700'],
            ],
        );

        app(VoucherService::class)->post($voucher);

        $row = collect($this->headsOnScreen())->firstWhere('code', $fuel->code);

        $this->assertNotNull($row, 'অন্য পথে আসা খরচটা পর্দায় নেই।');
        $this->assertSame(0, bccomp($row['now'], '700', 4));
    }

    /**
     * প্রতিটা সংখ্যা তার খাতের এন্ট্রিগুলোতে নিয়ে যায় (নিয়ম ১)।
     */
    public function test_each_figure_leads_to_the_entries_behind_it(): void
    {
        $fuel = $this->expenseHead('Fuel');
        $this->spend($fuel, '800', now()->toDateString());

        $html = (string) $this->get(route('finance.expense.index'))->getContent();

        $this->assertStringContainsString(
            route('accounts.coa.show', $fuel).'#transactions',
            $html,
            'খরচের সংখ্যাটা খাতের এন্ট্রিগুলোর কাছে নামায় না।',
        );
    }
}
