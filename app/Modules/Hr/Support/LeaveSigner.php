<?php

declare(strict_types=1);

namespace App\Modules\Hr\Support;

use App\Core\Services\Ownership;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveApplication;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ ছুটিতে কে সই দেন — কর্মীর নিজের ব্যবস্থাপক, আর HR (ঘাটতির তালিকা #১৫; a6, ২১ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `hr.leave.approve` চাবি হাতে যে কেউ নাগালের যেকোনো কর্মীর ছুটিতে সই দিতেন — নিজের দলের নন এমন মানুষের ছুটিও। কর্মীর সারিতে
 * `reports_to_employee_id` (যাঁর অধীনে) ছিল, কিন্তু সইয়ের প্রশ্নে কেউ সেটা পড়ত না।
 *
 * ── ⭐ নিয়ম (manager self-service + HR override — SuccessFactors/Oracle HCM-এর প্রমিত ছাঁচ) ─────────────────
 * সই দেওয়ার চাবি (`hr.leave.approve`) দরজায় লাগেই; তার উপরে এই দুইয়ের একটা —
 * ১. **কর্মীর নিজের ব্যবস্থাপক:** কর্মীর `reports_to_employee_id` যে কর্মী, তাঁর লগইন এই মানুষ, সেই কর্মী-সারি চালু ও না-মোছা,
 *    ⛔ **আর কর্মী তাঁর শাখার নাগালে** — রিপোর্টিং লাইন শাখার দেয়াল পেরোয় না (fe, ২১ অক্টোবর ২০২৬)। নাগালের বাইরে হলে ছুটি
 *    HR-এর, আর ব্যবস্থাপকের পর্দায় বোতামের বদলে সেই কথা ([[fallsToHr()]])।
 * ২. **HR-জুড়ে:** `hr.leave.manage` (HR-এর ভূমিকার চাবি); মালিক (সুপার অ্যাডমিন) সবসময়।
 * ⓘ ব্যবস্থাপক না থাকলে কেবল HR — কাগজ কখনো সই-হীন আটকে থাকে না।
 *
 * ── ⛔ দায়িত্বের ভাগ (SoD; fe, ২১ অক্টোবর ২০২৬) ──────────────────────
 * নিজের ছুটিতে কেউ সই দেন না ([[LeaveService::assertNotOwn()]])। আর যিনি অন্যের নামে আবেদনটা বসিয়েছেন (`created_by`), তিনি তাতে
 * সই দেন না, ফেরানও না ([[assertNotEnteredBy()]]) — ⓘ কেবল মালিক পারেন, আর তখন অডিটে আলাদা চিহ্ন `owner_self_signed`
 * ([[markOwnerSelfSigned()]]; অফারের সইয়ের হুবহু ব্যবস্থা — [[PromotionApprovalChain::OWNER_SELF_SIGNED]])।
 *
 * ⓘ একটাই উত্তরদাতা: সেবা ([[LeaveService::approve()]]/[[LeaveService::reject()]]) সব দরজার জন্য এটা দেখে; ওয়েবের বোতাম, ফোনের
 * `can_decide` আর "আমার সইয়ের অপেক্ষায়" গোনা [[maySign()]] জিজ্ঞেস করে — বোতাম দেখা গেলে দরজাও খোলে।
 */
final class LeaveSigner
{
    /** ⓘ অডিটের চিহ্ন — অফারের সইয়ের একই নাম (কলাম ২৪ অক্ষরের) */
    public const OWNER_SELF_SIGNED = 'owner_self_signed';

    /** @var array<int, list<int>> ব্যবহারকারী → তাঁর চালু কর্মী-সারিগুলো; এক অনুরোধে একবার গোনা */
    private array $employeesOf = [];

    public function __construct(private readonly BranchReach $reach) {}

    /** লাইনের প্রশ্ন — HR-জুড়ে, বা কর্মীর নিজের ব্যবস্থাপক আর কর্মী তাঁর নাগালে; চাবি দরজা দেখে */
    public function mayDecide(?User $user, LeaveApplication $application): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->isHrWide($user)) {
            return true;
        }

        return $this->isLineManager($user, $application) && $this->reach->reaches($user, $this->employeeOf($application));
    }

    /**
     * ⭐ বোতাম আর গোনার প্রশ্ন — সেবা যা যা আটকায় সব একসাথে: চাবি, অপেক্ষমাণ, নাগাল, নিজের নয়, নিজে বসানো নয় (মালিক ছাড়া), আর লাইন।
     */
    public function maySign(?User $user, LeaveApplication $application): bool
    {
        if ($user === null || ! $user->can('hr.leave.approve') || ! $application->isPending()) {
            return false;
        }

        if (! $this->reach->reaches($user, $this->employeeOf($application))) {
            return false;
        }

        if ($this->isOwn($user, $application) && ! $user->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE)) {
            return false;
        }

        if ($this->enteredBy($user, $application) && ! $this->isOwner($user, $application)) {
            return false;
        }

        return $this->mayDecide($user, $application);
    }

    /** ⭐ ব্যবস্থাপক, কিন্তু কর্মী তাঁর নাগালের বাইরে — ছুটি HR-এর কাছে গেছে; পর্দা বোতামের বদলে এটা বলে */
    public function fallsToHr(?User $user, LeaveApplication $application): bool
    {
        return $user !== null && $application->isPending() && ! $this->isHrWide($user)
            && $this->isLineManager($user, $application)
            && ! $this->reach->reaches($user, $this->employeeOf($application));
    }

    /** HR-জুড়ে — মালিক (সুপার অ্যাডমিন) বা `hr.leave.manage` */
    public function isHrWide(?User $user): bool
    {
        return $user !== null && ($user->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE) || $user->can('hr.leave.manage'));
    }

    /**
     * ⭐ "আমার সইয়ের অপেক্ষায়" — এই তালিকার যেগুলোতে এই মানুষ এখনই সই দিতে পারেন ([[maySign()]]-এর একই প্রশ্ন, প্রতিটা সারিতে)।
     *
     * @param  Builder<LeaveApplication>  $pending
     */
    public function countSignable(?User $user, Builder $pending): int
    {
        if ($user === null || ! $user->can('hr.leave.approve')) {
            return 0;
        }

        return $pending->with('employee')->get()
            ->filter(fn (LeaveApplication $a): bool => $this->maySign($user, $a))
            ->count();
    }

    /**
     * ⭐ ব্যবস্থাপকের নিজের দলের কর্মী — নাগালের বাইরে থাকলেও; তালিকায় সারিটা থাকে, বোতাম নয় ([[fallsToHr()]])।
     *
     * @return list<int>
     */
    public function teamOf(?User $user): array
    {
        $mine = $user === null ? [] : $this->employeesOf($user);

        return $mine === [] ? [] : Employee::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->whereIn('reports_to_employee_id', $mine)
            ->whereNull('deleted_at')
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * ⭐ ছুটির রেজিস্টারের সারি — ওয়েব আর ফোনের একই প্রশ্ন: দেখার শাখার কর্মী ([[BranchReach::throughEmployeeInView()]]), আর এই
     * মানুষের নিজের দলের আবেদন নাগালের বাইরে হলেও — যাতে ব্যবস্থাপক দেখেন ছুটিটা HR-এর কাছে গেছে ([[fallsToHr()]])।
     * ⓘ দলের সারিতে কেবল নাম, তারিখ আর অবস্থা; খোলা বা সই নাগাল দেখে, তাই দেয়াল ভাঙে না।
     *
     * @return Builder<LeaveApplication>
     */
    public static function listFor(?User $user): Builder
    {
        $seen = app(BranchReach::class)->throughEmployeeInView(LeaveApplication::query(), $user)->select('hr_leave_applications.id');
        $team = app(self::class)->teamOf($user);

        return LeaveApplication::query()->where(fn ($q) => $q->whereIn('hr_leave_applications.id', $seen)
            ->when($team !== [], fn ($w) => $w->orWhereIn('hr_leave_applications.employee_id', $team)));
    }

    /** ⛔ না পারলে ৪০৩ — ওয়েব আর ফোন দুই দরজাতেই, কারণ সেবা ডাকে */
    public function assertMayDecide(?User $user, LeaveApplication $application): void
    {
        if (! $this->mayDecide($user, $application)) {
            throw new AuthorizationException(__('hr::validation.leave_not_your_line'));
        }
    }

    /** ⛔ যিনি অন্যের নামে বসিয়েছেন তিনি সই দেন না, ফেরানও না — মালিক ছাড়া (fe, ২১ অক্টোবর ২০২৬) */
    public function assertNotEnteredBy(User $user, LeaveApplication $application): void
    {
        if ($this->enteredBy($user, $application) && ! $this->isOwner($user, $application)) {
            throw ValidationException::withMessages(['status' => __('hr::validation.leave_entered_by_you')]);
        }
    }

    /** ⛔ মালিক নিজে বসিয়ে নিজে সই দিলে (বা ফেরালে) অডিটে আলাদা চিহ্ন — অফারের সইয়ের হুবহু ([[PromotionApprovalChain]]) */
    public function markOwnerSelfSigned(User $user, LeaveApplication $application): void
    {
        if ($this->enteredBy($user, $application) && $this->isOwner($user, $application)) {
            $application->auditAction(self::OWNER_SELF_SIGNED, __('hr::validation.leave_owner_self_signed'));
        }
    }

    private function isLineManager(User $user, LeaveApplication $application): bool
    {
        // ⓘ কাঁচা মান — পর্দার ঢাকনা ([[FieldSecurity]]) এই প্রশ্ন বদলায় না
        $manager = $this->employeeOf($application)?->getAttributes()['reports_to_employee_id'] ?? null;

        return $manager !== null && in_array((int) $manager, $this->employeesOf($user), true);
    }

    /** নিজের ছুটি — কর্মীর লগইন এই মানুষ */
    private function isOwn(User $user, LeaveApplication $application): bool
    {
        $own = $this->employeeOf($application)?->getAttributes()['user_id'] ?? null;

        return $own !== null && (int) $own === (int) $user->id;
    }

    /** অন্যের নামে বসানো — নিজের আবেদন নিজে বসানো এর মধ্যে নয় (সেটা নিজের ছুটির নিয়ম) */
    private function enteredBy(User $user, LeaveApplication $application): bool
    {
        return $application->created_by !== null && (int) $application->created_by === (int) $user->id
            && ! $this->isOwn($user, $application);
    }

    /**
     * কোম্পানির মালিক — অফারের সইয়ের একই মাপ ([[Ownership::isOwnerIn()]]), আর সুপার অ্যাডমিন (fe: "owner (super admin)";
     * [[assertNotOwn()]]-এর একই ছাড়)।
     */
    private function isOwner(User $user, LeaveApplication $application): bool
    {
        return $user->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE)
            || app(Ownership::class)->isOwnerIn($user, (int) $application->company_id);
    }

    /** ⓘ তালিকা কর্মী আগেই তুলে থাকলে সেখান থেকে — প্রতি সারিতে নতুন প্রশ্ন নয় */
    private function employeeOf(LeaveApplication $application): ?Employee
    {
        $loaded = $application->relationLoaded('employee') ? $application->getRelation('employee') : null;

        return $loaded instanceof Employee
            ? $loaded
            : Employee::query()->withoutGlobalScopes()->find($application->employee_id);
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
