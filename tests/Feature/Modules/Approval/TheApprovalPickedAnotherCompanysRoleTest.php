<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Support\CompanyContext;
use App\Models\ApprovalLimit;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * অনুমোদন-ছক আর কর্তৃত্ব-সীমায় অন্য কোম্পানির রোল দেখা আর বসানো যেত — চূড়ান্ত অডিট, ৩০ সেপ্টেম্বর ২০২৬ (⛔১৫)।
 *
 * ⛔ ভুল রোল বাছলে সীমা নীরবে অকেজো হত, আর ধাপ এমন কারও কাছে ঝুলত যিনি এই কোম্পানিতে সই দেখতেই পান না।
 * ⓘ একই মালিক, একই পর্দা; কেবল রোলটা কোন কোম্পানির — সেটাই আলাদা।
 */
final class TheApprovalPickedAnotherCompanysRoleTest extends TestCase
{
    use RefreshDatabase;

    private Company $mine;

    private Role $foreign;

    private Role $own;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->mine = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $other = Company::query()->where('id', '!=', $this->mine->id)->firstOrFail();
        CompanyContext::set($this->mine->id, $this->mine->defaultBranch()?->id);

        $this->foreign = Role::query()->create(['name' => 'Foreign Signer Only', 'guard_name' => 'web', 'company_id' => $other->id]);
        $this->own = Role::query()->where('company_id', $this->mine->id)->where('name', '!=', 'super_admin')->firstOrFail();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    public function test_the_lists_show_only_this_companys_roles(): void
    {
        foreach (['approval.limit.index', 'approval.flow.create'] as $route) {
            $html = $this->actingAs($this->owner)->get(route($route))->assertOk()->getContent();

            $this->assertStringNotContainsString('Foreign Signer Only', $html, "{$route}: অন্য কোম্পানির রোল তালিকায় এসেছে।");
            $this->assertStringContainsString(e($this->own->name), $html, "{$route}: নিজের কোম্পানির রোলই নেই — তালিকা খালি হয়ে গেছে।");
        }
    }

    public function test_a_limit_cannot_be_saved_for_another_companys_role(): void
    {
        $this->actingAs($this->owner)
            ->post(route('approval.limit.store'), ['role_id' => $this->foreign->id, 'max_amount' => '5000'])
            ->assertSessionHasErrors('role_id');
        $this->assertSame(0, ApprovalLimit::query()->where('role_id', $this->foreign->id)->count());

        /* ⓘ নিজের রোলে একই অনুরোধ — বসে (বেশি বন্ধ করা হয়নি) */
        $this->actingAs($this->owner)
            ->post(route('approval.limit.store'), ['role_id' => $this->own->id, 'max_amount' => '5000'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, ApprovalLimit::query()->where('role_id', $this->own->id)->count());
    }

    public function test_a_flow_step_cannot_wait_on_another_companys_role(): void
    {
        $payload = fn (Role $role) => [
            'module' => 'sales', 'action' => 'discount', 'is_active' => '1',
            'steps' => [['level' => 1, 'approver' => 'role|'.$role->id]],
        ];

        $this->actingAs($this->owner)->post(route('approval.flow.store'), $payload($this->foreign))
            ->assertSessionHasErrors('steps.0.approver_id');

        $this->actingAs($this->owner)->post(route('approval.flow.store'), $payload($this->own))
            ->assertSessionDoesntHaveErrors('steps.0.approver_id');
    }
}
