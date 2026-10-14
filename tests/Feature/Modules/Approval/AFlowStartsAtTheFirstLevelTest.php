<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⛔ সইয়ের ছক স্তর ১ থেকে শুরু — Approval লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⓘ ফর্ম কেবল দেখত "অন্তত একটা স্তর" আর "একই জন দুইবার নয়"। কেবল দ্বিতীয় সারিটা ভরলে ছকের একমাত্র স্তর হত ২, অথচ প্রতিটা নতুন
 * অনুরোধ স্তর ১-এ বসে — যেখানে কেউ নেই। কাগজগুলো চিরকাল "সইয়ের অপেক্ষায়" থাকত, ফেরত নিয়ে আবার চাইলেও সেই স্তর ১।
 */
final class AFlowStartsAtTheFirstLevelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_flow_whose_lowest_level_is_not_one_is_refused(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $role = Role::query()->where('company_id', $company->id)->where('name', '!=', 'super_admin')->firstOrFail();

        $payload = fn (int $level) => ['module' => 'sales', 'action' => 'discount', 'is_active' => '1',
            'steps' => [['level' => $level, 'approver' => 'role|'.$role->id]]];
        $flows = ApprovalFlow::query()->count();

        $this->actingAs($owner)->post(route('approval.flow.store'), $payload(2))
            ->assertSessionHasErrors(['steps' => __('approval::validation.first_level_missing')]);
        $this->assertSame($flows, ApprovalFlow::query()->count(), '⛔ স্তর ১ ছাড়া ছক বসল — প্রতিটা কাগজ চিরকাল ঝুলত');

        // ⓘ স্তর ১ থাকলে এই নিয়ম থামায় না
        $this->actingAs($owner)->post(route('approval.flow.store'), $payload(1))->assertSessionDoesntHaveErrors('steps');
    }
}
