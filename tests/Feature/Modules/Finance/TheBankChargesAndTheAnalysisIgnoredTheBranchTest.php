<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Services\AccountAnalysis;
use App\Modules\Finance\Services\BankCharges;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ ব্যাংক চার্জের রিপোর্ট আর খাত-বিশ্লেষণ দেখার শাখার নিয়ম মানত না — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (সারাই ৭)।
 *
 * ⓘ ব্যাংক চার্জ সবসময় গোটা কোম্পানি দেখাত; খাত-বিশ্লেষণ কেবল "এক শাখা বাছা" চিনত — "সব শাখা"-তে নাগাল না মেনে গোটা
 * কোম্পানি। এখন দুইটাই দেখার পুরো নিয়মে ([[ViewedBranch::narrow()]]): এক শাখা → সেটা; "সব শাখা" → নাগাল + শাখাহীন।
 *
 * ⭐ দাবি: MMS-এ ৩০০, NTK-এ ৫০০ ব্যাংক চার্জ —
 *   · মালিক হেডারে MMS → চার্জের মোট ৩০০, বিশ্লেষণের ডেবিট ৩০০; "সব শাখা" → ৮০০
 *   · MMS-এ আটকানো কর্মী "সব শাখা"-তে → ৩০০ (NTK-এর ৫০০ নয়), চার্জেও, বিশ্লেষণেও (খোলা জেরসহ)
 */
final class TheBankChargesAndTheAnalysisIgnoredTheBranchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->branch('MMS')->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        // ⓘ গত মাসে একবার করে (খোলা জের), এই মাসে একবার করে
        foreach (['MMS' => '300', 'NTK' => '500'] as $code => $amount) {
            $this->charge($code, $amount, now()->subMonth()->toDateString());
            $this->charge($code, $amount, now()->toDateString());
        }
    }

    public function test_the_owner_sees_the_branch_the_header_shows(): void
    {
        $this->owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $this->branch('MMS')->id])->save();
        $this->actingAs($this->owner->fresh());
        app(DataScope::class)->forget();

        $this->assertFigures('300', 'হেডারে MMS');

        $this->owner->forceFill(['view_all_branches' => true])->save();
        $this->actingAs($this->owner->fresh());
        app(DataScope::class)->forget();

        $this->assertFigures('800', '"সব শাখা"');
    }

    public function test_a_clerk_limited_to_one_branch_sees_only_it_even_on_all_branches(): void
    {
        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id,
            'current_branch_id' => $this->branch('MMS')->id, 'view_all_branches' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        UserDataScope::query()->create([
            'company_id' => $this->company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->branch('MMS')->id,
        ]);
        app(DataScope::class)->forget();
        $this->actingAs($clerk->fresh());

        $this->assertFigures('300', 'MMS-এ আটকানো কর্মী, "সব শাখা"');
    }

    private function assertFigures(string $expected, string $who): void
    {
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $this->assertSame(0, bccomp(app(BankCharges::class)->total($from, $to), $expected, 2),
            "⛔ {$who}: ব্যাংক চার্জের মোট {$expected} নয়, এল ".app(BankCharges::class)->total($from, $to));

        $analysis = app(AccountAnalysis::class)->of(StandardChart::find(StandardChart::BANK_CHARGES), $from, $to);
        $this->assertSame(0, bccomp((string) $analysis['debit'], $expected, 2), "⛔ {$who}: খাত-বিশ্লেষণের ডেবিট {$expected} নয়, এল {$analysis['debit']}");
        $this->assertSame(0, bccomp((string) $analysis['opening'], $expected, 2), "⛔ {$who}: খাত-বিশ্লেষণের খোলা জের {$expected} নয়, এল {$analysis['opening']}");
    }

    private function charge(string $code, string $amount, string $on): void
    {
        app(PostingEngine::class)->post(
            sourceType: 'test:bank-charge',
            sourceId: random_int(1, 999999),
            trxDate: $on,
            lines: [
                ['account_id' => StandardChart::find(StandardChart::BANK_CHARGES)->id, 'debit' => $amount],
                ['account_id' => Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail()->id, 'credit' => $amount],
            ],
            branchId: $this->branch($code)->id,
        );
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
