<?php

declare(strict_types=1);

namespace App\Modules\Hr\Support;

use App\Core\Services\DataScope;
use App\Core\Services\PermissionSyncer;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Hr\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * হাজিরা আর ছুটির শাখার দেয়াল — কর্মীর শাখা ধরে; চূড়ান্ত অডিট ⛔১৭, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * কর্মী, বেতনের কাঠামো আর বেতনের পাতা শাখা মানত ([[EmployeePolicy]], [[PayslipPolicy]]); হাজিরা আর ছুটি মানত না।
 * ঢাকায় সীমিত ব্যবস্থাপক নেত্রকোনার কর্মীর ছুটি অনুমোদন করতে পারতেন, আর হাজিরার পাতায় তাঁদের দিন বসাতে
 * পারতেন — আর বেতন সেই হাজিরা ধরেই কাটে।
 *
 * ── ⓘ নিয়মটা কর্মীর পাতার সেই একই ────────────────────────────────────
 * [[DataScope]]-এর শাখার সীমা; শাখা লেখা নেই এমন কর্মী (প্রধান অফিস) সবার নাগালে, ঠিক কর্মীর তালিকার মতো
 * ([[EmployeeController::index()]])। ⚠️ দুই জায়গায় দুই নিয়ম হলে তালিকায় এমন সারি থাকত যেটা চাপলে ৪০৩ আসে।
 *
 * ── ⭐ super_admin কখনো আটকায় না ─────────────────────────────────────
 * মালিকের শর্ত (৩০ সেপ্টেম্বর ২০২৬): কোম্পানির একমাত্র মালিক তিনি, আর কোনো সারাই যেন তাঁকে না আটকায় — তাঁর
 * নামে শাখার সারি থেকে গেলেও। ⓘ অন্য সবার জন্য সীমা না থাকা মানে সব শাখা, আগের মতোই।
 */
final class BranchReach
{
    public function __construct(private readonly DataScope $scope) {}

    /** নাগালের শাখাগুলো — `null` মানে সব (সীমা নেই, বা super_admin) */
    public function branches(?User $user): ?array
    {
        if ($user === null || $user->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE)) {
            return null;
        }

        return $this->scope->idsFor($user, UserDataScope::BRANCH);
    }

    /** এই কর্মী কি নাগালে */
    public function reaches(?User $user, ?Employee $employee): bool
    {
        if ($employee === null) {
            return false;
        }

        $branches = $this->branches($user);

        return $branches === null || $employee->branch_id === null || in_array((int) $employee->branch_id, $branches, true);
    }

    /** কর্মীর তালিকা নাগালের ভেতরে */
    public function employees(Builder $query, ?User $user): Builder
    {
        $branches = $this->branches($user);

        return $branches === null
            ? $query
            : $query->where(fn ($q) => $q->whereIn('branch_id', $branches)->orWhereNull('branch_id'));
    }

    /** কর্মী ধরে রাখা কাগজের (ছুটি, হাজিরা) তালিকা নাগালের ভেতরে */
    public function throughEmployee(Builder $query, ?User $user): Builder
    {
        $branches = $this->branches($user);

        return $branches === null
            ? $query
            // ⚠️ OR বন্ধনীতে — খোলা থাকলে সম্পর্কের নিজের শর্তটা (কোন কর্মী) ভেঙে সবার কাগজ বেরিয়ে আসত
            : $query->whereHas('employee', fn ($q) => $q->where(fn ($w) => $w->whereIn('branch_id', $branches)->orWhereNull('branch_id')));
    }

    /**
     * ⭐ দেখার শাখা — নাগালের উপরে হেডারের বাছা শাখা (মালিকের স্থায়ী নিয়ম "হেডারের শাখা সবকিছু ছাঁকে"; fe, a6, ২১ অক্টোবর ২০২৬)।
     *
     * ⓘ একটা শাখা বাছা থাকলে কেবল সেটা, শাখাহীন (প্রধান অফিসের) কর্মী ছাড়া — রিপোর্ট ([[ReportEngine::normaliseFilters()]]) আর HR
     * ড্যাশবোর্ড ([[DataScope::inView()]]) যা করে, হুবহু; "সব শাখা"-তে নাগাল আর শাখাহীন, আগের মতো। `null` = কোনো ছাঁকনি নেই।
     * ⛔ কেবল **তালিকার** পর্দায়: একটা কাগজ খোলা বা তাতে সই দেওয়া নাগাল দেখে ([[reaches()]]), হেডার নয়।
     *
     * @return array{ids: list<int>, nulls: bool}|null
     */
    public function viewing(?User $user): ?array
    {
        if ($this->scope->viewsOneBranch($user)) {
            return ['ids' => array_values((array) $this->scope->viewBranchIds($user)), 'nulls' => false];
        }

        $branches = $this->branches($user);

        return $branches === null ? null : ['ids' => $branches, 'nulls' => true];
    }

    /** কর্মীর তালিকা দেখার শাখায় ([[viewing()]]) */
    public function employeesInView(Builder $query, ?User $user): Builder
    {
        $view = $this->viewing($user);

        return $view === null ? $query : $query->where(fn ($q) => self::narrow($q, $view));
    }

    /** কর্মী ধরে রাখা কাগজের (ছুটি, হাজিরা, বেতনশিট) তালিকা দেখার শাখায় ([[viewing()]]) */
    public function throughEmployeeInView(Builder $query, ?User $user): Builder
    {
        $view = $this->viewing($user);

        // ⚠️ OR বন্ধনীতে — [[throughEmployee()]]-এর একই কারণ
        return $view === null ? $query : $query->whereHas('employee', fn ($q) => $q->where(fn ($w) => self::narrow($w, $view)));
    }

    /** @param  array{ids: list<int>, nulls: bool}  $view */
    private static function narrow($query, array $view): void
    {
        $query->whereIn('branch_id', $view['ids']);

        if ($view['nulls']) {
            $query->orWhereNull('branch_id');
        }
    }
}
