<?php

declare(strict_types=1);

namespace App\Modules\Approval\Dashboard;

use App\Core\Contracts\DashboardWidgets;
use App\Core\Dashboard\Widget;
use App\Core\Engines\Approval\ApprovalEngine;
use App\Models\Approval;

/**
 * অনুমোদনের সংখ্যাগুলো হোম পর্দায়।
 *
 * ── কেন এটা এখানে থাকা জরুরি ────────────────────────────────────────
 * অনুমোদনের অনুরোধ কাউকে খুঁজে নেয় না — যিনি সিদ্ধান্ত দেবেন তিনি
 * নিজে থেকে তালিকাটা না খুললে জানতেই পারেন না কিছু ঝুলে আছে। আর
 * ততক্ষণ যিনি অনুরোধ করেছেন তাঁর বিলটা খসড়া হয়ে বসে থাকে।
 *
 * সংখ্যাটা হোম পর্দায় থাকলে ফাঁকটা প্রথম দিনেই চোখে পড়ে।
 */
final class ApprovalWidgets implements DashboardWidgets
{
    /** @return list<Widget> */
    public static function widgets(): array
    {
        $user = auth()->user();

        $waiting = $user !== null ? count(app(ApprovalEngine::class)->pendingFor($user)) : 0;

        return [
            new Widget(
                group: 'todo',
                label: __('approval::dashboard.waiting_for_me'),
                value: (string) $waiting,
                href: route('approval.inbox.index'),
                permission: 'approval.decide',
                tone: $waiting > 0 ? 'warn' : 'neutral',
                sort: 5,
            ),

            /*
             * নিজের অনুরোধগুলো — কেবল যেগুলো এখনো ঝুলে আছে।
             *
             * সিদ্ধান্ত হয়ে যাওয়া অনুরোধ "যা করা বাকি" দলে থাকার কথা
             * নয়; ওটা আর কারও কাজ নয়।
             */
            new Widget(
                group: 'todo',
                label: __('approval::dashboard.my_pending'),
                value: (string) Approval::query()
                    ->where('requested_by', $user?->id)
                    ->pending()
                    ->count(),
                href: route('approval.inbox.mine'),
                permission: 'approval.view',
                tone: 'neutral',
                sort: 6,
            ),
        ];
    }
}
