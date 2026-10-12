<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\ListExport;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Notification\Reports\NotificationReports;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ⭐ বিজ্ঞপ্তির ১৭টা রিপোর্টের পর্দা (মালিকের স্পেক §১৭; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৪) — রিপোর্ট সেন্টারের সাধারণ পর্দায়।
 *
 * ⓘ দেখা `notification.reports` চাবিতে; CSV/XLSX নামানো আলাদা চাবিতে (`notification.reports.export`) — চাবি না থাকলে বোতামও
 * নেই, ঠিকানায় `?export=` লিখলেও নামে না ([[ListExport::refuse()]])।
 */
class NotificationReportController extends Controller implements HasMiddleware
{
    /** @var array<string, string> পর্দার ঠিকানা => রিপোর্টের চাবি ([[NotificationReports]]) */
    private const SLUGS = [
        'summary' => 'notification.summary',
        'user-wise' => 'notification.user_wise',
        'module-wise' => 'notification.module_wise',
        'priority-wise' => 'notification.priority_wise',
        'channel-delivery' => 'notification.channel_delivery',
        'delivery-status' => 'notification.delivery_status',
        'read-unread' => 'notification.read_unread',
        'attempt-history' => 'notification.attempt_history',
        'provider-errors' => 'notification.provider_errors',
        'retry-dead-letter' => 'notification.retry_dead_letter',
        'rule-execution' => 'notification.rule_execution',
        'template-usage' => 'notification.template_usage',
        'escalation' => 'notification.escalation',
        'latency' => 'notification.latency',
        'channel-availability' => 'notification.channel_availability',
        'suppression' => 'notification.suppression',
        'audit' => 'notification.audit_report',
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:'.NotificationReports::PERMISSION)];
    }

    public function show(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        if (! $request->user()->can('notification.reports.export')) {
            app(ListExport::class)->refuse();
        }

        $key = self::SLUGS[$slug];
        $definition = $this->reports->get($key);

        $result = $this->reports->run(
            $key,
            $request->only($definition->requestKeys()),
            page: max(1, (int) $request->query('page', 1)),
            byBranch: true,
        );

        return view('accounts::report.show', [
            'menu' => $this->menu->forUser($request->user()),
            'slug' => $slug,
            'report' => $definition,
            'result' => $result,
            'branches' => $definition->hasFilter('branch') ? Branch::query()->active()->orderBy('name_en')->get() : collect(),
            'accounts' => collect(),
            'partyTypes' => collect(),
        ]);
    }
}
