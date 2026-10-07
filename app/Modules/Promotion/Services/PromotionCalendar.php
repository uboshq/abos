<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Services;

use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Support\PromotionStatus;
use Illuminate\Support\Carbon;

/**
 * অফারের ক্যালেন্ডার — স্পেক §১৬।
 *
 * ── ⭐ কেন শ্রেণিভাগের নিয়মটা এখানে, ব্লেডে নয় ─────────────────────
 * ⓘ *"চলছে · আসছে · শেষ হতে চলেছে · শেষ"* — চারটা প্রশ্নের উত্তর একটাই
 * জায়গায়, [[PromotionCalendar::classify()]]। ⛔ ব্লেডে আবার লিখলে পাতা
 * আর পরীক্ষা দুইটা আলাদা নিয়ম মানত — পরীক্ষা সবুজ, পাতা ভুল রঙে।
 *
 * ── ⚠️ কেন কেবল দেখানো মাসের অফার আনা হয় ─────────────────────────
 * ⓘ ক্যালেন্ডারটা একটা মাস দেখায়, তাই কোয়েরিও একটা মাস চায়:
 * `starts_on <= মাসের শেষ` আর `ends_on >= মাসের শুরু`। ⛔ গোটা টেবিল
 * টেনে PHP-তে ছাঁকলে প্রথম বছরে দ্রুত লাগত, তিন বছরে পাতাটা ধীরে মরত।
 * ⭐ তবু একটা ছাদ ([[PromotionCalendar::LIMIT]]) — কাটা পড়লে পাতায় লেখা থাকে।
 */
final class PromotionCalendar
{
    /*
     * ⓘ অবস্থার নাম — পাতায়, কিংবদন্তিতে আর পরীক্ষায় একই শব্দ।
     */
    public const ACTIVE = 'active';

    public const EXPIRING = 'expiring';

    public const UPCOMING = 'upcoming';

    public const EXPIRED = 'expired';

    /** ⓘ অনুমোদিত বা থামানো, তারিখের ভিতরে অথচ বিলে খাটছে না — ধূসর। */
    public const IDLE = 'idle';

    /*
     * ⭐ *"শেষ হতে চলেছে"* মানে আজ থেকে সাত দিনের ভিতরে শেষ — আজ সহ।
     *
     * ⓘ এক সপ্তাহ — বিক্রয়কর্মীর একটা পুরো ঘোরার চক্র। ⚠️ আজ শেষ হওয়া
     * অফার এখনো **চলছে**, তাই "শেষ হতে চলেছে", "শেষ" নয় — শেষ মানে
     * `ends_on` আজকের **আগে**। ⛔ সীমানাটা উল্টো ধরলে শেষ দিনে অফারটা
     * লাল দেখাত, আর বিক্রয়কর্মী ওটা বলাই বন্ধ করতেন অথচ বিলে খাটত।
     */
    public const SOON_DAYS = 7;

    /*
     * ⚠️ সপ্তাহ শুরু শনিবারে — এখানকার অফিসের সপ্তাহ, শুক্রবার ছুটি।
     */
    public const WEEK_STARTS = Carbon::SATURDAY;

    /** ⓘ এক মাসে এর বেশি অফার আঁকা হয় না — কাটা পড়লে পাতায় লেখা থাকে। */
    public const LIMIT = 300;

    /*
     * ⭐ কোন অবস্থাগুলো ক্যালেন্ডারে আসে — আর কোনগুলো **ইচ্ছা করে** আসে না।
     *
     * ⛔ খসড়া ও জমা (`DRAFT`, `SUBMITTED`) আসে না: ⓘ ওগুলো এখনো কারও সই
     * পায়নি। ক্যালেন্ডারে দেখালে বিক্রয়কর্মী ক্রেতাকে এমন অফারের কথা
     * দিতেন যা হয়তো কোনোদিন অনুমোদিতই হবে না।
     *
     * ⛔ বাতিল (`CANCELLED`) আসে না: ⓘ ওটা কোনোদিন চলবে না, আর চলেছিল
     * কি না তার উত্তর অফারের তালিকায় — ক্যালেন্ডার পরিকল্পনার জিনিস।
     *
     * ⚠️ থামানো (`PAUSED`) **আসে**, ধূসর রঙে: ⓘ সে আবার চালু হতে পারে,
     * আর লুকালে ক্যালেন্ডারে একটা মিথ্যা ফাঁকা দিন দেখাত — কেউ ঐ দিনে
     * নতুন একটা অফার বসিয়ে দিতেন, আর চালু হলে দুইটা একসাথে চলত।
     */
    public const SHOWN = [
        PromotionStatus::APPROVED,
        PromotionStatus::ACTIVE,
        PromotionStatus::PAUSED,
        PromotionStatus::EXPIRED,
    ];

    /**
     * ⭐ একটা অফার আজ কোন রঙে — **একমাত্র** নিয়ম।
     *
     * ⓘ ক্রমটাই নিয়ম: আগে "শেষ", তারপর "আসছে", তারপর "চলছে"।
     *
     * ⚠️ দিনের হিসাব, ঘড়ির নয়: ⓘ ক্যালেন্ডার দিনের ঘর আঁকে। *"আজ সন্ধ্যা
     * ৬টায় শেষ"* অফার আজ সারাদিন "শেষ হতে চলেছে" দেখায়; ঘড়ির হিসাব
     * বিলের কাজ — [[Promotion::isLiveOn()]]।
     *
     * @return string|null অবস্থার নাম, বা `null` — ক্যালেন্ডারে আসে না
     */
    public function classify(Promotion $offer, ?Carbon $today = null): ?string
    {
        if (! in_array($offer->status, self::SHOWN, true)) {
            return null;
        }

        $day = ($today ?? Carbon::today())->toDateString();
        $starts = $offer->starts_on->toDateString();
        $ends = $offer->ends_on->toDateString();

        /*
         * ⛔ তারিখ পেরোলে "শেষ" — অবস্থা যা-ই বলুক।
         *
         * ⓘ [[Promotion::hasLapsed()]]-এর একই কথা: `expired` লেখাটা একটা
         * নির্ধারিত কাজের উপর দাঁড়ায়, আর সে কাজ একদিন চলতে ভুলে যায়।
         * ⚠️ তখন গত মাসের অফার এখানে সবুজ দেখাত।
         */
        if ($offer->status === PromotionStatus::EXPIRED || $ends < $day) {
            return self::EXPIRED;
        }

        /* ⓘ থামানো অফার — তারিখ যা-ই হোক, আজ বিলে খাটছে না */
        if ($offer->status === PromotionStatus::PAUSED) {
            return self::IDLE;
        }

        if ($starts > $day) {
            return self::UPCOMING;
        }

        /*
         * ⚠️ অনুমোদিত, তারিখের ভিতরে, অথচ কেউ চালু করেননি।
         *
         * ⛔ "চলছে" বললে মিথ্যা — বিলে খাটে না। ⓘ "আসছে"-ও নয়, কারণ
         * শুরুর দিন পেরিয়ে গেছে। ধূসর — যাতে কারও চোখে পড়ে।
         */
        if ($offer->status !== PromotionStatus::ACTIVE) {
            return self::IDLE;
        }

        $left = (int) Carbon::parse($day)->diffInDays(Carbon::parse($ends));

        return $left <= self::SOON_DAYS ? self::EXPIRING : self::ACTIVE;
    }

    /**
     * ⓘ অবস্থার রং — [[x-ui.badge]]-এর নিজের টোকেন থেকে, হেক্স নয়।
     *
     * ⚠️ অচেনা নাম পেলে badge চুপচাপ ধূসর হয় — তাই কেবল ওর চেনা পাঁচটা।
     */
    public function tone(string $state): string
    {
        return match ($state) {
            self::ACTIVE => 'success',
            self::EXPIRING => 'pending',
            self::UPCOMING => 'info',
            self::EXPIRED => 'danger',
            default => 'draft',
        };
    }

    /** ⓘ কিংবদন্তির ক্রম — চোখ যে ক্রমে প্রশ্ন করে। */
    public function states(): array
    {
        return [self::ACTIVE, self::EXPIRING, self::UPCOMING, self::EXPIRED, self::IDLE];
    }

    /**
     * ⓘ `?month=YYYY-MM` থেকে মাসের প্রথম দিন।
     *
     * ⚠️ ভুল লেখা এলে চলতি মাস — ৫০০ নয়। ⓘ ঠিকানাটা মানুষ হাতে বদলান,
     * আর *"2026-13"* লিখলে পাতা ভাঙা উচিত নয়।
     */
    public function monthOf(?string $asked, ?Carbon $today = null): Carbon
    {
        $today ??= Carbon::today();

        if (is_string($asked) && preg_match('/^(\d{4})-(\d{2})$/', $asked, $m) === 1) {
            $year = (int) $m[1];
            $month = (int) $m[2];

            if ($year >= 2000 && $year <= 2100 && $month >= 1 && $month <= 12) {
                return Carbon::create($year, $month, 1)->startOfDay();
            }
        }

        return $today->copy()->startOfMonth()->startOfDay();
    }

    /**
     * ⭐ একটা মাসের গোটা ছবি — পাতা যা আঁকে, সবটা এখান থেকে।
     *
     * @return array{
     *     month: Carbon, prev: string, next: string, today: string,
     *     weeks: list<array{days: list<array>, bars: list<array>, rows: int}>,
     *     offers: list<array>, truncated: bool
     * }
     */
    public function month(Carbon $month, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $first = $month->copy()->startOfMonth()->startOfDay();
        $last = $month->copy()->endOfMonth()->startOfDay();

        /*
         * ⚠️ তারিখের তুলনা সাধারণ `where`-এ — [[Promotion::scopeLiveOn()]]-এর
         * একই কারণে: `whereDate()` কলামকে `DATE()`-এ জড়ায়, সূচক বসে থাকে।
         */
        $found = Promotion::query()
            ->whereIn('status', array_map(fn (PromotionStatus $s) => $s->value, self::SHOWN))
            ->where('starts_on', '<=', $last->toDateString())
            ->where('ends_on', '>=', $first->toDateString())
            ->orderBy('starts_on')
            ->orderBy('id')
            ->limit(self::LIMIT + 1)
            ->get();

        $truncated = $found->count() > self::LIMIT;

        $offers = [];

        foreach ($found->take(self::LIMIT) as $offer) {
            $state = $this->classify($offer, $today);

            if ($state === null) {
                continue;
            }

            $offers[] = [
                'offer' => $offer,
                'code' => $offer->code,
                'name' => $offer->name(),
                'state' => $state,
                'tone' => $this->tone($state),
                'label' => __('promotion::calendar.state.'.$state),
                'url' => route('promotion.show', $offer),
                'starts' => $offer->starts_on->toDateString(),
                'ends' => $offer->ends_on->toDateString(),
                'period' => $offer->starts_on->format('d/m/y').' — '.$offer->ends_on->format('d/m/y'),
            ];
        }

        return [
            'month' => $first,
            'prev' => $first->copy()->subMonth()->format('Y-m'),
            'next' => $first->copy()->addMonth()->format('Y-m'),
            'today' => $today->toDateString(),
            'weeks' => $this->weeks($first, $last, $offers, $today->toDateString()),
            'offers' => $offers,
            'truncated' => $truncated,
        ];
    }

    /**
     * ⓘ সপ্তাহে ভাগ — প্রতিটা অফার প্রতিটা সপ্তাহে একটা দাগ, মাসের ভিতরে কাটা।
     *
     * ⭐ দাগগুলো সারিতে (lane) সাজানো হয় এখানেই, ব্লেডে নয় — ⚠️ ব্লেডে
     * হিসাব লিখলে `@php` লাগত, আর `@php`-র ভিতরের মন্তব্য একবার গোটা
     * পাতা ৫০০ করেছিল।
     */
    private function weeks(Carbon $first, Carbon $last, array $offers, string $today): array
    {
        $cursor = $first->copy()->startOfWeek(self::WEEK_STARTS)->startOfDay();
        $end = $last->copy()->endOfWeek($this->weekEnds())->startOfDay();
        $monthFrom = $first->toDateString();
        $monthTo = $last->toDateString();
        $weeks = [];

        while ($cursor->lte($end)) {
            $weekFrom = $cursor->toDateString();
            $weekTo = $cursor->copy()->addDays(6)->toDateString();
            $days = [];

            for ($i = 0; $i < 7; $i++) {
                $date = $cursor->copy()->addDays($i);
                $iso = $date->toDateString();

                $days[] = [
                    'date' => $iso,
                    'day' => $date->day,
                    'col' => $i + 1,
                    'in_month' => $iso >= $monthFrom && $iso <= $monthTo,
                    'is_today' => $iso === $today,
                ];
            }

            $bars = [];

            foreach ($offers as $row) {
                /* ⓘ অফার ∩ মাস ∩ সপ্তাহ — মাসের বাইরের দিনে দাগ নেই */
                $from = max($row['starts'], $monthFrom, $weekFrom);
                $to = min($row['ends'], $monthTo, $weekTo);

                if ($from > $to) {
                    continue;
                }

                $col = (int) $cursor->diffInDays(Carbon::parse($from)) + 1;
                $span = (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;

                $bars[] = $row + [
                    'col' => $col,
                    'span' => $span,
                    'cut_before' => $from > $row['starts'],
                    'cut_after' => $to < $row['ends'],
                ];
            }

            [$bars, $lanes] = $this->lanes($bars);

            $weeks[] = [
                'days' => $days,
                'bars' => $bars,

                /* ⓘ খালি সপ্তাহেও ঘরগুলোর উচ্চতা থাকে — দিনের সংখ্যা + দুই সারি */
                'rows' => max($lanes, 2) + 1,
            ];

            $cursor->addWeek();
        }

        return $weeks;
    }

    /**
     * ⓘ দাগগুলোকে সারিতে বসানো — কেউ কারও উপরে পড়ে না।
     *
     * @return array{0: list<array>, 1: int}
     */
    private function lanes(array $bars): array
    {
        usort($bars, fn ($a, $b) => [$a['col'], -$a['span']] <=> [$b['col'], -$b['span']]);

        /** @var list<int> $until প্রতিটা সারির শেষ দখল করা কলাম */
        $until = [];

        foreach ($bars as $i => $bar) {
            $lane = 0;

            while (isset($until[$lane]) && $until[$lane] >= $bar['col']) {
                $lane++;
            }

            $until[$lane] = $bar['col'] + $bar['span'] - 1;

            /* ⓘ প্রথম গ্রিড-সারিটা দিনের সংখ্যার — দাগ শুরু দ্বিতীয় থেকে */
            $bars[$i]['row'] = $lane + 2;
        }

        return [$bars, count($until)];
    }

    /**
     * ⓘ মাথার সারির বারগুলো — সপ্তাহ যেদিন শুরু, সেদিন থেকে।
     *
     * ⚠️ তালিকাটা এখান থেকে ঘোরানো, হাতে লেখা নয়: ⛔ হাতে লিখলে কেউ
     * [[PromotionCalendar::WEEK_STARTS]] বদলাতেন আর মাথায় "শনি" থাকত
     * অথচ নিচের ঘরগুলো রবিবার থেকে — প্রতিটা তারিখ এক ঘর সরে।
     *
     * @return list<string>
     */
    public function weekdayKeys(): array
    {
        $all = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

        return array_merge(array_slice($all, self::WEEK_STARTS), array_slice($all, 0, self::WEEK_STARTS));
    }

    /** ⓘ শনিবারে শুরু হলে শুক্রবারে শেষ — দুইটা আলাদা ধরে নেওয়া নয়। */
    private function weekEnds(): int
    {
        return (self::WEEK_STARTS + 6) % 7;
    }
}
