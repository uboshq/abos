<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Controllers;

use App\Core\Engines\Assistant\AssistantEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\AssistantDismissal;
use App\Models\AssistantEvent;
use App\Models\User;
use App\Modules\Assistant\Watch\Brief;
use App\Modules\Assistant\Watch\Watch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ কোপাইলটের নিজের পাতা আর খাতা (পূর্ণ পরিকল্পনা 9a6nHmbFk5NF7YB5NbzCC2 — অংশ ১ আর ১২; ধাপ ০ ও ১)।
 *
 * ⓘ পাতা: সকালের সারাংশ (যার যার চাবি ধরে) আর নজর (চার বোর্ডের সমস্যা কেন্দ্র), "এটা ঠিক আছে" কারণসহ।
 *   প্রস্তাব, খসড়া আর প্রশ্ন পরের ধাপে — পাতায় তাদের ট্যাব "আসছে" বলে।
 * ⓘ খাতা: কে কী খুঁজল, কে কী জিজ্ঞেস করল, কোপাইলট কী বলল — কেবল মালিক; ছাঁকা যায়, বদলানো বা মোছা যায় না।
 */
class AssistantController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly AssistantEngine $engine,
    ) {}

    public function today(Request $request, Watch $watch, Brief $brief): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('assistant::today', [
            'menu' => $this->menu->forUser($user),
            'name' => $this->engine->name(),
            'brief' => $this->engine->on('brief') ? $brief->for($user) : null,
            'findings' => $this->engine->on('watch') ? $watch->for($user) : null,
            'dismissed' => AssistantDismissal::query()->with('user:id,name')->where('until', '>=', Carbon::today()->toDateString())->latest('id')->limit(20)->get(),
        ]);
    }

    /**
     * ⭐ "এটা ঠিক আছে" — নজরটা [[AssistantDismissal::DAYS]] দিন চুপ, কারণ লেখা বাধ্যতামূলক; খাতায়ও ওঠে।
     * ⓘ নজরটা এই মানুষের চোখে পড়ার মতো হতে হয় — অন্যের উৎসের নজর চুপ করানো যায় না।
     */
    public function dismiss(Request $request, Watch $watch): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'finding' => ['required', 'string', 'max:80'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $seen = collect($watch->for($user))->firstWhere('id', $data['finding']);
        abort_if($seen === null, 404);

        AssistantDismissal::query()->create([
            'finding' => $data['finding'], 'reason' => trim($data['reason']),
            'until' => Carbon::today()->addDays(AssistantDismissal::DAYS - 1)->toDateString(), 'user_id' => $user->id,
        ]);
        $this->engine->record(AssistantEvent::DISMISS, $user, (string) $seen['title'], [
            'module' => (string) $seen['module'], 'reason' => mb_substr(trim($data['reason']), 0, 255), 'outcome' => $data['finding'],
        ]);

        return redirect()->route('assistant.today')->with('saved', __('assistant::page.dismissed'));
    }

    /** ⭐ খোঁজা ও কোপাইলটের খাতা — কেবল মালিকের চাবি (`assistant.audit`) */
    public function audit(Request $request): View
    {
        $f = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'kind' => ['nullable', Rule::in([...AssistantEvent::KINDS, self::CHANGE, self::LOGIN])],
            'user_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:60'],
            'ip' => ['nullable', 'string', 'max:45'],
        ]);

        $company = (int) CompanyContext::id();
        $members = DB::table('company_user')->where('company_id', $company)->select('user_id');
        $from = isset($f['from']) ? Carbon::parse($f['from'])->startOfDay() : null;
        $to = isset($f['to']) ? Carbon::parse($f['to'])->addDay()->startOfDay() : null;
        $kind = $f['kind'] ?? null;

        /*
         * ⭐ এক সময়-রেখায় তিন খাতা (মালিক, ১১ নভেম্বর ২০২৬: "কে কী করতেছিল, কে কী সার্চ করছে … কার আইডি থেকে কখন, কোথায়, কীভাবে,
         * কোন জায়গা থেকে, সব কিছু অডিটে থাকবে"):
         *   ১. কোপাইলটের খাতা — পাতা খোলা ও নামানো, খোঁজা, প্রশ্ন, নজর, "ঠিক আছে" …
         *   ২. পরিবর্তনের খাতা (audit_trails) — কে কোন কাগজ বা রেকর্ড বানাল, বদলাল, অনুমোদন দিল, পোস্ট করল, মুছল
         *   ৩. লগইনের খাতা (login_history) — কে কখন ঢুকল বা ঢুকতে পারল না, কোথা থেকে
         * ⓘ প্রতিটা সারি: কে (আইডি), কখন, কী, কোন পাতা, ওয়েব না অ্যাপ, ব্রাউজার বা ফোন, IP, জায়গা (লগইনের সময় জানা গেলে)।
         * ⓘ ছাঁকনি তিন খাতাতেই একসাথে খাটে; ধরন বাছলে কেবল সেই খাতা।
         */
        $events = DB::table('assistant_events')->where('company_id', $company)
            ->selectRaw("updated_at as at, user_id, kind, text as what, page, outcome, channel, ip_address, user_agent, branch_id, NULL as place, reason")
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind));
        $changes = DB::table('audit_trails')->where('company_id', $company)
            ->selectRaw("created_at as at, user_id, '".self::CHANGE."' as kind, COALESCE(document_no, label, CONCAT('#', auditable_id)) as what, auditable_type as page, action as outcome, NULL as channel, ip_address, user_agent, branch_id, NULL as place, reason");
        $logins = DB::table('login_history')
            ->where(fn ($q) => $q->where('company_id', $company)->orWhere(fn ($w) => $w->whereNull('company_id')->whereIn('user_id', $members)))
            ->selectRaw("created_at as at, user_id, '".self::LOGIN."' as kind, identifier as what, NULL as page, CASE WHEN succeeded = 1 THEN 'ok' ELSE COALESCE(reason, 'failed') END as outcome, NULL as channel, ip_address, user_agent, NULL as branch_id, place, NULL as reason");

        $parts = match (true) {
            $kind === self::CHANGE => [$changes],
            $kind === self::LOGIN => [$logins],
            $kind !== null => [$events],
            default => [$events, $changes, $logins],
        };

        foreach ($parts as $part) {
            $part->when($from, fn ($q, $d) => $q->where('created_at', '>=', $d))
                ->when($to, fn ($q, $d) => $q->where('created_at', '<', $d))
                ->when($f['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', (int) $u))
                ->when($f['ip'] ?? null, fn ($q, $ip) => $q->where('ip_address', 'like', $ip.'%'));
        }

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        $rows = DB::query()->fromSub($union, 't')
            ->when($f['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('what', 'like', '%'.$s.'%')->orWhere('page', 'like', '%'.$s.'%')))
            ->orderByDesc('at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $people = User::query()->whereIn('id', $members)->orderBy('name')->get(['id', 'name', 'email']);

        // ⓘ পাতার মানুষের পড়ার মতো নাম — মেনুর নামই (ঠিকানা → নাম); না মিললে ঠিকানা
        $menu = $this->menu->forUser($request->user());
        $pages = [];
        foreach ($menu as $module) {
            foreach (collect($module['groups'] ?? [])->flatten(1) as $item) {
                if (! empty($item['url'])) {
                    $pages['/'.ltrim((string) parse_url((string) $item['url'], PHP_URL_PATH), '/')] ??= (string) $module['label'].' › '.(string) ($item['label'] ?? '');
                }
            }
        }

        return view('assistant::audit', [
            'menu' => $menu,
            'name' => $this->engine->name(),
            'rows' => $rows,
            'filters' => $f,
            'people' => $people,
            'pages' => $pages,
            'who' => $people->keyBy('id'),
            'kinds' => [...AssistantEvent::KINDS, self::CHANGE, self::LOGIN],
            'branches' => \App\Models\Branch::query()->get()->mapWithKeys(fn ($b) => [$b->id => $b->name()]),
        ]);
    }

    /** ⓘ পরিবর্তনের খাতা আর লগইনের খাতার সারি — সময়-রেখায় নিজের নামে */
    public const CHANGE = 'change';

    public const LOGIN = 'login';
}
