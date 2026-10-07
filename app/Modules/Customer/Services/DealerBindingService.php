<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Core\Services\DealerScope;
use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\DealerBinding;
use App\Modules\Customer\Models\StaffSupervisor;
use App\Modules\MasterData\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ বাঁধন বসানো, শেষ করা, হাতবদল আর উপরওয়ালা — ⛔১৬, ২ অক্টোবর ২০২৬।
 *
 * ⓘ মালিকের উত্তর (২৬ সেপ্টেম্বর ২০২৬): এক ডিলারে কয়েকজন; এলাকা বাছলে **সেই মুহূর্তের**
 * ডিলারগুলোই বাঁধা হয়, পরে আসা ডিলার আপনা থেকে নয়; হাতবদলে নতুনজন পুরনো বিলসহ দেখেন।
 *
 * ⛔ সুইচ ডিফল্ট চালু — বাঁধন না থাকলে বিক্রয়কর্মী শূন্য দেখেন। তাই এই পর্দা আর
 * [[preview()]]-এর আগাম দেখা চালুর **আগে** যায়।
 */
final class DealerBindingService
{
    public function __construct(private readonly DealerScope $scope) {}

    /**
     * কয়েকজন ডিলার একজন কর্মীর নামে — একই ডিলারে তাঁর চালু বাঁধন থাকলে আবার নয়।
     *
     * @param  list<int>  $customerIds
     * @return int কয়টা নতুন বাঁধন বসল
     */
    public function bind(int $userId, array $customerIds, string $startsOn): int
    {
        $this->assertStaff($userId);
        $start = $this->date($startsOn, 'starts_on');

        $ids = array_values(array_unique(array_filter(array_map('intval', $customerIds))));

        if ($ids === []) {
            throw ValidationException::withMessages(['customer_ids' => __('customer::binding.need_dealers')]);
        }

        // ⓘ দেয়াল ছাড়া, কিন্তু কোম্পানির ভিতরে — অন্য কোম্পানির আইডি এখানে বাদ পড়ে
        $found = Customer::acrossDealers()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (count($found) !== count($ids)) {
            throw ValidationException::withMessages(['customer_ids' => __('customer::binding.unknown_dealer')]);
        }

        return DB::transaction(function () use ($userId, $found, $start): int {
            $already = DealerBinding::query()
                ->where('user_id', $userId)
                ->whereIn('customer_id', $found)
                ->open()
                ->pluck('customer_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $made = 0;

            foreach (array_diff($found, $already) as $customerId) {
                DealerBinding::create([
                    'company_id' => CompanyContext::id(),
                    'user_id' => $userId,
                    'customer_id' => $customerId,
                    'starts_on' => $start,
                    'created_by' => auth()->id(),
                ]);
                $made++;
            }

            $this->scope->forget();

            return $made;
        });
    }

    /**
     * ⭐ এলাকা বাছলে তার নিচের **আজকের** ডিলারগুলো — এক এক করে বাঁধা।
     *
     * ⛔ মালিক: *"যা নিজে বাঁধা হবে কেবল তাই"* — কাল ঐ এলাকায় নতুন ডিলার বসলে তিনি
     * আপনা থেকে আসেন না; তাঁকে আলাদা করে বাঁধতে হয়।
     */
    public function bindArea(int $userId, int $locationId, string $startsOn): int
    {
        $location = Location::query()->find($locationId);

        if ($location === null) {
            throw ValidationException::withMessages(['location_id' => __('customer::binding.unknown_area')]);
        }

        $ids = Customer::acrossDealers()
            ->whereIn('location_id', $location->selfAndDescendants()->pluck('id'))
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids === []) {
            throw ValidationException::withMessages(['location_id' => __('customer::binding.area_empty')]);
        }

        return $this->bind($userId, $ids, $startsOn);
    }

    /** একটা বাঁধন শেষ — শেষ দিনটাও তিনি দেখেন। */
    public function end(DealerBinding $binding, string $endsOn): void
    {
        $end = $this->date($endsOn, 'ends_on');

        if ($end->lt($binding->starts_on)) {
            throw ValidationException::withMessages(['ends_on' => __('customer::binding.end_before_start')]);
        }

        $binding->forceFill(['ends_on' => $end, 'ended_by' => auth()->id()])->save();
        $this->scope->forget();
    }

    /**
     * ⭐ হাতবদল — এক কর্মীর চালু ডিলারগুলো (বা বাছা কয়েকটা) আরেকজনের কাছে, একটা তারিখে।
     *
     * ⓘ পুরনো জনের বাঁধন শেষ হয় আগের দিনে, নতুন জনের শুরু ঐ দিনে — কোনো দিন দুইজনের
     * ফাঁকে পড়ে না। নতুনজন ডিলারের সব কাগজ দেখেন, পুরনো বিলসহ (মালিকের উত্তর ৫)।
     * ⚠️ কার বিক্রি সেটা বদলায় না: আদেশে লেখক (`created_by`) যিনি ছিলেন তিনিই থাকেন।
     *
     * @param  list<int>|null  $customerIds  null = তাঁর সব চালু ডিলার
     * @return int কয়টা ডিলার হাত বদলাল
     */
    public function handover(int $fromUserId, int $toUserId, ?array $customerIds, string $onDate): int
    {
        $this->assertStaff($fromUserId);
        $this->assertStaff($toUserId);

        if ($fromUserId === $toUserId) {
            throw ValidationException::withMessages(['to_user_id' => __('customer::binding.same_person')]);
        }

        $on = $this->date($onDate, 'on_date');

        return DB::transaction(function () use ($fromUserId, $toUserId, $customerIds, $on): int {
            $rows = DealerBinding::query()
                ->where('user_id', $fromUserId)
                ->open()
                ->when($customerIds !== null, fn (Builder $q) => $q->whereIn('customer_id', array_map('intval', $customerIds)))
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['from_user_id' => __('customer::binding.nothing_to_hand_over')]);
            }

            foreach ($rows as $row) {
                /*
                 * ⓘ শেষ দিন = হাতবদলের আগের দিন — সবসময়। বাঁধনটা ঐ দিনেই বা পরে শুরু হলে শেষ দিন শুরুর
                 * আগে পড়ে, অর্থাৎ বাঁধনটা কোনোদিন চালুই হয়নি (মোছা নয় — ইতিহাসে থাকে)। ⛔ শুরুর দিনে
                 * আটকালে পুরনো জন হাতবদলের দিনটাও দেখতেন।
                 */
                $row->forceFill([
                    'ends_on' => $on->copy()->subDay(),
                    'ended_by' => auth()->id(),
                ])->save();
            }

            $dealers = $rows->pluck('customer_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
            $this->bind($toUserId, $dealers, $on->toDateString());
            $this->scope->forget();

            return count($dealers);
        });
    }

    /**
     * উপরওয়ালা বসানো বা তোলা।
     *
     * ⛔ নিজে নিজের, বা নিজের নিচের কেউ উপরওয়ালা নয় — চক্র হলে "নিচের গাছ" কথাটার
     * মানে থাকে না।
     */
    public function setSupervisor(int $userId, ?int $supervisorId): void
    {
        $this->assertStaff($userId);

        if ($supervisorId === null) {
            StaffSupervisor::query()->where('user_id', $userId)->delete();
            $this->scope->forget();

            return;
        }

        $this->assertStaff($supervisorId);

        $under = $this->scope->reachOf(User::query()->findOrFail($userId));

        if (in_array($supervisorId, $under, true)) {
            throw ValidationException::withMessages(['supervisor_id' => __('customer::binding.supervisor_loop')]);
        }

        StaffSupervisor::query()->updateOrCreate(
            ['company_id' => CompanyContext::id(), 'user_id' => $userId],
            ['supervisor_id' => $supervisorId, 'created_by' => auth()->id()],
        );

        $this->scope->forget();
    }

    /**
     * ⭐ আগাম দেখা — চালুর পর প্রত্যেকে কয়জন ডিলার দেখবেন; শূন্য-দেখা দেয়ালের মানুষ লাল।
     *
     * ⛔ এটা না থাকলে চালুর সকালে বিক্রয়কর্মী অন্ধ হতেন, আর কেউ বুঝত না কেন।
     *
     * @return Collection<int, array{user: User, walled: bool, dealers: int|null, supervisor: ?User}>
     */
    public function preview(): Collection
    {
        $supervisors = StaffSupervisor::query()->with('supervisor')->get()->keyBy('user_id');

        return $this->staff()->map(function (User $user) use ($supervisors): array {
            $walled = $this->scope->walled($user);

            return [
                'user' => $user,
                'walled' => $walled,
                // ⓘ দেয়ালের বাইরের মানুষ সব দেখেন — গোনার কিছু নেই
                'dealers' => $walled ? DB::query()->fromSub($this->scope->dealerIds($user), 'd')->distinct()->count('d.customer_id') : null,
                'supervisor' => $supervisors->get($user->id)?->supervisor,
            ];
        });
    }

    /**
     * এই কোম্পানির সক্রিয় কর্মী।
     *
     * @return Collection<int, User>
     */
    public function staff(): Collection
    {
        return User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    private function assertStaff(int $userId): void
    {
        $ours = User::query()
            ->whereKey($userId)
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->exists();

        if (! $ours) {
            throw ValidationException::withMessages(['user_id' => __('customer::binding.unknown_staff')]);
        }
    }

    private function date(string $value, string $field): Carbon
    {
        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => __('customer::binding.bad_date')]);
        }
    }
}
