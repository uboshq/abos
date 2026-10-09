<?php

declare(strict_types=1);

namespace App\Modules\Executive\Services;

use App\Models\User;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Executive\Models\SisterLink;
use App\Modules\Sales\Metrics\SalesMetrics;
use Illuminate\Support\Facades\Cache;

/**
 * ভাই-কোম্পানি বাদ — গ্রুপের মোট থেকে গ্রুপের ভিতরের বিক্রি, পাওনা আর দেনা (IFRS 10; মালিকের উত্তর, প্রশ্ন ২)।
 *
 * ── ⛔ কেবল জোড়া দেওয়া পক্ষ ───────────────────────────────────────────
 * কোন ক্রেতা বা সরবরাহকারী আসলে ভাই-কোম্পানি, তা কেবল [[SisterLink]]-এর সারি বলে। ⚠️ নাম মিলিয়ে আন্দাজ
 * নেই; জোড়া না থাকলে কিছুই বাদ যায় না, আর গ্রুপের মোট = সারিগুলোর যোগ।
 *
 * ── ⓘ কখন বাদ ──────────────────────────────────────────────────────────
 * দুই কোম্পানিই পর্দায় থাকলে — তবেই লেনদেনটা "গ্রুপের ভিতরে"। যিনি কেবল এক কোম্পানি দেখেন, তাঁর কাছে
 * ঐ বিক্রি সত্যিই বাইরের কাছে বিক্রি।
 *
 * ── ⭐ সংখ্যাটা মডিউলের নিজের ──────────────────────────────────────────
 * বাদের অঙ্ক ঐ ঘরের একই সংজ্ঞা, কেবল জোড়া পক্ষগুলোর জন্য: বিক্রি [[SalesMetrics::invoiceTotal()]],
 * পাওনা আর দেনা [[AccountsFacts::controlBalanceOf()]] — একই কোম্পানিতে, একই শাখার দৃষ্টিতে।
 */
final class Eliminations
{
    /** @var array<string, array{type: string, code: string}> কোন ঘর থেকে, কোন ধরনের পক্ষ */
    public const FIGURES = [
        Figures::SALES => ['type' => SisterLink::CUSTOMER, 'code' => StandardChart::RECEIVABLE],
        Figures::RECEIVABLE => ['type' => SisterLink::CUSTOMER, 'code' => StandardChart::RECEIVABLE],
        Figures::PAYABLE => ['type' => SisterLink::SUPPLIER, 'code' => StandardChart::PAYABLE],
    ];

    public function __construct(
        private readonly CompanyLens $lens,
        private readonly Figures $figures,
    ) {}

    /**
     * বাদের অঙ্ক — ঘর ধরে, সব কোম্পানি মিলিয়ে। `null` = কিছু বাদ যায়নি (কোনো জোড়া পর্দায় নেই)।
     *
     * @param  list<array{id: int, name: string, only?: ?int}>  $companies
     * @return array<string, string>|null
     */
    public function amounts(User $user, array $companies, string $from, string $to): ?array
    {
        $onScreen = array_column($companies, 'id');
        $out = array_fill_keys(array_keys(self::FIGURES), '0');
        $any = false;

        foreach ($companies as $company) {
            $links = SisterLink::acrossAllCompanies()
                ->where('company_id', $company['id'])
                ->whereIn('sister_company_id', $onScreen)
                ->where('sister_company_id', '<>', $company['id'])
                ->get(['party_type', 'party_id']);

            if ($links->isEmpty()) {
                continue;
            }

            $ids = [
                SisterLink::CUSTOMER => $links->where('party_type', SisterLink::CUSTOMER)->pluck('party_id')->map(fn ($id) => (int) $id)->all(),
                SisterLink::SUPPLIER => $links->where('party_type', SisterLink::SUPPLIER)->pluck('party_id')->map(fn ($id) => (int) $id)->all(),
            ];

            $key = implode(':', ['executive-elim', 'v'.Board::version($user), 'u'.$user->id, 'c'.$company['id'],
                'b'.($company['only'] ?? 'all'), $from, $to, md5(serialize($ids))]);

            $part = Cache::remember($key, Board::CACHE_SECONDS, fn () => $this->lens->within($user, $company['id'], $company['only'] ?? null,
                function () use ($user, $ids, $from, $to): array {
                    $accounts = app(AccountsFacts::class);
                    $part = [];

                    foreach (self::FIGURES as $figure => $rule) {
                        $parties = $ids[$rule['type']];

                        // ⓘ ঘরটা যিনি দেখেন না, তাঁর যোগেও এটা নেই — বাদেরও কিছু নেই
                        if ($parties === [] || ! $this->figures->visible($user, $figure)) {
                            $part[$figure] = '0';

                            continue;
                        }

                        $part[$figure] = bcadd($figure === Figures::SALES
                            ? SalesMetrics::invoiceTotal($from, $to, $parties)
                            : $accounts->controlBalanceOf($rule['code'], $rule['type'], $parties), '0', 4);
                    }

                    return $part;
                }));

            foreach ($part as $figure => $value) {
                $out[$figure] = bcadd($out[$figure], $value, 4);
            }

            $any = true;
        }

        return $any ? $out : null;
    }
}
