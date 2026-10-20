<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Widgets;

use App\Core\Support\CompanyContext;
use App\Core\Widgets\WidgetSummary;
use App\Models\User;
use App\Modules\CreditControl\Reports\CreditPositionReports;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Services\CreditExposure;

/**
 * ⭐ ১২ · বাকির সীমার ঝুঁকি — সীমার ৯০% বা তার বেশি ব্যবহার করা গ্রাহক (উইজেটের নকশা ১৯ অক্টোবর, পাতা ২; মালিকের আদেশ,
 * ২০ অক্টোবর ২০২৬; cb)।
 *
 * ⓘ ব্যবহারের হিসাব বাকির দেয়ালের হুবহু — [[CreditExposure::positionsFor()]]: খাতার বকেয়া + আটকে থাকা + ক্লিয়ার না হওয়া চেক;
 * গ্রাহকের দল বাকি নিয়ন্ত্রণের ড্যাশবোর্ডের একই ([[CreditDashboard::figures()]]: দেখার শাখা ও নিজের ডিলার, চালু বা বকেয়া আছে)।
 * ⓘ সীমা শূন্য (`used_percent` null) — বাকির সীমা বসানো নেই, তাই "সীমার কাছে" প্রশ্নই নেই।
 * ⓘ নাম "নাম · পয়েন্ট · শাখা" (মালিকের স্থায়ী নিয়ম, ১৯ অক্টোবর ২০২৬)। বেশি ব্যবহার আগে; ৮টা সারি, `count` সবার।
 */
final class CreditLimitsSummary implements WidgetSummary
{
    /** সীমার কত ভাগ ব্যবহার হলে উইজেটে আসে */
    public const NEAR_PERCENT = '90';

    public function key(): string
    {
        return 'credit_limits';
    }

    public function module(): string
    {
        return 'credit_control';
    }

    public function permission(): string
    {
        return 'credit_control.view';
    }

    public function data(User $user): array
    {
        $customers = Customer::query()
            ->inViewedBranch()
            ->tap(fn ($q) => CreditPositionReports::activeOrStillOwing($q, (int) CompanyContext::id()))
            ->withOutstanding()
            ->with(['location.parent', 'branch'])
            ->get()
            ->keyBy('id');

        $near = [];

        foreach (app(CreditExposure::class)->positionsFor($customers) as $id => $position) {
            $used = $position['used_percent'];

            if ($used !== null && bccomp((string) $used, self::NEAR_PERCENT, 4) >= 0) {
                $near[$id] = $position;
            }
        }

        uasort($near, fn (array $a, array $b) => bccomp((string) $b['used_percent'], (string) $a['used_percent'], 4));

        $rows = [];

        foreach (array_slice($near, 0, 8, true) as $id => $position) {
            $customer = $customers[$id];
            $rows[] = [
                'name' => self::label($customer),
                'due' => bcadd((string) $position['exposure'], '0', 4),
                'limit' => bcadd((string) $position['limit'], '0', 4),
                'percent' => (int) bcadd((string) $position['used_percent'], '0', 0),
                'link' => '/customers/'.$id,
            ];
        }

        return [
            'count' => count($near),
            'rows' => $rows,
            'link' => '/credit-control',
        ];
    }

    /** "নাম · পয়েন্ট · শাখা" — পয়েন্ট বা শাখা না থাকলে যা আছে */
    public static function label(Customer $customer): string
    {
        $branch = $customer->branch?->name();

        return $customer->nameWithPoint().($branch === null || $branch === '' ? '' : ' · '.$branch);
    }
}
