<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Core\Services\DataScope;
use App\Core\Services\DealerScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\AreaBinding;
use App\Modules\Customer\Models\DealerBinding;
use App\Modules\Customer\Models\StaffPosition;
use App\Modules\Customer\Models\StaffSupervisor;
use App\Modules\MasterData\Models\Location;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ ফিল্ড ফোর্সের গাছ — পদ, উপরওয়ালা, ডিপো, চলমান এলাকা, আর চালুর আগের তালিকা (মালিক, ১২ নভেম্বর ২০২৬:
 *   *"কাকে কোন এলাকা দিতে হবে, কার নিচে কীভাবে ট্রি হবে … পরে আমি এগুলা ট্রি অনুযায়ী বসায় নেব"*)।
 *
 * ⓘ "ফিল্ড ফোর্স" = যিনি দেয়ালে পড়বেন (নিজের-ডিলার চাবি আছে, সব-ডিলার নেই) অথবা যাঁর পদ বসানো।
 * ⛔ ডিপো বসানো এখানে কেবল এই মানুষদের — অফিসের কারও শাখা এই পাতা থেকে বদলায় না।
 */
final class FieldForceService
{
    public function __construct(
        private readonly DealerScope $scope,
        private readonly DealerBindingService $bindings,
    ) {}

    /**
     * গাছের প্রত্যেকে — পদ, উপরওয়ালা, ডিপো, এলাকা, আলাদা বাঁধা ডিলার, আর দেয়াল চালু হলে কয়জন ডিলার দেখবেন।
     *
     * @return Collection<int, array{user: User, position: ?string, supervisor: ?int, depots: list<int>, areas: Collection<int, AreaBinding>, extra: int, dealers: int}>
     */
    public function people(): Collection
    {
        $staff = $this->bindings->staff();
        $positions = StaffPosition::query()->pluck('position', 'user_id')->all();
        $links = StaffSupervisor::query()->pluck('supervisor_id', 'user_id')->map(fn ($id) => (int) $id)->all();
        $areas = AreaBinding::query()->open()->with('location')->orderBy('starts_on')->get()->groupBy('user_id');
        $extra = DealerBinding::query()->open()->selectRaw('user_id, count(*) n')->groupBy('user_id')->pluck('n', 'user_id')->all();
        $depots = UserDataScope::query()->where('scope_type', UserDataScope::BRANCH)->get(['user_id', 'scope_id'])
            ->groupBy('user_id')->map(fn ($rows) => $rows->pluck('scope_id')->map(fn ($id) => (int) $id)->sort()->values()->all());
        $bosses = array_flip(array_values($links));

        return $staff
            ->filter(fn (User $u) => $this->scope->wouldBeWalled($u) || isset($positions[$u->id]) || isset($bosses[$u->id]))
            ->mapWithKeys(fn (User $u) => [$u->id => [
                'user' => $u,
                'position' => $positions[$u->id] ?? null,
                'supervisor' => $links[$u->id] ?? null,
                'depots' => $depots->get($u->id, []),
                'areas' => $areas->get($u->id, collect()),
                'extra' => (int) ($extra[$u->id] ?? 0),
                'dealers' => DB::query()->fromSub($this->scope->dealerIds($u), 'd')->distinct()->count('d.customer_id'),
                'walled' => $this->scope->wouldBeWalled($u),
            ]]);
    }

    /**
     * ⭐ চালুর আগের তালিকা — সব সবুজ না হলে দেয়াল চালু হয় না ([[turnWallOn()]])।
     *
     * @param  Collection<int, array<string, mixed>>  $people
     * @return array{positioned: int, no_depot: list<int>, no_boss: list<int>, blind: list<int>, loose_dealers: int}
     */
    public function checklist(Collection $people): array
    {
        $walled = $people->filter(fn (array $p) => $p['walled']);

        return [
            'positioned' => $people->filter(fn (array $p) => $p['position'] !== null)->count(),
            // ⛔ দেয়ালের ভিতরে ডিপো না থাকলে কিছুই দেখেন না ([[DataScope::idsFor()]])
            'no_depot' => $walled->filter(fn (array $p) => $p['depots'] === [])->keys()->all(),
            // ⓘ DSM-এর উপরে কেউ থাকে না — বাকিদের উপরওয়ালা লাগে
            'no_boss' => $people->filter(fn (array $p) => $p['supervisor'] === null && $p['position'] !== 'DSM' && $p['position'] !== null)->keys()->all(),
            'blind' => $walled->filter(fn (array $p) => $p['dealers'] === 0)->keys()->all(),
            'loose_dealers' => $this->looseDealers(),
        ];
    }

    /** সক্রিয় ডিলার যিনি কারও নামে নন — না বাঁধা, না কারও এলাকায়। */
    public function looseDealers(): int
    {
        $today = Carbon::today()->toDateString();
        $companyId = CompanyContext::id();

        $bound = DB::table('dealer_bindings')->select('customer_id')->where('company_id', $companyId)
            ->where('starts_on', '<=', $today)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today));

        $roots = DB::table('area_bindings')->where('company_id', $companyId)
            ->where('starts_on', '<=', $today)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->pluck('location_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        $areas = $this->expand($roots);

        return DB::table('customers')->where('company_id', $companyId)->where('is_active', true)->whereNull('deleted_at')
            ->whereNotIn('id', $bound)
            ->when($areas !== [], fn ($q) => $q->where(fn ($w) => $w->whereNull('location_id')->orWhereNotIn('location_id', $areas)))
            ->count();
    }

    /** নিজের — নিজের এলাকা আর নিজে বাঁধা ডিলার, নিচের লোকেরটা বাদে ("তিনি যা দেখবেন"-এর প্রথম সারি)। */
    public function ownDealers(User $user): int
    {
        $today = Carbon::today()->toDateString();
        $companyId = CompanyContext::id();

        $bound = DB::table('dealer_bindings')->select('customer_id')->where('company_id', $companyId)->where('user_id', $user->id)
            ->where('starts_on', '<=', $today)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today));

        $areas = $this->expand(AreaBinding::query()->current()->where('user_id', $user->id)
            ->pluck('location_id')->map(fn ($id) => (int) $id)->unique()->values()->all());

        return DB::table('customers')->where('company_id', $companyId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereIn('id', $bound)->when($areas !== [], fn ($w) => $w->orWhereIn('location_id', $areas)))
            ->count();
    }

    /**
     * এলাকা আর তার নিচের সব এলাকা।
     *
     * @param  list<int>  $roots
     * @return list<int>
     */
    private function expand(array $roots): array
    {
        if ($roots === []) {
            return [];
        }

        return Location::query()->whereIn('id', $roots)->get()
            ->flatMap(fn (Location $l) => $l->selfAndDescendants()->pluck('id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * ⭐ একজনের সব একসাথে — পদ, উপরওয়ালা, ডিপো, এলাকা।
     *
     * @param  array{position: ?string, supervisor_id: ?int, depots: list<int>, areas: list<int>, starts_on: string}  $data
     */
    public function save(int $userId, array $data): void
    {
        $position = $data['position'] ?? null;

        // ⓘ গাছের মানুষ, অথবা এই কোম্পানির কর্মী যাঁকে এখন পদ দিয়ে গাছে আনা হচ্ছে ("নতুন মানুষ যোগ")
        $inTree = $this->people()->has($userId);
        $newcomer = $position !== null && $this->bindings->staff()->contains('id', $userId);

        if (! $inTree && ! $newcomer) {
            throw ValidationException::withMessages(['user_id' => __('customer::fieldforce.not_field')]);
        }

        if ($position !== null && ! in_array($position, StaffPosition::POSITIONS, true)) {
            throw ValidationException::withMessages(['position' => __('customer::fieldforce.bad_position')]);
        }

        $startsOn = Carbon::parse($data['starts_on'])->startOfDay();
        $depots = array_values(array_unique(array_map('intval', $data['depots'] ?? [])));
        $areas = array_values(array_unique(array_map('intval', $data['areas'] ?? [])));

        // ⛔ অন্য কোম্পানির ডিপো বা এলাকা নয় — BelongsToCompany-র গণ্ডিতে খোঁজা
        if (Branch::query()->whereIn('id', $depots)->count() !== count($depots)) {
            throw ValidationException::withMessages(['depots' => __('customer::fieldforce.bad_depot')]);
        }

        if (Location::query()->whereIn('id', $areas)->count() !== count($areas)) {
            throw ValidationException::withMessages(['areas' => __('customer::binding.unknown_area')]);
        }

        DB::transaction(function () use ($userId, $data, $position, $depots, $areas, $startsOn): void {
            $this->bindings->setSupervisor($userId, empty($data['supervisor_id']) ? null : (int) $data['supervisor_id']);

            if ($position === null) {
                StaffPosition::query()->where('user_id', $userId)->delete();
            } else {
                StaffPosition::query()->updateOrCreate(
                    ['company_id' => CompanyContext::id(), 'user_id' => $userId],
                    ['position' => $position, 'created_by' => auth()->id()],
                );
            }

            $this->setDepots($userId, $depots);
            $this->setAreas($userId, $areas, $startsOn);
        });

        $this->scope->forget();
        app(DataScope::class)->forget();
    }

    /** ⭐ দেয়াল চালু — তালিকার সব সবুজ হলে; বন্ধ করা সবসময় যায়। */
    public function setWall(bool $on): void
    {
        if ($on) {
            $list = $this->checklist($this->people());

            if ($list['no_depot'] !== [] || $list['blind'] !== []) {
                throw ValidationException::withMessages(['wall' => __('customer::fieldforce.wall_not_ready')]);
            }
        }

        app(SettingsService::class)->set(DealerScope::SWITCH, $on);
    }

    /** @param list<int> $depots */
    private function setDepots(int $userId, array $depots): void
    {
        $have = UserDataScope::query()->where('user_id', $userId)->where('scope_type', UserDataScope::BRANCH)->get();

        foreach ($have as $row) {
            if (! in_array((int) $row->scope_id, $depots, true)) {
                $row->delete();
            }
        }

        $kept = $have->pluck('scope_id')->map(fn ($id) => (int) $id)->all();

        foreach (array_diff($depots, $kept) as $branchId) {
            UserDataScope::query()->create([
                'company_id' => CompanyContext::id(), 'user_id' => $userId,
                'scope_type' => UserDataScope::BRANCH, 'scope_id' => $branchId,
            ]);
        }
    }

    /**
     * ⓘ নতুন এলাকা শুরুর দিন থেকে; বাদ দেওয়া এলাকা তার আগের দিনে শেষ — মোছা নয় ("মার্চে কার ছিল")।
     *
     * @param  list<int>  $areas
     */
    private function setAreas(int $userId, array $areas, Carbon $startsOn): void
    {
        $open = AreaBinding::query()->open()->where('user_id', $userId)->get();

        foreach ($open as $row) {
            if (in_array((int) $row->location_id, $areas, true)) {
                continue;
            }

            // ⓘ যে সারি এই দিনের আগে চালুই হয়নি, তাতে কেউ কিছু দেখেননি — মোছা; বাকিগুলো আগের দিনে শেষ
            if ($row->starts_on->gte($startsOn)) {
                $row->delete();
            } else {
                $row->update(['ends_on' => $startsOn->copy()->subDay()->toDateString(), 'ended_by' => auth()->id()]);
            }
        }

        $kept = $open->pluck('location_id')->map(fn ($id) => (int) $id)->all();

        foreach (array_diff($areas, $kept) as $locationId) {
            AreaBinding::query()->create([
                'company_id' => CompanyContext::id(), 'user_id' => $userId, 'location_id' => $locationId,
                'starts_on' => $startsOn->toDateString(), 'created_by' => auth()->id(),
            ]);
        }
    }
}
