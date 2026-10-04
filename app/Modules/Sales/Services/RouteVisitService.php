<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Models\RouteTarget;
use App\Modules\Sales\Models\RouteVisit;
use App\Modules\Sales\Models\SalesTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * রুটের সাপ্তাহিক ছক আর মাসের লক্ষ্য বসানো।
 *
 * ── ⛔ ছক থেকে সারি কখনো মোছা হয় না ─────────────────────────────────
 * হাতবদলে ইতিহাস থাকে (মালিকের উত্তর ৫, ২৬ সেপ্টেম্বর)। তাই "সরানো"
 * মানে শেষের তারিখ বসানো — মার্চে কে এই রুটে যেতেন, সেই উত্তর থাকে।
 */
final class RouteVisitService
{
    /**
     * এক বা একাধিক বারে একজনকে রুটে বসানো।
     *
     * @param  array{user_id: int|string, weekdays: list<int|string>, effective_from: string, effective_to?: ?string, narration?: ?string}  $data
     * @return Collection<int, RouteVisit>
     */
    public function assign(Location $route, array $data): Collection
    {
        $this->assertIsRoute($route);

        $userId = (int) $data['user_id'];
        $this->assertWorksHere($userId);

        $from = Carbon::parse($data['effective_from'])->startOfDay();
        $to = filled($data['effective_to'] ?? null) ? Carbon::parse($data['effective_to'])->startOfDay() : null;

        if ($to !== null && $to->lt($from)) {
            throw ValidationException::withMessages([
                'effective_to' => __('sales::route.error_to_before_from'),
            ]);
        }

        $weekdays = $this->weekdays($data['weekdays'] ?? []);

        return DB::transaction(function () use ($route, $userId, $weekdays, $from, $to, $data) {
            $clash = RouteVisit::query()
                ->where('route_id', $route->id)
                ->where('user_id', $userId)
                ->whereIn('weekday', $weekdays)
                // দুই সময়ের কোথাও মিল: পুরনোটা নতুনের শেষের আগে শুরু, আর নতুনের শুরুর পরে শেষ
                ->when($to !== null, fn ($q) => $q->whereDate('effective_from', '<=', $to->toDateString()))
                ->where(fn ($q) => $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $from->toDateString()))
                ->lockForUpdate()
                ->exists();

            /*
             * ⛔ একই মানুষ একই বারে একই রুটে দুইবার নয়।
             *
             * দুইটা সারি থাকলে "শনিবার কে যান" তালিকায় নাম দুইবার আসত, আর
             * একটা শেষ করলেও অন্যটা চুপচাপ চালু থাকত — মানুষ ভাবতেন হাতবদল
             * হয়ে গেছে, অথচ ছকে তিনি তখনো আছেন।
             */
            if ($clash) {
                throw ValidationException::withMessages([
                    'weekdays' => __('sales::route.error_overlap'),
                ]);
            }

            $created = new Collection;

            foreach ($weekdays as $weekday) {
                $created->push(RouteVisit::query()->create([
                    'company_id' => CompanyContext::id(),
                    'route_id' => $route->id,
                    'user_id' => $userId,
                    'weekday' => $weekday,
                    'effective_from' => $from->toDateString(),
                    'effective_to' => $to?->toDateString(),
                    'narration' => $data['narration'] ?? null,
                    'created_by' => auth()->id(),
                ]));
            }

            return $created;
        });
    }

    /**
     * ছকের একটা ঘর শেষ করা — শেষ দিনটাসহ তিনি রুটে ছিলেন।
     */
    public function end(RouteVisit $visit, Carbon|string $lastDay): RouteVisit
    {
        $last = Carbon::parse($lastDay)->startOfDay();

        if ($last->lt($visit->effective_from)) {
            throw ValidationException::withMessages([
                'effective_to' => __('sales::route.error_to_before_from'),
            ]);
        }

        // আগে শেষ হওয়া ঘরকে পরের তারিখে টেনে আনা মানে ইতিহাস বদলানো
        if ($visit->effective_to !== null && $visit->effective_to->lt($last)) {
            throw ValidationException::withMessages([
                'effective_to' => __('sales::route.error_already_ended'),
            ]);
        }

        $visit->forceFill(['effective_to' => $last->toDateString()])->save();

        return $visit->refresh();
    }

    /**
     * রুটের মাসের লক্ষ্য — খালি বা শূন্য মানে লক্ষ্য নেই ([[SalesTargetService::setForMonth()]]-এর নিয়ম)।
     */
    public function setTarget(Location $route, Carbon|string $month, ?string $amount): ?RouteTarget
    {
        $this->assertIsRoute($route);

        $first = SalesTarget::monthOf($month);
        $amount = trim((string) $amount);

        // ⓘ কেবল সাধারণ দশমিক — `1e3` is_numeric পাশ করে, অথচ bcmath ওটা পড়তে পারে না
        if ($amount !== '' && preg_match('/^\d+(\.\d+)?$/', $amount) !== 1) {
            throw ValidationException::withMessages(['amount' => __('sales::route.error_amount')]);
        }

        if ($amount === '' || bccomp($amount, '0', 4) <= 0) {
            RouteTarget::query()
                ->where('route_id', $route->id)
                ->whereDate('month', $first)
                ->get()
                ->each(fn (RouteTarget $t) => $t->delete());

            return null;
        }

        return RouteTarget::query()->updateOrCreate(
            [
                'company_id' => CompanyContext::id(),
                'route_id' => $route->id,
                'month' => $first,
            ],
            [
                'amount' => bcadd($amount, '0', 4),
                'created_by' => auth()->id(),
            ],
        );
    }

    /**
     * ছকে কাকে বসানো যায় — এই কোম্পানির চালু মানুষ।
     *
     * ⚠️ `User`-এ কোম্পানির স্কোপ নেই ([[SalesTargetService::sellers()]]-এর
     * মন্তব্য) — ছাঁকনিটা হাতে। নাহলে অন্য কোম্পানির কর্মীকে এই রুটে বসানো যেত।
     *
     * @return Collection<int, User>
     */
    public function candidates(): Collection
    {
        return User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * ⛔ কেবল রুট স্তর, আর কেবল এই কোম্পানির।
     *
     * পয়েন্ট বা এরিয়াকে "রুট" ধরলে তার নিচের কোনো ডিলারের খাতা এই পাতায়
     * আসত না (রুটের খাতা ডিলারের নিজের জায়গা ধরে), আর ছকটা মিথ্যা বলত।
     */
    public function assertIsRoute(Location $route): void
    {
        if ((int) $route->company_id !== (int) CompanyContext::id()
            || ! $route->isRoute()
            || ! $route->is_active) {
            throw ValidationException::withMessages([
                'route_id' => __('sales::route.error_not_a_route'),
            ]);
        }
    }

    private function assertWorksHere(int $userId): void
    {
        $works = User::query()
            ->whereKey($userId)
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->where('is_active', true)
            ->exists();

        if (! $works) {
            throw ValidationException::withMessages([
                'user_id' => __('sales::route.error_user'),
            ]);
        }
    }

    /**
     * @param  list<int|string>  $raw
     * @return list<int>
     */
    private function weekdays(array $raw): array
    {
        $days = [];

        foreach ($raw as $day) {
            if (! is_numeric($day) || (int) $day < 0 || (int) $day > 6 || (string) (int) $day !== (string) $day) {
                throw ValidationException::withMessages([
                    'weekdays' => __('sales::route.error_weekday'),
                ]);
            }

            $days[] = (int) $day;
        }

        $days = array_values(array_unique($days));

        if ($days === []) {
            throw ValidationException::withMessages([
                'weekdays' => __('sales::route.error_weekday'),
            ]);
        }

        return $days;
    }
}
