<?php

declare(strict_types=1);

namespace App\Core\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Dynamics 365-এর প্রসেস ব্যান্ড — কাগজগুলো কোন ধাপে আটকে আছে।
 *
 * ── কেন এটা D365-এর স্বাক্ষর ─────────────────────────────────────────
 * ২৯ আগস্ট ২০২৬-এ মালিক বললেন ABOS-এর `dynamic` রূপটা D365-এর ঠিক নকল
 * হয়নি, আর Ava ও DMS দেখে মেলাতে বললেন।
 *
 * মিলিয়ে দেখা গেল রং আর কাঠামো বেশিরভাগই ঠিক: নেভি হেডার (`#0B2A4A`),
 * `#F5F5F5` সাইট ম্যাপ (Fluent-এর নিজের রং), নিচে এরিয়া-সুইচার, ওয়াফল।
 * **যেটা নেই সেটা শেভরন ব্যান্ড** — আর DMS-এর নিজের ঘোষণায় ওটাই
 * `dynamic`-এর signature বলে লেখা:
 *
 *   "A real chevron bar (clip-path, not borders) carrying a count and
 *    a total per stage"
 *
 * D365-এ ওই তীরগুলোই বলে দেয় কাজটা কোথায় দাঁড়িয়ে। ওটা ছাড়া রূপটা
 * কেবল একটা নেভি হেডার।
 *
 * ── কেন সংখ্যার সাথে টাকাও ───────────────────────────────────────────
 * "খসড়া ৩১" জানলে কাজটা কত বড় তা বোঝা যায় না। "খসড়া ৩১ · ২,৪৭,০০০"
 * জানলে যায়। আর প্রতিটা তীর তার নিজের ছাঁকা তালিকা খোলে, কারণ তীরের
 * ভেতরের সংখ্যাও একটা সংখ্যা — [[feedback_every_figure_links_to_its_source]]।
 */
final class ProcessBand
{
    /**
     * এক ধাপের তীরের জ্যামিতি।
     *
     * ── কেন এটা PHP-তে, CSS-এ নয় ────────────────────────────────────
     * প্রথমটার বাঁ কিনারা সমান, শেষটার ডান কিনারা সমান, মাঝেরগুলোর
     * দুই দিকেই খাঁজ। ওটা সারিতে অবস্থানের অঙ্ক, সাজসজ্জা নয় — আর
     * CSS-এ "প্রথম" ও "শেষ" আলাদা করা যায় (`:first-child`), কিন্তু
     * তিন রকম `clip-path` তিন জায়গায় লিখতে হত, আর একটা বদলালে বাকি
     * দুইটা চুপচাপ পিছিয়ে থাকত।
     *
     * ── কেন `clip-path`, বর্ডার নয় ──────────────────────────────────
     * বর্ডার দিয়ে তীর বানানো DMS-এ আগে করা হয়েছিল। যেকোনো zoom-এ
     * জোড়াটা "একটা তীর" নয়, "কোণ করে মেলা দুইটা লাইন" হয়ে পড়ত।
     */
    public static function chevronPoints(int $index, int $count): string
    {
        $notch = '14px';

        $left = $index === 0
            ? "0 0, calc(100% - {$notch}) 0, 100% 50%, calc(100% - {$notch}) 100%, 0 100%"
            : "0 0, calc(100% - {$notch}) 0, 100% 50%, calc(100% - {$notch}) 100%, 0 100%, {$notch} 50%";

        if ($index === $count - 1) {
            return $index === 0
                ? '0 0, 100% 0, 100% 100%, 0 100%'
                : "0 0, 100% 0, 100% 100%, 0 100%, {$notch} 50%";
        }

        return $left;
    }

    /**
     * অবস্থা ধরে ধাপগুলো — সংখ্যা, টাকা, আর যে তালিকাটা খোলে।
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $base
     *                                                                        অবস্থার ছাঁকনি **ছাড়া** কোয়েরিটা — বাকি সব ছাঁকনি
     *                                                                        (তারিখ, গ্রাহক, খোঁজা) ধরা থাকে, নাহলে তীরের সংখ্যা
     *                                                                        আর নিচের তালিকা দুই কথা বলত।
     * @param  list<array{status: string, label: string}>  $stages
     * @param  array<string, mixed>  $params  চলতি ছাঁকনিগুলো, লিংকে ধরে রাখার জন্য
     * @return list<array{label: string, count: int, total: string, url: string, current: bool, points: string}>
     */
    public static function forStatuses(
        Builder $base,
        array $stages,
        string $route,
        array $params,
        ?string $active,
        string $totalColumn = 'total',
    ): array {
        $out = [];
        $n = count($stages);

        foreach (array_values($stages) as $i => $stage) {
            /*
             * প্রতিটা ধাপে কোয়েরিটা নতুন করে ক্লোন — একই বিল্ডারে
             * পরপর `where` বসালে দ্বিতীয় ধাপে দুইটা অবস্থার শর্ত
             * জমত, আর তৃতীয়টায় তিনটা। সংখ্যাগুলো তখন শূন্য আসত, আর
             * কেউ ধরতে পারত না কেন।
             */
            $q = (clone $base)->where('status', $stage['status']);

            $out[] = [
                'label' => $stage['label'],
                'count' => (clone $q)->count(),
                'total' => (string) ((clone $q)->sum($totalColumn) ?: '0'),
                'url' => route($route, array_merge($params, ['stage' => $stage['status']])),
                'current' => $active === $stage['status'],
                'points' => self::chevronPoints($i, $n),
            ];
        }

        return $out;
    }
}
