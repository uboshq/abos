<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ এক শাখায় সীমিত অ্যাডমিন নিজের আর অন্যের দেখার সীমা বাড়াতে পারতেন — পুরো ERP অডিট, ৬ অক্টোবর ২০২৬ (SystemAdmin ⛔৩)।
 *
 * দাবি — একই অ্যাডমিন (শাখা A-তে সীমিত):
 *   · কাউকে "সীমা নেই" (খালি) বা শাখা B দেওয়া ফেরত; শাখা A দেওয়া চলে
 *   · নিজের সীমা খালি করে নিজেকে সীমাহীন করা ফেরত
 *   · সীমাহীন সহকর্মীর খাতায় হাত নয়; তিনি A-তে সীমিত হলে তবেই
 */
final class ALimitedAdminWidenedTheirOwnViewTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $a;

    private Branch $b;

    private User $admin;

    private User $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        [$this->a, $this->b] = Branch::acrossAllCompanies()->where('company_id', $this->company->id)->orderBy('id')->take(2)->get()->all();
        CompanyContext::set($this->company->id, $this->a->id);

        // ⓘ চাবিহীন একটা সাধারণ ভূমিকা — ফর্মে ভূমিকা লাগে, আর এটা যে কেউ দিতে পারেন
        CompanyContext::forCompany($this->company->id, fn () => \Spatie\Permission\Models\Role::query()->create([
            'name' => 'a_plain', 'guard_name' => 'web', 'company_id' => $this->company->id,
        ]));

        $this->admin = $this->member();
        CompanyContext::forCompany($this->company->id, fn () => $this->admin->givePermissionTo('system_admin.user.manage'));
        $this->limitTo($this->admin, $this->a);

        $this->target = $this->member();
        $this->limitTo($this->target, $this->a);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_a_limited_admin_gives_only_what_they_see_themselves(): void
    {
        $this->actingAs($this->admin->fresh())->put(route('system_admin.user.update', $this->target), $this->form($this->target, []))
            ->assertSessionHasErrors('branch_scope.'.$this->company->id);
        $this->assertSame([$this->a->id], $this->scopeOf($this->target), '⛔ সীমিত অ্যাডমিন কাউকে সীমাহীন করলেন।');

        $this->actingAs($this->admin->fresh())->put(route('system_admin.user.update', $this->target), $this->form($this->target, [$this->b->id]))
            ->assertSessionHasErrors('branch_scope.'.$this->company->id);
        $this->assertSame([$this->a->id], $this->scopeOf($this->target), '⛔ সীমিত অ্যাডমিন নিজের না-দেখা শাখা দিলেন।');

        $this->actingAs($this->admin->fresh())->put(route('system_admin.user.update', $this->target), $this->form($this->target, [$this->a->id]))
            ->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_a_limited_admin_cannot_lift_their_own_limit(): void
    {
        $this->actingAs($this->admin->fresh())->put(route('system_admin.user.update', $this->admin), $this->form($this->admin, []))
            ->assertSessionHasErrors('branch_scope.'.$this->company->id);
        $this->assertSame([$this->a->id], $this->scopeOf($this->admin), '⛔ সীমিত অ্যাডমিন নিজের সীমা মুছে দিলেন।');
    }

    public function test_a_limited_admin_cannot_touch_an_unlimited_colleague(): void
    {
        $free = $this->member();

        $this->assertFalse($this->mayChange($free), '⛔ সীমিত অ্যাডমিন সীমাহীন সহকর্মীর খাতা বদলাতে পারেন।');

        $this->limitTo($free, $this->a);
        $this->assertTrue($this->mayChange($free), 'একই সীমার সহকর্মী — তবু ফেরানো হলো।');
    }

    private function mayChange(User $target): bool
    {
        return CompanyContext::forCompany($this->company->id, fn () => Gate::forUser($this->admin->fresh())->allows('update', $target->fresh()));
    }

    /** @param  list<int>  $branches @return array<string, mixed> */
    private function form(User $user, array $branches): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'locale' => 'bn',
            'is_active' => '1',
            'roles' => ['a_plain'],
            'companies' => [$this->company->id],
            'default_branch' => [$this->company->id => $this->a->id],
            'branch_scope' => [$this->company->id => $branches],
        ];
    }

    private function member(): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user;
    }

    private function limitTo(User $user, Branch $branch): void
    {
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $user->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $branch->id,
        ]);
        app(\App\Core\Services\DataScope::class)->forget();
    }

    /** @return list<int> */
    private function scopeOf(User $user): array
    {
        return UserDataScope::query()->withoutGlobalScopes()->where('user_id', $user->id)
            ->where('scope_type', UserDataScope::BRANCH)->pluck('scope_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }
}
