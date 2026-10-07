<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Services\DataScope;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "আমি কে, আর আমি কী দেখব" — ফোনের প্রথম প্রশ্ন।
 *
 * ── কেন এটা লাগল ────────────────────────────────────────────────────
 * মালিকের সিদ্ধান্ত (৪ সেপ্টেম্বর ২০২৬): **নয়টা রোল, একটাই অ্যাপ** —
 * সুপার অ্যাডমিন থেকে কর্মী, সবাই একই অ্যাপ ব্যবহার করবেন, আর অ্যাপটা
 * রোল অনুযায়ী আচরণ করবে।
 *
 * ফোন আজ লগইন করতে পারে আর সিঙ্ক করতে পারে, কিন্তু **জিজ্ঞেস করতে
 * পারে না "আমি কে"** — তাই অ্যাপের পর্দা কী দেখাবে সেটা আজ পর্যন্ত
 * অ্যাপের ভিতরে হাতে লেখা ছাড়া উপায় ছিল না।
 *
 * ⭐ ইঞ্জিনটা নতুন নয়: ওয়েব আজ যে [[MenuBuilder::forUser()]] দিয়ে
 * সাইডবার আঁকে, এই দরজাটা সেটাই ব্যবহার করে। **দুইটা জায়গায় দুইটা
 * মেনু-নিয়ম থাকলে একদিন একটায় নতুন সারি যোগ হত, অন্যটায় না।**
 *
 * ⚠️ ── এটা নিরাপত্তা নয়, সুবিধা ──────────────────────────────────────
 * এই উত্তরটা যায় ফোনের ভিতরে, আর ফোনটা ব্যবহারকারীর হাতে। **কোনো
 * দরজার পাহারা এর উপর দাঁড়ায় না** — প্রতিটা দরজা নিজে নিজের অনুমতি
 * দেখে, ওয়েবে আজ যেমন দেখে। এখানে যা যায় তা কেবল "কী দেখানো যাবে",
 * "কী করা যাবে" নয়।
 *
 * ⛔ ── এই দরজা কেবল কর্মীর, আর সেটা ইচ্ছাকৃত সীমা ─────────────────────
 * **গ্রাহক ও সরবরাহকারী এখানে ঢোকেন না, আর ঢোকানোর চেষ্টাও করবেন না।**
 * মালিকের সিদ্ধান্ত (৪ সেপ্টেম্বর ২০২৬): *"সরু পথ রাখ"* — তাঁদের
 * কর্মীর মতো পূর্ণ পরিচয় (রোল, অনুমতির তালিকা, গোটা মেনু) দেওয়া হবে
 * না; তাঁরা পাবেন নিজের জিনিসের একটা সরু পথ — গ্রাহক নিজের বকেয়া ও
 * অর্ডার, সরবরাহকারী নিজের বকেয়া ও বিল।
 *
 * ⚠️ **এটা "এখনো বানানো হয়নি" নয় — এটা নকশা।** ছয় মাস পরে কেউ যেন
 * "ভুলে বাদ পড়েছে" ভেবে এখানে `Customer` ঢোকানোর চেষ্টা না করেন।
 *
 * ⓘ কাঠামোও সেভাবেই দাঁড়িয়ে আছে, আর সেটা মেপে দেখা:
 *
 *   Customer   `HasRoles` নেই, `Authorizable` নেই → রোল ও অনুমতিই নেই
 *   Supplier   `AuthenticatableContract`-ই নেই → লগইন করতে পারেন না
 *   login()    `CredentialCheck::verify()` **User** ফেরত দেয়
 *
 * অর্থাৎ কোডটা আজ ঠিক ওই সীমাটাই মানে — টাইপ-স্তরে। গ্রাহক ঢোকেন
 * ওয়েব-পোর্টালের নিজের গার্ডে (`portal`), আর সেটাই থাকবে।
 */
class MeController extends Controller
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly PhoneModules $phone,
        private readonly DataScope $scope,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $this->identity($user),
            'company' => $this->company(),
            'branch' => $this->branch(),

            /*
             * ⭐ কোথায় কোথায় যাওয়া যায় — ফোনের কোম্পানি/শাখা বাছাইয়ের তালিকা (১ অক্টোবর ২০২৬)।
             *
             * ⓘ বদলানোর দরজা [[WorkspaceApiController]]; তালিকা আর দরজা একই নিয়ম মানে —
             * সদস্যপদ ([[User::companies()]]) আর শাখার নাগাল ([[DataScope]]), ওয়েবের
             * হেডারের মতোই ([[ShellFacts]])। ⛔ ক্রমিক আইডি নয়, কেবল `public_id`।
             */
            'companies' => $this->companies($user),
            'branches' => $this->branches($user),
            'viewAllBranches' => (bool) ($user->view_all_branches ?? true),

            /*
             * ⭐ এই কোম্পানির ফোনে কোন মডিউল চালু — [[PhoneModules]]।
             * ⓘ মেনু এমনিতেই ছাঁকা; তালিকাটা যায় যাতে অ্যাপ মেনুর বাইরের নিজের
             * টাইল আর গভীর লিংকও একই সুইচে আটকাতে পারে।
             */
            'phoneModules' => $this->phone->onCodes(),

            /*
             * ⭐ কোম্পানি DO ছেড়ে বিক্রয় আদেশে চলে কি না (`sales.orders_replace_do`; DO বিক্রয় আদেশে মেশানো, ধাপ ১০,
             * ৫ অক্টোবর ২০২৬) — অ্যাপ তখন DO-র পর্দায় আদেশের দরজায় লেখে (`/sales/orders`)। ⓘ পুরনো অ্যাপ এটা পড়ে না।
             */
            'ordersReplaceDo' => (bool) app(SettingsService::class)->get('sales.orders_replace_do', false),

            /*
             * ⭐ ফোনে টাকা আদায় লেখা যায় কি না — কেবল অফিসের লোক (মালিক, ৭ অক্টোবর ২০২৬: "এটা কেবল অফিসের লোকদের
             * জন্য … ফিল্ডের জন্য deposit request")। ⓘ আদায়ের চাবি SR-দেরও আছে (জমার অনুরোধের জন্য), তাই দরজা অফিসের চাবিতে:
             * খাতায় টাকা তোলার চাবি (`accounts.voucher.create` — হিসাবরক্ষক আর মালিকের, মাঠের নয়), আর ডিলারে বাঁধা নন।
             * ⓘ পুরনো অ্যাপ এটা পড়ে না।
             */
            'mayCollect' => \App\Modules\Sales\Sync\CollectionSync::officeMayCollect($user),

            /*
             * ⭐ ফোনের পর্দা আড়াল আর উইজেটের অঙ্ক — মালিকের দুই সুইচ (সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬; [[PhoneModules]])।
             * ⓘ পুরনো অ্যাপ পড়ে না; নতুন অ্যাপ না পেলে আড়াল চালু আর অঙ্ক লুকানো ধরে।
             */
            'secureScreens' => (bool) app(SettingsService::class)->get(\App\Core\Services\PhoneModules::SECURE_SCREENS, true),
            'widgetAmounts' => (bool) app(SettingsService::class)->get(\App\Core\Services\PhoneModules::WIDGET_AMOUNTS, false),

            /*
             * কার্যকর অনুমতির তালিকা — রোলের নাম নয়, চাবিগুলো।
             *
             * ⓘ অ্যাপ "এই বোতামটা দেখাব কি না" প্রশ্নের উত্তর চায়, আর
             * সেটা চাবি দেখে হয়, রোলের নাম দেখে নয়। রোল দেখালে অ্যাপে
             * `if (role === 'manager')` লেখা হত, আর একটা কোম্পানি নতুন
             * রোল বানালেই অ্যাপ ভুল বলত — অথচ রোল এখানে **সারি**, কোড
             * নয়।
             */
            'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values(),

            'menu' => $this->menuFor($user),
        ]);
    }

    /**
     * পরিচয় — আর কোনো ক্রমিক আইডি নয়।
     *
     * ⚠️ ভেতরের `id` পাঠানো হয় না। সিঙ্কের কোডে কারণটা লেখা: ক্রমিক
     * আইডি হাতে পেলে কেউ **গুনে ফেলতে পারেন "আমার আগে কতজন ছিল"** —
     * কর্মী কতজন, গ্রাহক কতজন। `public_id` কিছুই বলে না।
     *
     * @return array<string, mixed>
     */
    private function identity(User $user): array
    {
        return [
            'public_id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,

            // রোলের নাম দেখানোর জন্য — সিদ্ধান্তের জন্য নয় (উপরে দেখুন)
            'roles' => $user->getRoleNames()->values(),

            /*
             * ⭐ পদবি আর ছবি — মালিক, ২ অক্টোবর ২০২৬: *"app e profile photo dekhay na, role nadekiye designation dekhabe"*।
             * ⓘ ওয়েবের ফুটারের একই নিয়ম ([[x-shell.statusbar]]): HR-এর কর্মী-রেকর্ডের পদবি ([[FactRegistry]]),
             * আর গ্রুপের মালিকের এক পদবি সব কোম্পানিতে ([[Ownership::groupOwnerTitle()]])। ⛔ পদবি না থাকলে খালি —
             * ফাঁক ভরাতে রোল নয় (মালিক: "namer sate podobi rakbe roll noy")।
             * ⓘ ছবির ঠিকানা পুরো (https://…), ফোন নিজে জুড়ে নিতে পারে না।
             */
            'designation' => $this->designationOf($user),
            'avatar_url' => ($url = $user->avatarUrl()) === null ? null : url($url),
        ];
    }

    private function designationOf(User $user): ?string
    {
        $ownership = app(\App\Core\Services\Ownership::class);

        if ($ownership->isGroupOwner($user) && ($title = $ownership->groupOwnerTitle()) !== null) {
            return $title;
        }

        $value = app(\App\Core\Panels\FactRegistry::class)->forRecord('user', (int) $user->id)[0]->value ?? null;

        return filled($value) ? (string) $value : null;
    }

    /**
     * কোন কোম্পানিতে বসে আছেন।
     *
     * ⓘ প্রসঙ্গটা `ResolveCompanyContext` মিডলওয়্যার আগেই বসিয়ে দেয়,
     * তাই এখানে কেবল পড়া হয়। ওটা না বসলে এই দরজাটাও ভুল কোম্পানির
     * মেনু দিত — আর ভুলটা নীরব হত।
     *
     * @return array<string, mixed>|null
     */
    private function company(): ?array
    {
        $id = CompanyContext::id();

        if ($id === null) {
            return null;
        }

        $company = Company::query()->find($id);

        return $company === null ? null : [
            'public_id' => $company->public_id,
            'code' => $company->code,
            'name' => $company->name(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function branch(): ?array
    {
        $id = CompanyContext::branchId();

        if ($id === null) {
            return null;
        }

        $branch = Branch::query()->withoutGlobalScopes()->find($id);

        return $branch === null ? null : [
            'public_id' => $branch->public_id,
            'code' => $branch->code,
            'name' => $branch->name(),
        ];
    }

    /**
     * মানুষটার সচল কোম্পানিগুলো — ওয়েবের সুইচারের তালিকা ([[ShellFacts::companies()]])।
     *
     * @return list<array<string, mixed>>
     */
    private function companies(User $user): array
    {
        return $user->companies()
            ->where('companies.is_active', true)
            ->orderBy('name_en')
            ->get()
            ->map(fn (Company $company): array => [
                'public_id' => $company->public_id,
                'code' => $company->code,
                'name' => $company->name(),
            ])
            ->values()
            ->all();
    }

    /**
     * চলতি কোম্পানির সচল শাখা, মানুষটার নাগালের ভেতরে — [[ShellFacts::branches()]]-এর নিয়ম।
     *
     * @return list<array<string, mixed>>
     */
    private function branches(User $user): array
    {
        if (CompanyContext::id() === null) {
            return [];
        }

        $allowed = $this->scope->idsFor($user, UserDataScope::BRANCH);

        return Branch::query()
            ->active()
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->ordered()
            ->get()
            ->map(fn (Branch $branch): array => [
                'public_id' => $branch->public_id,
                'code' => $branch->code,
                'name' => $branch->name(),
            ])
            ->values()
            ->all();
    }

    /**
     * মেনু — ওয়েবের সাথে হুবহু একই ইঞ্জিন থেকে।
     *
     * ── ⚠️ URL পাঠানো হয় না, রুটের **নাম** পাঠানো হয় ─────────────────
     * অ্যাপের নিজের পর্দা আছে; সে `sales.order.create` নামটা দেখে
     * নিজের পর্দায় নিয়ে যায়। URL পাঠালে অ্যাপ ওয়েবের ঠিকানার সাথে
     * বাঁধা পড়ত, আর ওয়েবে একটা রুট সরালে অ্যাপ নীরবে ভুল জায়গায়
     * পাঠাত।
     *
     * ── সার্ভার সত্যিটা বলে, ছাঁকে না ────────────────────────────────
     * যে সারির পর্দা অ্যাপে এখনো নেই, সেটা **অ্যাপ নিজে বাদ দেবে**।
     * সার্ভার "অ্যাপে আছে কি না" জানার চেষ্টা করলে প্রতিটা নতুন
     * পর্দায় সার্ভার বদলাতে হত — আর দুইটা তালিকা একদিন অমিল হত।
     *
     * ⓘ `planned` সারিগুলোও যায়, পতাকাসহ — অ্যাপ চাইলে "শীঘ্রই" লিখে
     * দেখাতে পারে, ওয়েব আজ যেমন দেখায়।
     *
     * @return list<array<string, mixed>>
     */
    private function menuFor(User $user): array
    {
        return array_map(
            fn (array $module): array => [
                'code' => $module['code'],
                'label' => $module['label'],
                'section' => $module['section'],
                'order' => $module['order'],
                'groups' => array_map(
                    fn (array $rows): array => array_map(
                        fn (array $row): array => [
                            'label' => $row['label'],
                            'route' => $row['route'],
                            'planned' => $row['planned'],
                        ],
                        $rows,
                    ),
                    $module['groups'] ?? [],
                ),
            ],
            /*
             * ⭐ ফোনে বন্ধ মডিউল মেনুতে আসে না — [[PhoneModules::isOn()]]।
             * ⓘ ওয়েবের মেনু ছোঁয়া হয় না; এটা কেবল ফোনের উত্তর।
             */
            array_values(array_filter(
                $this->menu->forUser($user),
                fn (array $module): bool => $this->phone->isOn((string) $module['code']),
            )),
        );
    }
}
