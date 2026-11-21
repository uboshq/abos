<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\ContributesToAProfile;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Core\Support\RoleLabel;
use App\Models\AuditTrail;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * ⭐ একজন মানুষের প্রোফাইল — ওয়েবের "আমার প্রোফাইল" আর অ্যাপের "আমি" একই তথ্য এখান থেকে পায় (মালিক, ২০ নভেম্বর ২০২৬:
 *   প্রোফাইলের নমুনা ক/গ-তে "ok"; "অ্যাপ আর ওয়েব — একই API থেকে একই তথ্য")।
 *
 * ── কে কী দেখেন (নমুনার "অন্যের প্রোফাইলে কী দেখা যায়") ─────────────────
 * ⓘ সবাই: নাম, ছবি, মলাট, পদ, কোম্পানি · শাখা।
 * ⛔ লুকানো: ফোন, ইমেইল, কাজের সংখ্যা, লগইন করা ফোন, নিরাপত্তা — কেবল নিজে বা মালিক (super_admin)। মালিক অন্যের পরিচয় আর
 *   কাজের সংখ্যা দেখেন; ফোন আর নিরাপত্তা কেবল নিজের (বের করার বোতাম নিজের জিনিস)।
 * ⓘ সাম্প্রতিক কাজ: যিনি দেখছেন তাঁর নাগালের ([[DrillResolver::resolve()]] নাগাল ধরে খোঁজে) আর দেখার অনুমতির কাগজই কেবল।
 * ⛔ সহকর্মী না হলে (একই চালু কোম্পানির চালু সদস্য নন) প্রোফাইলই নেই — ৪০৪ ([[AvatarService::visibleTo()]], ছবির একই নিয়ম)।
 *
 * ⓘ টাকা আর সংখ্যা ইংরেজি অঙ্কে (মালিক, ১৬ নভেম্বর ২০২৬)। মোট কেবল পাকা কাগজের ([[DocumentStatus::POSTED]]; মালিকের নিয়ম —
 *   খসড়া, অপেক্ষায় বা বাতিল যোগে নয়)।
 */
final class ProfileFacts
{
    public const RECENT_WEB = 6;

    public const RECENT_APP = 4;

    public const MAX_STATS = 3;

    /** সহকর্মীর চোখে লুকানো ঘর */
    public const HIDDEN = ['phone', 'email', 'stats', 'devices', 'security'];

    /** খাতার যে ঘটনাগুলো "আমার কাজ" — দেখা বা ছোট বদল নয়, কাগজে সিদ্ধান্ত */
    /** সংখ্যার চেহারা — ধরন, চিহ্ন, রং (নমুনার ঘ-৬); মান আসে মডিউল থেকে */
    private const STAT_LOOK = [
        'sales_total' => ['sales', 'tag', '#16a34a'],
        'my_sales' => ['sales', 'tag', '#16a34a'],
        'my_orders' => ['orders', 'cart', '#ea580c'],
        'target' => ['target', 'chart', '#4f46e5'],
        'approvals_given' => ['approvals', 'approve', '#e11d48'],
        'vouchers_posted' => ['vouchers', 'doc', '#2563eb'],
        'deposits_matched' => ['deposits', 'deposit', '#7c3aed'],
    ];

    private const ACTIONS =['created', 'confirmed', 'approved', 'rejected', 'cancelled', 'returned_to_draft', 'reopened'];

    public function __construct(
        private readonly DrillResolver $drill,
        private readonly DataScope $scope,
        private readonly AvatarService $avatars,
        private readonly ModuleRegistry $modules,
    ) {}

    /** @var list<ContributesToAProfile>|null */
    private ?array $providers = null;

    /** ⛔ প্রোফাইল খোলে কি না — নিজে, নয়তো একই চালু কোম্পানির চালু সহকর্মী */
    public function opens(User $viewer, User $owner): bool
    {
        if ((int) $viewer->getKey() === (int) $owner->getKey()) {
            return true;
        }

        // ⛔ সহকর্মী এই চালু কোম্পানিতেই — অন্য কোনো কোম্পানিতে একসাথে থাকলেও এখানকার পাতা (কাজ, শাখা) তাঁর নয়; কোম্পানি না থাকলে কেউ নয়
        $company = CompanyContext::id();

        return $company !== null
            && $this->avatars->visibleTo($viewer, $owner)
            && DB::table('company_user')->where('company_id', $company)->where('is_active', true)
                ->where('user_id', $owner->getKey())->exists()
            && DB::table('company_user')->where('company_id', $company)->where('is_active', true)
                ->where('user_id', $viewer->getKey())->exists();
    }

    /** ফোন, ইমেইল, কাজের সংখ্যা — নিজে বা মালিক */
    public function seesPrivate(User $viewer, User $owner): bool
    {
        return (int) $viewer->getKey() === (int) $owner->getKey() || $this->isOwner($viewer);
    }

    /**
     * ⭐ পুরো প্রোফাইল — API-র `GET /me/profile` আর `GET /users/{id}/profile`-এর হুবহু আকার; ওয়েবের পাতাও এটাই আঁকে।
     *
     * @param  bool  $absolute  ছবির ঠিকানা পরম (ফোন) না আপেক্ষিক (ওয়েব)
     * @return array<string, mixed>
     */
    public function for(User $viewer, User $owner, int $recent = self::RECENT_WEB, bool $absolute = false): array
    {
        $self = (int) $viewer->getKey() === (int) $owner->getKey();
        $private = $this->seesPrivate($viewer, $owner);
        $identity = $this->identityOf($owner);

        return [
            'public_id' => (string) $owner->public_id,
            'name' => (string) $owner->name,
            'initial' => $owner->initial(),
            'designation' => $this->designationOf($owner, $identity),
            'company' => $this->companyName(),
            'branch' => $this->branchOf($owner, $self),
            'company_colour' => $this->companyColour(),
            'photo_url' => $absolute ? $owner->avatarApiUrl() : $owner->avatarUrl(),
            'cover_url' => $this->coverUrlOf($owner, $absolute),
            'self' => $self,
            'can_edit' => $self,
            'about' => $private ? $this->about($owner, $identity) : null,
            'month' => $private ? $this->month($owner) : null,
            'recent' => $this->recent($owner, max(1, min(20, $recent))),
            'hidden' => $self ? [] : ($private ? ['devices', 'security'] : self::HIDDEN),
        ];
    }

    // ── পরিচয় ───────────────────────────────────────────────────────────

    /** @return array{phone: ?string, email: ?string, employee_no: ?string, joined_on: ?string, role: ?string, branches: ?string} */
    private function about(User $owner, array $identity): array
    {
        $phone = trim((string) ($owner->mobile ?? '')) ?: trim((string) ($identity['phone'] ?? ''));

        return [
            'phone' => $phone !== '' ? $phone : null,
            'email' => filled($owner->email) ? (string) $owner->email : null,
            'employee_no' => filled($identity['employee_no'] ?? null) ? (string) $identity['employee_no'] : null,
            'joined_on' => filled($identity['joined_on'] ?? null) ? (string) $identity['joined_on'] : null,
            'role' => $this->rolesOf($owner),
            'branches' => $this->branchesOf($owner),
        ];
    }

    /**
     * ⭐ যে মডিউলগুলো প্রোফাইলে কিছু বলে — `module.php` → `profile` ([[ContributesToAProfile]])। ⓘ কোর কারও নাম জানে না।
     *
     * @return list<ContributesToAProfile>
     */
    private function providers(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $out = [];

        foreach ($this->modules->all() as $module) {
            foreach ($module->profile as $class) {
                $out[] = app($class);
            }
        }

        return $this->providers = $out;
    }

    /**
     * ⓘ পরিচয়ের ঘর — প্রথম যে মডিউল একটা ঘর জানে তার উত্তর (HR: পদ, ফোন, কর্মী নম্বর, যোগদান)।
     *
     * @return array<string, ?string>
     */
    private function identityOf(User $owner): array
    {
        $out = [];

        foreach ($this->providers() as $provider) {
            try {
                $facts = $provider->identityOf($owner);
            } catch (\Throwable) {
                // ⓘ মডিউল বন্ধ বা টেবিল নেই — পরিচয়ের ঐ ঘরগুলো খালি, পাতা চলে
                continue;
            }

            foreach ($facts as $key => $value) {
                if (! isset($out[$key]) && $value !== null && $value !== '') {
                    $out[$key] = (string) $value;
                }
            }
        }

        return $out;
    }

    /** @param  array<string, ?string>  $identity */
    private function designationOf(User $owner, array $identity): ?string
    {
        if (filled($identity['designation'] ?? null)) {
            return (string) $identity['designation'];
        }

        // ⓘ কর্মীর খাতা বা পদ না থাকলে প্রথম রোলের নাম (মালিক → "মালিক")
        $role = $this->roleNames($owner)[0] ?? null;

        return $role === null ? null : RoleLabel::for($role);
    }

    /** @return list<string> */
    private function roleNames(User $owner): array
    {
        try {
            return array_values(array_map('strval', $owner->getRoleNames()->all()));
        } catch (\Throwable) {
            return [];
        }
    }

    private function rolesOf(User $owner): ?string
    {
        $names = array_map(fn (string $r) => RoleLabel::for($r), $this->roleNames($owner));

        if ($names === []) {
            return null;
        }

        // ⓘ মালিকের পাশে "সব অনুমতি" — নমুনার "মালিক — সব অনুমতি"
        return implode(', ', $names).(in_array('super_admin', $this->roleNames($owner), true) ? ' — '.__('profile.all_rights') : '');
    }

    private function branchesOf(User $owner): ?string
    {
        try {
            $ids = $this->scope->viewBranchIds($owner);
        } catch (\Throwable) {
            return null;
        }

        if ($ids === null) {
            return __('profile.all_branches');
        }

        $names = Branch::query()->whereIn('id', $ids)->orderBy('id')->get()->map(fn (Branch $b) => $b->name())->filter()->all();

        return $names === [] ? null : implode(', ', $names);
    }

    private function companyName(): ?string
    {
        $company = Company::query()->find(CompanyContext::id());

        return $company?->name();
    }

    /** ⓘ নিজের বেলায় এখন বাছা শাখা; অন্যের বেলায় এই কোম্পানিতে তাঁর নিজের শাখা */
    private function branchOf(User $owner, bool $self): ?string
    {
        $id = $self ? CompanyContext::branchId() : null;

        if ($id === null) {
            $id = DB::table('company_user')->where('company_id', CompanyContext::id())->where('user_id', $owner->getKey())->value('default_branch_id');
        }

        return $id === null ? null : Branch::query()->find($id)?->name();
    }

    /**
     * ⓘ মলাট না থাকলে কোম্পানির রঙের ঢাল। ⚠️ কোম্পানির নিজের রঙের ঘর এখনো নেই (২০ নভেম্বর ২০২৬) — তাই null, আর অ্যাপ ও ওয়েব
     *   নিজের নীল ঢাল আঁকে। ঘরটা এলে এখানে পড়লেই দুই জায়গায় একসাথে বদলাবে (চুক্তির ঘর আগে থেকেই আছে)।
     */
    private function companyColour(): ?string
    {
        return null;
    }

    private function coverUrlOf(User $owner, bool $absolute): ?string
    {
        $method = $absolute ? 'coverApiUrl' : 'coverUrl';

        return method_exists($owner, $method) ? $owner->{$method}() : null;
    }

    // ── এই মাসে আমার কাজ ────────────────────────────────────────────────

    /**
     * ⭐ পদ অনুযায়ী তিনটা সংখ্যা (নমুনার ঘ-৬): মালিক — মোট বিক্রি, দেওয়া অনুমোদন, পোস্ট করা ভাউচার; SR — বিক্রি, অর্ডার, লক্ষ্যের %;
     *   হিসাবরক্ষক — পোস্ট করা ভাউচার, মেলানো জমা। ⓘ যে সংখ্যা পড়া যায় না (মডিউল বন্ধ) সেটা বাদ, পরেরটা আসে।
     *
     * @return array{label: string, stats: list<array{key: string, label: string, value: string, kind: string, icon: string, tone: string}>}
     */
    private function month(User $owner): array
    {
        $from = now()->startOfMonth();
        $to = now()->endOfMonth();

        if ($this->isOwner($owner)) {
            $plan = ['sales_total', 'approvals_given', 'vouchers_posted'];
        } else {
            $plan = [];

            if ($this->may($owner, 'sales.order.create') || $this->may($owner, 'sales.invoice.create')) {
                array_push($plan, 'my_sales', 'my_orders', 'target');
            }

            if ($this->may($owner, 'accounts.voucher.create')) {
                array_push($plan, 'vouchers_posted', 'deposits_matched');
            }

            $plan[] = 'approvals_given';
        }

        $stats = [];

        foreach (array_unique($plan) as $key) {
            if (count($stats) >= self::MAX_STATS) {
                break;
            }

            $stat = $this->stat($key, $owner, $from, $to);

            if ($stat !== null) {
                $stats[] = $stat;
            }
        }

        return [
            'label' => __('profile.month.'.$from->month).' '.$from->year,
            'stats' => $stats,
        ];
    }

    /** @return array{key: string, label: string, value: string, kind: string, icon: string, tone: string}|null */
    private function stat(string $key, User $owner, Carbon $from, Carbon $to): ?array
    {
        $look = self::STAT_LOOK[$key] ?? null;

        if ($look === null) {
            return null;
        }

        [$kind, $icon, $tone] = $look;

        // ⓘ অনুমোদন কোরের নিজের খাতা; বাকি সংখ্যা মডিউল দেয় ([[ContributesToAProfile]]) — যে প্রথম চেনে তার উত্তর
        $value = $key === 'approvals_given' ? $this->approvalsGiven($owner, $from, $to) : $this->fromModules($key, $owner, $from, $to);

        if ($value === null) {
            return null;
        }

        return ['key' => $key, 'label' => __('profile.stat.'.$key), 'value' => $value, 'kind' => $kind, 'icon' => $icon, 'tone' => $tone];
    }

    private function fromModules(string $key, User $owner, Carbon $from, Carbon $to): ?string
    {
        foreach ($this->providers() as $provider) {
            try {
                $value = $provider->stat($key, $owner, $from, $to);
            } catch (\Throwable) {
                // ⓘ মডিউল বন্ধ বা টেবিল নেই — এই সংখ্যাটা বাদ
                continue;
            }

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function approvalsGiven(User $by, Carbon $from, Carbon $to): ?string
    {
        try {
            return number_format(DB::table('approval_decisions')
                ->join('approvals', 'approvals.id', '=', 'approval_decisions.approval_id')
                ->where('approvals.company_id', CompanyContext::id())
                ->where('approval_decisions.user_id', $by->getKey())
                ->where('approval_decisions.decision', 'approved')
                ->whereBetween('approval_decisions.decided_at', [$from, $to])
                ->count());
        } catch (\Throwable) {
            return null;
        }
    }

    private function taka(string $amount): string
    {
        return '৳'.Money::format($amount, 0);
    }

    // ── সাম্প্রতিক কাজ ──────────────────────────────────────────────────

    /**
     * ⭐ যে কাগজে শেষ হাত দিয়েছেন — একটা কাগজ একবার, সর্বশেষ ঘটনাটা। ⛔ যিনি দেখছেন তাঁর নাগালের আর দেখার অনুমতির কাগজই কেবল।
     *
     * @return list<array{no: string, action: string, verb: string, summary: string, at: ?string, ago: string, kind: string, url: ?string}>
     */
    private function recent(User $owner, int $limit): array
    {
        $types = array_flip($this->drill->map());

        $rows = AuditTrail::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->where('user_id', $owner->getKey())
            ->whereIn('action', self::ACTIONS)
            ->whereIn('auditable_type', array_keys($types))
            ->orderByDesc('id')
            ->limit(200)
            ->get(['auditable_type', 'auditable_id', 'action', 'document_no', 'created_at']);

        $out = [];
        $seen = [];

        foreach ($rows as $row) {
            $key = $row->auditable_type.'#'.$row->auditable_id;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $type = $types[$row->auditable_type];

            try {
                $paper = $this->drill->resolve($type, (int) $row->auditable_id);
            } catch (\Throwable) {
                $paper = null;
            }

            if (! $paper instanceof Model || ! $this->mayView($paper)) {
                continue;
            }

            $route = $paper->drillRoute();
            $amount = $paper->getAttribute('total') ?? $paper->getAttribute('amount');
            $kindLabel = __('core.source.'.$type);
            $kindLabel = $kindLabel === 'core.source.'.$type ? '' : (string) $kindLabel;

            $out[] = [
                'no' => (string) ($paper->drillDocumentNo() ?: $row->document_no),
                'action' => $this->did((string) $row->action),
                // ⓘ কাঁচা ঘটনা (created, approved, returned_to_draft …) — চিহ্ন বাছার জন্য; লেখাটা `action`
                'verb' => (string) $row->action,
                'summary' => implode(' · ', array_filter([
                    is_numeric($amount) ? $this->taka((string) $amount) : null,
                    $kindLabel ?: null,
                ])),
                'at' => $row->created_at?->toIso8601String(),
                'ago' => $this->ago($row->created_at),
                'kind' => $type,
                'url' => is_array($route) && isset($route[0]) && \Illuminate\Support\Facades\Route::has((string) $route[0])
                    ? route((string) $route[0], $route[1] ?? [], false)
                    : null,
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** ⓘ নীতি থাকলে তার "দেখা"; না থাকলে নাগালই যথেষ্ট (resolve() নাগাল ধরে খোঁজে) */
    private function mayView(Model $paper): bool
    {
        $viewer = auth()->user();

        if (! $viewer instanceof User) {
            return false;
        }

        return Gate::getPolicyFor($paper) === null || Gate::forUser($viewer)->allows('view', $paper);
    }

    private function did(string $action): string
    {
        $text = __('profile.did.'.$action);

        return $text === 'profile.did.'.$action ? (string) __('profile.did.other') : (string) $text;
    }

    /** "2 ঘণ্টা আগে", "গতকাল", "3 দিন আগে" — ইংরেজি অঙ্কে (Carbon-এর বাংলা অঙ্ক দিত) */
    public function ago(?CarbonInterface $at): string
    {
        if ($at === null) {
            return '';
        }

        $minutes = (int) max(0, $at->diffInMinutes(now()));

        return match (true) {
            $minutes < 1 => (string) __('profile.ago.now'),
            $minutes < 60 => (string) __('profile.ago.minutes', ['n' => $minutes]),
            $minutes < 24 * 60 && $at->isToday() => (string) __('profile.ago.hours', ['n' => intdiv($minutes, 60)]),
            $at->isYesterday() => (string) __('profile.ago.yesterday'),
            $minutes < 30 * 24 * 60 => (string) __('profile.ago.days', ['n' => max(2, (int) $at->copy()->startOfDay()->diffInDays(now()->startOfDay()))]),
            default => $at->format('d-m-Y'),
        };
    }

    private function isOwner(User $user): bool
    {
        try {
            return $user->hasRole('super_admin');
        } catch (\Throwable) {
            return false;
        }
    }

    private function may(User $user, string $ability): bool
    {
        try {
            return $user->can($ability);
        } catch (\Throwable) {
            return false;
        }
    }
}
