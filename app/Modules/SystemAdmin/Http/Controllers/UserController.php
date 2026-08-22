<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Services\DataScope;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
                ->orderBy('name')
                ->paginate(50),
        ]);
    }

    public function create(Request $request): View
    {
        return view('system_admin::user.form', [
            'menu' => $this->menu->forUser($request->user()),
            'user' => new User(['is_active' => true, 'locale' => 'bn']),
            'scopes' => [],
            ...$this->formData(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);

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
        return view('system_admin::user.form', [
            'menu' => $this->menu->forUser($request->user()),
            'user' => $user->load(['roles', 'companies']),
            'scopes' => $this->scopesOf($user),
            ...$this->formData(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);

        $this->assertNotLockingThemselvesOut($request, $user, $data);

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

        $user->companies()->sync($companies);

        $this->applyScopes($user, $data);
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
        UserDataScope::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereIn('company_id', $companyIds)
            ->where('scope_type', UserDataScope::BRANCH)
            ->delete();

        $rows = [];

        foreach ($companyIds as $companyId) {
            foreach ($data['branch_scope'][$companyId] ?? [] as $branchId) {
                $rows[] = [
                    'public_id' => (string) Str::uuid7(),
                    'company_id' => $companyId,
                    'user_id' => $user->id,
                    'scope_type' => UserDataScope::BRANCH,
                    'scope_id' => (int) $branchId,
                    'created_by' => auth()->id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
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
        $ids = UserDataScope::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereIn('company_id', $companyIds)
            ->where('scope_type', UserDataScope::BRANCH)
            ->pluck('scope_id')
            ->all();

        if ($ids === []) {
            return '';
        }

        return Branch::query()
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
             */
            'password' => [$user === null ? 'required' : 'nullable', 'string', 'min:8', 'max:191'],

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
    private function scopesOf(User $user): array
    {
        return UserDataScope::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('scope_type', UserDataScope::BRANCH)
            ->get(['company_id', 'scope_id'])
            ->groupBy('company_id')
            ->map(fn ($rows) => $rows->pluck('scope_id')->map(fn ($id) => (int) $id)->all())
            ->all();
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
        ];
    }
}
