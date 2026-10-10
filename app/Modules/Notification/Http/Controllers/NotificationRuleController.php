<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Core\Notifications\NotificationVariables;
use App\Core\Notifications\RecipientChoices;
use App\Core\Notifications\RecipientResolver;
use App\Core\Notifications\RuleEngine;
use App\Core\Notifications\RuleWriter;
use App\Core\Services\MenuBuilder;
use App\Core\Support\NotificationKinds;
use App\Http\Controllers\Controller;
use App\Models\NotificationRule;
use App\Models\NotificationRuleVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ নিয়মের পর্দা — কোড ছাড়া নিয়ম লেখা, সংস্করণ দেখা, শুকনো পরীক্ষা (মালিকের স্পেক §৪ "Rules", §৮; ধাপ ৩)।
 *
 * ⓘ প্রতিটা সংরক্ষণে সংস্করণ বাড়ে আর পুরো ছবি [[NotificationRuleVersion]]-এ; নিয়মটা নিজে নিরীক্ষিত (IsAudited)।
 * ⓘ "পরীক্ষা" কিছু পাঠায় না — কেবল বলে নিয়মটা খাটত কি না, আর কারা পেতেন।
 */
class NotificationRuleController extends Controller
{
    private const PER_PAGE = 50;

    /** পর্দায় শর্তের সারি কয়টা — [[RuleWriter::CONDITION_ROWS]] */
    public const CONDITION_ROWS = RuleWriter::CONDITION_ROWS;

    public function __construct(private readonly MenuBuilder $menu) {}

    public function index(Request $request): View
    {
        $f = $request->validate([
            'module' => ['nullable', 'string', 'max:32'],
            'state' => ['nullable', Rule::in(['active', 'inactive'])],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $rows = NotificationRule::query()
            ->with('template')
            ->when($f['module'] ?? null, fn ($q, $m) => $q->where('module', $m))
            ->when(($f['state'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($f['state'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($f['q'] ?? null, fn ($q, $s) => $q->where('name', 'like', '%'.addcslashes($s, '%_\\').'%'))
            ->orderBy('module')->orderBy('event')->orderBy('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return view('notification::rules.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            'filters' => $f,
            'modules' => $this->modules(),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, new NotificationRule(['is_active' => true, 'delay_minutes' => 0, 'cooldown_minutes' => 0]));
    }

    public function store(Request $request): RedirectResponse
    {
        $writer = app(RuleWriter::class);
        $rule = $writer->save($writer->validate($request->all()));

        return redirect()->route('notification.rules.edit', $rule)->with('saved', __('notification::rule.saved'));
    }

    public function edit(Request $request, NotificationRule $rule): View
    {
        return $this->form($request, $rule);
    }

    public function update(Request $request, NotificationRule $rule): RedirectResponse
    {
        $writer = app(RuleWriter::class);
        $writer->save($writer->validate($request->all()), $rule);

        return redirect()->route('notification.rules.edit', $rule)->with('saved', __('notification::rule.saved'));
    }

    /**
     * ⭐ শুকনো পরীক্ষা — নমুনা মান দিয়ে: নিয়মটা খাটত কি না, কোন শর্ত আটকাল, কারা পেতেন, কোন মাধ্যমে। কিছু পাঠায় না।
     */
    public function test(Request $request, NotificationRule $rule): View
    {
        $sample = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'priority' => ['nullable', Rule::in(NotificationKinds::PRIORITIES)],
            'values' => ['nullable', 'array'],
            'values.*' => ['nullable', 'string', 'max:191'],
        ]);

        $branch = isset($sample['branch_id']) ? (int) $sample['branch_id'] : null;
        $priority = $sample['priority'] ?? NotificationKinds::classify((string) $rule->event)['priority'];
        $values = NotificationVariables::clean(array_filter((array) ($sample['values'] ?? []), fn ($v) => $v !== null && $v !== ''));

        $facts = $values + ['priority' => $priority, 'branch_id' => $branch === null ? '' : (string) $branch];
        $checks = array_map(fn ($c) => ['condition' => $c, 'holds' => RuleEngine::holds($facts, (array) $c)], (array) ($rule->conditions ?? []));
        $matches = app(RuleEngine::class)->matches($rule, $branch, $priority, $values, now());

        return $this->form($request, $rule, [
            'tested' => true,
            'matches' => $matches,
            'checks' => $checks,
            'wouldReach' => $matches ? app(RecipientResolver::class)->resolve((array) ($rule->recipients ?? []), $branch) : collect(),
            'sample' => $sample,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function form(Request $request, NotificationRule $rule, array $extra = []): View
    {
        return view('notification::rules.form', $extra + [
            'menu' => $this->menu->forUser($request->user()),
            'rule' => $rule,
            'modules' => $this->modules(),
            'events' => NotificationKinds::all(),
            'versions' => $rule->exists ? $rule->versions()->with('changer')->limit(20)->get() : collect(),
            'choices' => RecipientChoices::all(),
            'tested' => false,
        ]);
    }

    /** @return list<string> ধরনগুলোর মডিউল */
    private function modules(): array
    {
        return collect(array_keys(NotificationKinds::all()))
            ->map(fn ($t) => NotificationKinds::classify($t)['module'])->unique()->sort()->values()->all();
    }
}
