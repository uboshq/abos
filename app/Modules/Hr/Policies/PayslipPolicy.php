<?php

declare(strict_types=1);

namespace App\Modules\Hr\Policies;

use App\Core\Services\DataScope;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;

/**
 * একটা বেতনের পাতা — তার রান যেখানে দেখা যায়, সেখানেই।
 *
 * ⚠️ রানটা শাখা ধরে সীমিত, কিন্তু পাতাটা রানের **সন্তান** — তার নিজের
 * কোনো শাখা নেই। ⛔ তাই `/hr/payslips/{id}/print` ঠিকানায় অন্য শাখার
 * যেকোনো পাতার নম্বর বসালে পাতাটা খুলে যাওয়ার পথ ছিল; আটকাত কেবল একটা
 * দুর্ঘটনা (রানটা না পেয়ে পর্দা ৫০০ দিত)। ⭐ এখন সিদ্ধান্তটা স্পষ্ট:
 * রানের শাখা আপনার নাগালে না থাকলে ৪০৩।
 *
 * ⓘ রান খোঁজা হয় শাখার সীমা ছাড়া (`acrossBranches()`), কারণ প্রশ্নটাই
 * হলো "রানটা কোন শাখার" — সীমার ভিতরে খুঁজলে উত্তরটা লুকিয়ে যেত।
 */
class PayslipPolicy
{
    public function __construct(private readonly DataScope $scope) {}

    public function view(User $user, Payslip $payslip): bool
    {
        if (! $user->can('hr.payroll.view')) {
            return false;
        }

        $branchId = PayrollRun::acrossBranches()
            ->whereKey($payslip->payroll_run_id)
            ->value('branch_id');

        return $this->scope->allows($user, UserDataScope::BRANCH, $branchId === null ? null : (int) $branchId);
    }
}
