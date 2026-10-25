<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\NetWorthStatement;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ নিট সম্পদ বিবরণী — মালিকের "আমি কোথায় আছি" (মালিকের সরাসরি আদেশ, fe ২৫ অক্টোবর ২০২৬; [[NetWorthStatement]])।
 *
 * দাবি:
 *  - কেবল কোম্পানির মালিক দেখেন — অন্য কেউ ফোনের দরজায় ৪০৪ পান, সেবাও তাঁকে "না" বলে;
 *  - ভাগ খাতের ধরন আর গাছ ধরে: নগদ/ব্যাংক, গ্রাহকের পাওনা, মজুদ, সরবরাহকারীর পাওনা, বাকি দায়, মূলধন, উত্তোলন, এ বছরের লাভ —
 *    প্রতিটা চেনা দাখিলায় ঠিক ঐ ঘরটা ঠিক ঐ অঙ্কে নড়ে;
 *  - নিট = মোট সম্পদ − সরবরাহকারীর পাওনা − বাকি দায়, আর মূলধন − উত্তোলন + আগের লাভ + এ বছরের লাভ — দুই দিক মেলে;
 *  - সব কোম্পানি মিলিয়ে = মালিকের প্রতিটা কোম্পানির যোগ।
 */
final class OnlyTheOwnerSeesTheNetWorthStatementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 11:00:00'));
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_the_owner_sees_it_on_the_phone(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $clerk = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $statement = app(NetWorthStatement::class);

        $this->assertTrue($statement->mayView($owner, (int) $this->company->id), 'দৃশ্যটাই ভুল — ডেমোর মালিক এই কোম্পানির মালিক নন।');
        $this->assertFalse($statement->mayView($clerk, (int) $this->company->id), '⛔ হিসাবরক্ষককে মালিকের নিট সম্পদ দেখানোর অনুমতি দেওয়া হলো।');

        /*
         * ⓘ "মালিক" = কোম্পানির super admin ভূমিকা ([[Ownership::role()]]), আর কোম্পানিতে মালিক একজনই: দ্বিতীয়জন বসানো ব্যবহারকারীর
         * ফর্ম আর কোম্পানি খোলা — দুই পথেই আটকানো ([[Ownership::assertMayBecomeOwner()]])। ⛔ তাই দরজা ঠিক মালিকদের তালিকা — কোম্পানির
         * প্রতিটা সদস্যকে মেপে দেখা: দেখেন কেবল যিনি সেই তালিকায়, আর তালিকায় একজনই।
         */
        $owners = app(\App\Core\Services\Ownership::class)->activeOwnersIn((int) $this->company->id);
        $this->assertCount(1, $owners, 'দৃশ্যটাই ভুল — ডেমোর কোম্পানিতে মালিক ঠিক একজন নন।');

        foreach (User::query()->whereHas('companies', fn ($q) => $q->whereKey($this->company->id))->get() as $member) {
            $this->assertSame($owners->contains('id', $member->id), $statement->mayView($member, (int) $this->company->id),
                "⛔ {$member->email}: নিট সম্পদ দেখার দরজা মালিকের তালিকার সাথে মেলে না।");
        }

        Sanctum::actingAs($clerk, [AuthController::APP]);
        $this->assertSame(404, $this->getJson('/api/v1/accounts/net-worth')->status(),
            '⛔ মালিক নন এমন কেউ ফোনের দরজায় নিট সম্পদ পেলেন (বা দরজাটা আছে বলে জানলেন)।');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($owner, [AuthController::APP]);
        $response = $this->getJson('/api/v1/accounts/net-worth');

        $this->assertSame(200, $response->status(), '⛔ মালিক নিজের নিট সম্পদ ফোনে পেলেন না: '.$response->status());
        $this->assertSame($statement->forCompany((int) $this->company->id)['figures'], $response->json('figures'),
            '⛔ ফোনের অঙ্ক বিবরণীর অঙ্ক নয়।');
        $this->assertTrue((bool) $response->json('agrees'), '⛔ ফোনের বিবরণীতে দুই দিক মেলে না।');

        // ⓘ হোমের ছোট কার্ড — একই অঙ্ক, একই পাহারা
        $summary = $this->getJson('/api/v1/accounts/net-worth/summary');
        $this->assertSame(200, $summary->status(), '⛔ মালিক হোমের ছোট কার্ড পেলেন না।');
        $this->assertSame($response->json('figures.net'), $summary->json('net'), '⛔ হোমের কার্ডের নিট অঙ্ক বিবরণীর নিট নয়।');
        $this->assertSame($response->json('figures.this_year'), $summary->json('this_year'), '⛔ হোমের কার্ডের এ বছরের লাভ বিবরণীর নয়।');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($clerk, [AuthController::APP]);
        $this->assertSame(404, $this->getJson('/api/v1/accounts/net-worth/summary')->status(),
            '⛔ মালিক নন এমন কেউ হোমের ছোট কার্ডে নিট সম্পদ পেলেন।');
    }

    public function test_each_known_entry_moves_exactly_its_own_line_and_both_sides_agree(): void
    {
        $statement = app(NetWorthStatement::class);
        $before = $statement->forCompany((int) $this->company->id);
        $this->assertTrue($before['agrees'], 'দৃশ্যটাই ভুল — ডেমোর খাতায় শুরুতেই দুই দিক মেলে না।');

        $id = fn (callable $q) => (int) $q(DB::table('accounts')->where('company_id', $this->company->id)->where('is_group', false))->orderBy('id')->value('id');
        $cash = $id(fn ($q) => $q->where('money_kind', 'cash'));
        $stock = (int) StandardChart::find(StandardChart::INVENTORY)->id;
        $receivable = (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
        $payable = (int) StandardChart::find(StandardChart::PAYABLE)->id;
        $vat = (int) StandardChart::find(StandardChart::VAT_PAYABLE)->id;
        $capital = (int) StandardChart::find(StandardChart::OWNER_CAPITAL)->id;
        $drawings = (int) StandardChart::find(StandardChart::DRAWINGS)->id;
        $income = $id(fn ($q) => $q->where('type', 'income'));
        $expense = $id(fn ($q) => $q->where('type', 'expense'));
        $customer = ['party_type' => 'customer', 'party_id' => (int) DB::table('customers')->where('company_id', $this->company->id)->orderBy('id')->value('id')];
        $supplier = ['party_type' => 'supplier', 'party_id' => (int) DB::table('suppliers')->where('company_id', $this->company->id)->orderBy('id')->value('id')];
        $this->assertTrue($cash && $stock && $receivable && $payable && $vat && $capital && $drawings && $income && $expense && $customer['party_id'] && $supplier['party_id'],
            'দৃশ্যটাই ভুল — ডেমোতে কোনো খাত বা পক্ষ নেই।');

        $post = fn (array $lines) => app(PostingEngine::class)->post(sourceType: 'test:net_worth', sourceId: random_int(1, 9_999_999),
            trxDate: '2026-10-20', lines: $lines, branchId: $this->company->defaultBranch()?->id);

        $post([['account_id' => $stock, 'debit' => '1000'], ['account_id' => $cash, 'credit' => '1000']]);
        $post([['account_id' => $expense, 'debit' => '500'], ['account_id' => $payable, 'credit' => '500', ...$supplier]]);
        $post([['account_id' => $cash, 'debit' => '2000'], ['account_id' => $capital, 'credit' => '2000']]);
        $post([['account_id' => $drawings, 'debit' => '300'], ['account_id' => $cash, 'credit' => '300']]);
        $post([['account_id' => $receivable, 'debit' => '700', ...$customer], ['account_id' => $income, 'credit' => '700']]);
        $post([['account_id' => $cash, 'debit' => '400'], ['account_id' => $vat, 'credit' => '400']]);

        $after = $statement->forCompany((int) $this->company->id);
        $moved = fn (string $line) => bcsub($after['figures'][$line], $before['figures'][$line], 4);

        $expected = [
            'cash' => '1100', 'stock' => '1000', 'receivable' => '700', 'other_assets' => '0',
            'payable' => '500', 'other_liabilities' => '400',
            'capital' => '2000', 'drawings' => '300', 'this_year' => '200', 'earlier' => '0',
            'assets' => '2800', 'net' => '1900', 'equity' => '1900',
        ];

        foreach ($expected as $line => $amount) {
            $this->assertSame(0, bccomp($moved($line), $amount, 4),
                "⛔ নিট সম্পদ বিবরণীর «{$line}» ঘর {$moved($line)} নড়ল, চেনা দাখিলায় নড়ার কথা {$amount} — ভাগটা খাতের ধরন আর গাছ ধরে হচ্ছে না।");
        }

        $this->assertTrue($after['agrees'], '⛔ দাখিলার পরে নিট সম্পদ আর মূলধনের দিক মেলে না: '.$after['figures']['net'].' বনাম '.$after['figures']['equity']);
        $this->assertSame(0, bccomp($after['figures']['net'],
            bcsub(bcsub($after['figures']['assets'], $after['figures']['payable'], 4), $after['figures']['other_liabilities'], 4), 4),
            '⛔ নিট = মোট সম্পদ − সরবরাহকারীর পাওনা − বাকি দায় নয়।');
    }

    public function test_all_companies_is_the_sum_of_the_owners_companies(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $mine = app(NetWorthStatement::class)->forOwner($owner);

        $this->assertNotEmpty($mine['companies'], 'দৃশ্যটাই ভুল — মালিকের কোনো কোম্পানি নেই।');

        foreach (NetWorthStatement::LINES as $line) {
            $sum = array_reduce($mine['companies'], fn (string $s, array $c) => bcadd($s, $c['figures'][$line], 4), '0');
            $this->assertSame(0, bccomp((string) $mine['all'][$line], $sum, 4), "⛔ সব কোম্পানি মিলিয়ে «{$line}» প্রতিটা কোম্পানির যোগ নয়।");
        }

        $clerk = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $theirs = app(NetWorthStatement::class)->forOwner($clerk);
        $this->assertSame([], $theirs['companies'], '⛔ মালিক নন এমন কেউ "সব কোম্পানি মিলিয়ে" বিবরণী পেলেন।');
    }
}
