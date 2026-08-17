<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
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
            'default_branch.*' => ['nullable', 'integer'],
        ]);
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
