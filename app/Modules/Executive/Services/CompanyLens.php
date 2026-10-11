<?php

declare(strict_types=1);

namespace App\Modules\Executive\Services;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\ViewedBranch;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * অন্য কোম্পানিতে ঢোকার দরজা — মালিকের কেন্দ্রের একমাত্র দরজা।
 *
 * ── ⛔ কে কোন কোম্পানি দেখেন ──────────────────────────────────────────
 * কেবল যে কোম্পানির সদস্য (`company_user`, চালু), **আর** সেখানে যাঁর
 * `executive.view` চাবি আছে — চাবি দেখা হয় **ঐ কোম্পানিতে বসে**
 * ([[User::canInCompany()]]), চলতি কোম্পানিতে নয়। ⓘ মালিক প্রতিটা কোম্পানিতে
 * super_admin, তাই তিনি সব দেখেন; বাকিরা কেবল নিজেরগুলো।
 *
 * ── ⭐ শাখা — ঐ কোম্পানির নিজের নিয়মে ────────────────────────────────
 * একটা শাখার সংখ্যা মানে ঐ কোম্পানির ড্যাশবোর্ড **ঐ শাখা বেছে** দেখলে যা
 * দেখায়। তাই এখানে কোনো শাখার ছাঁকনি লেখা নেই: প্রসঙ্গে শাখাটা বসানো হয়, আর
 * মানুষটাকে কিছুক্ষণের জন্য "এক শাখা দেখছেন" করা হয় ([[DataScope::viewsOneBranch()]])।
 * ⓘ বাকিটা মডিউলের নিজের কোয়েরি করে — ঠিক যেমন হেডারে শাখা বাছলে করত।
 *
 * ⚠️ মানুষটার সারি বদলানো হয় না (save নেই) — কেবল স্মৃতিতে, আর finally-তে আগেরটা ফেরে।
 */
final class CompanyLens
{
    public const KEY = 'executive.view';

    public function __construct(private readonly DataScope $scope) {}

    /**
     * যে কোম্পানিগুলো এই মানুষটা এই পর্দায় দেখতে পারেন — নামের ক্রমে।
     *
     * @return list<array{id: int, name: string}>
     */
    public function companies(User $user): array
    {
        $out = [];

        foreach ($user->companies()->where('companies.is_active', true)->orderBy('companies.id')->get() as $company) {
            if (! $user->canInCompany((int) $company->id, self::KEY)) {
                continue;
            }

            $out[] = ['id' => (int) $company->id, 'name' => $this->companyName($company)];
        }

        return $out;
    }

    /**
     * ঐ কোম্পানির যে শাখাগুলো মানুষটার সীমায় — সীমা না থাকলে সবগুলো।
     *
     * @return list<array{id: int, name: string}>
     */
    public function branches(User $user, int $companyId): array
    {
        $reach = CompanyContext::forCompany($companyId, fn () => $this->scope->idsFor($user, UserDataScope::BRANCH));

        return Branch::acrossAllCompanies()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->when($reach !== null, fn ($q) => $q->whereIn('id', $reach ?: [0]))
            ->orderBy('id')
            ->get()
            ->map(fn (Branch $b) => ['id' => (int) $b->id, 'name' => $b->name()])
            ->values()
            ->all();
    }

    /**
     * হেডারে বাছা শাখা — কেবল চলতি কোম্পানিতে, আর কেবল "এক শাখা দেখছি" হলে।
     *
     * ⓘ হেডারের শাখাটা একটা কোম্পানির শাখা; অন্য কোম্পানিতে তার কোনো মানে নেই।
     */
    public function headerBranch(User $user): ?int
    {
        $current = (int) ($user->current_company_id ?? 0);

        if ($current === 0) {
            return null;
        }

        return CompanyContext::forCompany($current, function () use ($user, $current): ?int {
            CompanyContext::set($current, $user->current_branch_id === null ? null : (int) $user->current_branch_id);

            return ViewedBranch::one($user);
        });
    }

    /**
     * ঐ কোম্পানিতে, ঐ শাখা দেখার মতো করে, কাজটা চালানো।
     *
     * `$branchId = null` মানে "সব শাখা" — মানুষটার সীমার সব শাখা, শাখাহীন সারিসহ,
     * ঠিক হেডারে "সব শাখা" বাছলে যেমন।
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function within(User $user, int $companyId, ?int $branchId, Closure $work): mixed
    {
        return CompanyContext::forCompany($companyId, function () use ($user, $companyId, $branchId, $work) {
            CompanyContext::set($companyId, $branchId);

            $before = $user->getAttribute('view_all_branches');
            $wasClean = ! $user->isDirty('view_all_branches');

            /*
             * ⚠️ মডিউলের কোয়েরি সীমা পড়ে `auth()->user()` থেকে ([[DataScope::inView()]]),
             * তাই যাঁর চোখে দেখা হচ্ছে তিনিই প্রহরীর কাছে বসেন — রাতের ক্রনে কেউ বসে নেই।
             */
            $guard = Auth::guard();
            $seated = $guard->user();
            $guard->setUser($user);

            $user->setAttribute('view_all_branches', $branchId === null);
            $user->unsetRelation('roles')->unsetRelation('permissions');

            try {
                return $work();
            } finally {
                $user->setAttribute('view_all_branches', $before);

                if ($wasClean) {
                    $user->syncOriginalAttribute('view_all_branches');
                }

                $user->unsetRelation('roles')->unsetRelation('permissions');
                $seated === null ? $guard->forgetUser() : $guard->setUser($seated);
            }
        });
    }

    private function companyName(Company $company): string
    {
        return $company->name();
    }
}
