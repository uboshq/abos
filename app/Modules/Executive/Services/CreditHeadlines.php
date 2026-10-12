<?php

declare(strict_types=1);

namespace App\Modules\Executive\Services;

use App\Core\Engines\Dashboard\DashboardEngine;
use App\Models\User;
use App\Modules\CreditControl\Dashboard\CreditDashboard;

/**
 * ⭐ মালিকের কেন্দ্রে বাকির চার সংখ্যা — ব্যবহৃত বাকি, মেয়াদ পেরোনো, বাকি বন্ধ গ্রাহক, সইয়ের অপেক্ষার সীমার অনুরোধ
 * (বাকি নিয়ন্ত্রণ ধাপ ৩.৩, ১২ অক্টোবর ২০২৬)।
 *
 * ⛔ এখানে কোনো হিসাব নেই — প্রতিটা কোম্পানির সংখ্যা বাকি নিয়ন্ত্রণের নিজের ড্যাশবোর্ডের যোগ ([[CreditDashboard::figures()]]),
 * আর সেটা দেয়ালের হিসাব থেকে। ⓘ দ্বিতীয় কোনো "মালিকের বাকির ড্যাশবোর্ড" নেই — এই চার সংখ্যা চাপলে ঐ কোম্পানির বাকির ড্যাশবোর্ড।
 *
 * ⓘ দেয়াল: [[CompanyLens::within()]] — ঐ কোম্পানিতে, মানুষটার চোখে, হেডারের শাখাসহ; ঐ কোম্পানিতে বাকির ড্যাশবোর্ডের চাবি না
 * থাকলে কোম্পানিটা যোগে নেই, আর `partial` বলে যোগটা অসম্পূর্ণ।
 */
final class CreditHeadlines
{
    /** @var list<string> পর্দার ক্রম */
    public const KEYS = ['exposure', 'overdue', 'blocked', 'pending_requests'];

    public function __construct(
        private readonly CompanyLens $lens,
        private readonly DashboardEngine $dashboards,
    ) {}

    /**
     * @param  list<array{id: int, name: string, only: ?int}>  $companies
     * @return array{totals: array<string, string>, companies: list<array{id: int, name: string, only: ?int, values: array<string, string>}>, partial: bool}|null
     *         null = কোনো কোম্পানিতেই দেখার চাবি নেই (বা বাকি নিয়ন্ত্রণ নেই)
     */
    public function for(User $user, array $companies): ?array
    {
        if (! $this->dashboards->has('credit_control')) {
            return null;
        }

        $gate = $this->dashboards->permissionFor('credit_control');
        $totals = array_fill_keys(self::KEYS, '0');
        $parts = [];
        $partial = false;

        foreach ($companies as $company) {
            $values = $this->lens->within($user, $company['id'], $company['only'], function () use ($user, $gate): ?array {
                if ($gate !== null && ! $user->can($gate)) {
                    return null;
                }

                $t = CreditDashboard::figures()['totals'];

                return [
                    'exposure' => bcadd((string) $t['exposure'], '0', 4),
                    'overdue' => bcadd((string) $t['overdue'], '0', 4),
                    'blocked' => (string) (int) $t['blocked'],
                    'pending_requests' => (string) (int) $t['pending_requests'],
                ];
            });

            if ($values === null) {
                $partial = true;

                continue;
            }

            foreach (self::KEYS as $key) {
                $totals[$key] = bcadd($totals[$key], $values[$key], 4);
            }

            $parts[] = [...$company, 'values' => $values];
        }

        return $parts === [] ? null : ['totals' => $totals, 'companies' => $parts, 'partial' => $partial];
    }
}
