<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DueNotices;
use App\Modules\Finance\Services\RentalContractService;
use App\Modules\Finance\Services\RentalNotices;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * অন্য শাখার জমা আর ভাড়ার খবর এমন কর্মীর ঘণ্টায় যেত, যিনি কাগজটা খুলতেই পারেন না — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (⚠️৫)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * [[DueNotices]] আর [[RentalNotices]] প্রাপক বাছত কেবল `can()` ধরে। জমা, হাতধার, ব্যাংক ঋণ, বীমা আর ভাড়ার পাতা নাগালের
 * বাইরের সারি খোলে না ([[OpensOnlyInReach]]), অথচ খবরটা কাগজের নম্বর, প্রতিষ্ঠান, পক্ষের নাম আর অঙ্ক নিয়ে এক শাখায়
 * আটকানো কর্মীর কাছেও যেত — তথ্য ফাঁস, আর লিংকে ৪০৪।
 *
 * ── ⭐ দাবি ───────────────────────────────────────────────────────────
 * MMS-এ আটকানো কর্মী NTK-এর জমা আর ভাড়ার খবর পান না, নিজের শাখারটা পান; সীমাহীন মালিক দুইটাই পান।
 */
final class TheBellToldAnotherBranchsPapersToAClerkWhoCannotOpenThemTest extends TestCase
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
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        app(DepositKindInstaller::class)->install();
    }

    public function test_a_deposit_notice_reaches_only_those_whose_reach_covers_its_branch(): void
    {
        $theirs = $this->deposit('FDR-NTK', 'NTK');
        $mine = $this->deposit('FDR-MMS', 'MMS');
        $clerk = $this->clerkLimitedTo('MMS');

        $this->morning(fn () => app(DueNotices::class)->depositsAboutToMature());

        $this->assertSame(1, $this->bells($this->owner, DueNotices::MATURING_SOON, $this->depositUrl($theirs)),
            'প্রস্তুতিটাই ভুল — মালিকও NTK-এর জমার খবর পাননি।');
        $this->assertSame(0, $this->bells($clerk, DueNotices::MATURING_SOON, $this->depositUrl($theirs)),
            '⛔ MMS-এ আটকানো কর্মী NTK-এর জমার খবর পেয়েছেন — নম্বর, প্রতিষ্ঠান আর মেয়াদ অন্য শাখার মানুষের ঘণ্টায়।');
        $this->assertSame(1, $this->bells($clerk, DueNotices::MATURING_SOON, $this->depositUrl($mine)),
            '⛔ কর্মী নিজের শাখার জমার খবরও পাননি — দেয়ালটা বেশি আটকাচ্ছে।');
    }

    public function test_a_rent_notice_reaches_only_those_whose_reach_covers_its_branch(): void
    {
        $theirs = $this->contract('NTK');
        $clerk = $this->clerkLimitedTo('MMS');

        $this->morning(fn () => app(RentalNotices::class)->ending());

        $url = route('finance.rental.show', $theirs->id);

        $this->assertGreaterThan(0, Notification::query()->where('user_id', $this->owner->id)->where('url', $url)->count(),
            'প্রস্তুতিটাই ভুল — মালিকও NTK-এর চুক্তি শেষের খবর পাননি।');
        $this->assertSame(0, Notification::query()->where('user_id', $clerk->id)->where('url', $url)->count(),
            '⛔ MMS-এ আটকানো কর্মী NTK-এর ভাড়ার খবর পেয়েছেন — বাড়িওয়ালার নাম অন্য শাখার মানুষের ঘণ্টায়।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function morning(\Closure $send): void
    {
        // ⓘ কেউ লগইন না থাকা অবস্থায়, যেমন সময়সূচিতে ([[TheDepositReminderCameAtThirtyAndAtSevenTest]]-এর ছাঁচ)
        $this->app['auth']->forgetGuards();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(DataScope::class)->forget();

        $send();
    }

    private function bells(User $user, string $type, string $url): int
    {
        return Notification::query()->where('user_id', $user->id)->where('type', $type)->where('url', $url)->count();
    }

    private function depositUrl(Deposit $deposit): string
    {
        return route('finance.deposit.show', ['issuer' => $deposit->kind->issuer, 'deposit' => $deposit->id]);
    }

    private function deposit(string $no, string $branch): Deposit
    {
        return Deposit::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch($branch)->id,
            'document_no' => $no, 'kind_id' => DepositKind::query()->where('code', 'FDR')->value('id'),
            'institution' => 'সোনালী ব্যাংক', 'held_by' => Deposit::BUSINESS, 'principal' => '100000',
            'profit_rate' => '8', 'tax_rate' => '10', 'return_word' => 'interest',
            'opened_on' => now()->subYear()->toDateString(),
            'matures_on' => now()->addDays(5)->toDateString(),
            'account_id' => StandardChart::find(StandardChart::DEPOSITS_AND_INVESTMENTS)->id,
            'status' => Deposit::ACTIVE,
        ])->load('kind');
    }

    /** এক মাসের চুক্তি — এ মাসের শেষে শেষ, তাই ৬০ দিনের ধাপের ভিতরে ([[AFinancePaperOpenedByIdFromAnotherBranchTest]]-এর ছাঁচ) */
    private function contract(string $branch): RentalContract
    {
        CompanyContext::set($this->company->id, $this->branch($branch)->id);
        $this->actingAs($this->owner);

        $contract = app(RentalContractService::class)->open([
            'counterparty' => 'Landlord '.$branch, 'subject' => 'Shop '.$branch, 'deposit_amount' => '0', 'monthly_rent' => '1000',
            'monthly_adjustment' => '0', 'term_months' => 1, 'starts_on' => now()->startOfMonth()->toDateString(),
        ]);

        $this->assertSame($this->branch($branch)->id, (int) $contract->branch_id, "দৃশ্যটাই বানানো যায়নি — চুক্তিটা {$branch}-এ নয়।");

        return $contract;
    }

    private function clerkLimitedTo(string $code): User
    {
        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id, 'current_branch_id' => $this->branch($code)->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);

        CompanyContext::forCompany($this->company->id, function () use ($clerk): void {
            foreach (['finance.deposit.view', 'finance.rental.view'] as $permission) {
                $clerk->givePermissionTo(Permission::findOrCreate($permission, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        UserDataScope::query()->create([
            'company_id' => $this->company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->branch($code)->id,
        ]);
        app(DataScope::class)->forget();

        return $clerk->fresh();
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
