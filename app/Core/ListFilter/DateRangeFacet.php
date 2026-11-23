<?php

declare(strict_types=1);

namespace App\Core\ListFilter;

use App\Core\Support\DateFormat;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ⭐ তারিখের পরিসর — "থেকে / পর্যন্ত" (`?created_from=…&created_to=…`); যেকোনো একটা বা দুটোই।
 *
 * ⓘ চাবির নাম তালিকার নিজের (পুরোনো লিংক অটুট থাকে): `from` আর `to` চাবি দুটো ঘোষণায় আলাদা করে দেওয়া যায়।
 * ⛔ পড়া যায় না এমন তারিখ চুপচাপ বাদ — ৫০০ নয়।
 */
final class DateRangeFacet extends Facet
{
    /**
     * @param  Closure(Builder, ?string, ?string): mixed  $applyRange  থেকে, পর্যন্ত — Y-m-d
     */
    public function __construct(
        string $key,
        string $label,
        private readonly Closure $applyRange,
        private readonly string $fromKey = '',
        private readonly string $toKey = '',
    ) {
        parent::__construct($key, $label);
    }

    /**
     * ⭐ চটজলদি তারিখ — পরিকল্পনা §৩.৩ (মালিক, ১৪ নভেম্বর ২০২৬): *"আজ, গতকাল, গত ৭ দিন, এই মাস, গত মাস, গত ৯০ দিন, যেকোনো তারিখ"*।
     * ⓘ "যেকোনো তারিখ" মানে দুই চাবিই মোছা — নিচের তালিকায় নেই, [[FilterSet::presets()]] শেষে যোগ করে।
     */
    public const PRESETS = ['today', 'yesterday', 'last7', 'this_month', 'last_month', 'last90'];

    /** ⭐ লেনদেনের তালিকা — বারে "📅 তারিখ" বোতাম (পরিকল্পনা §৩.১: *"📅 তারিখ (লেনদেনের তালিকায়)"*); গ্রাহকের "যোগের তারিখ"-এ নয় */
    public bool $onBar = false;

    public function onTheBar(): static
    {
        $this->onBar = true;

        return $this;
    }

    /**
     * ⓘ পাতার নিজের বাড়তি চটজলদি পরিসর — যেমন রিপোর্টের "শুরু থেকে" (`from=all`); চাবি => মান, যা ঠিকানায় বসে।
     *
     * @var list<array{key: string, label: string, set: array<string, string>, on: bool}>
     */
    public array $extra = [];

    /** @param  array<string, string>  $set */
    public function withPreset(string $key, string $label, array $set, bool $on): static
    {
        $this->extra[] = ['key' => $key, 'label' => $label, 'set' => $set, 'on' => $on];

        return $this;
    }

    /**
     * চটজলদি নামের পরিসর — Y-m-d, আজ সহ।
     *
     * ⛔ "গত মাস" `subMonthNoOverflow` — ৩১ তারিখে `subMonth()` আগের মাসে না গিয়ে এই মাসেই থেকে যায়
     *   ([[last-month-on-the-31st-is-this-month]], ৩১ অক্টোবর ২০২৬-এ দুইটা লাল)।
     *
     * @return array{from: string, to: string}
     */
    public static function presetRange(string $preset, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        [$from, $to] = match ($preset) {
            'today' => [$today, $today],
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'last7' => [$today->copy()->subDays(6), $today],
            'this_month' => [$today->copy()->startOfMonth(), $today],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'last90' => [$today->copy()->subDays(89), $today],
            default => throw new \InvalidArgumentException($preset),
        };

        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    /**
     * এখনকার পরিসর কোন চটজলদি নামের সাথে হুবহু মেলে — না মিললে null (তখন বোতামে তারিখ দুটোই লেখা)।
     *
     * @param  array{from: ?string, to: ?string}|null  $value
     */
    public static function presetOf(?array $value, ?Carbon $today = null): ?string
    {
        if ($value === null) {
            return null;
        }

        foreach (self::PRESETS as $preset) {
            if (self::presetRange($preset, $today) === $value) {
                return $preset;
            }
        }

        return null;
    }

    public function kind(): string
    {
        return 'date';
    }

    public function fromKey(): string
    {
        return $this->fromKey !== '' ? $this->fromKey : $this->key.'_from';
    }

    public function toKey(): string
    {
        return $this->toKey !== '' ? $this->toKey : $this->key.'_to';
    }

    public function keys(): array
    {
        return [$this->fromKey(), $this->toKey()];
    }

    /** @return array{from: ?string, to: ?string}|null */
    public function read(Request $request): mixed
    {
        $from = self::day($request->query($this->fromKey()));
        $to = self::day($request->query($this->toKey()));

        /*
         * ⓘ উল্টো পরিসর সোজা করে নেওয়া — কেউ "থেকে" ৩১ আর "পর্যন্ত" ১ দিলে তালিকা খালি দেখাত আর মানুষ ভাবত মাসটায়
         *   কিছু হয়নি। আগের তারিখের ঘরের ([[FiltersByDate::applyDateRange()]]) একই নিয়ম।
         */
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return $from === null && $to === null ? null : ['from' => $from, 'to' => $to];
    }

    /** @param  array{from: ?string, to: ?string}  $value */
    public function apply(Builder $query, mixed $value): void
    {
        ($this->applyRange)($query, $value['from'], $value['to']);
    }

    /** @param  array{from: ?string, to: ?string}  $value */
    public function chips(mixed $value): array
    {
        $chips = [];

        foreach (['from' => $this->fromKey(), 'to' => $this->toKey()] as $end => $key) {
            if ($value[$end] !== null) {
                $chips[] = [
                    'text' => $this->label.' '.__('core.list_filter.'.$end).' '.DateFormat::format($value[$end]),
                    'drop' => [$key => null],
                ];
            }
        }

        return $chips;
    }

    public static function day(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
