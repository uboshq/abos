<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\ViewedBranch;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Documents\Support\DocumentCatalog;
use App\Modules\MasterData\Models\Department;

/**
 * ডকুমেন্টের ফর্মের বাছাইগুলো — ফোল্ডার, ধরন, গোপনীয়তা, শাখা, বিভাগ, মালিক।
 *
 * ⓘ ফর্ম আর যাচাই ([[DocumentDetailsRules]]) দুইজনই এই একটা জায়গা পড়ে — যা পর্দায়
 * বাছা যায় না, তা জমাও হয় না।
 */
final class DocumentChoices
{
    public function __construct(
        private readonly DocumentAccess $access,
        private readonly DataScope $scope,
    ) {}

    /** @return array<string, string> */
    public function folders(): array
    {
        return $this->words(DocumentCatalog::FOLDERS, 'folder');
    }

    /** @return array<string, string> */
    public function types(): array
    {
        return $this->words(DocumentCatalog::TYPES, 'type');
    }

    /**
     * কেবল যে ধাপগুলো এই মানুষ নিজে দেখেন ([[DocumentAccess::levelsToChoose()]])।
     *
     * @return array<string, string>
     */
    public function levels(User $user): array
    {
        return $this->words($this->access->levelsToChoose($user), 'level');
    }

    /**
     * যে শাখাগুলোয় এই মানুষ কাগজ রাখতে পারেন — নিজের দেয়ালের ভিতরের।
     *
     * ⓘ "গোটা কোম্পানি" (ফাঁকা শাখা) কেবল যিনি সব শাখা দেখেন তাঁর জন্য
     * ([[companyWideAllowed()]]) — নাহলে তিনি কাগজ রেখে নিজেই আর খুঁজে পেতেন না।
     *
     * @return array<int, string>
     */
    public function branches(User $user): array
    {
        $ids = $this->scope->viewBranchIds($user);

        return Branch::query()
            ->active()
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name_en')
            ->get()
            ->mapWithKeys(fn (Branch $b) => [(int) $b->id => $b->name()])
            ->all();
    }

    public function companyWideAllowed(User $user): bool
    {
        return $this->scope->viewBranchIds($user) === null;
    }

    /** নতুন কাগজের শাখা — হেডারে বাছা শাখা, নয়তো কাজের শাখা */
    public function defaultBranch(User $user): ?int
    {
        $one = ViewedBranch::one($user) ?? CompanyContext::branchId();

        return $one !== null && array_key_exists($one, $this->branches($user)) ? $one : null;
    }

    /** @return array<int, string> */
    public function departments(): array
    {
        return Department::query()
            ->active()
            ->orderBy('name_en')
            ->get()
            ->mapWithKeys(fn (Department $d) => [(int) $d->id => $d->name()])
            ->all();
    }

    /**
     * মালিক — কেবল এই কোম্পানির মানুষ।
     *
     * ⛔ `users` টেবিলে কোম্পানি নেই; সম্পর্কটা `company_user`-এ, তাই ছাঁকনি সেখানে
     * ([[EveryUserListAsksWhichCompanyTest]])।
     *
     * @return array<int, string>
     */
    public function owners(): array
    {
        $companyId = CompanyContext::id();

        return User::query()
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $companyId))
            ->orderBy('name')
            ->get(['users.id', 'users.name'])
            ->mapWithKeys(fn (User $u) => [(int) $u->id => (string) $u->name])
            ->all();
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function words(array $keys, string $group): array
    {
        $out = [];

        foreach ($keys as $key) {
            $out[$key] = __('documents::catalog.'.$group.'.'.$key);
        }

        return $out;
    }
}
