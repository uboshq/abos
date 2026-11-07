<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⓘ দাবির প্রস্তুতি: টাকার কাগজে দ্বিতীয় হাত — ভা-১৪ (মালিকের সিদ্ধান্ত ২, ২৭ সেপ্টেম্বর ২০২৬; কোড ৭ নভেম্বর ২০২৬):
 * যিনি টাকার কাগজ চান বা বানান, তিনি — সুপার অ্যাডমিন হলেও — নিজে অনুমোদন দেন না ([[ApprovalEngine::ownMoneyHand()]])।
 *
 * ⚠️ পুরনো দাবিগুলোতে মালিক নিজেই কাগজ বানাতেন আর ছকের একমাত্র অনুমোদনদাতাও ছিলেন। ⭐ এখানে কোম্পানির আরেকজন সুপার
 * অ্যাডমিন — টাকার সীমার প্রশ্ন ওঠে না, দাবিটা যা মাপত তা-ই মাপে; শুধু অনুমোদনের হাতটা আলাদা।
 */
trait HasASecondSigner
{
    private ?User $secondSignerCache = null;

    protected function secondSigner(?Company $company = null): User
    {
        if ($this->secondSignerCache !== null) {
            return $this->secondSignerCache;
        }

        $company ??= Company::query()->findOrFail(CompanyContext::id());

        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $company->id,
            'current_branch_id' => $company->defaultBranch()?->id]);
        $user->companies()->attach($company->id, ['is_active' => true]);

        CompanyContext::forCompany($company->id, fn () => $user->assignRole(
            Role::query()->where('company_id', $company->id)->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail()));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->secondSignerCache = $user->fresh();
    }
}
