<?php

declare(strict_types=1);

namespace Tests\Feature\Speed;

use App\Core\Dashboard\DashboardRegistry;
use App\Core\Services\DashboardFigureCache;
use App\Core\Services\ReadWindow;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * ⭐ Remembered dashboard figures belong to one scope only, and a key still gates every figure (speed work, 23 Nov
 * 2026; [[DashboardFigureCache]]; owner rule "a board must keep the cost key", 11 Nov 2026).
 *
 * Claims:
 *   1. one user's remembered figure never answers another user;
 *   2. another branch, or a changed key, counts afresh;
 *   3. a posting (bust) counts afresh;
 *   4. outside a read-only page nothing is remembered;
 *   5. after the owner filled the home figures, a user without the keys gets none of the gated widgets;
 *   6. a figure that cannot be stored (a closure inside) is still answered, every time.
 */
final class DashboardFiguresAreRememberedPerScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $sales;

    private int $computed = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
    }

    public function test_one_users_figure_never_answers_another_user(): void
    {
        $this->readPageAs($this->owner);
        $this->assertSame('figure-1', $this->figure());
        $this->assertSame('figure-1', $this->figure(), 'prerequisite — the same user, the same scope, is remembered');

        $this->readPageAs($this->sales);
        $this->assertSame('figure-2', $this->figure(), '⛔ the owner\'s remembered figure answered another user.');
    }

    public function test_another_branch_or_a_changed_key_counts_afresh(): void
    {
        $this->readPageAs($this->owner);
        $this->figure();

        $other = \App\Models\Branch::query()->where('company_id', $this->company->id)
            ->whereKeyNot(CompanyContext::branchId())->value('id');

        if ($other !== null) {
            CompanyContext::set($this->company->id, (int) $other);
            $this->readPageAs($this->owner);
            $this->assertSame('figure-2', $this->figure(), '⛔ another branch read the first branch\'s figure.');
            CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        }

        $this->readPageAs($this->sales);
        $before = $this->figure();
        $this->sales->givePermissionTo(\Spatie\Permission\Models\Permission::query()->where('guard_name', 'web')
            ->whereNotIn('name', $this->sales->getAllPermissions()->pluck('name'))->firstOrFail());
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->sales = $this->sales->fresh();

        $this->readPageAs($this->sales);
        $this->assertNotSame($before, $this->figure(), '⛔ a changed key met the figure counted under the old keys.');
    }

    public function test_a_posting_counts_afresh(): void
    {
        $this->readPageAs($this->owner);
        $this->assertSame('figure-1', $this->figure());

        DashboardFigureCache::bust($this->company->id);
        $this->readPageAs($this->owner);

        $this->assertSame('figure-2', $this->figure(), '⛔ a posting left the old figure in place.');
    }

    public function test_outside_a_read_only_page_nothing_is_remembered(): void
    {
        $this->actingAs($this->owner);
        $request = Request::create('/x', 'POST');
        $this->app->instance('request', $request);
        app(ReadWindow::class)->open($request);

        $this->assertSame('figure-1', $this->figure());
        $this->assertSame('figure-2', $this->figure(), '⛔ a form post answered from memory.');
    }

    public function test_a_user_without_the_keys_never_sees_a_gated_widget_the_owner_filled(): void
    {
        $this->readPageAs($this->owner);
        $ownerWidgets = collect(app(DashboardRegistry::class)->forUser($this->owner))->flatten(1);
        $this->assertNotEmpty($ownerWidgets, 'prerequisite — the owner has home widgets');

        $this->readPageAs($this->sales);
        $salesWidgets = collect(app(DashboardRegistry::class)->forUser($this->sales))->flatten(1);

        foreach ($salesWidgets as $widget) {
            $this->assertTrue($this->sales->can($widget->permission),
                "⛔ '{$widget->label}' reached a user without '{$widget->permission}'.");
        }

        $this->assertNotEmpty($ownerWidgets->reject(fn ($w) => $this->sales->can($w->permission)),
            'prerequisite — the owner sees a widget the sales user may not, so the claim proves something');
    }

    public function test_a_figure_that_cannot_be_stored_is_still_answered_every_time(): void
    {
        $this->readPageAs($this->owner);
        $cache = app(DashboardFigureCache::class);

        $first = $cache->remember('closure', fn () => ['n' => ++$this->computed, 'f' => fn () => 1]);
        $second = $cache->remember('closure', fn () => ['n' => ++$this->computed, 'f' => fn () => 1]);

        $this->assertSame([1, 2], [$first['n'], $second['n']]);
    }

    private function figure(): string
    {
        return app(DashboardFigureCache::class)->remember('probe', fn () => 'figure-'.(++$this->computed));
    }

    /** a fresh GET page being built for this user — the window opens exactly as the middleware opens it */
    private function readPageAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user);
        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);
        $this->app->forgetScopedInstances();
        app(ReadWindow::class)->open($request);
    }
}
