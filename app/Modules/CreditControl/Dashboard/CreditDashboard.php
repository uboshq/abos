<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Stat;
use App\Models\Approval;
use App\Modules\Customer\Models\Customer;

/**
 * ⭐ বাকি নিয়ন্ত্রণের ড্যাশবোর্ড — এক পর্দায় কার কত বাকি, কে আটকে, কী অপেক্ষায়।
 *
 * ⛔ এখানে কোনো সূত্র নেই: প্রতিটা অঙ্ক দেয়ালের নিজের হিসাব বা আগের রিপোর্ট থেকে, আর প্রতিটা সংখ্যায় চাপলে তার তালিকা।
 */
final class CreditDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        return new DashboardDefinition(
            title: __('credit_control::dashboard.title'),
            subtitle: __('credit_control::dashboard.subtitle'),
            stats: [
                new Stat(
                    label: __('credit_control::dashboard.blocked'),
                    value: (string) Customer::query()->inViewedBranch()->whereNotNull('credit_blocked_at')->count(),
                    hint: __('credit_control::dashboard.blocked_hint'),
                    href: route('sales.report.show', ['slug' => 'blocked-customers']),
                    tone: Stat::BAD,
                    permission: 'sales.report',
                ),
                new Stat(
                    label: __('credit_control::dashboard.pending_requests'),
                    value: (string) self::pendingRequests(),
                    hint: __('credit_control::dashboard.pending_requests_hint'),
                    href: route('sales.report.show', ['slug' => 'limit-history']),
                    tone: Stat::WARN,
                    permission: 'sales.report',
                ),
            ],
        );
    }

    /** সই না পড়া সীমা বাড়ানোর অনুরোধ — দেখা যায় এমন গ্রাহকের */
    private static function pendingRequests(): int
    {
        return Approval::query()
            ->where('module', 'customer')
            ->where('action', 'credit_limit')
            ->where('status', Approval::PENDING)
            ->where('approvable_type', (new Customer)->getMorphClass())
            ->whereIn('approvable_id', Customer::query()->inViewedBranch()->select('customers.id'))
            ->count();
    }
}
