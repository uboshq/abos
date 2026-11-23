<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\PartyAutoSend;
use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Core\Support\PerPage;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Models\PartyMessage;
use App\Models\PartyMessageOptout;
use App\Models\PartyMessageTemplate;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * ⭐ নিজে থেকে পাঠানো — নমুনা HUXqpfhxng8823eEFrvH54 ট্যাব ঙ (মালিকের "ok", ২৩ নভেম্বর ২০২৬)।
 *
 * ⓘ মাথা এক সারিতে: নাম · ট্যাব (নিয়ম / আজ কারা পাবেন / বাদ রাখা পার্টি) · "সংরক্ষণ" — নামের নিচে ব্যাখ্যার লাইন নেই
 *   (মালিক, ১৯ নভেম্বর ২০২৬ রাত)।
 * ⓘ নিয়ম: দুই কার্ড (বাকির তাগাদা, টাকা পাওয়ার খবর) আর সবার-নিয়মের বাক্স; কাজ সব [[PartyAutoSend]]-এ, এখানে কেবল পর্দা।
 * ⓘ "প্রতিটা শাখার নিজের নিয়ম" চালু থাকলে কার্ডের ঘর মাথায় বাছা শাখার; "সব শাখা"-তে কোম্পানির (যে শাখার নিজের নিয়ম নেই, সে
 *   এটাতেই চলে)।
 */
class PartyAutoController extends Controller
{
    public const TABS = ['rules', 'today', 'optout'];

    private const PER_PAGE = 50;

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly PartyAutoSend $auto,
    ) {}

    public function index(Request $request, PartyRegistry $parties): View
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'rules';
        $now = now();

        PartyMessageTemplate::ensureStarters((int) CompanyContext::id());

        $data = [
            'menu' => $this->menu->forUser($request->user()),
            'tab' => $tab,
            'tabs' => array_map(fn (string $key): array => [
                'key' => $key,
                'label' => __('notification::party_auto.tabs.'.$key),
                'url' => route('notification.party_auto.index', $key === 'rules' ? [] : ['tab' => $key]),
                'active' => $key === $tab,
            ], self::TABS),
        ];

        return view('notification::party.auto', $data + match ($tab) {
            'today' => $this->today($now),
            'optout' => $this->optouts($parties),
            default => $this->rules($request, $now),
        });
    }

    public function save(Request $request): RedirectResponse
    {
        // ⓘ "5,000" যেভাবে মানুষ লেখেন — কমা ফেলে যাচাই
        $request->merge(array_map(
            fn ($v) => is_string($v) ? str_replace([',', ' '], '', $v) : $v,
            $request->only(['due_min', 'paid_min']),
        ));

        $data = $request->validate([
            'due_enabled' => ['required', 'boolean'],
            'due_after' => ['required', Rule::in(PartyAutoSend::AFTER_DAYS)],
            'due_every' => ['required', Rule::in(PartyAutoSend::EVERY_DAYS)],
            'due_min' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'due_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'due_days' => ['required', Rule::in(PartyAutoSend::DAYS)],
            'due_template' => ['nullable', 'integer'],
            'paid_enabled' => ['required', 'boolean'],
            'paid_when' => ['required', Rule::in(PartyAutoSend::WHEN)],
            'paid_min' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'paid_template' => ['nullable', 'integer'],
            'paid_link' => ['required', 'boolean'],
            'quiet' => ['required', 'boolean'],
            'once_a_day' => ['required', 'boolean'],
            'optout' => ['required', 'boolean'],
            'per_branch' => ['required', 'boolean'],
        ], [
            'due_time.regex' => __('notification::party_auto.bad_time'),
        ]);

        // ⛔ বাছা লেখা এই নিয়মেরই আর চালু — অন্য কাগজের বা বন্ধ লেখা নয়
        foreach (['due_template' => PartyMessage::DUE_REMINDER, 'paid_template' => PartyMessage::PAYMENT_RECEIVED] as $field => $rule) {
            $id = (int) ($data[$field] ?? 0);

            if ($id > 0 && ! $this->auto->templatesFor($rule)->whereKey($id)->exists()) {
                throw ValidationException::withMessages([$field => __('notification::party_auto.template_gone')]);
            }
        }

        $perBranch = (bool) $data['per_branch'];

        $this->auto->save([
            PartyAutoSend::DUE_ON => (bool) $data['due_enabled'],
            PartyAutoSend::DUE_AFTER => (int) $data['due_after'],
            PartyAutoSend::DUE_EVERY => (int) $data['due_every'],
            PartyAutoSend::DUE_MIN => (string) $data['due_min'],
            PartyAutoSend::DUE_TIME => (string) $data['due_time'],
            PartyAutoSend::DUE_DAYS => (string) $data['due_days'],
            PartyAutoSend::DUE_TEMPLATE => (int) ($data['due_template'] ?? 0),
            PartyAutoSend::PAID_ON => (bool) $data['paid_enabled'],
            PartyAutoSend::PAID_WHEN => (string) $data['paid_when'],
            PartyAutoSend::PAID_MIN => (string) $data['paid_min'],
            PartyAutoSend::PAID_TEMPLATE => (int) ($data['paid_template'] ?? 0),
            PartyAutoSend::PAID_LINK => (bool) $data['paid_link'],
            PartyAutoSend::QUIET => (bool) $data['quiet'],
            PartyAutoSend::ONCE_A_DAY => (bool) $data['once_a_day'],
            PartyAutoSend::OPTOUT => (bool) $data['optout'],
            PartyAutoSend::PER_BRANCH => $perBranch,
        ], $perBranch ? ViewedBranch::one() : null);

        return redirect()->route('notification.party_auto.index')->with('saved', __('notification::party_auto.saved'));
    }

    /** ⭐ "বাদ রাখা পার্টি"-তে যোগ — পার্টি খোঁজার ঘরের মান "ধরন:id" ([[x-ui.party-search]]) */
    public function optOut(Request $request, PartyRegistry $parties): RedirectResponse
    {
        $data = $request->validate([
            'party' => ['required', 'string', 'regex:/^[a-z_]+:\d+$/'],
            'reason' => ['nullable', 'string', 'max:191'],
        ]);

        [$type, $id] = explode(':', $data['party']);
        $id = (int) $id;

        if (! $parties->knows($type) || ! $parties->exists($type, $id)) {
            throw ValidationException::withMessages(['party' => __('notification::party_auto.party_gone')]);
        }

        if (! PartyMessageOptout::covers($type, $id)) {
            PartyMessageOptout::query()->create([
                'company_id' => CompanyContext::id(),
                'party_type' => $type,
                'party_id' => $id,
                'party_name' => mb_substr((string) ($parties->labelsOf([[$type, $id]])[$type.':'.$id] ?? ''), 0, 191) ?: null,
                'reason' => $data['reason'] ?? null,
                'created_by' => $request->user()?->id,
            ]);
        }

        return redirect()->route('notification.party_auto.index', ['tab' => 'optout'])->with('saved', __('notification::party_auto.optout_added'));
    }

    /** ⭐ তালিকা থেকে সরানো — আবার নিজে থেকে বার্তা পাবেন (কে সরাল, খাতায় থাকে: সারিটা নিরীক্ষিত) */
    public function optIn(PartyMessageOptout $optout): RedirectResponse
    {
        $optout->delete();

        return redirect()->route('notification.party_auto.index', ['tab' => 'optout'])->with('saved', __('notification::party_auto.optout_removed'));
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, Carbon $now): array
    {
        $company = $this->auto->rules(null);
        $branch = $company[PartyAutoSend::PER_BRANCH] ? ViewedBranch::one() : null;
        $rules = $this->auto->rules($branch);
        $dueTemplates = $this->auto->templatesFor(PartyMessage::DUE_REMINDER)->get();
        $paidTemplates = $this->auto->templatesFor(PartyMessage::PAYMENT_RECEIVED)->get();
        $dueChosen = $this->auto->templateFor(PartyMessage::DUE_REMINDER, $rules);
        $paidChosen = $this->auto->templateFor(PartyMessage::PAYMENT_RECEIVED, $rules);
        $today = $now->toDateString();

        return [
            'rules' => $rules,
            'dueTemplates' => $dueTemplates,
            'paidTemplates' => $paidTemplates,
            'dueChosen' => $dueChosen?->id,
            'paidChosen' => $paidChosen?->id,
            'duePreview' => $this->auto->duePreview($now),
            'paidPreview' => $this->auto->paidPreview((bool) $rules[PartyAutoSend::PAID_LINK]),
            'dueCount' => count($this->visible($this->auto->dueList($now))),
            'dueTime' => PartyAutoSend::dueTimeOn($now, $rules)->format('H:i'),
            'paidToday' => $this->auto->todayCounts(PartyMessage::PAYMENT_RECEIVED, $now),
            'logUrl' => $request->user()?->can('notification.party_messages')
                ? route('notification.party_messages.index', ['from' => $today, 'to' => $today, 'rule' => PartyMessage::PAYMENT_RECEIVED])
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function today(Carbon $now): array
    {
        return [
            'rows' => array_map(fn (array $r): array => [
                'name' => $r['name'],
                'phone' => $r['phone'],
                'due' => Money::format($r['due']),
                'oldest' => (string) $r['oldest']['no'],
                'oldest_date' => DateFormat::format(Carbon::parse((string) $r['oldest']['date'])),
                'road' => $r['road'],
                'at' => PartyAutoSend::dueTimeOn($now, $r['rules'])->format('H:i'),
            ], $this->visible($this->auto->dueList($now))),
        ];
    }

    /** @return array<string, mixed> */
    private function optouts(PartyRegistry $parties): array
    {
        $rows = PartyMessageOptout::query()->orderByDesc('id')->paginate(PerPage::of(self::PER_PAGE))->withQueryString();
        $items = collect($rows->items());

        return [
            'optouts' => $rows,
            // ⓘ only this company's people (EveryUserListAsksWhichCompanyTest) — a marker from elsewhere shows as nobody
            'optoutBy' => User::query()->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))->whereIn('id',$items->pluck('created_by')->filter()->unique()->all() ?: [0])->pluck('name', 'id')->all(),
            'optoutNames' => $parties->labelsOf($items->map(fn (PartyMessageOptout $o) => [(string) $o->party_type, (int) $o->party_id])->all()),
            'pickerGroups' => $parties->forPicker(),
        ];
    }

    /**
     * ⓘ মাথায় একটা শাখা বাছা থাকলে কেবল সেই শাখার পার্টি — সব পর্দার নিয়ম (মালিক: মাথার শাখা সব ছাঁকে)।
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function visible(array $rows): array
    {
        $branch = ViewedBranch::one();

        return $branch === null ? $rows : array_values(array_filter($rows, fn (array $r) => $r['branch_id'] === $branch));
    }
}
