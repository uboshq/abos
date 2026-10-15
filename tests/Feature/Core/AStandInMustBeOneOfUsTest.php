<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DelegationService;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ ভার কেবল এই কোম্পানির চালু মানুষকে, আর বন্ধ করা মানুষের ভার আর খাটে না — Approval লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬
 * ([[ApprovalDelegationController::store()]]; [[ApprovalEngine]] `delegatorAt`)।
 *
 * ⓘ ভারের ফর্ম কেবল দেখত মানুষটা কোথাও আছেন কি না — হাতে বানানো অনুরোধে অন্য কোম্পানির কাউকে ভার দেওয়া যেত। আর যিনি ভার দিয়েছিলেন
 * তাঁকে বন্ধ করলেও তাঁর ভারপ্রাপ্ত তাঁর হয়ে সই চালিয়ে যেতেন।
 */
final class AStandInMustBeOneOfUsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stand_in_from_outside_the_company_is_refused(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $outsider = User::query()->create(['name' => 'Outsider', 'email' => 'outsider@elsewhere.test', 'password' => 'x']);

        $form = fn (User $to) => ['to_user_id' => $to->id, 'starts_on' => now()->toDateString(), 'ends_on' => now()->addDays(2)->toDateString()];

        $this->actingAs($owner)->post(route('approval.delegation.store'), $form($outsider))->assertSessionHasErrors('to_user_id');

        $colleague = User::query()->create(['name' => 'Colleague', 'email' => 'colleague@abos.test', 'password' => 'x']);
        $colleague->companies()->attach($company->id, ['is_active' => true]);
        $this->actingAs($owner)->post(route('approval.delegation.store'), $form($colleague))->assertSessionDoesntHaveErrors('to_user_id');
    }

    public function test_a_switched_off_persons_stand_in_signs_no_more(): void
    {
        $company = Company::create(['code' => 'OFF', 'name_en' => 'Off Co']);
        CompanyContext::set($company->id);
        [$clerk, $boss, $deputy] = array_map(function (string $who) use ($company) {
            $user = User::create(['name' => $who, 'email' => $who.'@off.test', 'password' => 'x']);
            $user->companies()->attach($company->id);

            return $user;
        }, ['clerk', 'boss', 'deputy']);

        $flow = ApprovalFlow::create(['module' => 'sales', 'action' => 'discount']);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $boss->id]);
        app(DelegationService::class)->grant(from: $boss, to: $deputy, startsOn: now()->toDateString(), endsOn: now()->addDays(3)->toDateString());

        $approval = app(ApprovalEngine::class)->request(Branch::create(['code' => 'O'.uniqid(), 'name_en' => 'Doc']),
            'sales', 'discount', '5000', null, 'test', $clerk->id);

        $this->assertTrue(app(ApprovalEngine::class)->canDecide($approval, $deputy), 'দৃশ্যটাই বানানো যায়নি — ভারপ্রাপ্ত সই দিতে পারছেন না');

        $boss->forceFill(['is_active' => false])->save();
        $this->assertFalse(app()->make(ApprovalEngine::class)->canDecide($approval->fresh(), $deputy), '⛔ বন্ধ করা মানুষের হয়ে ভারপ্রাপ্ত এখনো সই দিচ্ছেন');
        CompanyContext::clear();
    }
}
