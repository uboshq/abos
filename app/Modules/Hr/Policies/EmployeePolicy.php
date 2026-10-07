<?php

declare(strict_types=1);

namespace App\Modules\Hr\Policies;

use App\Core\Services\DataScope;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Hr\Models\Employee;

/**
 * একজন কর্মী — কে দেখতে ও বদলাতে পারে।
 *
 * ── ⛔ নিরীক্ষার ফলাফল ৩.৪, ১২ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * *"HR-এ রেকর্ড-স্তরের পলিসি যোগ করুন — বেতনের মতো তথ্যে কেবল
 * মিডলওয়্যার যথেষ্ট নয়।"*
 *
 * ⓘ মিডলওয়্যার বলে "আপনি কর্মী দেখতে পারেন"। কোন কর্মী, সেটা বলে না।
 * ⚠️ বেতনের রান ইতিমধ্যেই শাখা ধরে সীমিত ([[ScopedToUserBranch]]) —
 * একটা শাখার হিসাবরক্ষক অন্য শাখার রান দেখেন না। কিন্তু সেই শাখার
 * **কর্মীর পাতা** খোলা ছিল: ঠিকানা বদলালেই অন্য শাখার একজনের বেতনের
 * কাঠামো, ব্যাংক হিসাব আর পরিচয়পত্র।
 *
 * ⭐ এখন নিয়মটা এক: যে শাখা আপনার নাগালের বাইরে, তার কর্মীও। শাখা
 * লেখা নেই এমন কর্মী সবার নাগালে — [[DataScope::allows()]]-এর নিয়মেই,
 * কারণ প্রধান অফিসের লোকের শাখা থাকে না।
 */
class EmployeePolicy
{
    public function __construct(private readonly DataScope $scope) {}

    public function view(User $user, Employee $employee): bool
    {
        return $user->can('hr.employee.view') && $this->reaches($user, $employee);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->can('hr.employee.manage') && $this->reaches($user, $employee);
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $this->update($user, $employee);
    }

    /** বেতনের কাঠামো দেখা — নিজের অনুমতির সাথে কর্মীর শাখাও */
    public function viewSalary(User $user, Employee $employee): bool
    {
        return $user->can('hr.salary.view') && $this->reaches($user, $employee);
    }

    public function manageSalary(User $user, Employee $employee): bool
    {
        return $user->can('hr.salary.manage') && $this->reaches($user, $employee);
    }

    private function reaches(User $user, Employee $employee): bool
    {
        return $this->scope->allows($user, UserDataScope::BRANCH, $employee->branch_id);
    }
}
