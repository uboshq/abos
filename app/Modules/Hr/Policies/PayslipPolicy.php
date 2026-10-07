<?php

declare(strict_types=1);

namespace App\Modules\Hr\Policies;

use App\Core\Services\DataScope;
use App\Models\User;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Support\BranchReach;

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

        /*
         * ⛔ কর্মীর শাখা, রানের নয় — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (HR ⛔২)। ⓘ রান পুরো কোম্পানির কর্মীদের; "কোথায় বসে
         * চালানো হলো" দিয়ে কার স্লিপ দেখা যাবে ঠিক হত, তাই সীমিত ম্যানেজার রানের শাখায় থাকলে সব শাখার কর্মীর বেতন দেখতেন —
         * যাঁদের কর্মীর তালিকা তাঁর কাছ থেকে লুকায় ([[EmployeePolicy]])। কর্মীর তালিকার একই নাগাল ([[BranchReach]])।
         */
        return app(BranchReach::class)->reaches($user, $payslip->employee()->withoutGlobalScopes()->first());
    }
}
