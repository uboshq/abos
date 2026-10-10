<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Core\Engines\Approval\ApprovalSla;
use App\Core\Services\DataScope;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\ApprovalFlowStep;
use App\Models\User;
use App\Models\UserDataScope;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ ওপরে পাঠানো (Escalation) — শেষ সময়, স্তর, দায়িত্বে কে, কাকে পাঠানো হলো (মালিকের স্পেক §৪ "Escalation", §১৪; ধাপ ৪)।
 *
 * ── ⛔ এখানে নতুন কোনো ইঞ্জিন নেই ───────────────────────────────────────
 * মনে করানো আর ওপরে পাঠানো করে অনুমোদন ইঞ্জিন নিজে — ধাপের SLA, সতর্কের ঘণ্টা, কাকে ([[ApprovalSla]], `abos:approvals-due`)।
 * বিজ্ঞপ্তি মডিউল সেটাই দেখায় আর সেটারই খবর পাঠায় (`approval.reminder`, `approval.escalated`)। ⓘ ওপরে পাঠানো মানে অনুমোদন নয় —
 * কাগজটা যেমন ছিল তেমনই থাকে।
 *
 * ⓘ অনুমোদনের অনুরোধে শাখা নেই — তাই পর্দাটা গোটা কোম্পানির, আর শাখায় আটকানো মানুষের কাছে বন্ধ।
 * ⓘ "ফাঁক" — যে ধাপে ঘড়ি আছে কিন্তু কাকে পাঠাতে হবে বলা নেই; সেখানে সময় পেরোলেও কেউ জানবেন না।
 */
class NotificationEscalationController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private readonly MenuBuilder $menu) {}

    public function index(Request $request): View
    {
        abort_if(app(DataScope::class)->isLimited($request->user(), UserDataScope::BRANCH), 403, __('notification::escalation.whole_company'));

        $f = $request->validate(['state' => ['nullable', Rule::in(['waiting', 'late', 'escalated'])]]);
        $sla = app(ApprovalSla::class);

        $rows = Approval::query()->pending()->with(['assignee:id,name'])
            ->whereNotNull('due_at')
            ->when(($f['state'] ?? null) === 'waiting', fn ($q) => $q->whereNull('escalated_at')->where('due_at', '>', now()))
            ->when(($f['state'] ?? null) === 'late', fn ($q) => $q->whereNull('escalated_at')->where('due_at', '<=', now()))
            ->when(($f['state'] ?? null) === 'escalated', fn ($q) => $q->whereNotNull('escalated_at'))
            ->orderBy('due_at')
            ->paginate(self::PER_PAGE)->withQueryString();

        $escalatedTo = User::query()->withoutGlobalScope('company')
            ->whereIn('id', $rows->pluck('escalated_to')->filter()->unique())->pluck('name', 'id');

        $holes = ApprovalFlowStep::query()
            ->join('approval_flows', 'approval_flows.id', '=', 'approval_flow_steps.approval_flow_id')
            ->where('approval_flows.company_id', CompanyContext::id())
            ->where('approval_flows.is_active', true)
            ->where(fn ($q) => $q->whereNotNull('approval_flow_steps.sla_hours')->orWhereNotNull('approval_flow_steps.escalate_hours'))
            ->where(fn ($q) => $q->whereNull('approval_flow_steps.escalate_to_type')->orWhereNull('approval_flow_steps.escalate_to_id'))
            ->orderBy('approval_flows.module')->orderBy('approval_flow_steps.level')
            ->limit(100)
            ->get(['approval_flows.module', 'approval_flows.action', 'approval_flow_steps.level', 'approval_flow_steps.step_name', 'approval_flow_steps.sla_hours']);

        return view('notification::escalations.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            'filters' => $f,
            'states' => $rows->getCollection()->mapWithKeys(fn (Approval $a) => [$a->id => $sla->stateOf($a)]),
            'escalatedTo' => $escalatedTo,
            'holes' => $holes,
        ]);
    }
}
