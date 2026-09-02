<?php

declare(strict_types=1);

namespace App\Modules\Approval\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Models\Approval;

/**
 * অনুমোদন মডিউলের ড্যাশবোর্ড।
 *
 * ── কেন এখানে "অপেক্ষমাণ" সবচেয়ে বড় সংখ্যা ──────────────────────────
 * অনুমোদনের পুরো কাজটাই **কেউ অপেক্ষা করছেন** — একটা ছাড়, একটা বিল,
 * একটা ছুটি আটকে আছে কারও সিদ্ধান্তের জন্য। বাকি সংখ্যাগুলো ইতিহাস;
 * এটাই আজকের কাজ।
 */
final class ApprovalDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        return new DashboardDefinition(
            title: __('approval::dashboard.title'),
            subtitle: __('approval::dashboard.subtitle'),

            tiles: [
                new Tile(label: __('approval::menu.inbox'), href: route('approval.inbox.index'),
                    permission: 'approval.view', icon: 'inbox'),
                new Tile(label: __('approval::menu.flows'), href: route('approval.flow.index'),
                    permission: 'approval.flow.manage', icon: 'settings'),
            ],

            stats: [
                new Stat(
                    label: __('approval::dashboard.pending'),
                    value: (string) Approval::query()->where('status', Approval::PENDING)->count(),
                    hint: __('approval::dashboard.pending_hint'),
                    href: route('approval.inbox.index'),
                    tone: Stat::WARN,
                ),
                new Stat(
                    label: __('approval::dashboard.approved'),
                    value: (string) Approval::query()->where('status', Approval::APPROVED)->count(),
                    hint: __('approval::dashboard.approved_hint'),
                    href: route('approval.inbox.index'),
                    tone: Stat::GOOD,
                ),
                new Stat(
                    label: __('approval::dashboard.rejected'),
                    value: (string) Approval::query()->where('status', Approval::REJECTED)->count(),
                    hint: __('approval::dashboard.rejected_hint'),
                    href: route('approval.inbox.index'),
                    tone: Stat::BAD,
                ),
            ],

            listings: [
                new Listing(
                    label: __('approval::dashboard.waiting_now'),
                    columns: [
                        ['key' => 'module', 'label' => __('approval::dashboard.module'), 'width' => '9rem',
                            'render' => fn ($a) => $a->module],
                        ['key' => 'action', 'label' => __('approval::dashboard.action'),
                            'render' => fn ($a) => $a->action],
                        ['key' => 'amount', 'label' => __('approval::dashboard.amount'), 'width' => '9rem',
                            'render' => fn ($a) => $a->amount],
                    ],
                    rows: Approval::query()->where('status', Approval::PENDING)->latest('id')->limit(8)->get(),
                    empty: __('approval::dashboard.nothing_waiting'),
                    href: route('approval.inbox.index'),
                ),
            ],
        );
    }
}
