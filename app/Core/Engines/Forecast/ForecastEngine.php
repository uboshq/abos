<?php

declare(strict_types=1);

namespace App\Core\Engines\Forecast;

use App\Core\Module\ModuleRegistry;
use App\Models\User;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * ⭐ পূর্বাভাসের ইঞ্জিন — "এক সংখ্যা, এক জায়গা" (পূর্ণ পরিকল্পনা অংশ ১০, ১১ নভেম্বর ২০২৬)।
 *
 * ⓘ AI নয়, অঙ্ক: শেষ [[HISTORY_DAYS]] দিনের ইতিহাস → সাম্প্রতিক গড় ([[BASE_DAYS]] দিন) × সপ্তাহের দিনের ধরন (শুক্রবার কম, শনিবার বেশি …)।
 *   প্রতিটা উত্তরের সাথে ভরসার সীমা (নিচে–উপরে), ভরসার মাত্রা, ভিত্তি (কত দিনের ইতিহাস, কোন নিয়ম), আর আগের সপ্তাহের পূর্বাভাস কতটা
 *   মিলেছিল (ভুলের হার — একই নিয়মে পেছনে গিয়ে মেলানো)। প্রতিবার একই উত্তর, টেস্ট করা যায়, কোপাইলট বন্ধ থাকলেও চলে।
 * ⓘ ইতিহাস দেয় মডিউল ([[ForecastSource]], `module.php` → `forecasts`); কোর মডিউলের নাম জানে না।
 * ⓘ উৎসব (ঈদ, পূজা) এখনো ধরা হয় না — উত্তরের ভিত্তিতে লেখা থাকে; কুইক কমার্সের সময় একই ইঞ্জিনে যোগ হবে।
 */
final class ForecastEngine
{
    /** কত দিনের ইতিহাস পড়া হয় — ১২ সপ্তাহ */
    public const HISTORY_DAYS = 84;

    /** সাম্প্রতিক গড় কত দিনের — ৪ সপ্তাহ */
    public const BASE_DAYS = 28;

    /** ভরসার সীমা — প্রায় ৮০% (১.২৮ মানক বিচ্যুতি) */
    public const BAND_Z = 1.28;

    public function __construct(private readonly ModuleRegistry $modules) {}

    /** @return array<string, class-string<ForecastSource>> নাম → উৎস */
    public function sources(): array
    {
        $out = [];

        foreach ($this->modules->all() as $module) {
            foreach ($module->forecasts as $class) {
                $out[$class::key()] = $class;
            }
        }

        return $out;
    }

    public function has(string $key): bool
    {
        return isset($this->sources()[$key]);
    }

    /**
     * ⭐ আগামী কয়েক দিনের পূর্বাভাস।
     *
     * @param  array<string, mixed>  $args
     * @return array{key: string, days: int, total: string, low: string, high: string, daily: list<array{date: string, value: string}>, rate: string,
     *               confidence: string, accuracy: ?int, basis: array{history_days: int, base_days: int, weekday_pattern: bool, festivals: bool}}
     */
    public function forecast(string $key, ?User $user, int $days = 7, array $args = []): array
    {
        $source = $this->source($key, $user);
        $today = Carbon::today();
        $history = $this->series($source, $today->copy()->subDays(self::HISTORY_DAYS), $today->copy()->subDay(), $args);
        $model = self::fit($history);

        $daily = [];
        $total = 0.0;
        for ($i = 0; $i < $days; $i++) {
            $day = $today->copy()->addDays($i);
            $value = $model['base'] * $model['weekday'][$day->dayOfWeek];
            $daily[] = ['date' => $day->toDateString(), 'value' => self::s($value)];
            $total += $value;
        }

        $spread = self::BAND_Z * $model['sd'] * sqrt(max(1, $days));

        return [
            'key' => $key,
            'days' => $days,
            'total' => self::s($total),
            'low' => self::s(max(0.0, $total - $spread)),
            'high' => self::s($total + $spread),
            'daily' => $daily,
            'rate' => self::s($model['base']),
            'confidence' => self::confidence($model),
            'accuracy' => $this->accuracy($history),
            'basis' => ['history_days' => count($history), 'base_days' => self::BASE_DAYS, 'weekday_pattern' => true, 'festivals' => false],
        ];
    }

    /**
     * ⭐ হাতের মাল কত দিন চলবে — হাতে ÷ পূর্বাভাসের দিনে গড়; চাহিদা না থাকলে null।
     *
     * @param  array<string, mixed>  $args
     */
    public function daysOfCover(string $onHand, string $key, ?User $user, array $args = []): ?int
    {
        $rate = (float) $this->forecast($key, $user, 7, $args)['rate'];

        return $rate <= 0 ? null : (int) floor(max(0.0, (float) $onHand) / $rate);
    }

    /** @param  array<string, mixed>  $args */
    private function series(ForecastSource $source, Carbon $from, Carbon $to, array $args): array
    {
        $raw = $source->history($from->toDateString(), $to->toDateString(), $args);
        $out = [];

        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $out[$d->toDateString()] = (float) ($raw[$d->toDateString()] ?? 0);
        }

        return $out;
    }

    /**
     * গড় আর সপ্তাহের দিনের ধরন।
     *
     * @param  array<string, float>  $history
     * @return array{base: float, weekday: array<int, float>, sd: float, mean: float, n: int}
     */
    public static function fit(array $history): array
    {
        $values = array_values($history);
        $n = count($values);
        $recent = array_slice($values, -self::BASE_DAYS);
        $base = $recent === [] ? 0.0 : array_sum($recent) / count($recent);
        $mean = $n === 0 ? 0.0 : array_sum($values) / $n;

        // ⓘ সপ্তাহের দিনের ধরন — সেই দিনের গড় ÷ সব দিনের গড়; ইতিহাস কম হলে সমান (১)
        $byDay = array_fill(0, 7, []);
        foreach ($history as $date => $v) {
            $byDay[Carbon::parse($date)->dayOfWeek][] = $v;
        }
        $weekday = [];
        foreach ($byDay as $dow => $vs) {
            $weekday[$dow] = ($mean > 0 && count($vs) >= 3) ? (array_sum($vs) / count($vs)) / $mean : 1.0;
        }

        // ⓘ ছড়ানো — সাম্প্রতিক দিনগুলো ধরনসহ গড় থেকে কত দূরে
        $res = [];
        foreach (array_slice($history, -self::BASE_DAYS, null, true) as $date => $v) {
            $res[] = $v - $base * $weekday[Carbon::parse($date)->dayOfWeek];
        }
        $sd = count($res) < 2 ? $base : sqrt(array_sum(array_map(fn (float $r): float => $r * $r, $res)) / (count($res) - 1));

        return ['base' => $base, 'weekday' => $weekday, 'sd' => $sd, 'mean' => $mean, 'n' => $n];
    }

    /**
     * ⓘ আগের সপ্তাহের পূর্বাভাস কতটা মিলেছিল — শেষ ৭ দিন বাদ দিয়ে একই নিয়মে হিসাব, তারপর আসলের সাথে; ১০০ − ভুলের হার (%)।
     *
     * @param  array<string, float>  $history
     */
    private function accuracy(array $history): ?int
    {
        if (count($history) < self::BASE_DAYS + 7) {
            return null;
        }

        $before = array_slice($history, 0, -7, true);
        $after = array_slice($history, -7, null, true);
        $model = self::fit($before);
        $predicted = 0.0;
        foreach (array_keys($after) as $date) {
            $predicted += $model['base'] * $model['weekday'][Carbon::parse($date)->dayOfWeek];
        }
        $actual = array_sum($after);

        if ($actual <= 0) {
            return null;
        }

        return max(0, (int) round(100 - abs($predicted - $actual) / $actual * 100));
    }

    /** ⓘ ভরসা — ছড়ানো গড়ের তুলনায় কম হলে বেশি; ইতিহাস ছোট হলে কম */
    private static function confidence(array $model): string
    {
        if ($model['n'] < 28 || $model['base'] <= 0) {
            return 'low';
        }

        $cv = $model['sd'] / $model['base'];

        return $cv < 0.35 ? 'high' : ($cv < 0.8 ? 'medium' : 'low');
    }

    private function source(string $key, ?User $user): ForecastSource
    {
        $class = $this->sources()[$key] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("No forecast source '{$key}'.");
        }

        abort_unless($user === null || $user->can($class::permission()), 403);

        return app($class);
    }

    private static function s(float $v): string
    {
        return number_format($v, 4, '.', '');
    }
}
