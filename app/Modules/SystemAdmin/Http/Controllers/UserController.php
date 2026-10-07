<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Security\MfaService;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\DataScope;
use App\Core\Services\MenuBuilder;
use App\Core\Services\Ownership;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Core\Support\RoleLabel;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RefuseInactiveAccounts;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ব্যবহারকারী — কে ঢুকতে পারেন, আর কী করতে পারেন।
 *
 * ── কেন এই পর্দাটা ছাড়া গোটা অনুমতি-ব্যবস্থাটাই অপ্রমাণিত ───────────
 * ABOS-এ প্রতিটা রুটে অনুমতির পাহারা আছে, প্রতিটা মেনু সারি অনুমতি ধরে
 * ছাঁকা হয়, আর কোড বলে বিক্রয়কর্মী হিসাবের পর্দা খুলতে পারেন না।
 * কিন্তু চালু সাইটে **একজন non-owner ব্যবহারকারীই ছিল না**, কারণ
 * ব্যবহারকারী বানানোর কোনো পথ ছিল না — কেবল সিডার আর কমান্ড লাইন।
 *
 * HP-র পরীক্ষক দুইবার লিখেছেন তিনি ৪০৩-এর দাবিগুলো যাচাই করতে পারছেন
 * না, কারণ `/system/users` ৪০৪ দেয়। অর্থাৎ পাহারাগুলো ছিল, প্রমাণ ছিল
 * না — আর যে নিরাপত্তা কখনো পরখ করা হয়নি, সেটা নিরাপত্তা নয়, আশা।
 *
 * ── কেন মোছা যায় না ────────────────────────────────────────────────
 * একজন ব্যবহারকারীর নাম প্রতিটা বিলে, প্রতিটা অডিট সারিতে, প্রতিটা
 * লগইনের খাতায় বসে আছে। মুছে ফেললে ওই সব কাগজে "কে করেছিল" প্রশ্নের
 * উত্তর হারায়। নিষ্ক্রিয় করা যায় — তখন আর ঢোকা যায় না, কিন্তু ইতিহাস
 * অক্ষত থাকে।
 */
class UserController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly AuditEngine $audit,
        private readonly Ownership $ownership,

        /* ⓘ আসল ক্ষমতার সারিগুলো মডিউলের ঘোষণা থেকেই — [[effectiveAccess()]]। */
        private readonly ModuleRegistry $modules,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.user.manage')];
    }

    public function index(Request $request): View
    {
        return view('system_admin::user.index', [
            'menu' => $this->menu->forUser($request->user()),
            'users' => User::query()
                ->with(['roles', 'currentBranch', 'companies'])

                /*
                 * ⛔ চলতি কোম্পানির ব্যবহারকারীরাই — ৬ সেপ্টেম্বর ২০২৬।
                 *
                 * ── কী ভাঙা ছিল ─────────────────────────────────────
                 * ছাঁকনিটা ছিলই না। ⚠️ কোম্পানি ৪৬-এ দাঁড়িয়ে একজন প্রশাসক
                 * কোম্পানি ৪৫-এর **প্রতিটা ব্যবহারকারীর নাম, ইমেইল ও ভূমিকা**
                 * দেখতেন — লাইভ সেশনে প্রমাণিত।
                 *
                 * ⓘ [[User]] [[BaseEntity]]-র গ্লোবাল স্কোপ পায় না: সে
                 * `companies` পিভটে ঝোলে, তাই ছাঁকনিটা **হাতে বসাতে হয়** —
                 * আর হাতের কাজ ভুলে যাওয়া যায়।
                 *
                 * ⭐ এই ছাঁচটা রিপোতে **পাঁচ জায়গায় আগে থেকেই আছে** —
                 * CashTill · MoneyTransfer · Location · CounterApproval ·
                 * ApprovalFlow। ⚠️ অর্থাৎ নীতির অভাব নয়, **পৌঁছানোর অভাব**;
                 * আর সেজন্যই [[EveryUserListAsksWhichCompanyTest]] সারাইয়ের
                 * চেয়েও জরুরি।
                 *
                 * ⚠️ CLAUDE.md-তে এটা রুচির কথা নয়: *"বহু-টেন্যান্ট বলেই
                 * টেন্যান্ট বিচ্ছিন্নতা সুবিধা নয়, **আইনি বাধ্যবাধকতা**।"*
                 */
                ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))

                /*
                 * টুলবারের খোঁজা — নাম বা ইমেইল, ১৯ সেপ্টেম্বর ২০২৬।
                 *
                 * ⓘ খোঁজার ঘরটা সব তালিকায় একই জায়গায় বসে, আর যে ঘর কিছুই
                 * খোঁজে না সেটা মৃত বোতাম। তাই ঘরটার সাথে এই ছাঁকনিটাও এল।
                 *
                 * ⚠️ `where(fn …)`-এর ভেতরে — বাইরে `orWhere` বসালে উপরের
                 * কোম্পানির ছাঁকনিটা ভেঙে অন্য কোম্পানির মানুষও মিলে যেত।
                 */
                ->when(trim((string) $request->query('q')) !== '', function ($query) use ($request) {
                    $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $request->query('q'))).'%';

                    $query->where(fn ($inner) => $inner
                        ->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like));
                })
                ->orderBy('name')
                ->paginate(50)
                // ⓘ পাতা বদলালে খোঁজা আর ঘনত্ব হারায় না
                ->withQueryString(),
        ]);
    }

    /**
     * এই ব্যবহারকারী কি চলতি কোম্পানির?
     *
     * ⚠️ **তালিকা ছাঁকলেই যথেষ্ট নয়।** ⓘ `edit(Request $request, User $user)`
     * রুট-মডেল বাইন্ডিং ব্যবহার করে, আর সে **যেকোনো id খুলে দেয়** —
     * তালিকায় নামটা না দেখলেও কেউ ঠিকানা বদলে ঢুকতে পারতেন
     * (`/system/users/68/edit` লাইভে ২০০ ফেরত দিত)।
     *
     * ⛔ **৪০৪, ৪০৩ নয় — ইচ্ছাকৃত।** ⓘ ৪০৩ বলে *"এটা আছে, কিন্তু আপনি
     * পাবেন না"*, আর সেটাই একটা তথ্য: id ৬৮ সত্যিই একজন ব্যবহারকারী।
     * ⚠️ ভিন্ন কোম্পানির কাছে ঐ সারিটার **অস্তিত্বই থাকা উচিত নয়**।
     */
    private function mustBeInThisCompany(User $user): void
    {
        abort_unless(
            $user->companies()->whereKey(CompanyContext::id())->exists(),
            404,
        );
    }

    public function create(Request $request): View
    {
        return view('system_admin::user.form', [
            'menu' => $this->menu->forUser($request->user()),
            'user' => new User(['is_active' => true, 'locale' => 'bn']),
            'scopes' => [],
            'houseScopes' => [],

            /* ⓘ নতুন ব্যবহারকারীর এখনো কোনো ক্ষমতা নেই — ঘরটা আঁকাই হয় না। */
            'effective' => [],
            ...$this->formData(null, $this->companiesWithinReach($request)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $reach = $this->companiesWithinReach($request);
        $data = $this->validated($request, null, $reach);

        $this->assertRolesWithinReach($request, new User, $data);
        $this->assertOwnershipRules(new User, $data);

        $user = DB::transaction(function () use ($data, $reach) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],

                /*
                 * ⚠️ খালি ঘর মানে `null`, খালি স্ট্রিং নয়।
                 *
                 * ⛔ `''` বসলে দুইজনের লগইন নাম "এক" হয়ে যেত আর unique
                 * সূচক দ্বিতীয়জনকে আটকাত — অথচ কেউ কোনো আইডি বসায়ইনি।
                 * ⓘ [[CredentialCheck]]-এর টীকাতেও একই কথা: খালিরা সবাই
                 * NULL, আর NULL কারো সমান নয়।
                 */
                'login_id' => blank($data['login_id'] ?? null) ? null : $data['login_id'],

                'mobile' => $data['mobile'] ?? null,
                'remarks' => $data['remarks'] ?? null,

                'password' => $data['password'],
                'locale' => $data['locale'],
                'is_active' => filter_var($data['is_active'] ?? false, FILTER_VALIDATE_BOOL),
            ]);

            $this->applyAccess($user, $data, $reach);

            return $user;
        });

        return redirect()
            ->route('system_admin.user.index')
            ->with('saved', __('system_admin::message.user_created', ['name' => $user->name]));
    }

    public function edit(Request $request, User $user): View
    {
        $this->mustBeInThisCompany($user);
        $this->authorize('update', $user);

        return view('system_admin::user.form', [
            'menu' => $this->menu->forUser($request->user()),
            'user' => $user->load(['roles', 'companies']),
            'scopes' => $this->scopesOf($user, UserDataScope::BRANCH),
            'houseScopes' => collect(array_keys($this->scopeKinds()))
                ->mapWithKeys(fn (string $t) => [$t => $this->scopesOf($user, $t)])->all(),
            'effective' => $this->effectiveAccess($user),
            ...$this->formData($user, $this->companiesWithinReach($request)),
        ]);
    }

    /**
     * ⭐ আসল ক্ষমতা — মালিকের স্পেক §৮, ২৪ সেপ্টেম্বর ২০২৬।
     *
     * ── ⛔ কেন এটা স্পেকে "সবচেয়ে দরকারি" ────────────────────────────
     * ⓘ স্পেকের কথা: *"সাপোর্টে সবচেয়ে বেশি আসা প্রশ্নটাই এটা — সে কেন
     * এই পাতাটা দেখতে পাচ্ছে না?"*
     *
     * ⚠️ এই পর্দায় আগে রোলের নামগুলো ছিল, কিন্তু **কোন রোল কী দিচ্ছে**
     * তা ছিল না। ⛔ ফলে উত্তর পেতে হলে রোলের পর্দায় গিয়ে একটা একটা করে
     * রোল খুলে ছক মেলাতে হত — চারটা রোল মানে চারটা পর্দা, আর মাথায়
     * যোগ করা।
     *
     * ── ⚠️ প্রতিটা সারির পাশে "কোথা থেকে এল" ─────────────────────────
     * ⓘ স্পেক এটা আলাদা করে দাগিয়ে বলে: *"কেবল ✓/✕ দেখালে প্রশ্নটার
     * উত্তর মেলে না, আর পর্দাটা বানিয়েও লাভ হয় না।"*
     *
     * ── ⓘ যা এখানে **নেই**, আর কেন ───────────────────────────────────
     * স্পেকের সূত্রে *"উত্তরাধিকার"* আর *"অস্থায়ী অনুমতি"*ও আছে। ⛔
     * দুইটার একটাও এখনো বানানো হয়নি, তাই সারিতে ওদের নাম বসালে সেটা
     * মিথ্যা হত — আর এই পর্দাটার একমাত্র কাজই সত্যি বলা।
     *
     * @return list<array{label: string, held: int, all: int, from: list<string>}>
     */
    private function effectiveAccess(User $user): array
    {
        /*
         * ⓘ `setPermissionsTeamId` ছাড়া spatie চলতি কোম্পানির রোল
         * খোঁজে, আর এই পর্দাটা সবসময় চলতি কোম্পানিরই — [[formData()]]
         * একই অনুমান ধরে।
         */
        $byPermission = [];

        foreach ($user->roles as $role) {
            foreach ($role->permissions as $permission) {
                $byPermission[$permission->name][] = RoleLabel::for($role->name);
            }
        }

        /*
         * ⭐ সরাসরি দেওয়া অনুমতিও গোনা হয়।
         *
         * ⚠️ spatie একজনকে রোল ছাড়াও অনুমতি দিতে দেয়। ⛔ ওগুলো বাদ দিলে
         * পর্দাটা বলত *"এটা তার নেই"*, অথচ সে দিব্যি পাতাটা খুলতে
         * পারতেন — আর তখন এই পর্দাটাই মিথ্যাবাদী।
         */
        foreach ($user->getDirectPermissions() as $permission) {
            $byPermission[$permission->name][] = __('system_admin::permission.granted_directly');
        }

        $out = [];

        foreach ($this->modules->all() as $module) {
            $all = 0;
            $held = 0;
            $from = [];

            foreach ($module->permissions as $name) {
                $all++;

                if (! isset($byPermission[$name])) {
                    continue;
                }

                $held++;
                $from = [...$from, ...$byPermission[$name]];
            }

            /* ⓘ যে মডিউলে কিছুই নেই, তার সারিও থাকে — *"অর্থ ✕"*,
             * স্পেকের নমুনায় ঠিক এই সারিটাই। ⛔ বাদ দিলে পর্দাটা
             * "নেই" আর "এমন মডিউলই নেই" আলাদা করতে পারত না। */
            if ($all === 0) {
                continue;
            }

            $out[] = [
                'label' => $module->label(),
                'held' => $held,
                'all' => $all,
                'from' => array_values(array_unique($from)),
            ];
        }

        return $out;
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->mustBeInThisCompany($user);
        $this->authorize('update', $user);

        $reach = $this->companiesWithinReach($request);
        $data = $this->validated($request, $user, $reach);

        $this->assertRolesWithinReach($request, $user, $data);

        $this->assertNotLockingThemselvesOut($request, $user, $data);
        $this->assertSupremeRoleStays($user, $data);
        $this->assertOwnershipRules($user, $data);

        DB::transaction(function () use ($user, $data, $reach) {
            $user->update([
                'name' => $data['name'],
                'email' => $data['email'],

                /* ⓘ কারণটা store()-এ লেখা — খালি ঘর `null`, `''` নয় */
                'login_id' => blank($data['login_id'] ?? null) ? null : $data['login_id'],

                'mobile' => $data['mobile'] ?? null,
                'remarks' => $data['remarks'] ?? null,

                'locale' => $data['locale'],
                'is_active' => filter_var($data['is_active'] ?? false, FILTER_VALIDATE_BOOL),
            ]);

            /*
             * পাসওয়ার্ড কেবল লিখলে বদলায়।
             *
             * খালি ঘরটা "পাসওয়ার্ড মুছে দাও" নয় — নাম শুধরাতে গিয়ে
             * কারও লগইন কেড়ে নেওয়ার কোনো কারণ নেই।
             */
            if (($data['password'] ?? '') !== '') {
                $user->update(['password' => $data['password']]);

                // কী বসল তা নয়, বসেছে সেটাই খাতায় — নাহলে খাতাটাই
                // একটা পাসওয়ার্ডের তালিকা হয়ে যেত
                $this->audit->recordAction($user, 'password_set');
            }

            /*
             * ⛔ নিষ্ক্রিয় করা বা পাসওয়ার্ড বসানো মানে পুরনো চাবিগুলো
             * শেষ — নিরীক্ষা §১.৫, ২৭ সেপ্টেম্বর ২০২৬।
             *
             * আগে এখানে কেবল সারিটা বদলাত; "মনে রাখুন" কুকি আর ফোনের
             * টোকেন আগের মতোই খুলত। ⓘ খোলা ওয়েব সেশনগুলো মোছা যায় না
             * (ড্রাইভার `file`), সেগুলো পরের অনুরোধেই
             * [[RefuseInactiveAccounts]]-এ কাটা পড়ে।
             */
            if (! $user->is_active || ($data['password'] ?? '') !== '') {
                RefuseInactiveAccounts::revokeStandingAccess($user);
            }

            $this->applyAccess($user, $data, $reach);
        });

        /*
         * ⓘ প্রশাসক নিজের পাসওয়ার্ডই বদলালে তাঁর এই সেশনটা থাকে —
         * নাহলে পরের ক্লিকেই তিনি নিজেকেই বের করে দিতেন।
         */
        if (($data['password'] ?? '') !== '' && $request->user()?->is($user)) {
            RefuseInactiveAccounts::keepThisSession($request, $user->fresh());
        }

        return redirect()
            ->route('system_admin.user.index')
            ->with('saved', __('system_admin::message.user_updated', ['name' => $user->name]));
    }

    /**
     * রোল ও কোম্পানির অধিকার বসানো।
     *
     * ── কেন রোল বদলটা আলাদা করে খাতায় ওঠে ───────────────────────────
     * রোল বসে `model_has_roles` টেবিলে, ব্যবহারকারীর নিজের সারিতে নয়।
     * তাই মডেলের অডিট ওটা দেখে না — কেউ কাউকে মালিক বানিয়ে দিলে
     * ইতিহাসে কোনো চিহ্নই থাকত না, অথচ ওটাই সবচেয়ে বড় বদল।
     *
     * @param  array<string, mixed>  $data
     * @param  list<int>  $reach  [[companiesWithinReach()]]
     */
    private function applyAccess(User $user, array $data, array $reach): void
    {
        $before = $user->roles->pluck('name')->sort()->values()->all();
        $after = array_values($data['roles'] ?? []);

        // ⓘ ভূমিকা বসে নিচে — কোম্পানির তালিকা ঠিক হওয়ার পর ([[rolesInEveryCompany()]])

        if ($before !== collect($after)->sort()->values()->all()) {
            $this->audit->recordAction($user, 'roles_changed',
                implode(', ', $before).' → '.implode(', ', $after));
        }

        /*
         * কোন কোম্পানিতে ঢুকতে পারবেন, আর কোন শাখায় বসবেন।
         *
         * কোম্পানি না দিলে ব্যবহারকারী লগইন করে একটা খালি পর্দা পেতেন
         * আর বুঝতে পারতেন না কেন — তাই অন্তত একটা লাগে (নিয়ম নিচে
         * `validated()`-এ)।
         */
        $companies = [];

        foreach ($data['companies'] ?? [] as $companyId) {
            $branchId = $data['default_branch'][$companyId] ?? null;

            $companies[(int) $companyId] = [
                'default_branch_id' => $branchId !== null && $branchId !== '' ? (int) $branchId : null,
                'is_active' => true,
            ];
        }

        /*
         * ⛔ কোম্পানির অধিকারের বদলও খাতায় — ১২ সেপ্টেম্বর ২০২৬।
         *
         * ── কী ভাঙা ছিল ─────────────────────────────────────────────
         * রোল আর দেখার সীমা — দুইটাই আলাদা করে লেখা হত, আর দুইটার
         * কারণ **হুবহু এক**: বদলটা ব্যবহারকারীর নিজের সারিতে ঘটে না,
         * ঘটে একটা পিভট টেবিলে, তাই মডেলের অডিট সেটা দেখে না।
         *
         * ⚠️ `company_user` ঠিক একই ধরনের পিভট, অথচ সে বাদ পড়ে
         * গিয়েছিল। ফলে কাউকে একটা **নতুন প্রতিষ্ঠানের ভেতরে ঢুকিয়ে
         * দেওয়া** যেত কোনো চিহ্ন না রেখে — বা উল্টোটা, কাউকে বের করে
         * দেওয়া।
         *
         * ── ⭐ কেন এটা রোল বদলের চেয়ে কম নয় ─────────────────────────
         * ⓘ বহু-কোম্পানি ব্যবস্থায় রোল বলে *কী করতে পারেন*, আর এই
         * সারিটা বলে *কার বই-খাতায়*। ⚠️ একজন সেলসম্যানকে দ্বিতীয়
         * কোম্পানিতে জুড়ে দিলে তাঁর রোল এক থাকে, কিন্তু তিনি একটা
         * সম্পূর্ণ ভিন্ন প্রতিষ্ঠানের সংখ্যা দেখতে শুরু করেন — আর
         * CLAUDE.md-তে টেন্যান্ট বিচ্ছিন্নতা **আইনি বাধ্যবাধকতা**।
         *
         * ── কেন আইডি নয়, কোড ─────────────────────────────────────────
         * `scopeSummary()`-তে যে কারণটা লেখা, সেটাই এখানে: ছয় মাস পরে
         * খাতা পড়তে গিয়ে "৪৫, ৪৬" কিছুই বলে না।
         *
         * ── কেন ডিফল্ট শাখা এই সারিতে নেই ───────────────────────────
         * ⓘ ওটা অধিকার নয়, **কার্সার কোথায় বসবে** — আর মানুষ কী
         * দেখতে পাবেন সেটা `scopes_changed` আলাদা করে লেখে। দুইটা
         * একসাথে লিখলে শাখা বদলের সারিতে অধিকার বদলের সারিটা চাপা
         * পড়ত।
         *
         * ⚠️ তালিকা দুইটা **কোয়েরি করে** নেওয়া হয়, `$user->companies`
         * সম্পর্কটা পড়ে নয়: `edit()` ওটা আগেই লোড করে রাখে, তাই
         * `sync()`-এর পরেও সেটা **পুরনো তালিকাই** ফেরত দিত — অর্থাৎ
         * আগে-পরে সবসময় সমান মনে হত, আর সারিটা কখনো লেখা হত না।
         */
        $beforeCompanies = $this->companyCodesOf($user);

        /*
         * ⛔ কেবল নাগালের কোম্পানিগুলোর সারি — নিরীক্ষা §১.৭, ২৭ সেপ্টেম্বর ২০২৬।
         *
         * আগে এখানে ছিল `sync($companies)`, আর `sync` ফর্মে না-আসা **প্রতিটা**
         * সারি মুছে দেয়। ⚠️ ফর্মে কেবল প্রশাসকের নাগালের কোম্পানিই থাকে,
         * তাই দুই-কোম্পানির একজন কর্মীকে ক থেকে সম্পাদনা করলে তাঁর খ-এর
         * সদস্যপদ নীরবে উঠে যেত — আর নিচের মোছায় খ-এর ভূমিকাও। খ-এর
         * প্রশাসক কিছুই জানতেন না, কর্মী পরদিন তালাবন্ধ।
         *
         * ⭐ এখন: টিক দেওয়াগুলো বসে/হালনাগাদ হয়, আর মোছা যায় কেবল নাগালের
         * ভেতরের যেগুলো টিক পায়নি। নাগালের বাইরের সারিতে একটা বাইটও বদলায় না।
         */
        $user->companies()->syncWithoutDetaching($companies);

        $gone = array_values(array_diff($reach, array_keys($companies)));

        if ($gone !== []) {
            $user->companies()->detach($gone);
        }

        $this->rolesInEveryCompany($user, $after, array_keys($companies), $gone);

        $afterCompanies = $this->companyCodesOf($user);

        if ($beforeCompanies !== $afterCompanies) {
            $this->audit->recordAction($user, 'companies_changed',
                implode(', ', $beforeCompanies).' → '.implode(', ', $afterCompanies));
        }

        $this->applyScopes($user, $data);
    }

    /**
     * ভূমিকা বসে **প্রতিটা টিক দেওয়া কোম্পানিতে**।
     *
     * ── ⛔ মালিকের অভিযোগ, ২১ সেপ্টেম্বর ২০২৬ ─────────────────
     * *"abu kawser manage role er onumoti dewa ache, tar poreo keno
     * dekhabe na"* — ফর্মে দুইটা কোম্পানি (DEM, TCL) আর দুইটা
     * ভূমিকা টিক দেওয়া ছিল। ⛔ তবু লাইভে মেপে দেখা গেল সারি দুইটাই
     * কেবল DEM-এ — আর তিনি দাঁড়িয়ে ছিলেন TCL-এ, তাই পর্দা ফাঁকা।
     *
     * ── ⚠️ কারণ ──────────────────────────────────────
     * অনুমতির ব্যবস্থায় `teams` চালু, আর দলটা কোম্পানি
     * ([[CompanyContext::set()]] প্রতিবার `setPermissionsTeamId()` ডাকে)।
     * তাই একবারের `syncRoles()` লিখত কেবল **প্রশাসক তখন যে কোম্পানিতে
     * বসে আছেন** তার নামে — ফর্মে কয়টা কোম্পানি টিক দেওয়া হলো তাতে
     * কিছু যায়-আসত না।
     *
     * ⚠️ আর পর্দায় লেখা কথাটা সরাসরি **মিথ্যা** ছিল: *"রোল
     * ব্যবহারকারী ধরে বসে, কোম্পানি ধরে নয় — দুই কোম্পানিতে একই
     * অধিকার থাকবে"*। ⭐ এখন কথাটা সত্যি।
     *
     * ── ⓘ দুইটা সূক্ষ্ম বিষয় ─────────────────────────────
     * ① কোম্পানির তালিকাটা **ফর্ম থেকে** নেওয়া, সম্পর্ক থেকে নয় —
     *   `sync()`-এর পরেও `companies()` পুরনো তালিকা ফেরত দেয় (ঠিক
     *   নিচের `$beforeCompanies`-এর মন্তব্যে লেখা ফাঁদটা)।
     * ② যে কোম্পানিতে ভূমিকার সারিটা নেই, সেখানে সেটা বসে যায়,
     *   অনুমতিসহ নকল হয়ে ([[makeSureTheseRolesExistHere()]])।
     *
     * @param  list<string>  $roles
     * @param  list<int>  $companyIds
     * @param  list<int>  $gone  নাগালের ভেতরের যে কোম্পানিগুলোর টিক উঠল
     */
    private function rolesInEveryCompany(User $user, array $roles, array $companyIds, array $gone): void
    {
        $was = CompanyContext::id();

        try {
            foreach ($companyIds as $companyId) {
                setPermissionsTeamId((int) $companyId);

                $this->makeSureTheseRolesExistHere((int) $companyId, $roles, $was);

                $user->unsetRelation('roles')->syncRoles($roles);
            }
        } finally {
            setPermissionsTeamId($was);
        }

        /*
         * ⛔ যে কোম্পানির টিক তুলে নেওয়া হলো, সেখানকার ভূমিকাও যায়।
         *
         * ⚠️ উপরের লুপ কেবল টিক দেওয়া কোম্পানিগুলোতে হাত দেয়, তাই
         * বাদ পড়া কোম্পানির সারিটা এমনিতে **রয়ে যেত**। কাউকে বের
         * করে দিয়ে ছয় মাস পরে আবার ঢোকালে তাঁর পুরনো ক্ষমতা নীরবে
         * ফিরে আসত — কেউ সেটা টিকও দেয়নি।
         */
        /*
         * ⛔ মোছা কেবল নাগালের ভেতরে — নিরীক্ষা §১.৭।
         *
         * আগে ছিল `whereNotIn(টিক দেওয়াগুলো)`, অর্থাৎ **প্রশাসক যে কোম্পানি
         * দেখতেই পান না** সেখানকার ভূমিকাও মুছত। এখন কেবল সেগুলো, যেগুলো
         * তাঁর নাগালে ছিল আর টিক পায়নি।
         */
        if ($gone !== []) {
            DB::table('model_has_roles')
                ->where('model_type', $user->getMorphClass())
                ->where('model_id', $user->getKey())
                ->whereIn('company_id', $gone)
                ->delete();
        }

        /*
         * ⚠️ অনুমতির ক্যাশ চব্বিশ ঘণ্টা ধরে জমে থাকে — না মুছলে
         * "সংরক্ষিত হয়েছে" বলার পরেও পুরনো উত্তরটাই ফিরত।
         */
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * এই কোম্পানিতে ভূমিকাগুলো না থাকলে বসিয়়ে দেওয়া — অনুমতিসহ।
     *
     * ⓘ মেপে দেখা: `accountant` ছিল কেবল এক নম্বর কোম্পানিতে। ⛔ তখন
     * `syncRoles()` সরাসরি ছোঁড়ত: *"There is no role named `accountant`"*।
     *
     * ⚠️ অনুমতি নকল হয় একই নামের পুরনো সারি থেকে — দুই কোম্পানিতে
     * "ম্যানেজার" দুই রকম হলে নামটাই মিথ্যা হয়ে যেত।
     *
     * ⛔ নকলের উৎস **চলতি কোম্পানির** ভূমিকা — নিরীক্ষা §১.৭। আগে
     * `->first()` যেকোনো কোম্পানির একই নামের সারি তুলত, তাই অন্য
     * কোম্পানির নিজের বানানো ভূমিকা অনুমতিসহ এখানে চলে আসতে পারত।
     *
     * @param  list<string>  $roles
     */
    private function makeSureTheseRolesExistHere(int $companyId, array $roles, ?int $source): void
    {
        foreach ($roles as $name) {
            if (Role::query()->where('name', $name)->where('company_id', $companyId)->exists()) {
                continue;
            }

            $elsewhere = Role::query()->with('permissions')->where('name', $name)
                ->where('company_id', $source)->first();

            if ($elsewhere === null) {
                continue;
            }

            Role::query()->create([
                'name' => $name,
                'guard_name' => $elsewhere->guard_name,
                'company_id' => $companyId,
            ])->syncPermissions($elsewhere->permissions);
        }
    }

    /**
     * এই ব্যবহারকারী এখন কোন কোম্পানিগুলোতে ঢুকতে পারেন — কোড ধরে, সাজানো।
     *
     * ⓘ সাজানো, কারণ তুলনাটা **তালিকার** তুলনা, ক্রমের নয়: ফর্মে
     * টিকের ক্রম বদলালে অধিকার বদলায়নি, আর তখন একটা মিথ্যা সারি বসত।
     *
     * @return list<string>
     */
    private function companyCodesOf(User $user): array
    {
        return $user->companies()->orderBy('code')->pluck('code')->all();
    }

    /**
     * দেখার সীমা — কোম্পানির ভেতরে কোন শাখাগুলো (ভাগ চ, RLS)।
     *
     * ── কেন সারি না থাকা মানে "সব দেখা যায়" ────────────────────────
     * `UserDataScope` নিজে তাই বলে, আর মাইগ্রেশনে কারণটা লেখা: উল্টো
     * ধরলে ফিচারটা চালু হওয়ার মুহূর্তে সবাই অন্ধ হয়ে যেতেন। পর্দাটাও
     * তাই কোনো "সব" ঘর দেখায় না — কিছু না বাছাই মানেই সব।
     *
     * ── কেন `sync()` নয়, মুছে-বসানো ─────────────────────────────────
     * এটা সম্পর্ক নয়, তিনটা কলামের সারি (company_id, scope_type,
     * scope_id)। এক কোম্পানির সীমা বদলাতে গিয়ে অন্য কোম্পানিরটা
     * মুছে ফেলা যাবে না, তাই মোছার কোয়েরিটা কেবল যে কোম্পানিগুলো
     * ফর্মে এসেছে তাদের মধ্যেই সীমাবদ্ধ।
     *
     * ── কেন খাতায় আলাদা করে ওঠে ─────────────────────────────────────
     * সারিগুলো ব্যবহারকারীর নিজের সারিতে নয়, আলাদা টেবিলে — রোলের
     * মতোই। ফলে ব্যবহারকারীর অডিট এটা দেখে না, অথচ "কে কার দেখার
     * সীমা তুলে দিল" প্রশ্নটা রোল বদলের মতোই বড়।
     *
     * @param  array<string, mixed>  $data
     */
    private function applyScopes(User $user, array $data): void
    {
        $companyIds = collect($data['companies'] ?? [])->map(fn ($id) => (int) $id)->all();

        if ($companyIds === []) {
            return;
        }

        $before = $this->scopeSummary($user, $companyIds);

        /*
         * `withoutGlobalScopes()` — এই পর্দা কোম্পানির বাইরে কাজ করে।
         *
         * একজন ব্যবহারকারী একাধিক কোম্পানিতে থাকতে পারেন, আর সিস্টেম
         * অ্যাডমিন সবগুলোর সীমা একসাথে বসান। টেন্যান্ট স্কোপ চালু
         * থাকলে কেবল চলতি কোম্পানিরটা মুছত ও বসত, আর বাকিগুলো নীরবে
         * পুরনো থেকে যেত।
         */
        /*
         * দুই ধরনের সীমা একসাথে — শাখা আর গুদাম।
         *
         * ── কেন একই পদ্ধতিতে ────────────────────────────────────────
         * দুইটার নিয়ম হুবহু এক: কিছু না বাছলে সীমা নেই, বাছলে কেবল
         * বাছাইগুলো, আর কেবল সেই কোম্পানিগুলোতে যেগুলোতে মানুষটা
         * ঢুকতে পারেন। আলাদা দুইটা পদ্ধতি লিখলে একদিন একটায় নিয়ম
         * বদলাত আর অন্যটায় থাকত না — আর ফলটা হত "বেশি দেখা", কোনো
         * ভুল বার্তা ছাড়াই।
         */
        $kinds = [UserDataScope::BRANCH => 'branch_scope'];

        foreach (array_keys($this->scopeKinds()) as $type) {
            $kinds[$type] = $type.'_scope';
        }

        UserDataScope::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereIn('company_id', $companyIds)
            ->whereIn('scope_type', array_keys($kinds))
            ->delete();

        $rows = [];

        foreach ($companyIds as $companyId) {
            foreach ($kinds as $type => $field) {
                foreach ($data[$field][$companyId] ?? [] as $scopeId) {
                    $rows[] = [
                        'public_id' => (string) Str::uuid7(),
                        'company_id' => $companyId,
                        'user_id' => $user->id,
                        'scope_type' => $type,
                        'scope_id' => (int) $scopeId,
                        'created_by' => auth()->id(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        if ($rows !== []) {
            UserDataScope::query()->insert($rows);
        }

        $after = $this->scopeSummary($user, $companyIds);

        if ($before !== $after) {
            $this->audit->recordAction($user, 'scopes_changed',
                ($before ?: __('system_admin::message.scope_none')).' → '
                .($after ?: __('system_admin::message.scope_none')));
        }

        /*
         * ক্যাশটা অনুরোধ-জীবনকালের, আর এই অনুরোধেই সীমা বদলেছে।
         *
         * না ভুললে সেভ করার ঠিক পরের রিডাইরেক্টে পুরনো সীমা খাটত —
         * অর্থাৎ নিজের সীমা বসিয়ে সেভ করলে পর্দা এখনো সব দেখাত, আর
         * ব্যবহারকারী ভাবতেন সেভ হয়নি।
         */
        app(DataScope::class)->forget();
    }

    /**
     * সীমাটা এক লাইনে — খাতায় লেখার জন্য।
     *
     * আইডি নয়, শাখার কোড: ছয় মাস পরে অডিট পড়তে গিয়ে "৪, ৭" কিছুই
     * বলে না, আর ততদিনে শাখাটার নাম বদলে থাকতে পারে।
     *
     * @param  list<int>  $companyIds
     */
    private function scopeSummary(User $user, array $companyIds): string
    {
        return trim(implode(' · ', array_filter([
            $this->summaryOf($user, $companyIds, UserDataScope::BRANCH, Branch::class),
            ...array_map(
                fn (string $type) => $this->summaryOf(
                    $user, $companyIds, $type, $this->scopeKinds()[$type]['model'],
                ),
                array_keys($this->scopeKinds()),
            ),
        ])));
    }

    /**
     * এক ধরনের সীমা এক লাইনে — কোড ধরে, আইডি ধরে নয়।
     *
     * @param  list<int>  $companyIds
     * @param  class-string<Model>  $model
     */
    private function summaryOf(User $user, array $companyIds, string $type, string $model): string
    {
        $ids = UserDataScope::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereIn('company_id', $companyIds)
            ->where('scope_type', $type)
            ->pluck('scope_id')
            ->all();

        if ($ids === []) {
            return '';
        }

        return $model::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->orderBy('code')
            ->pluck('code')
            ->implode(', ');
    }

    /**
     * নিজের পায়ে কুড়াল নয়।
     *
     * ── কেন এই দুইটা বাধা ───────────────────────────────────────────
     * যিনি এই পর্দাটা খুলতে পারেন তিনিই একমাত্র ব্যক্তি যিনি ভুলটা
     * শোধরাতে পারতেন। নিজের অধিকার নিজে কেড়ে নিলে ফেরার পথ কেবল
     * কমান্ড লাইন — আর ঠিক সেই অবস্থাটা থেকে বাঁচতেই এই পর্দাটা
     * বানানো হয়েছে।
     *
     * @param  array<string, mixed>  $data
     */
    /**
     * মালিক একজনই — আর এই পর্দাটাই সেই নিয়মের সবচেয়ে ব্যস্ত দরজা।
     *
     * ── কেন নিয়মটা এখানেও, যদিও `Ownership`-এ লেখা আছে ────────────────
     * নিয়মটার সংজ্ঞা এক জায়গায় (`Ownership`), কিন্তু **প্রয়োগ** প্রতিটা
     * দরজায় বসাতে হয়। ⓘ `CompanyProvisioner` কোম্পানি বানানোর পথটা
     * পাহারা দেয়; এই পর্দা দিয়েই বাকি সব রোল বদল হয়, তাই এখানে না
     * বসালে পাহারাটা কেবল ঐ পথগুলো দেখত যেগুলো দিয়ে কেউ যায় না।
     *
     * ⚠️ `$user->exists` দেখা হয় কারণ `store()` থেকেও এটা ডাকা হয় —
     * তখন মানুষটা এখনো নেই, তাই "ইনি সরে গেলে কেউ থাকে কি না" প্রশ্নটাই
     * ওঠে না; কেবল "দ্বিতীয় মালিক হচ্ছেন কি না" প্রশ্নটা ওঠে।
     *
     * @param  array<string, mixed>  $data
     */
    /**
     * প্রতিটা নতুন ভূমিকা দাতার নিজের ক্ষমতার ভিতরে — [[UserPolicy::grantRole()]]।
     *
     * ⓘ ৪০৩ নয়, ঘরের পাশে বার্তা: মানুষটা ভুল ভূমিকা বেছেছেন, পাতা থেকে
     * তাড়িয়ে দেওয়ার মতো কিছু করেননি।
     *
     * @param  array<string, mixed>  $data
     */
    private function assertRolesWithinReach(Request $request, User $user, array $data): void
    {
        $beyond = Role::query()
            ->whereIn('name', $data['roles'] ?? [])
            // ⓘ চলতি কোম্পানির সারি — অন্য কোম্পানির একই নামের ভূমিকার অনুমতি এখানে অপ্রাসঙ্গিক
            ->where('company_id', CompanyContext::id())
            ->with('permissions')
            ->get()
            ->reject(fn (Role $role) => $request->user()?->can('grantRole', [$user, $role]))
            ->pluck('name');

        if ($beyond->isNotEmpty()) {
            throw ValidationException::withMessages([
                'roles' => __('system_admin::validation.role_beyond_your_own', [
                    'roles' => $beyond->implode(', '),
                ]),
            ]);
        }
    }

    private function assertOwnershipRules(User $user, array $data): void
    {
        $companyId = CompanyContext::id();

        $wantsOwner = in_array(
            PermissionSyncer::SUPER_ADMIN_ROLE,
            $data['roles'] ?? [],
            true,
        );

        /*
         * ⛔ কোম্পানির প্রসঙ্গ না থাকলে **চুপচাপ ছেড়ে দেওয়া যায় না**।
         *
         * রোল বসে কোম্পানি ধরে, তাই প্রসঙ্গ ছাড়া `Ownership`-এর গণনাটা
         * খালি ফিরত — আর খালি গণনা মানে "কোনো মালিক নেই", অর্থাৎ তালাটা
         * ঠিক তখনই খুলে যেত যখন সে কিছু দেখতেই পাচ্ছে না।
         *
         * ⚠️ এটাই আজকের বারবার-শেখা ভুলটার আকার: একটা পাহারা যেটা কিছু
         * না দেখেই পাশ করে। তাই না দেখতে পেলে সে **প্রত্যাখ্যান** করে।
         */
        if ($companyId === null) {
            if ($wantsOwner) {
                throw ValidationException::withMessages([
                    'roles' => __('system_admin::validation.owner_needs_company'),
                ]);
            }

            return;
        }

        if ($wantsOwner) {
            $this->ownership->assertMayBecomeOwner($user, $companyId);
        }

        if ($user->exists) {
            $this->ownership->assertCompanyKeepsAnOwner(
                $user,
                $companyId,
                $wantsOwner,
                filter_var($data['is_active'] ?? false, FILTER_VALIDATE_BOOL),
            );
        }
    }

    /**
     * ⛔ সুপার অ্যাডমিনের রোলটা এই পর্দা দিয়ে তোলা যায় না।
     *
     * ── ⭐ মালিকের কথা, ২৪ সেপ্টেম্বর ২০২৬ ───────────────
     * *"role kokonoi edite kora zabena"* — ⓘ নাম, পদবি, ইমেইল, মোবাইল
     * সব বদলানো যাবে, কেবল রোলটা নয়।
     *
     * ── ⛔ যে ফাঁকটা খোলা ছিল, আর কেন আগের দুইটা পাহারা ধরত না ──
     * ⓘ [[UserController::assertNotLockingThemselvesOut()]] কেবল **নিজেকে**
     * বাঁচায় — সম্পাদক আর সম্পাদিত এক না হলে সে সাথে সাথেই ফিরে যায়।
     * ⓘ [[Ownership::assertCompanyKeepsAnOwner()]] কেবল **শেষ** মালিককে বাঁচায়।
     *
     * ⛔ তাই দুইজন মালিক থাকলে একজন অন্যজনকে এক ক্লিকে নামিয়ে
     * দিতে পারতেন, আর কিছুই আটকাত না।
     *
     * ── ⚠️ পর্দার তালা যথেষ্ট নয় ─────────────────────────
     * ⓘ `disabled` চেকবক্স শুধু চোখকে আটকায়। ⛔ ফর্ম যে কেউ বদলে
     * পাঠাতে পারেন, তাই আসল দেয়ালটা এখানে।
     *
     * ── ⓘ এটা একমুখী দরজা নয় ───────────────────────────
     * নামানোর পথ আছে — মালিকানা হস্তান্তরের পর্দা
     * ([[Ownership::transfer()]]), যে লেনদেনের ভিতরেই গুনে দেখে কোম্পানিটা
     * মালিকহীন হয়নি। ⭐ অর্থাৎ নামানো একটা **হস্তান্তর**, সম্পাদনা নয়।
     *
     * @param  array<string, mixed>  $data
     */
    private function assertSupremeRoleStays(User $user, array $data): void
    {
        /* ⓘ `store()` থেকে ডাকা হয় না — নতুন মানুষের কোনো রোলই নেই */
        if (! $user->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE)) {
            return;
        }

        if (in_array(PermissionSyncer::SUPER_ADMIN_ROLE, $data['roles'] ?? [], true)) {
            return;
        }

        throw ValidationException::withMessages([
            'roles' => __('system_admin::validation.supreme_role_is_locked'),
        ]);
    }

    private function assertNotLockingThemselvesOut(Request $request, User $user, array $data): void
    {
        if ($request->user()?->id !== $user->id) {
            return;
        }

        /*
         * চেকবক্সের "0" একটা স্ট্রিং, আর স্ট্রিংটা সত্য।
         *
         * প্রথম সংস্করণে এখানে `=== false` লেখা ছিল, আর ফর্ম পাঠাত
         * `is_active=0` — অর্থাৎ নিজেকে নিষ্ক্রিয় করার বাধাটা কখনো
         * খাটতই না। টেস্টটা লিখে চালানোর আগে বোঝার উপায়ও ছিল না,
         * কারণ কোডটা পড়তে ঠিকই লাগে।
         */
        if (! filter_var($data['is_active'] ?? false, FILTER_VALIDATE_BOOL)) {
            throw ValidationException::withMessages([
                'is_active' => __('system_admin::validation.cannot_deactivate_yourself'),
            ]);
        }

        $keeps = collect($data['roles'] ?? [])
            ->contains(fn (string $role) => Role::query()->where('name', $role)->first()
                ?->hasPermissionTo('system_admin.user.manage') ?? false);

        if (! $keeps) {
            throw ValidationException::withMessages([
                'roles' => __('system_admin::validation.cannot_drop_your_own_key'),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  list<int>  $reach  [[companiesWithinReach()]]
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?User $user, array $reach): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191',
                Rule::unique('users', 'email')->ignore($user?->id)->whereNull('deleted_at')],

            /*
             * ⭐ লগইন নাম — ২২ সেপ্টেম্বর ২০২৬, মালিকের *"Login ID kothay?"*।
             *
             * ⓘ নিয়মগুলো হুবহু [[ProfileController::updateProfile()]]-এর,
             * আর সেটা ইচ্ছাকৃত: একই ঘর দুই পর্দা থেকে ভরা যায়, তাই দুই
             * জায়গায় দুই নিয়ম থাকলে প্রশাসক এমন একটা আইডি বসাতে পারতেন
             * যেটা ব্যবহারকারী নিজে বসাতে পারতেন না — আর কেউ বলতে পারত
             * না কোনটা ঠিক।
             *
             * ⚠️ `nullable` — বহু ব্যবহারকারীর কোনো লগইন নাম নেই, তাঁরা
             * ইমেইল দিয়ে ঢোকেন। ⛔ `required` দিলে **প্রতিটা পুরনো
             * ব্যবহারকারীর সম্পাদনা আটকে যেত**, এমনকি কেবল মোবাইল নম্বর
             * বদলাতে গেলেও।
             *
             * ⓘ অনন্যতা `deleted_at` ছাড়াই — নরম-মোছা একজনের আইডি
             * পুনর্ব্যবহার করলে [[CredentialCheck]] দুইজনকে পেত।
             */
            'login_id' => ['nullable', 'string', 'min:3', 'max:40',
                'regex:/^[a-z][a-z0-9._-]*$/',
                Rule::unique('users', 'login_id')->ignore($user?->id)],

            /*
             * ⭐ মোবাইল ও মন্তব্য — একই দিনে, একই কারণে।
             *
             * ⚠️ দুইটাই তালিকায় কলাম হিসেবে দেখানো হয়, অথচ ভরার কোনো
             * পথ ছিল না। ⓘ মন্তব্যের কলামটা প্রতিটা সারিতে খালি
             * ছিল — আর ওটাই প্রশ্নটা তুলল।
             *
             * ⓘ মোবাইলের নিয়মটা [[ProfileController]]-এর মতোই ঢিলা
             * (`max:25`): দেশের কোড, ড্যাশ, ফাঁকা — মানুষ যেভাবে লেখেন।
             * ⛔ কড়া ছাঁচ বসালে সঠিক নম্বরও আটকাত, আর ঘরটা তখন
             * না-থাকার চেয়েও খারাপ হত।
             */
            'mobile' => ['nullable', 'string', 'max:25'],
            'remarks' => ['nullable', 'string', 'max:255'],

            /*
             * নতুন ব্যবহারকারীতে পাসওয়ার্ড লাগে, সম্পাদনায় নয়।
             *
             * বারো অক্ষর (২৭ সেপ্টেম্বর ২০২৬ পর্যন্ত আট) — কারণ এই লগইনের পেছনে টাকার খাতা, আর ছোট
             * পাসওয়ার্ড আন্দাজ করা যায়। কী লেখা হলো তা কোথাও দেখানো
             * বা লেখা হয় না; ভুলে গেলে আবার বসাতে হয়।
             *
             * ── কেবল দৈর্ঘ্য যথেষ্ট নয়, ৩১ আগস্ট ২০২৬ ────────────────
             * আগে নিয়ম ছিল শুধু `min:8`, তাই `12345678` বা `password`
             * দুইটাই চলত। লগইনে পাহারা একটাই স্তরের (IP ধরে মিনিটে
             * দশবার), আর অ্যাকাউন্ট ধরে কোনো লক নেই — অর্থাৎ দুর্বল
             * পাসওয়ার্ড ধরার দ্বিতীয় কোনো জাল নেই।
             *
             * অক্ষর **আর** সংখ্যা চাওয়া হয়, বড়-ছোট হরফ নয়: ডিপোর
             * কর্মীরা অনেকে বাংলা কিবোর্ডে টাইপ করেন, আর জটিল নিয়ম
             * বসালে পাসওয়ার্ড কাগজে লেখা শুরু হয় — তখন নিয়মটা
             * নিরাপত্তা বাড়ায় না, কমায়।
             */
            'password' => [
                $user === null ? 'required' : 'nullable',
                'string',
                'max:191',
                /*
                 * ⛔ ১২ অক্ষর আর ফাঁসের তালিকা — নিরীক্ষা, ২৭ সেপ্টেম্বর ২০২৬।
                 * ⓘ আট অক্ষরের অক্ষর-সংখ্যার পাসওয়ার্ড (`password1`) প্রতিটা
                 * আক্রমণ-তালিকার প্রথম পাতায়। `uncompromised()` Have I Been
                 * Pwned-কে কেবল hash-এর প্রথম পাঁচ অক্ষর পাঠায় — পাসওয়ার্ড
                 * কখনো বাইরে যায় না; আর নেট না পেলে Laravel দরজা আটকায় না।
                 * ⚠️ একই নিয়ম পাঁচটা দরজায় — [[AnEightLetterPasswordWasEnoughTest]]
                 * প্রতিটায় আলাদা করে প্রমাণ করে।
                 */
                Password::min(12)->letters()->numbers()->uncompromised(),

                /* ⭐ দ্বিতীয়বার লেখা মিলতে হবে — মালিক, ৩০ সেপ্টেম্বর ২০২৬: "2 bar like confam korlei valo vul hoyna" */
                'confirmed',
            ],

            'locale' => ['required', Rule::in(['bn', 'en'])],
            'is_active' => ['nullable', 'boolean'],

            'roles' => ['required', 'array', 'min:1'],
            /*
             * ⛔ কেবল এই কোম্পানির ভূমিকা — নিরীক্ষা §১.৭। আগে যেকোনো
             * কোম্পানির নাম চলত, আর [[makeSureTheseRolesExistHere()]] খ-এর
             * নিজের ভূমিকা অনুমতিসহ এখানে নকল করে দিত।
             */
            'roles.*' => [Rule::exists('roles', 'name')->where('company_id', CompanyContext::id())],

            'companies' => ['required', 'array', 'min:1'],
            /*
             * ⛔⛔ কেবল **আপনার নিজের** কোম্পানিগুলো — ২১ সেপ্টেম্বর ২০২৬।
             *
             * ── ⚠️ যা ভাঙা ছিল ──────────────────────────────────
             * আগে ছিল কেবল `Rule::exists('companies','id')` — অর্থাৎ
             * সার্ভারের **যেকোনো** কোম্পানি। ⓘ `update()` ঠিকই
             * [[mustBeInThisCompany]] ডাকে, কিন্তু সেটা মাপে **কোন
             * ব্যবহারকারী সম্পাদনা হচ্ছে**, **কোন কোম্পানি যোগ হচ্ছে**
             * তা নয় — দুইটা আলাদা প্রশ্ন, আর কেবল প্রথমটাই করা হত।
             *
             * ⛔ ফল: একজন অ্যাডমিন নিজেকে সম্পাদনা করে অন্য কোম্পানি
             * যোগ করে নিতে পারতেন। ⓘ ক্ষতিটা এখনই পুরো নয় (ভূমিকা
             * কোম্পানি-ভিত্তিক, তাই ঢুকেও অনুমতি থাকত না) — কিন্তু
             * দেয়ালটা ভাঙা, আর অনুমতিহীন খোলা যেকোনো পাতার সাথে
             * জুড়লে এটাই সিঁড়ি।
             *
             * ⭐ নিয়মটা এক লাইনের: যে কোম্পানিতে আপনি নিজে নেই, সেটা
             * আপনি কাউকে দিতেও পারেন না।
             */
            /*
             * ⛔ আর "নিজের কোম্পানি" মানে নাগাল — নিরীক্ষা §১.৭, ২৭ সেপ্টেম্বর ২০২৬।
             * সদস্য হওয়াই যথেষ্ট ছিল, তাই খ-তে সাধারণ সদস্য প্রশাসকও খ-তে
             * ভূমিকা বসাতে পারতেন। ⓘ চুপচাপ বাদ না দিয়ে ৪২২: "সংরক্ষিত" বলে
             * আসলে না করাটা আরেকটা মিথ্যা হত।
             */
            'companies.*' => [
                Rule::exists('companies', 'id'),
                Rule::in($reach),
            ],
            'default_branch' => ['nullable', 'array'],

            /*
             * দেখার সীমা — কোম্পানি প্রতি শাখার আইডির তালিকা।
             *
             * `exists` যাচাই ইচ্ছাকৃতভাবে নেই: শাখায় টেন্যান্ট স্কোপ বসানো,
             * তাই নিয়মটা কেবল চলতি কোম্পানির শাখা চিনত আর অন্য কোম্পানির
             * বৈধ শাখাকেও ভুল বলত। বেঠিক আইডি এলে সারিটা বসে কিন্তু কোনো
             * শাখার সাথে মেলে না — ফল হয় "কিছুই দেখা যায় না", অর্থাৎ
             * বেশি দেখা নয়, কম দেখা।
             */
            'branch_scope' => ['nullable', 'array'],
            'branch_scope.*' => ['nullable', 'array'],
            'branch_scope.*.*' => ['integer'],
            'warehouse_scope' => ['nullable', 'array'],
            'warehouse_scope.*' => ['nullable', 'array'],
            'warehouse_scope.*.*' => ['integer'],
            'default_branch.*' => ['nullable', 'integer'],
        ]);

        $this->assertPlacesBelongToTheirCompany($data, $reach);
        $this->assertScopesWithinMine($data);

        return $data;
    }

    /**
     * শাখা আর গুদাম — যে কোম্পানির ঘরে এসেছে, সেই কোম্পানিরই কি না।
     *
     * ── ⛔ নিরীক্ষা §১.৭, ২৭ সেপ্টেম্বর ২০২৬ ────────────────────────────
     * উপরে `exists` নেই (কারণ সেখানে লেখা), তাই ক-এর ঘরে খ-এর শাখার আইডি
     * পাঠালে সেটা ক-এর সদস্যপদে ডিফল্ট শাখা হয়ে বসত — মানুষটার কার্সার
     * অন্য কোম্পানির শাখায়। ⓘ এখানে টেন্যান্ট স্কোপ সরিয়ে সরাসরি
     * `company_id` মেলানো হয়, তাই "কেবল চলতি কোম্পানি চেনে" সমস্যাটা নেই।
     *
     * ⚠️ নাগালের বাইরের কোম্পানির নামে ঘর এলে সেটাও ৪২২ — ফর্ম সেই ঘর
     * আঁকেই না, তাই সেটা কেবল হাতে বানানো অনুরোধেই আসে।
     *
     * @param  array<string, mixed>  $data
     * @param  list<int>  $reach
     */
    /**
     * ⛔ নিজের সীমার বাইরে কাউকে দেখার সীমা দেওয়া নয় — নিজেকেও নয় (পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, SystemAdmin ⛔৩)।
     *
     * ⓘ আগে কেবল দেখা হত শাখা-গুদাম ঐ কোম্পানির কি না: এক শাখায় সীমিত অ্যাডমিন নিজের সীমা মুছে দিতে পারতেন, অন্যকে
     * সীমাহীন বা নিজের না-দেখা শাখায় বসাতে পারতেন। ⭐ যে কোম্পানিতে আমি শাখা বা গুদামে সীমিত, সেখানে বাছাই কেবল আমার
     * সীমার ভেতরে — আর খালি রাখা ("সীমা নেই") মানেই আমার চেয়ে বেশি, তাই সেটাও নয়। সীমাহীন অ্যাডমিন যেমন ছিলেন।
     *
     * @param  array<string, mixed>  $data
     */
    private function assertScopesWithinMine(array $data): void
    {
        $actor = auth()->user();
        $scope = app(DataScope::class);
        $kinds = [UserDataScope::BRANCH => 'branch_scope'];

        foreach (array_keys($this->scopeKinds()) as $type) {
            $kinds[$type] = $type.'_scope';
        }

        $errors = [];

        foreach ((array) ($data['companies'] ?? []) as $companyId) {
            $companyId = (int) $companyId;

            foreach ($kinds as $type => $field) {
                $mine = CompanyContext::forCompany($companyId, fn () => $scope->idsFor($actor, $type));

                if ($mine === null) {
                    continue;
                }

                $chosen = array_values(array_unique(array_map('intval', array_filter(
                    (array) ($data[$field][$companyId] ?? []),
                    fn ($id) => $id !== null && $id !== '',
                ))));

                if ($chosen === [] || array_diff($chosen, $mine) !== []) {
                    $errors[$field.'.'.$companyId] = __('system_admin::validation.scope_beyond_your_own');
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertPlacesBelongToTheirCompany(array $data, array $reach): void
    {
        $slots = ['default_branch' => Branch::class, 'branch_scope' => Branch::class];

        foreach ($this->scopeKinds() as $type => $spec) {
            $slots[$type.'_scope'] = $spec['model'];
        }

        $errors = [];

        foreach ($slots as $field => $model) {
            foreach ((array) ($data[$field] ?? []) as $companyId => $ids) {
                $ids = array_values(array_unique(array_map('intval', array_filter(
                    (array) $ids,
                    fn ($id) => $id !== null && $id !== '',
                ))));

                if ($ids === []) {
                    continue;
                }

                $belongs = in_array((int) $companyId, $reach, true)
                    && $model::query()->withoutGlobalScopes()
                        ->where('company_id', (int) $companyId)
                        ->whereIn('id', $ids)
                        ->count() === count($ids);

                if (! $belongs) {
                    $errors[$field.'.'.$companyId] = __('system_admin::validation.place_of_another_company');
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * এই ব্যবহারকারীর বসানো সীমা — কোম্পানি ধরে সাজানো।
     *
     * খালি অ্যারে মানে কোনো সীমা নেই, অর্থাৎ সব দেখা যায় — পর্দায়
     * কোনো ঘরে টিক থাকে না, আর সেটাই সঠিক ছবি।
     *
     * @return array<int, list<int>>
     */
    private function scopesOf(User $user, string $type): array
    {
        return UserDataScope::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('scope_type', $type)
            ->get(['company_id', 'scope_id'])
            ->groupBy('company_id')
            ->map(fn ($rows) => $rows->pluck('scope_id')->map(fn ($id) => (int) $id)->all())
            ->all();
    }

    /**
     * শাখা ছাড়া আর কী কী ধরনের সীমা বসানো যায়।
     *
     * শাখাটা কোরের নিজের ধারণা, তাই ওটা আলাদা করে হাতে লেখা।
     * বাকিগুলো মডিউলের — আজ কেবল গুদাম, কাল টেরিটরি হতে পারে, আর
     * তখন এই ফাইলে কিছুই বদলাতে হবে না।
     *
     * @return array<string, array{model: class-string, label: string}>
     */
    private function scopeKinds(): array
    {
        $kinds = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->dataScopes as $type => $spec) {
                $kinds[$type] = $spec;
            }
        }

        return $kinds;
    }

    /**
     * এই প্রশাসক কোন কোম্পানিগুলোর সদস্যপদ আর ভূমিকা ছুঁতে পারেন।
     *
     * ── ⛔ নিরীক্ষা §১.৭, ২৭ সেপ্টেম্বর ২০২৬ ────────────────────────────
     * ⭐ সাধারণ প্রশাসক: কেবল যে কোম্পানিতে দাঁড়িয়ে আছেন। অন্য কোম্পানিতে
     * সদস্য হওয়া মানে সেখানকার মানুষ সামলানোর অধিকার নয়।
     *
     * ⓘ সুপার অ্যাডমিন: আজকের মতোই একাধিক কোম্পানি (মালিকের ২১
     * সেপ্টেম্বরের অভিযোগ — [[rolesInEveryCompany()]]) — তবে কেবল সেগুলো,
     * যেখানে তিনি **নিজেও সুপার অ্যাডমিন**। ⚠️ আগে সদস্য হওয়াই যথেষ্ট
     * ছিল, তাই ক-এর মালিক খ-তে সাধারণ সদস্য হয়েও খ-তে যেকোনো ভূমিকা
     * বসাতে পারতেন ([[UserPolicy::grantRole()]] কেবল ক-এর ক্ষমতা দেখে)।
     *
     * @return list<int>
     */
    private function companiesWithinReach(Request $request): array
    {
        $here = CompanyContext::id();
        $actor = $request->user();

        if ($here === null || $actor === null) {
            return [];
        }

        if (! $actor->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE)) {
            return [$here];
        }

        $owned = DB::table('company_user')
            ->join('model_has_roles', function ($join) {
                $join->on('model_has_roles.model_id', '=', 'company_user.user_id')
                    ->on('model_has_roles.company_id', '=', 'company_user.company_id');
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('company_user.user_id', $actor->getKey())
            ->where('company_user.is_active', true)
            ->where('model_has_roles.model_type', $actor->getMorphClass())
            ->where('roles.name', PermissionSyncer::SUPER_ADMIN_ROLE)
            ->pluck('company_user.company_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique([$here, ...$owned]));
    }

    /**
     * ফর্মের তালিকাগুলো — ভূমিকা, কোম্পানি, শাখা, গুদাম।
     *
     * ⓘ `$user` লাগে কেবল একটা কারণে: যাঁর ইতিমধ্যেই সুপার
     * অ্যাডমিন ভূমিকাটা আছে, তাঁর তালিকায় সেটা থাকতে হবে।
     *
     * @param  list<int>  $reach  [[companiesWithinReach()]]
     * @return array<string, mixed>
     */
    private function formData(?User $user, array $reach): array
    {
        /*
         * ⛔ কেবল নাগালের কোম্পানি — নিরীক্ষা §৩ ও §১.৭, ২৭ সেপ্টেম্বর ২০২৬।
         * আগে সার্ভারের **সব** কোম্পানির নাম, শাখা আর গুদাম এই ফর্মে আসত।
         */
        $companies = Company::query()->whereIn('id', $reach)->orderBy('code')->get();

        return [
            /*
             * ⭐ প্রতিটা ভূমিকা একবার — মালিকের নির্দেশ, ২১ সেপ্টেম্বর ২০২৬।
             *
             * *"ekhane tinti kore roll keno"* — ভূমিকার সারি কোম্পানি
             * ধরে জমা থাকে (`roles.company_id`), তাই প্রতিটা নাম
             * কোম্পানির সংখ্যায় গুণ হয়ে পর্দায় আসত। ⛔ মেপে দেখা:
             * ২৬টা সারি, আলাদা নাম মাত্র ১৪। তিন কোম্পানির লাইভে
             * তিনটা করে — আর দুইটা "Warehouse" দেখতে হুবহু এক।
             *
             * ⓘ সংরক্ষণ হয় **নাম ধরে** (`syncRoles` নাম নেয়), তাই তিনটা
             * সারির যেকোনোটায় টিক দিলে একই ফল — তিনটা দেখানোর দরকারই
             * নেই, আর দেখালে মানুষ ভাবেন তিনটা আলাদা জিনিস।
             *
             * ── ⛔ সুপার অ্যাডমিন এই তালিকায় নেই ───────────────────
             * মালিক: *"সুপার অ্যাডমিন কেন থাকবে? সুপার অ্যাডমিন শুধু
             * একজনেই পাবে"*। ⓘ ওটা গোটা ব্যবস্থার চাবি — বাকি
             * তেরোটার পাশে টিকবক্স হয়ে বসলে দেখতে হয় সাধারণ একটা
             * সিদ্ধান্তের মতো, আর **যে জিনিস দেখতে সাধারণ, সেটা
             * সাবধানে করা হয় না**।
             *
             * ⭐ ওটা দেওয়ার নিজস্ব পাতা আগে থেকেই আছে — মালিকানা
             * হস্তান্তর (`system_admin.ownership.*`), যেখানে কাজটা দেখতেও বড়।
             *
             * ⚠️ যাঁর ইতিমধ্যেই ভূমিকাটা আছে, তাঁর সেটা তালিকায় থাকে —
             * নাহলে তাঁকে সম্পাদনা করলেই টিকটা খসে যেত, আর মালিক
             * নীরবে নিজের চাবি হারাতেন।
             */
            'roles' => Role::query()
                // ⛔ কেবল চলতি কোম্পানির ভূমিকা — অন্য কোম্পানির নিজের বানানো নাম এখানে আসে না (§১.৭)
                ->where('company_id', CompanyContext::id())
                ->orderBy('name')
                ->get()
                ->unique('name')
                ->reject(fn (Role $role) => $role->name === PermissionSyncer::SUPER_ADMIN_ROLE
                    && $user?->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE) !== true)
                ->values(),
            'companies' => $companies,

            /*
             * প্রতিটা কোম্পানির শাখাগুলো — টেন্যান্ট স্কোপ সরিয়ে।
             *
             * শাখায় স্কোপ বসানো, তাই স্কোপ না সরালে কেবল চলতি
             * কোম্পানির শাখাগুলো আসত আর বাকিগুলোর ঘর খালি দেখাত।
             */
            'branches' => $companies->mapWithKeys(fn (Company $company) => [
                $company->id => CompanyContext::forCompany(
                    $company->id,
                    fn () => Branch::query()->orderBy('code')->get(),
                ),
            ]),

            /*
             * গুদামের তালিকাও কোম্পানি ধরে, শাখার মতোই।
             *
             * `withoutGlobalScopes()` লাগে **দুইবার**: টেন্যান্ট স্কোপের
             * জন্য (`forCompany` সেটা সামলায়) আর নিজের গুদাম-স্কোপের
             * জন্য। দ্বিতীয়টা না দিলে যিনি নিজে সীমাবদ্ধ তিনি অন্যের
             * সীমা বসাতে গিয়ে কেবল নিজের গুদামগুলোই দেখতেন — আর
             * বাকিগুলো নীরবে উধাও।
             */
            /*
             * শাখা ছাড়া বাকি সীমাগুলো — মডিউলরা নিজেরা ঘোষণা করে।
             *
             * ── কেন `Warehouse::class` এখানে লেখা নেই ────────────────
             * লিখলে system_admin চিরকাল Inventory ছাড়া চলত না, আর
             * `BoundariesTest` ঠিক সেটাই ধরেছিল (§১৯.৭)। মজুদ মডিউল
             * নিজে `data_scopes`-এ বলে সে গুদামের সীমা দিতে পারে;
             * এই পর্দা কেবল তালিকাটা পড়ে।
             *
             * মজুদ মডিউল না থাকলে তালিকাটা খালি, আর গুদামের ঘরগুলোই
             * বসে না — সেটাই সঠিক আচরণ, কোনো ভুল বার্তা নয়।
             */
            'scopeKinds' => $this->scopeKinds(),

            'scopeChoices' => $companies->mapWithKeys(fn (Company $company) => [
                $company->id => CompanyContext::forCompany(
                    $company->id,
                    fn () => collect($this->scopeKinds())->map(
                        /*
                         * ⛔ হেডারের শাখার দেয়ালও তুলতে হয় ('viewed-branch-warehouse')। মালিক হেডারে
                         * সুপার বেছে থাকলে ADI-র পাঁচ গুদামের একটাই দেখাত, বাকি চারটা উধাও — কাউকে
                         * ওগুলো দেওয়াই যেত না, আর কাউন্টারে লট আসত না (মালিক, ৭ অক্টোবর ২০২৬)।
                         * ⓘ নামটা লেখা, কারণ ট্রেইটের ধ্রুবক সরাসরি পড়া যায় না; যে মডেলে নেই তাতে ক্ষতি নেই।
                         */
                        fn (array $kind) => $kind['model']::query()
                            ->withoutGlobalScopes(['user-warehouse', 'viewed-branch-warehouse'])
                            ->orderBy('code')->get(),
                    ),
                ),
            ]),
        ];
    }

    /**
     * ⭐ অন্য একজনের দুই ধাপ রিসেট — ফোন হারালে।
     *
     * ── ⛔ কেন এটা লাগল ───────────────────────────────
     * সুপার অ্যাডমিনে দুই ধাপ বাধ্যতামূলক হওয়ার পর ফোন হারানো মানে
     * নিজের ব্যবসায় আটকে যাওয়া। ⓘ ⭐ তিনটা পথ, এই ক্রমে:
     *   ১. নিজের উদ্ধার-কোড (বসানোর দিন একবারই দেখানো হয়)
     *   ২. অন্য সুপার অ্যাডমিনের এই রিসেট
     *   ৩. একজনই সুপার অ্যাডমিন হলে সার্ভারে `abos:two-step-reset`
     *
     * ── ⚠️ তিনটা তালা, আর প্রত্যেকটার নিজস্ব কারণ ────────────
     * ⭐ অনুরোধকারীকে সুপার অ্যাডমিন হতে হয় — দুই ধাপ খুলে দেওয়া
     *   একটা তালা খোলা, আর ওটা সাধারণ ব্যবহারকারী-সম্পাদনা নয়।
     * ⭐ নিজেরটা এখান থেকে নয় — নিজেরটা পাসওয়ার্ড দিয়ে নিজের পর্দায়।
     *   ⛔ নাহলে একজন সুপার অ্যাডমিন নিজের তালাটা এক ক্লিকে খুলে
     *   ফেলতেন, আর বাধ্যতামূলক শব্দটার মানে থাকত না।
     * ⭐ কারণ লেখা বাধ্যতামূলক — ছয় মাস পরে *"কেন খোলা হয়েছিল"*
     *   প্রশ্নটার উত্তর এই একটা লাইনই।
     *
     * ⓘ নিরীক্ষার সারিটা রিসেটের **আগে** লেখা হয়। ⚠️ পরে লিখলে
     * মাঝে কিছু ভাঙলে তালা খুলে যেত আর খাতায় কোনো দাগ থাকত না —
     * আর বিনা দাগে খোলা তালাই সবচেয়ে খারাপ ফল।
     */
    /**
     * ⭐ এই ব্যবহারকারীর দুই ধাপ চালু বা বন্ধ — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬।
     *
     * ── ⓘ চালু করা সস্তা, বন্ধ করা নয় ─────────────────────────────────
     * ⭐ চালু করা কেবল একটা ঘর `true` — কারণ লাগে না, কারণ এতে
     * নিরাপত্তা **বাড়ে**। ⓘ পরের অনুরোধেই তিনি বসানোর পর্দায় যাবেন।
     *
     * ⛔ বন্ধ করা উল্টো: ওটা একটা তালা খোলা। ⚠️ তাই কারণ লেখা
     * বাধ্যতামূলক, নিরীক্ষার সারি **আগে** বসে, আর তাঁর বসানো চাবিটাও
     * মুছে যায় — নাহলে পরে আবার চালু করলে পুরনো ফোনের কোড চলত।
     *
     * ── ⚠️ নিজের তালা নিজে খোলা যায় না ────────────────────────────────
     * [[self::resetTwoStep()]]-এর একই নিয়ম, আর একই কারণে: পারলে
     * "বাধ্যতামূলক" শব্দটার কোনো মানে থাকত না।
     * ⓘ নিজের জন্য **চালু** করা অবশ্য চলে — ওটা তালা বসানো, খোলা নয়।
     */
    public function setTwoStep(Request $request, User $user, MfaService $mfa): RedirectResponse
    {
        /*
         * ⛔ চূড়ান্ত অডিট, ৩০ সেপ্টেম্বর ২০২৬ (⛔২): এখানে কোম্পানির যাচাই ছিল না — অন্য কোম্পানির মানুষের তালাও
         * খোলা যেত। ⓘ ৪০৪, [[mustBeInThisCompany()]]-এর একই কারণে।
         */
        $this->mustBeInThisCompany($user);

        $data = $request->validate([
            'required' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'min:5', 'max:500'],
        ]);

        $wanted = (bool) $data['required'];

        if ($wanted) {
            $user->forceFill(['two_step_required' => true])->save();

            return back()->with('status', __('auth.two_step_now_required'));
        }

        // ── ⛔ এখান থেকে নিচে সবটাই তালা খোলার পথ ──────────────────────

        // ⛔ যাঁর খাতা বদলাতে পারি না, তাঁর তালাও খুলি না — প্রতিটা কোম্পানিতে (পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, SystemAdmin ⛔২)
        $this->authorize('update', $user);

        /*
         * ⛔ সুপার অ্যাডমিনের তালা খোলে কেবল আরেকজন সুপার অ্যাডমিন — চূড়ান্ত অডিট (⛔২)। ⚠️ আগে "User Admin" চাবিই
         * যথেষ্ট ছিল, আর তাতে মালিকের দ্বিতীয় তালা খুলে তাঁর ফোনের চাবি মুছে ফেলা যেত। ⓘ [[resetTwoStep()]]-এর
         * একই নিয়ম। ⭐ মালিক নিজে সুপার অ্যাডমিন, তাই তাঁর ক্ষমতা যেমন ছিল তেমন থাকে (৩০ সেপ্টেম্বর: "SURIMPOWER")।
         */
        abort_if(
            $user->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE)
                && ! $request->user()->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE),
            403,
        );

        if ((int) $request->user()->id === (int) $user->id) {
            return back()->withErrors(['reason' => __('auth.two_step_reset_not_self')]);
        }

        if (($data['reason'] ?? '') === '') {
            return back()->withErrors(['reason' => __('auth.two_step_reset_needs_reason')]);
        }

        /*
         * ⓘ দাগটা আগে, তালা পরে। ⚠️ পরে লিখলে মাঝে কিছু ভাঙলে তালা খুলে
         * যেত আর খাতায় কোনো দাগ থাকত না — আর বিনা দাগে খোলা তালাই
         * সবচেয়ে খারাপ ফল।
         */
        $this->audit->recordAction($user, 'two_step_off', $data['reason']);

        $user->forceFill(['two_step_required' => false])->save();

        /* ⭐ বসানো চাবিটাও যায় — নইলে আবার চালু করলে পুরনো ফোন চলত */
        if ($mfa->isOn($user)) {
            $mfa->turnOff($user);
        }

        return back()->with('status', __('auth.two_step_now_off'));
    }

    public function resetTwoStep(Request $request, User $user, MfaService $mfa): RedirectResponse
    {
        $actor = $request->user();

        /* ⛔ অন্য কোম্পানির মানুষের তালা নয় — চূড়ান্ত অডিট (⛔২) */
        $this->mustBeInThisCompany($user);

        abort_unless($actor->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE), 403);
        // ⛔ এক কোম্পানির মালিক অন্য কোম্পানির মালিকের তালা নয় (পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, SystemAdmin ⛔২)
        $this->authorize('update', $user);

        if ((int) $actor->id === (int) $user->id) {
            return back()->withErrors(['reason' => __('auth.two_step_reset_not_self')]);
        }

        $data = $request->validate(
            ['reason' => ['required', 'string', 'min:5', 'max:500']],
            ['reason.required' => __('auth.two_step_reset_needs_reason')],
        );

        $this->audit->recordAction($user, 'two_step_reset', $data['reason']);

        $mfa->turnOff($user);

        return back()->with('status', __('auth.two_step_reset_done'));
    }
}
