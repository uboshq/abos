<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\Finance\Services\OwnerCapital;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ মালিকের নামে শুরুর মূলধন, শাখা ধরে — মালিকের আদেশ, ৫ অক্টোবর ২০২৬: *"মূলধন ও বিনিয়োগে মালিকের নামই নেই"*।
 *
 * ⛔ খোলা জের মালিকের মূলধনে (৩১০০) বসত, অথচ রেজিস্টার কারো নামে কিছু দেখাত না। ⓘ দাবির মেরুদণ্ড একটাই: রেজিস্টারের
 * মোট = ৩১০০-এর জের, প্রতিটা শাখায় আলাদা করে — তাই দুবার গোনা হয় না।
 */
final class TheOwnersOpeningCapitalHadNoNameTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Branch $mymensingh;

    private Branch $netrakona;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->mymensingh = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->netrakona = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        // ⓘ ডেমোর ছকে মালিকের মূলধন (৩১০০) থাকে না — খোলা জের তখন সঞ্চিত মুনাফায় যেত, আর দাবিটা কিছুই মাপত না
        app(StandardChart::class)->install();
        // ⓘ ডেমোতে এই সুইচ বন্ধ — খোলা জের তখন সঞ্চিত মুনাফায় যায়; এই ফাইল মালিকের মূলধনে যাওয়ার পথ মাপে (d4aa3751)
        app(\App\Core\Services\SettingsService::class)->set('accounts.opening_to_capital', true);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    public function test_without_an_owner_the_page_asks_for_one_and_nothing_is_made(): void
    {
        $this->opening($this->mymensingh, '50000');

        $this->get(route('finance.capital.index'))->assertOk()->assertSee('data-owner-needed', false)
            ->assertSee(route('finance.capital.owner'));

        $this->assertSame(0, CapitalEntry::query()->where('in_kind', CapitalEntry::OPENING)->count(), 'মালিক ছাড়া কার নামে বসল?');
    }

    public function test_choosing_the_owner_brings_each_branchs_opening_capital_in_under_their_name(): void
    {
        $this->opening($this->mymensingh, '50000');
        $this->opening($this->netrakona, '30000');

        $this->post(route('finance.capital.owner'), ['person_new' => 'Abdul Malik'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $person = Person::query()->where('name_en', 'Abdul Malik')->sole();
        $this->assertSame((int) $person->id, (int) app(OwnerCapital::class)->owner()?->id);

        foreach ([$this->mymensingh, $this->netrakona] as $branch) {
            $this->assertSame(0, bccomp($this->register($branch), $this->books($branch), 4),
                "⛔ শাখা {$branch->code}-এর রেজিস্টার আর ৩১০০-এর জের আলাদা।");
        }

        $this->assertSame(0, bccomp($this->register(), $this->books(), 4), '⛔ কোম্পানির রেজিস্টারের মোট ৩১০০-এর জেরের সমান নয়।');
        $this->assertTrue(CapitalEntry::query()->where('in_kind', CapitalEntry::OPENING)->where('person_id', $person->id)->exists());

        $mine = CapitalEntry::query()->where('in_kind', CapitalEntry::OPENING)->get();
        $this->assertSame([] , $mine->filter(fn ($e) => (int) $e->person_id !== (int) $person->id)->all(), 'অন্য কারো নামে শুরুর মূলধন বসেছে।');

        // ⓘ শাখা ধরে মূলধন — দুই শাখার সারি আর মোট
        $byBranch = app(CapitalService::class)->byBranch();
        $this->assertSame(0, bccomp($byBranch['total'], $this->register(), 4));
        $this->get(route('finance.capital.index', ['tab' => 'owners']))->assertOk()
            ->assertSee('data-branch-capital', false)->assertSee($this->mymensingh->name())->assertSee($this->netrakona->name())
            ->assertDontSee('data-owner-needed', false);
    }

    /** ⓘ মালিক বাছা থাকলে নতুন খোলা জের নিজেই তাঁর নামে — আর আবার চালালে কিছুই হয় না */
    public function test_a_new_opening_comes_in_by_itself_and_never_twice(): void
    {
        $person = Person::query()->create(['company_id' => $this->company->id, 'code' => 'P-OWN', 'name_en' => 'Owner', 'is_active' => true]);
        app(OwnerCapital::class)->choose($person);
        app(OwnerCapital::class)->reconcile();
        $rows = CapitalEntry::query()->count();

        $this->opening($this->mymensingh, '12000');

        $this->assertSame($rows + 1, CapitalEntry::query()->count(), '⛔ নতুন খোলা জের রেজিস্টারে নিজে আসেনি।');
        $this->assertSame(0, bccomp($this->register(), $this->books(), 4));

        $this->assertSame([], app(OwnerCapital::class)->reconcile(), '⛔ আবার চালাতে দ্বিতীয়বার বসল।');
        $this->assertSame([], app(OwnerCapital::class)->gaps());
    }

    public function test_the_command_counts_first_and_applies_once(): void
    {
        $this->opening($this->mymensingh, '40000');
        $person = Person::query()->create(['company_id' => $this->company->id, 'code' => 'P-CMD', 'name_en' => 'Cmd Owner', 'is_active' => true]);
        app(OwnerCapital::class)->choose($person);

        $this->artisan('finance:opening-capital', ['--company' => 'TDEPOT'])->assertSuccessful();
        $this->assertSame(0, CapitalEntry::query()->where('in_kind', CapitalEntry::OPENING)->count(), '⛔ --apply ছাড়াই বসাল।');

        $this->artisan('finance:opening-capital', ['--company' => 'TDEPOT', '--apply' => true])->assertSuccessful();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $made = CapitalEntry::query()->where('in_kind', CapitalEntry::OPENING)->count();
        $this->assertGreaterThan(0, $made);
        $this->assertSame(0, bccomp($this->register(), $this->books(), 4));

        $this->artisan('finance:opening-capital', ['--company' => 'TDEPOT', '--apply' => true])->assertSuccessful();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->assertSame($made, CapitalEntry::query()->where('in_kind', CapitalEntry::OPENING)->count(), '⛔ দ্বিতীয়বার চালাতে আবার বসাল।');
    }

    public function test_only_the_super_admin_names_the_owner(): void
    {
        // ⓘ পাতা দেখার চাবি আছে, সুপার অ্যাডমিন নন — দরজা পেরোয়, মালিক ঠিক করার নিয়মে থামে
        $accountant = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $accountant->givePermissionTo('finance.capital.view');
        $this->actingAs($accountant->fresh());
        $this->get(route('finance.capital.index'))->assertOk()->assertDontSee(route('finance.capital.owner'));

        $this->post(route('finance.capital.owner'), ['person_new' => 'Not Me'])->assertForbidden();
        $this->assertNull(app(OwnerCapital::class)->owner());
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function opening(Branch $branch, string $amount): void
    {
        app(OpeningBalanceService::class)->forInventory(
            sourceId: random_int(100000, 999999), documentNo: 'OPEN-'.$branch->code, amount: $amount,
            date: now()->toDateString(), branchId: (int) $branch->id,
        );
    }

    private function books(?Branch $branch = null): string
    {
        $accounts = StandardChart::find(StandardChart::OWNER_CAPITAL)->selfAndDescendants()->pluck('id');

        return (string) LedgerEntry::query()->where('company_id', $this->company->id)->whereIn('account_id', $accounts)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as n')->value('n');
    }

    private function register(?Branch $branch = null): string
    {
        return (string) CapitalEntry::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('status', CapitalEntry::POSTED)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
            ->sum('amount');
    }
}
