<?php

declare(strict_types=1);

namespace App\Modules\Hr\Support;

use App\Core\Services\PermissionSyncer;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * ⭐ ছুটিতে কে সই দেন — কর্মীর নিজের ব্যবস্থাপক, আর HR (ঘাটতির তালিকা #১৫; a6, ২১ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `hr.leave.approve` চাবি হাতে যে কেউ নাগালের যেকোনো কর্মীর ছুটিতে সই দিতেন — নিজের দলের নন এমন মানুষের ছুটিও। কর্মীর সারিতে
 * `reports_to_employee_id` (যাঁর অধীনে) ছিল, কিন্তু সইয়ের প্রশ্নে কেউ সেটা পড়ত না।
 *
 * ── ⭐ নিয়ম (manager self-service + HR override — SuccessFactors/Oracle HCM-এর প্রমিত ছাঁচ) ─────────────────
 * সই দেওয়ার চাবি (`hr.leave.approve`) দরজায় লাগেই; তার উপরে এই দুইয়ের একটা —
 * ১. **কর্মীর নিজের ব্যবস্থাপক:** কর্মীর `reports_to_employee_id` যে কর্মী, তাঁর লগইন (`user_id`) এই মানুষ — আর সেই কর্মী-সারি
 *    চালু ও না-মোছা (ছেড়ে যাওয়া ব্যবস্থাপক আর সই দেন না)।
 * ২. **HR-জুড়ে:** `hr.leave.manage` — HR-এর ভূমিকার চাবি (যে কারও নামে ছুটি বসায়, মঞ্জুর ছুটি প্রত্যাহার করে); নতুন চাবি নয়,
 *    মাইগ্রেশনও নয়। মালিক (সুপার অ্যাডমিন) সবসময়।
 * ⓘ ব্যবস্থাপক না থাকলে (`reports_to` ফাঁকা) কেবল HR — কাগজ কখনো সই-হীন আটকে থাকে না, কারণ HR সবসময় আছেন।
 * ⛔ শাখার দেয়াল আগের মতোই দরজায় ([[BranchReach]]), আর নিজের ছুটিতে নিজে সই নয় ([[LeaveService::assertNotOwn()]]) — দুইটাই এর
 * আগে; এই নিয়ম তাদের সরায় না।
 *
 * ⓘ একটাই উত্তরদাতা: সেবা ([[LeaveService::approve()]]/[[LeaveService::reject()]]) সব দরজার জন্য এটা দেখে, আর ওয়েবের বোতাম
 * ও ফোনের `can_decide` একই প্রশ্ন করে — বোতাম দেখা গেলে দরজাও খোলে।
 */
final class LeaveSigner
{
    /** @var array<int, list<int>> ব্যবহারকারী → তাঁর চালু কর্মী-সারিগুলো; এক অনুরোধে একবার গোনা (তালিকার প্রতিটা সারির জন্য নয়) */
    private array $employeesOf = [];

    /** এই মানুষ কি এই আবেদনে সই দিতে (বা ফেরাতে) পারেন — চাবি আর নাগাল দরজা দেখে, এটা কেবল "কার" প্রশ্ন */
    public function mayDecide(?User $user, LeaveApplication $application): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE) || $user->can('hr.leave.manage')) {
            return true;
        }

        // ⓘ তালিকা কর্মী আগেই তুলে থাকলে সেখান থেকে (প্রতি সারিতে নতুন প্রশ্ন নয়); কাঁচা মান — পর্দার ঢাকনা ([[FieldSecurity]]) এই প্রশ্ন বদলায় না
        $loaded = $application->relationLoaded('employee') ? $application->getRelation('employee') : null;
        $manager = $loaded instanceof Employee
            ? ($loaded->getAttributes()['reports_to_employee_id'] ?? null)
            : Employee::query()->withoutGlobalScopes()->whereKey($application->employee_id)->value('reports_to_employee_id');

        return $manager !== null && in_array((int) $manager, $this->employeesOf($user), true);
    }

    /** ⛔ না পারলে ৪০৩ — ওয়েব আর ফোন দুই দরজাতেই, কারণ সেবা ডাকে */
    public function assertMayDecide(?User $user, LeaveApplication $application): void
    {
        if (! $this->mayDecide($user, $application)) {
            throw new AuthorizationException(__('hr::validation.leave_not_your_line'));
        }
    }

    /** @return list<int> */
    private function employeesOf(User $user): array
    {
        return $this->employeesOf[(int) $user->id] ??= Employee::query()
            ->where('user_id', $user->id)
            ->active()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
