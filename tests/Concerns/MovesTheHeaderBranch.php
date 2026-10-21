<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\FinancialYear;
use App\Models\NumberSeries;
use App\Models\User;

/**
 * উপরের শাখা-বাছাই বদলানো — আসল দরজা দিয়ে (`branch.switch`), যেমন অন্য ট্যাবে মানুষ বদলান (ec, ১৯ অক্টোবর ২০২৬; RCV-0143)।
 *
 * ⓘ একটা শাখার নিজের নম্বর সিরিজও বসানো যায় — কাগজের নম্বর কোন শাখার সিরিজ থেকে এল, সেটা উপসর্গেই পড়া যায়।
 */
trait MovesTheHeaderBranch
{
    /** `$branch` = শাখার আইডি, বা `'all'` (সব শাখা দেখা; কাজের শাখা আগেরটাই থাকে) */
    protected function moveHeaderTo(User $user, int|string $branch): User
    {
        $this->actingAs($user->fresh())
            ->post(route('branch.switch'), ['branch_id' => (string) $branch])
            ->assertRedirect();

        $user = $user->fresh();
        CompanyContext::set((int) $user->current_company_id, $user->current_branch_id === null ? null : (int) $user->current_branch_id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($user);

        return $user;
    }

    /** এই শাখার নিজের সিরিজ — নম্বর `<prefix>-0001` থেকে */
    protected function giveTheBranchItsOwnSeries(Branch $branch, string $module, string $docType, string $prefix): void
    {
        NumberSeries::query()->create([
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
            'financial_year_id' => FinancialYear::forDate(now())?->id,
            'module' => $module,
            'doc_type' => $docType,
            'prefix' => $prefix,
            'format' => '{PREFIX}-{SEQ}',
            'padding' => 4,
            'next_number' => 1,
            'start_number' => 1,
            'is_active' => true,
        ]);
    }
}
