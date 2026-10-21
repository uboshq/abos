<?php

declare(strict_types=1);

namespace App\Modules\Executive\Widgets;

use App\Core\Widgets\WidgetSummaries;
use App\Core\Widgets\WidgetSummary;
use App\Http\Controllers\Api\DashboardTodayController;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * ⭐ মালিকের পূর্ণ-পর্দা উইজেট (৪×৬) — এক নজরে পুরো ব্যবসা (নকশা: মালিকের-পূর্ণ-পর্দা-উইজেট-২০-অক্টোবর.pdf; মালিকের আদেশ,
 * ২০ অক্টোবর ২০২৬)।
 *
 * ⭐ নিজে কিছু গোনে না:
 *  - আজকের চার মূল সংখ্যা (বিক্রি, আদায়, হাতে মোট, বকেয়া) আর সইয়ের অপেক্ষা — ফোনের "আজ কেমন গেল" দরজার হুবহু উত্তর
 *    ([[DashboardTodayController]]), যা নিজেই ওয়েবের হোমের একই উৎস নেয়;
 *  - মাসের ছবি আর সংকেত (লক্ষ্য, লাভ, খরচ, চেক, কম মজুদ, পথের চালান, নোটিশ) — দলের অন্য উইজেটের সারাংশ, যদি এই মানুষ সেটা পায়
 *    ([[WidgetSummaries::visibleTo()]]) — তাই পূর্ণ-পর্দা আর ছোট উইজেট কখনো আলাদা সংখ্যা বলে না।
 *
 * ⛔ শুধু মালিক বা যার নির্বাহী-চাবি আছে (`executive.view`, ওয়েবের মালিকের পাতার চাবি); অন্য কারো উইজেট-তালিকায় আসেই না।
 * প্রতিটা ভাগ নিজের চাবিতেও আটকায় — Today দরজা ঘরে ঘরে চাবি দেখে, অংশগুলো দরজার ছাঁকনিতে।
 */
final class OwnerBoardSummary implements WidgetSummary
{
    /** পূর্ণ-পর্দায় যে ছোট উইজেটগুলোর সারাংশ বসে — যা আছে আর যা এই মানুষ পায় */
    public const PARTS = ['target', 'profit', 'expenses', 'cheques', 'stock_alerts', 'challans_today', 'notices'];

    public function key(): string
    {
        return 'owner_board';
    }

    public function module(): string
    {
        return 'executive';
    }

    public function permission(): ?string
    {
        return 'executive.view';
    }

    public function data(User $user): array
    {
        $request = Request::create('/api/v1/dashboard/today');
        $request->setUserResolver(fn () => $user);
        $today = app(DashboardTodayController::class)($request)->getData(true);

        $parts = [];
        foreach (WidgetSummaries::visibleTo($user) as $key => $summary) {
            if (in_array($key, self::PARTS, true)) {
                $parts[$key] = $summary->data($user);
            }
        }

        return [
            'company' => $today['company'] ?? null,
            'branch' => $today['branch'] ?? null,
            'today' => array_intersect_key($today, array_flip(['sales', 'collections', 'money', 'dues', 'approvals'])),
            'parts' => (object) $parts,
            'link' => '/owner',
        ];
    }
}
