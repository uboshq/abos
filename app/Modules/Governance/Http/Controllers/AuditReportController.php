<?php

declare(strict_types=1);

namespace App\Modules\Governance\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * নিরীক্ষার তিনটা খাতা — হিসাবের রিপোর্টের ভিউ দিয়েই ([[AuditReports]], [[ApprovalReportController]]-এর একই ধাঁচ)।
 *
 * ⓘ কেবল নিরীক্ষার চাবিওয়ালা (`governance.audit.view`) — কে কী বদলাল, সেটা সবার দেখার নয়।
 */
final class AuditReportController extends Controller implements HasMiddleware
{
    /**
     * ঠিকানার নাম → রিপোর্টের চাবি।
     *
     * @var array<string, string>
     */
    private const SLUGS = [
        'changes' => 'governance.changes',
        'backdated' => 'governance.backdated',
        'periods' => 'governance.periods',
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:governance.audit.view')];
    }

    public function show(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        $key = self::SLUGS[$slug];
        $definition = $this->reports->get($key);

        return view('accounts::report.show', [
            'menu' => $this->menu->forUser($request->user()),
            'slug' => $slug,
            'report' => $definition,
            'result' => $this->reports->run(
                $key,
                $request->only($definition->requestKeys()),
                page: max(1, (int) $request->query('page', 1)),
                byBranch: true,
            ),
            // ⓘ শাখার ঘর কেবল যে খাতা শাখা মানে তার জন্য — মাসের তালা কোম্পানির, শাখার নয়
            'branches' => $definition->hasFilter('branch')
                ? Branch::query()->active()->orderBy('name_en')->get()
                : collect(),
            'accounts' => collect(),
            'partyTypes' => collect(),
            'notice' => null,
        ]);
    }
}
