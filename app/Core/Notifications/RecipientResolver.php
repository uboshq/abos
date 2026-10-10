<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\NotificationRecipientGroup;
use App\Models\User;
use App\Models\UserDataScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ নিয়ম বা দলের "কার কাছে" থেকে আসল মানুষ (মালিকের স্পেক §৪ "Recipient Management", §৮ "Recipient"; ধাপ ৩)।
 *
 * ── কে কোন ধরনে পড়েন ────────────────────────────────────────────────
 *   · users — সরাসরি বাছা মানুষ
 *   · roles — এই কোম্পানিতে সেই রোলে যাঁরা আছেন
 *   · branches — সেই শাখার কর্মী (HR-এ শাখা বসানো, লগইন আছে) আর যাঁদের দেখার সীমা সেই শাখায় বাঁধা
 *   · departments — সেই বিভাগের কর্মী (HR-এ বিভাগ বসানো, লগইন আছে)
 *   · groups — নাম দেওয়া দলের সদস্য (উপরের চার ধরন)
 *   · responsible — কাগজটা যিনি বানিয়েছেন (`created_by`)
 *
 * ── ⛔ দেয়াল ─────────────────────────────────────────────────────────
 * গোনা হয় পাঠানোর মুহূর্তে, আর সবার উপর তিনটা ছাঁকনি: সক্রিয় ব্যবহারকারী, এই কোম্পানির সক্রিয় সদস্য, আর খবরের শাখা তাঁর
 * নাগালে ([[DataScope::allows()]])। তাই নিয়মে "সব শাখার হিসাবরক্ষক" লিখলেও অন্য শাখায় আটকানো কেউ সেই শাখার কাগজের খবর পান না।
 */
final class RecipientResolver
{
    public function __construct(private readonly DataScope $scope) {}

    /**
     * @param  array<string, mixed>  $spec  {users, roles, branches, departments, groups, responsible}
     * @return Collection<int, User>
     */
    public function resolve(array $spec, ?int $branchId = null, ?Model $about = null): Collection
    {
        $company = (int) CompanyContext::id();
        $ids = $this->idsFor($spec, $company);

        foreach ($this->list($spec['groups'] ?? []) as $groupId) {
            $group = NotificationRecipientGroup::query()->whereKey($groupId)->where('is_active', true)->first();

            if ($group !== null) {
                $ids = array_merge($ids, $this->idsFor((array) $group->members, $company));
            }
        }

        if (! empty($spec['responsible']) && $about !== null) {
            $owner = $about->getAttribute('created_by');

            if (is_numeric($owner)) {
                $ids[] = (int) $owner;
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return collect();
        }

        return User::query()->withoutGlobalScope('company')
            ->whereIn('users.id', $ids)
            ->where('users.is_active', true)
            ->whereExists(fn ($q) => $q->from('company_user')->whereColumn('company_user.user_id', 'users.id')
                ->where('company_user.company_id', $company)->where('company_user.is_active', true))
            ->orderBy('users.id')
            ->get()
            ->filter(fn (User $user) => $this->scope->allows($user, UserDataScope::BRANCH, $branchId))
            ->values();
    }

    /** @return list<int> চার ধরনের সদস্যের ব্যবহারকারী-আইডি */
    private function idsFor(array $spec, int $company): array
    {
        $ids = $this->list($spec['users'] ?? []);

        if ($roles = $this->list($spec['roles'] ?? [])) {
            $ids = array_merge($ids, DB::table('model_has_roles')
                ->where('company_id', $company)->whereIn('role_id', $roles)
                ->where('model_type', (new User)->getMorphClass())
                ->pluck('model_id')->map(fn ($id) => (int) $id)->all());
        }

        if ($branches = $this->list($spec['branches'] ?? [])) {
            $ids = array_merge($ids, $this->employees($company, 'branch_id', $branches), DB::table('user_data_scopes')
                ->where('company_id', $company)->where('scope_type', UserDataScope::BRANCH)->whereIn('scope_id', $branches)
                ->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        }

        if ($departments = $this->list($spec['departments'] ?? [])) {
            $ids = array_merge($ids, $this->employees($company, 'department_id', $departments));
        }

        return $ids;
    }

    /** @return list<int> */
    private function employees(int $company, string $column, array $values): array
    {
        return DB::table('hr_employees')
            ->where('company_id', $company)->whereIn($column, $values)
            ->whereNotNull('user_id')->whereNull('deleted_at')->where('is_active', true)
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> */
    private function list(mixed $values): array
    {
        return array_values(array_filter(array_map('intval', (array) $values)));
    }
}
