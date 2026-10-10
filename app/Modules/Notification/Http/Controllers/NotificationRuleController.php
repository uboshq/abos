<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Core\Notifications\NotificationVariables;
use App\Core\Notifications\RecipientResolver;
use App\Core\Notifications\RuleEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Services\NotificationAudit;
use App\Core\Support\Actor;
use App\Core\Support\NotificationKinds;
use App\Http\Controllers\Controller;
use App\Models\NotificationChannel;
use App\Models\NotificationRule;
use App\Models\NotificationRuleVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    /** পর্দায় শর্তের সারি কয়টা */
    public const CONDITION_ROWS = 4;

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
        $data = $this->validated($request);

        $rule = DB::transaction(function () use ($data): NotificationRule {
            $rule = NotificationRule::query()->create($data + ['version' => 1, 'created_by' => Actor::userId(), 'updated_by' => Actor::userId()]);
            $this->remember($rule);

            return $rule;
        });

        app(NotificationAudit::class)->record('rule_save', $rule, 'done', ['rule_id' => $rule->id, 'version' => 1]);

        return redirect()->route('notification.rules.edit', $rule)->with('saved', __('notification::rule.saved'));
    }

    public function edit(Request $request, NotificationRule $rule): View
    {
        return $this->form($request, $rule);
    }

    public function update(Request $request, NotificationRule $rule): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($rule, $data): void {
            $rule->fill($data + ['updated_by' => Actor::userId()]);

            if ($rule->isDirty()) {
                $rule->version = (int) $rule->version + 1;
                $rule->save();
                $this->remember($rule);
            }
        });

        app(NotificationAudit::class)->record('rule_save', $rule, 'done', ['rule_id' => $rule->id, 'version' => (int) $rule->version]);

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

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $choices = RecipientChoices::ids();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'event' => ['required', Rule::in(array_keys(NotificationKinds::all()))],
            'branch_id' => ['nullable', Rule::in($choices['branches'])],
            'conditions' => ['nullable', 'array', 'max:'.self::CONDITION_ROWS],
            'conditions.*.field' => ['nullable', Rule::in(NotificationVariables::CONDITION_FIELDS)],
            'conditions.*.op' => ['nullable', Rule::in(NotificationRule::OPERATORS)],
            'conditions.*.value' => ['nullable', 'string', 'max:191'],
            'recipients' => ['nullable', 'array'],
            'recipients.users' => ['nullable', 'array'], 'recipients.users.*' => [Rule::in($choices['users'])],
            'recipients.roles' => ['nullable', 'array'], 'recipients.roles.*' => [Rule::in($choices['roles'])],
            'recipients.branches' => ['nullable', 'array'], 'recipients.branches.*' => [Rule::in($choices['branches'])],
            'recipients.departments' => ['nullable', 'array'], 'recipients.departments.*' => [Rule::in($choices['departments'])],
            'recipients.groups' => ['nullable', 'array'], 'recipients.groups.*' => [Rule::in($choices['groups'])],
            'recipients.responsible' => ['nullable', 'boolean'],
            'channels' => ['nullable', 'array'], 'channels.*' => [Rule::in(NotificationChannel::ALL)],
            'priority' => ['nullable', Rule::in(NotificationKinds::PRIORITIES)],
            'template_id' => ['nullable', Rule::in($choices['templates'])],
            'delay_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'expires_minutes' => ['nullable', 'integer', 'min:1', 'max:525600'],
            'cooldown_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'is_active' => ['nullable', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        // ⓘ ফাঁকা শর্তের সারি বাদ; সংখ্যার ঘরে সংখ্যা ছাড়া কিছু নয়
        $conditions = [];

        foreach ((array) ($data['conditions'] ?? []) as $i => $c) {
            if (blank($c['field'] ?? null) || blank($c['op'] ?? null)) {
                continue;
            }

            $value = trim((string) ($c['value'] ?? ''));

            if (in_array($c['field'], NotificationVariables::NUMERIC_FIELDS, true) && $c['op'] !== 'in'
                && preg_match('/^-?[\d০-৯,]+(\.[\d০-৯]+)?$/u', $value) !== 1) {
                throw ValidationException::withMessages(["conditions.$i.value" => __('notification::rule.number_needed')]);
            }

            $conditions[] = ['field' => $c['field'], 'op' => $c['op'], 'value' => $value];
        }

        $recipients = [];

        foreach (['users', 'roles', 'branches', 'departments', 'groups'] as $kind) {
            if ($ids = array_values(array_unique(array_map('intval', (array) ($data['recipients'][$kind] ?? []))))) {
                $recipients[$kind] = $ids;
            }
        }

        if (! empty($data['recipients']['responsible'])) {
            $recipients['responsible'] = true;
        }

        return [
            'name' => $data['name'],
            'module' => NotificationKinds::classify($data['event'])['module'],
            'event' => $data['event'],
            'branch_id' => $data['branch_id'] ?? null,
            'conditions' => $conditions ?: null,
            'recipients' => $recipients ?: null,
            'channels' => array_values(array_unique((array) ($data['channels'] ?? []))) ?: null,
            'priority' => $data['priority'] ?? null,
            'template_id' => $data['template_id'] ?? null,
            'delay_minutes' => (int) ($data['delay_minutes'] ?? 0),
            'expires_minutes' => isset($data['expires_minutes']) ? (int) $data['expires_minutes'] : null,
            'cooldown_minutes' => (int) ($data['cooldown_minutes'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? false),
            'effective_from' => $data['effective_from'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
        ];
    }

    private function remember(NotificationRule $rule): void
    {
        NotificationRuleVersion::query()->create([
            'company_id' => $rule->company_id,
            'rule_id' => $rule->id,
            'version' => (int) $rule->version,
            'snapshot' => $rule->snapshot(),
            'changed_by' => Actor::userId(),
        ]);
    }

    /** @return list<string> ধরনগুলোর মডিউল */
    private function modules(): array
    {
        return collect(array_keys(NotificationKinds::all()))
            ->map(fn ($t) => NotificationKinds::classify($t)['module'])->unique()->sort()->values()->all();
    }
}
