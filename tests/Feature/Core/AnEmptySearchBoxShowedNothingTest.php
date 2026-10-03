<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Search\StartingPoints;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Http\Middleware\RefuseSwitchedOffScreens;
use App\Models\Branch;
use App\Models\Company;
use App\Models\RecentPaper;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Ctrl+K খুললে খালি বাক্সে লেখা থাকত কেবল "খুঁজতে লিখুন"।
 *
 * ── ⭐ ডিজাইন-চেকলিস্ট, ধাপ ৭ · ৫ (২ অক্টোবর ২০২৬) ─────────────────────
 * *"Ctrl+K খালি অবস্থায় সাম্প্রতিক কাগজ আর প্রস্তাবিত কাজ"*। ⓘ পাঁচ মিনিট
 * আগে খোলা বিলে ফিরতেও তার নম্বর মনে রাখতে হত, আর "নতুন কিছু বানানো"
 * মানে মেনু ঘেঁটে খোঁজা।
 *
 * ── ⛔ এই ফাইলের সবচেয়ে জরুরি দাবিগুলো দেয়ালের ─────────────────────────
 * সাম্প্রতিক তালিকা একটা **দ্বিতীয় দরজা**: পুরনো সারি থেকে নম্বর আর নাম
 * দেখা যায়। ⚠️ তাই প্রতিটা দেয়াল — অনুমতি, শাখা, কোম্পানি — **একই
 * মানুষ, একই কাগজ** দিয়ে মাপা: চাবি থাকলে দেখা যায়, কাড়লে যায় না,
 * ফেরালে আবার দেখা যায়। ⓘ দুইজন আলাদা মানুষ নিলে না-দেখাটা অন্য কোনো
 * কারণেও হতে পারত।
 */
final class AnEmptySearchBoxShowedNothingTest extends TestCase
{
    use RefreshDatabase;

    private const SEE = 'accounts.report';

    private Company $company;

    private Company $other;

    private Branch $home;

    private Branch $away;

    private User $owner;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->other = Company::query()->where('code', 'FMART')->firstOrFail();

        $this->home = Branch::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->away = Branch::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->switchCompany($this->company->id);

        CompanyContext::set($this->company->id, $this->home->id);
        $this->actingAs($this->owner->fresh());

        app(StandardChart::class)->install();

        $this->clerk = User::factory()->create([
            'current_company_id' => $this->company->id,
            'current_branch_id' => $this->home->id,
            'is_active' => true,
        ]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->grant(self::SEE);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ⭐ খোলা কাগজটাই ফেরে, ঠিক ক্রমে ─────────────────────────────────

    public function test_the_paper_just_opened_comes_back_first_and_opens_that_paper(): void
    {
        $a = $this->voucher($this->home, 'RECENT-A');
        $b = $this->voucher($this->home, 'RECENT-B');

        $this->open($this->owner, $a);
        $this->open($this->owner, $b);

        $recent = $this->start($this->owner)['recent'];

        $this->assertSame(
            [route('accounts.voucher.show', $b), route('accounts.voucher.show', $a)],
            array_slice(array_column($recent, 'url'), 0, 2),
            'শেষে খোলা কাগজটা তালিকার মাথায় নেই, বা ঠিকানাটা ঐ কাগজের নয়।',
        );

        /* ⛔ নম্বরটা মাপা কাগজ থেকে, হাতে টাইপ করা নয় */
        $this->assertSame($b->fresh()->drillDocumentNo(), $recent[0]['no']);

        /* ⓘ আবার খুললে নতুন সারি নয় — পুরনোটা মাথায় ওঠে */
        $this->open($this->owner, $a);

        $urls = array_column($this->start($this->owner)['recent'], 'url');

        $this->assertSame(route('accounts.voucher.show', $a), $urls[0]);
        $this->assertSame(1, count(array_keys($urls, route('accounts.voucher.show', $a), true)),
            'একই কাগজ তালিকায় দুইবার।');
    }

    public function test_a_page_that_refused_is_not_remembered(): void
    {
        /*
         * ⛔ অন্য কোম্পানির কাগজের ঠিকানা — দরজা ৪০৪ দেয়, আর তখন কোনো সারি
         * বসার কথা নয়। ⚠️ বসলে "খুলেছিলেন" কথাটাই মিথ্যা।
         */
        $theirs = $this->voucherIn($this->other, 'RECENT-THEIRS');

        $this->actingAs($this->owner->fresh())
            ->get(route('accounts.voucher.show', $theirs))
            ->assertNotFound();

        $this->assertSame(0, RecentPaper::query()->withoutGlobalScopes()->count(),
            'খোলা যায়নি এমন কাগজের সারি বসেছে।');

        /*
         * ⛔ চাবি ছাড়া নিজের কোম্পানির কাগজ — দরজা ৪০৩। ⓘ এটা আলাদা পথ:
         * ৪০৪ আসে রুট-বাঁধাইয়ে, মনে-রাখার আগেই; ৪০৩ আসে রুটের `can:`-এ,
         * মনে-রাখার **ভিতরে** — তাই কেবল এখানেই "২০০ কি না" প্রশ্নটা পাহারা দেয়।
         */
        $this->revoke(self::SEE);

        $this->actingAs($this->clerk->fresh())
            ->get(route('accounts.voucher.show', $this->voucher($this->home, 'RECENT-NOKEY')))
            ->assertForbidden();

        $this->assertSame(0, RecentPaper::query()->withoutGlobalScopes()->count(),
            '⛔ ৪০৩ পাওয়া কাগজের সারি বসেছে — খোলাই যায়নি, অথচ "সাম্প্রতিক"।');

        /* ⭐ একই মানুষ, নিজের কোম্পানির কাগজ — সারি বসে (মাপাটা জীবিত) */
        $this->open($this->owner, $this->voucher($this->home, 'RECENT-OURS'));

        $this->assertSame(1, RecentPaper::query()->withoutGlobalScopes()->count());
    }

    // ── ⛔ দেয়াল: একই মানুষ, একই কাগজ ───────────────────────────────────

    public function test_a_key_taken_away_takes_the_paper_off_the_list_and_giving_it_back_returns_it(): void
    {
        $paper = $this->voucher($this->home, 'RECENT-KEY');
        $url = route('accounts.voucher.show', $paper);

        $this->open($this->clerk, $paper);
        $this->assertContains($url, $this->recentUrls($this->clerk), 'চাবি থাকতেও কাগজটা তালিকায় নেই।');

        $this->revoke(self::SEE);
        $this->assertNotContains($url, $this->recentUrls($this->clerk),
            '⛔ চাবি কাড়ার পরেও কাগজের নম্বর আর নাম খালি বাক্সে দেখা যাচ্ছে।');

        $this->grant(self::SEE);
        $this->assertContains($url, $this->recentUrls($this->clerk), 'চাবি ফেরার পরেও কাগজটা ফেরেনি।');
    }

    public function test_a_branch_taken_away_takes_its_papers_off_the_list(): void
    {
        $here = $this->voucher($this->home, 'RECENT-HOME');
        $there = $this->voucher($this->away, 'RECENT-AWAY');

        $this->scope([$this->home, $this->away]);
        $this->open($this->clerk, $here);
        $this->open($this->clerk, $there);

        $this->assertContains(route('accounts.voucher.show', $there), $this->recentUrls($this->clerk));

        /* ⛔ নেত্রকোনা কাড়া হলো — ঐ কাগজের পাতা এখন ৪০৪, তালিকাতেও নেই */
        $this->scope([$this->home]);

        $this->actingAs($this->clerk->fresh())->get(route('accounts.voucher.show', $there))->assertNotFound();

        $urls = $this->recentUrls($this->clerk);
        $this->assertNotContains(route('accounts.voucher.show', $there), $urls,
            '⛔ শাখা কাড়ার পরেও সেই শাখার কাগজ খালি বাক্সে দেখা যাচ্ছে।');
        $this->assertContains(route('accounts.voucher.show', $here), $urls,
            'নিজের শাখার কাগজটাও হারিয়েছে — দেয়ালটা সবকিছু ঢেকে দিচ্ছে।');

        $this->scope([$this->home, $this->away]);
        $this->assertContains(route('accounts.voucher.show', $there), $this->recentUrls($this->clerk));
    }

    public function test_a_paper_opened_in_one_company_never_shows_in_another(): void
    {
        $paper = $this->voucher($this->home, 'RECENT-COMPANY');
        $url = route('accounts.voucher.show', $paper);

        $this->open($this->owner, $paper);
        $this->assertContains($url, $this->recentUrls($this->owner));

        $this->owner->switchCompany($this->other->id);
        CompanyContext::set($this->other->id, $this->other->defaultBranch()?->id);

        $this->assertNotContains($url, $this->recentUrls($this->owner),
            '⛔ এক কোম্পানিতে খোলা কাগজ আরেক কোম্পানির খালি বাক্সে।');

        $this->owner->switchCompany($this->company->id);
        CompanyContext::set($this->company->id, $this->home->id);

        $this->assertContains($url, $this->recentUrls($this->owner));
    }

    public function test_one_letter_is_still_a_search_not_the_start_list(): void
    {
        $this->open($this->owner, $this->voucher($this->home, 'RECENT-LETTER'));

        $json = $this->actingAs($this->owner->fresh())->getJson(route('search', ['q' => 'R']))->assertOk()->json();

        $this->assertSame([], $json['hits']);
        $this->assertArrayNotHasKey('recent', $json, 'এক অক্ষর লেখার পরেও পুরনো তালিকা ফিরছে।');

        /* ⭐ খালি বাক্সে তালিকাটা আসে — মাপাটা জীবিত */
        $this->assertNotSame([], $this->start($this->owner)['recent']);
    }

    // ── ⭐ প্রস্তাবিত কাজ ────────────────────────────────────────────────

    public function test_every_suggested_action_opens_for_the_person_it_is_suggested_to(): void
    {
        $actions = $this->start($this->owner)['actions'];

        $this->assertNotSame([], $actions, 'মালিকের জন্য একটাও কাজ প্রস্তাব হয়নি।');
        $this->assertLessThanOrEqual(StartingPoints::ACTIONS, count($actions));

        foreach ($actions as $action) {
            $this->assertNotSame('', trim($action['label']));
            $this->assertStringNotContainsString('core.', $action['label'], 'কাঁচা অনুবাদ-চাবি পর্দায়।');

            $this->actingAs($this->owner->fresh())->get($action['url'])->assertOk();
        }
    }

    public function test_an_action_appears_with_its_key_and_leaves_with_it(): void
    {
        [$create, $permission] = $this->aCreatablePaper();

        $url = route($create);

        $this->assertNotContains($url, $this->actionUrls($this->clerk), 'চাবি ছাড়াই কাজটা প্রস্তাব হয়েছে।');

        $this->grant($permission);
        $this->assertContains($url, $this->actionUrls($this->clerk), 'চাবি পাওয়ার পরেও কাজটা আসেনি।');
        $this->actingAs($this->clerk->fresh())->get($url)->assertOk();

        $this->revoke($permission);
        $this->assertNotContains($url, $this->actionUrls($this->clerk), '⛔ চাবি কাড়ার পরেও কাজটা প্রস্তাবে।');
    }

    // ── সহায়ক ──────────────────────────────────────────────────────────

    /**
     * একটা কাগজ, যার পাশে প্যারামিটার-ছাড়া "নতুন" পাতা আছে আর দরজাটা একটা
     * সাধারণ অনুমতি — মাপা ঘোষণা থেকে, হাতে বাছা নয়।
     *
     * @return array{0: string, 1: string}
     */
    private function aCreatablePaper(): array
    {
        $switches = app(RefuseSwitchedOffScreens::class);

        foreach (app(StartingPoints::class)->papersByRoute() as $show => [$class]) {
            $create = substr($show, 0, -strlen('.show')).'.create';
            $route = Route::getRoutes()->getByName($create);

            if ($route === null || $route->parameterNames() !== [] || $switches->refuses($create)) {
                continue;
            }

            if (! Lang::has('core.source.'.$class::drillSourceType())) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'can:') && ! str_contains($middleware, ',')) {
                    return [$create, substr($middleware, 4)];
                }
            }
        }

        $this->fail('অনুমতি-দরজার একটাও "নতুন" পাতা পাওয়া গেল না — মাপার কিছু নেই।');
    }

    private function voucher(Branch $branch, string $narration): Voucher
    {
        [$first, $second] = Account::query()
            ->postable()->active()->whereNull('money_kind')
            ->orderBy('code')->limit(2)->get()->all();

        return app(VoucherService::class)->create([
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
            'narration' => $narration,
            'branch_id' => $branch->id,
        ], [
            ['account_id' => $first->id, 'debit' => '1000', 'credit' => '0'],
            ['account_id' => $second->id, 'debit' => '0', 'credit' => '1000'],
        ]);
    }

    private function voucherIn(Company $company, string $narration): Voucher
    {
        return CompanyContext::forCompany($company->id, function () use ($company, $narration) {
            app(StandardChart::class)->install();

            $branch = Branch::query()->withoutGlobalScopes()
                ->where('company_id', $company->id)->orderByDesc('is_default')->firstOrFail();

            return $this->voucher($branch, $narration);
        });
    }

    private function open(User $user, Voucher $paper): void
    {
        $this->actingAs($user->fresh())->get(route('accounts.voucher.show', $paper))->assertOk();
    }

    /** @return array{hits: list<mixed>, recent: list<array<string, string>>, actions: list<array<string, string>>} */
    private function start(User $user): array
    {
        $json = $this->actingAs($user->fresh())->getJson(route('search'))->assertOk()->json();

        $this->assertArrayHasKey('recent', $json);
        $this->assertArrayHasKey('actions', $json);

        return $json;
    }

    /** @return list<string> */
    private function recentUrls(User $user): array
    {
        return array_column($this->start($user)['recent'], 'url');
    }

    /** @return list<string> */
    private function actionUrls(User $user): array
    {
        return array_column($this->start($user)['actions'], 'url');
    }

    private function grant(string $permission): void
    {
        Permission::findOrCreate($permission, 'web');

        CompanyContext::forCompany($this->company->id, fn () => $this->clerk->givePermissionTo($permission));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function revoke(string $permission): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $this->clerk->revokePermissionTo($permission));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param  list<Branch>  $branches */
    private function scope(array $branches): void
    {
        UserDataScope::query()->withoutGlobalScopes()
            ->where('user_id', $this->clerk->id)
            ->where('scope_type', UserDataScope::BRANCH)
            ->delete();

        foreach ($branches as $branch) {
            UserDataScope::query()->create([
                'company_id' => $this->company->id,
                'user_id' => $this->clerk->id,
                'scope_type' => UserDataScope::BRANCH,
                'scope_id' => $branch->id,
            ]);
        }

        app(DataScope::class)->forget();
    }
}
