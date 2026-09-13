<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\DataScope;
use App\Core\Services\MenuBuilder;
use App\Core\Services\Ownership;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
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
                ->with(['roles', 'companies'])

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
                ->orderBy('name')
                ->paginate(50),
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
            ...$this->formData(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);

        $this->assertOwnershipRules(new User, $data);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'locale' => $data['locale'],
                'is_active' => filter_var($data['is_active'] ?? false, FILTER_VALIDATE_BOOL),
            ]);

            $this->applyAccess($user, $data);

            return $user;
        });

        return redirect()
            ->route('system_admin.user.index')
            ->with('saved', __('system_admin::message.user_created', ['name' => $user->name]));
    }

    public function edit(Request $request, User $user): View
    {
        $this->mustBeInThisCompany($user);

        return view('system_admin::user.form', [
            'menu' => $this->menu->forUser($request->user()),
            'user' => $user->load(['roles', 'companies']),
            'scopes' => $this->scopesOf($user, UserDataScope::BRANCH),
            'houseScopes' => collect(array_keys($this->scopeKinds()))
                ->mapWithKeys(fn (string $t) => [$t => $this->scopesOf($user, $t)])->all(),
            ...$this->formData(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->mustBeInThisCompany($user);

        $data = $this->validated($request, $user);

        $this->assertNotLockingThemselvesOut($request, $user, $data);
        $this->assertOwnershipRules($user, $data);

        DB::transaction(function () use ($user, $data) {
            $user->update([
                'name' => $data['name'],
                'email' => $data['email'],
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

            $this->applyAccess($user, $data);
        });

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
     */
    private function applyAccess(User $user, array $data): void
    {
        $before = $user->roles->pluck('name')->sort()->values()->all();
        $after = array_values($data['roles'] ?? []);

        $user->syncRoles($after);

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

        $user->companies()->sync($companies);

        $afterCompanies = $this->companyCodesOf($user);

        if ($beforeCompanies !== $afterCompanies) {
            $this->audit->recordAction($user, 'companies_changed',
                implode(', ', $beforeCompanies).' → '.implode(', ', $afterCompanies));
        }

        $this->applyScopes($user, $data);
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
    private function validated(Request $request, ?User $user): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191',
                Rule::unique('users', 'email')->ignore($user?->id)->whereNull('deleted_at')],

            /*
             * নতুন ব্যবহারকারীতে পাসওয়ার্ড লাগে, সম্পাদনায় নয়।
             *
             * আট অক্ষর — কারণ এই লগইনের পেছনে টাকার খাতা, আর ছোট
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
                Password::min(8)->letters()->numbers(),
            ],

            'locale' => ['required', Rule::in(['bn', 'en'])],
            'is_active' => ['nullable', 'boolean'],

            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::exists('roles', 'name')],

            'companies' => ['required', 'array', 'min:1'],
            'companies.*' => [Rule::exists('companies', 'id')],
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
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        $companies = Company::query()->orderBy('code')->get();

        return [
            'roles' => Role::query()->orderBy('name')->get(),
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
                        fn (array $kind) => $kind['model']::query()
                            ->withoutGlobalScope('user-warehouse')
                            ->orderBy('code')->get(),
                    ),
                ),
            ]),
        ];
    }
}
