<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\DataScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ মালিক, ১২ নভেম্বর ২০২৬: "সবার সব ডিপো … যেগুলো দেয়া নাই এইগুলো অন্য আইডিতে দেখা যায়"।
 *
 * ⛔ ফিল্ড ফোর্সের (ডিলারের দেয়ালের ভেতরের মানুষ) ডিপো বসানো না থাকলে কোনো ডিপো নয় — "সব" নয়। ⓘ কেবল কোম্পানির দেয়ালের সুইচ চালু
 *   থাকলে; অফিসের মানুষের নিয়ম আগের মতো (সারি নেই মানে সব)।
 */
final class AFieldForcePersonWithoutADepotSeesNoDepotTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    public function test_a_walled_person_with_no_depot_sees_none_and_with_one_sees_only_it(): void
    {
        app(SettingsService::class)->set('customer.dealer_scope_enabled', true);
        $sr = $this->person(['customer.view', 'customer.dealers.own']);
        $office = $this->person(['customer.view']);

        $this->assertSame([], $this->branches($sr), '⛔ ডিপো না বসানো ফিল্ড ফোর্স সব ডিপো দেখে।');
        $this->assertNull($this->branches($office), '⛔ অফিসের মানুষের "সারি নেই মানে সব" ভেঙেছে।');

        $depot = (int) $this->company->defaultBranch()?->id;
        UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $sr->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $depot]);
        $this->assertSame([$depot], $this->branches($sr), '⛔ বসানো ডিপোটা দেখা যায় না।');
    }

    /** ⓘ সুইচ বন্ধ (বাঁধন আর ডিপো বসানোর আগে) — আজকের দেখা হঠাৎ শূন্য হয় না */
    public function test_with_the_switch_off_nothing_changes_for_anyone(): void
    {
        app(SettingsService::class)->set('customer.dealer_scope_enabled', false);
        $sr = $this->person(['customer.view', 'customer.dealers.own']);

        $this->assertNull($this->branches($sr), '⛔ সুইচ বন্ধেও ফিল্ড ফোর্সের ডিপো শূন্য — বসানোর আগেই দেখা বন্ধ হয়ে যেত।');
    }

    /** @return list<int>|null */
    private function branches(User $user): ?array
    {
        $scope = app(DataScope::class);
        $scope->forget();

        return $scope->idsFor($user, UserDataScope::BRANCH);
    }

    /** @param list<string> $keys */
    private function person(array $keys): User
    {
        $u = User::factory()->create(['current_company_id' => $this->company->id]);
        $u->companies()->attach($this->company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $u->givePermissionTo($keys);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }
}
