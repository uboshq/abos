<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Security\FieldSecurity;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Services\PayrollService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ টেস্টে "দ্বিতীয় সই" — মালিকের ব্যতিক্রম (a6, ২১ অক্টোবর ২০২৬): বেতনের রান, বেতনের কাঠামো আর বেতনের ঠিকানা ছক ছাড়াও আরেকজনের
 * সই চায় ([[ApprovalRules::secondHand()]])।
 *
 * ⓘ ডেমোর কোম্পানিতে কোনো ছক নেই ([[DemoSeeder::clearTheApprovalFlows()]]), তাই আগে রান এক চাপে নিশ্চিত হত; এখন প্রথম চাপে থামে।
 * যে টেস্টের বিষয় সই নয়, সে এখানে আসল পথে যায়: নিশ্চিত → থামা → আলাদা একজন চাবি থাকা মানুষের সই, আসল ইঞ্জিনে → আবার নিশ্চিত।
 * ⛔ নিয়মটা বন্ধ করার কোনো সুইচ এখানে নেই ([[SignsMoneyOff]]-এর একই কারণ)।
 */
trait SignsTheSecondHand
{
    /**
     * ⭐ রানটা নিশ্চিত — সই লাগলে আলাদা একজন দেন (`hr.payroll.manage`, `hr.salary.view`, `approval.decide`)।
     */
    protected function confirmedPayroll(PayrollRun $run): PayrollRun
    {
        $payroll = app(PayrollService::class);

        try {
            return $payroll->confirm($run->fresh());
        } catch (HeldForApproval) {
            // ⓘ থামল — নিচে দ্বিতীয় হাত
        }

        $this->signThePendingPayroll($run);

        return $payroll->confirm($run->fresh());
    }

    /**
     * ⓘ সই চাওয়া আর দ্বিতীয় হাতের সই — নিশ্চিত না করে (যে টেস্ট সইয়ের পরের পাহারা দেখে, যেমন বাসি কপি থেকে নিশ্চিত)।
     */
    protected function signPayrollWithoutConfirming(PayrollRun $run): void
    {
        try {
            app(PayrollService::class)->confirm($run->fresh());
            $this->fail('The payroll run was expected to stop for a signature, and it posted at once.');
        } catch (HeldForApproval) {
        }

        $this->signThePendingPayroll($run);
    }

    private function signThePendingPayroll(PayrollRun $run): void
    {
        $approval = app(ApprovalEngine::class)->latestFor($run->fresh(), 'payroll');

        if ($approval === null || $approval->status !== Approval::PENDING) {
            $this->fail('The payroll run stopped for a signature but no pending request was found.');
        }

        $signer = $this->secondHandIn((int) $run->company_id, 'hr.payroll.manage', 'hr.salary.view', 'approval.decide');
        CompanyContext::forCompany((int) $run->company_id,
            fn () => app(ApprovalEngine::class)->approve($approval, $signer, 'second hand in test'));
    }

    /** ⓘ চাবি থাকা আলাদা একজন — প্রতিবার নতুন, সুপার অ্যাডমিন নন */
    protected function secondHandIn(int $companyId, string ...$keys): User
    {
        $user = User::factory()->create(['current_company_id' => $companyId, 'is_active' => true]);
        $user->companies()->syncWithoutDetaching([$companyId => ['is_active' => true]]);

        CompanyContext::forCompany($companyId, function () use ($user, $keys) {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        FieldSecurity::forget();

        return $user->fresh();
    }
}
