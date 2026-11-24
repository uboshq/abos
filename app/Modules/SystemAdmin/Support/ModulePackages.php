<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Support;

use App\Core\Module\ModuleRegistry;
use App\Models\Branch;
use App\Models\BranchModulePackage;
use App\Models\ModulePackage;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ মডিউল প্যাকেজ — এক নামে একগুচ্ছ মডিউল, অনেক শাখায় একসাথে (মালিক, ২৪ নভেম্বর ২০২৬, নমুনা system-plan-v4)।
 *
 * ⓘ প্যাকেজ নতুন কোনো দেয়াল নয়: বসালে শাখার সেই পুরনো সারিই লেখা হয় ([[BranchModule]]), তাই মেনু, দরজা আর ফোন আগের নিয়মেই পড়ে।
 * ⛔ হাতে বদলানো শাখা প্যাকেজের বদলে নড়ে না — মানুষের সিদ্ধান্ত নীরবে মোছে না; আবার প্যাকেজে ফেরানো আলাদা একটা কাজ।
 */
final class ModulePackages
{
    public function __construct(private readonly ModuleBoard $board, private readonly ModuleRegistry $registry) {}

    /**
     * কোন শাখায় কোন প্যাকেজ, হাতে বদলানো কি না — এক প্রশ্নে।
     *
     * @param  list<int>  $branchIds
     * @return array<int, array{name: string, custom: bool}>
     */
    public function namesFor(array $branchIds): array
    {
        return BranchModulePackage::query()->with('package')->whereIn('branch_id', $branchIds)->get()
            ->filter(fn (BranchModulePackage $r) => $r->package !== null)
            ->mapWithKeys(fn (BranchModulePackage $r) => [(int) $r->branch_id => ['name' => $r->package->name(), 'custom' => (bool) $r->is_custom]])->all();
    }

    /** @return list<string> প্যাকেজে নেই এমন মডিউল — তালাবন্ধগুলো বাদ */
    public function offIn(ModulePackage $package): array
    {
        return array_values(array_filter(array_keys($this->registry->all()),
            fn (string $code) => ! $this->board->locked($code) && ! in_array($code, (array) $package->modules, true)));
    }

    /** হাতে বদলানোর পরে — প্যাকেজের সাথে আর না মিললে "নিজের মতো", মিললে আবার প্যাকেজের */
    public function markHandMade(array $branchIds): void
    {
        $rows = BranchModulePackage::query()->with('package')->whereIn('branch_id', $branchIds)->get();
        $off = $this->board->branchOff($branchIds);

        foreach ($rows as $row) {
            if ($row->package === null) {
                continue;
            }
            $now = array_filter($off[(int) $row->branch_id] ?? [], fn (string $c) => ! $this->board->locked($c));
            $same = array_diff($now, $this->offIn($row->package)) === [] && array_diff($this->offIn($row->package), $now) === [];
            if ((bool) $row->is_custom === $same) {
                $row->is_custom = ! $same;
                $row->save();
            }
        }
    }

    /**
     * শাখাগুলোয় প্যাকেজ বসানো — চলতি কোম্পানির দেয়ালের ভিতরে; আগে যা-ই থাকুক, এখন প্যাকেজের মতো।
     *
     * @param  list<int>  $branchIds
     */
    public function assign(ModulePackage $package, array $branchIds, ?int $userId): int
    {
        return DB::transaction(function () use ($package, $branchIds, $userId): int {
            foreach ($branchIds as $id) {
                $row = BranchModulePackage::query()->firstOrNew(['branch_id' => $id]);
                $row->fill(['package_id' => $package->id, 'is_custom' => false, 'created_by' => $row->exists ? $row->created_by : $userId])->save();
            }

            return $this->write($package, $branchIds, $userId);
        });
    }

    /** প্যাকেজ বদলালে — কেবল হাতে না বদলানো শাখায় */
    public function spread(ModulePackage $package, ?int $userId): int
    {
        return $this->write($package, $this->followers($package), $userId);
    }

    /** @return list<int> */
    public function followers(ModulePackage $package): array
    {
        return BranchModulePackage::query()->where('package_id', $package->id)->where('is_custom', false)
            ->whereIn('branch_id', Branch::query()->where('is_active', true)->select('id'))
            ->pluck('branch_id')->map(fn ($id) => (int) $id)->all();
    }

    private function write(ModulePackage $package, array $branchIds, ?int $userId): int
    {
        if ($branchIds === []) {
            return 0;
        }

        $plan = $this->plan($package, array_fill_keys($branchIds, ''));

        return $plan['changes'] === [] ? 0 : $this->board->writeBranches($plan['changes'], $userId, byHand: false);
    }

    /** @param  array<int, string>  $names */
    public function plan(ModulePackage $package, array $names): array
    {
        $off = $this->offIn($package);
        $wanted = [];
        foreach (array_keys($this->registry->all()) as $code) {
            $wanted[$code] = ! in_array($code, $off, true);
        }

        return $this->board->plan($names, $this->board->branchOff(array_keys($names)), $wanted);
    }
}
