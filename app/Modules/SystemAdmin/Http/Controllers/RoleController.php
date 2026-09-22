<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Core\Support\RoleLabel;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * রোল — এক নামে এক গুচ্ছ অনুমতি।
 *
 * ── কেন রোল সারি, কোডে লেখা তালিকা নয় ───────────────────────────────
 * তিনটা রোল সিডারে বসানো ছিল: মালিক, হিসাবরক্ষক, বিক্রয়কর্মী। কিন্তু
 * ডিপোভেদে কাজের ভাগ আলাদা — কারও একজন "গুদাম রক্ষক" লাগে যিনি চালান
 * কাটেন অথচ দাম দেখেন না, কারও "ক্যাশিয়ার" যিনি কেবল কাউন্টার চালান।
 * কোডে লেখা তিনটা নাম দিয়ে ওটা করা যায় না, আর তখন মানুষ বাধ্য হয়ে
 * সবাইকে মালিক বানিয়ে দেন — যেটা অনুমতি না থাকারই সমান।
 *
 * ── মালিকের রোলটা বদলানো যায় না ────────────────────────────────────
 * ওটা সংজ্ঞা অনুযায়ীই সব পারে, আর `abos:sync-permissions` প্রতিবার
 * নতুন অনুমতিগুলো ওখানে বসিয়ে দেয়। এখানে কেটে দিলে পরের ডিপ্লয়েই
 * ফিরে আসত — অর্থাৎ পর্দাটা একটা মিথ্যা প্রতিশ্রুতি দিত।
 */
class RoleController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly ModuleRegistry $modules,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.role.manage')];
    }

    public function index(Request $request): View
    {
        return view('system_admin::role.index', [
            'menu' => $this->menu->forUser($request->user()),
            /*
             * ⛔ চলতি কোম্পানির রোলই — ৭ সেপ্টেম্বর ২০২৬।
             *
             * ── ⚠️ কেন আজ এটা লাগল, গতকাল লাগত না ──────────────────────
             * এতদিন রোল ছিল **বিশ্বজনীন**, তাই ছাঁকনির প্রশ্নই ছিল না —
             * সবার একটাই `owner`, একটাই `salesman`। ⓘ আজ spatie teams
             * চালু হওয়ায় প্রতিটা কোম্পানি নিজের কপি পেয়েছে।
             *
             * ⛔ ছাঁকনি ছাড়া এই তালিকাটা **সব ক্রেতার রোল** দেখাত, আর
             * পাশের `update()` অন্য কোম্পানিরটা বদলাতে দিত — অর্থাৎ যে
             * ফাঁকটা বন্ধ করতে teams চালু করা হলো, সেটাই এই পর্দায় খোলা
             * থাকত।
             *
             * ⚠️ spatie নিজে `Role::query()`-তে কোনো global scope বসায় না
             * — সে টিমটা দেখে **বরাদ্দ ও যাচাইয়ের সময়**। ⓘ তালিকা ছাঁকা
             * আমাদের কাজ, আর হাতের কাজ ভুলে যাওয়া যায়।
             */
            'roles' => Role::query()
                ->where('company_id', CompanyContext::id())
                ->withCount(['permissions', 'users'])
                ->orderBy('name')
                ->get()

                /*
                 * টুলবারের খোঁজা — রোলের নাম, ১৯ সেপ্টেম্বর ২০২৬।
                 *
                 * ⚠️ কোয়েরিতে নয়, তোলা তালিকার উপর — ইচ্ছাকৃত। পর্দায় যে
                 * নামটা দেখা যায় সেটা [[RoleLabel]]-এর অনুবাদ ("মালিক"),
                 * আর টেবিলে বসে কাঁচা নাম (`super_admin`)। ⛔ ডাটাবেজে খুঁজলে
                 * চোখে দেখা নামটা লিখে কিছুই মিলত না। ⓘ রোল হাতেগোনা, তাই
                 * দুইটাতেই মেলানোর খরচ নেই।
                 */
                ->when(trim((string) $request->query('q')) !== '', function ($roles) use ($request) {
                    $term = Str::lower(trim((string) $request->query('q')));

                    return $roles->filter(fn (Role $role) => Str::contains(
                        Str::lower($role->name.' '.RoleLabel::for($role->name)),
                        $term,
                    ))->values();
                }),
            'ownerRole' => PermissionSyncer::SUPER_ADMIN_ROLE,
        ]);
    }

    public function create(Request $request): View
    {
        return view('system_admin::role.form', [
            'menu' => $this->menu->forUser($request->user()),
            'role' => new Role,
            'held' => [],
            'members' => collect(),
            ...$this->formData(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $role->syncPermissions($data['permissions'] ?? []);

        return redirect()
            ->route('system_admin.role.index')
            ->with('saved', __('system_admin::message.role_created', ['name' => $role->name]));
    }

    public function edit(Request $request, Role $role): View
    {
        $this->mustBeInThisCompany($role);
        $this->assertNotTheOwnerRole($role);

        return view('system_admin::role.form', [
            'menu' => $this->menu->forUser($request->user()),
            'role' => $role,
            'held' => $role->permissions->pluck('name')->all(),
            'members' => $role->users()->orderBy('name')->get(['users.id', 'users.name', 'users.email']),
            ...$this->formData(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->mustBeInThisCompany($role);
        $this->assertNotTheOwnerRole($role);

        $data = $this->validated($request, $role);

        $role->update(['name' => $data['name']]);
        $role->syncPermissions($data['permissions'] ?? []);

        return redirect()
            ->route('system_admin.role.index')
            ->with('saved', __('system_admin::message.role_updated', ['name' => $role->name]));
    }

    /**
     * মালিকের রোলে হাত দেওয়া যায় না — পর্দা থেকেও নয়, ঠিকানা থেকেও নয়।
     */

    /**
     * এই রোলটা কি চলতি কোম্পানির?
     *
     * ⛔ ৪০৪, ৪০৩ নয় — ইচ্ছাকৃত। ⓘ ৪০৩ বলে *"এটা আছে, কিন্তু আপনি পাবেন
     * না"*, আর সেটাই একটা তথ্য: ওই আইডিতে সত্যিই একটা রোল আছে। ⚠️ ভিন্ন
     * কোম্পানির কাছে ঐ সারিটার **অস্তিত্বই থাকা উচিত নয়**।
     *
     * ── ⚠️ কেন তালিকা ছাঁকাই যথেষ্ট নয় ─────────────────────────────
     * `edit(Role $role)` রুট-মডেল বাইন্ডিং ব্যবহার করে, আর সে **যেকোনো
     * id** খুলে দেয়। ⓘ তালিকায় নামটা না দেখেও কেউ ঠিকানা বদলে ঢুকতে
     * পারতেন — ছাঁকনি ভুল ঠেকায়, দরজা পাহারা আক্রমণ ঠেকায়।
     */
    private function mustBeInThisCompany(Role $role): void
    {
        abort_unless((int) $role->company_id === (int) CompanyContext::id(), 404);
    }

    private function assertNotTheOwnerRole(Role $role): void
    {
        if ($role->name === PermissionSyncer::SUPER_ADMIN_ROLE) {
            throw ValidationException::withMessages([
                'name' => __('system_admin::validation.owner_role_is_fixed'),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Role $role): array
    {
        /*
         * ⛔ নামের ছাঁচ কেবল **নতুন নামে** — ১৯ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ টেমপ্লেটের রোলগুলো মডিউল থেকে আসে, আর তাদের নাম এই ছাঁচে
         * পড়ে না: `Manager`, `Field Sales`, `HR`, `Warehouse`। ⚠️ ফলে
         * ঐ রোলগুলোর একটাও এই পর্দা থেকে **সংরক্ষণই করা যেত না** — নাম
         * না ছুঁয়ে কেবল একটা অনুমতি বদলালেও বলত "নামের ছাঁচ ভুল"।
         * ধরা পড়েছে নতুন ছকের পাহারায় (`TheRolePageShowsEveryPermissionTest`)।
         *
         * ⓘ নাম অপরিবর্তিত থাকলে ছাঁচ প্রশ্নই নয় — কোড যে নামটা খোঁজে
         * সেটা তো বদলাচ্ছে না।
         */
        $keepsItsName = $role !== null && $request->input('name') === $role->name;

        return $request->validate([
            /*
             * ⚠️ নামের অদ্বিতীয়তা **কোম্পানির ভেতরে**, বিশ্বজুড়ে নয় —
             * ৭ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ teams চালু হওয়ার পর প্রতিটা কোম্পানির নিজের "বিক্রয়কর্মী"
             * আছে। ⛔ ছাঁকনি ছাড়া এই নিয়মটা বলত *"এই নামে একটা রোল আছে"*
             * — অন্য কারও কোম্পানিতে — আর ব্যবহারকারী নিজের কোম্পানিতে
             * সেই নামটা বসাতেই পারতেন না।
             *
             * ⚠️ আর ফাঁসও: বার্তাটা জানিয়ে দিত অন্য কোথাও ওই নামের রোল
             * আছে কি না।
             */
            'name' => ['required', 'string', 'max:64',
                ...($keepsItsName ? [] : ['regex:/^[a-z][a-z0-9_]*$/']),
                Rule::unique('roles', 'name')
                    ->where('company_id', CompanyContext::id())
                    ->ignore($role?->id)],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::exists('permissions', 'name')],
        ], [
            // নামটা কোডে বসে (`$user->can(...)`), তাই ছাঁচটা বাঁধা
            'name.regex' => __('system_admin::validation.role_name_shape'),
        ]);
    }

    /**
     * অনুমতিগুলো মডিউল ▸ জিনিস ▸ কাজ — একটা ছক।
     *
     * ── ⛔ কেন ছক, ১৯ সেপ্টেম্বর ২০২৬ ────────────────────────────────
     * আগে প্রতিটা মডিউলের নিচে অনুমতিগুলো **কাঁচা নামে** এক সারিতে বসত
     * (`accounts.voucher.update`)। ⓘ মালিক দুইটা নকশা পাঠালেন — প্রতিটা
     * জিনিস এক সারি, পাশে দেখা · তৈরি · সম্পাদনা · মোছা।
     *
     * ⚠️ জমা দেওয়ার পথ বদলায়নি: প্রতিটা সুইচ এখনো `permissions[]`-এ
     * পুরো নামটাই পাঠায়, তাই `store()` / `update()` এক লাইনও বদলায়নি।
     *
     * ── ⓘ চার কলামে যা ধরে না ────────────────────────────────────────
     * `manage` একাই তৈরি-সম্পাদনা-মোছা — তাই যেখানে ঐ তিনটা আলাদা নেই,
     * সুইচটা তিন কলাম জুড়ে বসে। বাকি কাজগুলো (অনুমোদন, রিপোর্ট, নিয়ম
     * পেরোনো…) "বিশেষ" কলামে নামসহ।
     *
     * ⛔ কোনো অনুমতি বাদ পড়ে না — ছকে না ধরলে বিশেষ কলামে যায়।
     * `TheRolePageShowsEveryPermissionTest` গুনে দেখে।
     *
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        $labels = [];

        /*
         * ⭐ কোন অনুমতি কোন ভাগে — মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬।
         *
         * ── ⓘ ভাগটা কোথা থেকে ───────────────────────────────────────
         * মেনু **আগে থেকেই** ভাগ করা (`master` · `transactions` ·
         * `reports` · `settings`), আর প্রতিটা সারিতে অনুমতির নাম বসানো।
         * ⭐ তাই নতুন কোনো তালিকা বানানো হয়নি: মানুষ মেনুতে জিনিসটা যে
         * ভাগে দেখেন, অনুমতির পর্দাতেও সেই ভাগেই দেখবেন।
         *
         * ⚠️ হাতে আরেকটা তালিকা লিখলে দুইটা একদিন আলাদা হয়ে যেত — আর
         * তখন কোনটা সত্যি, কেউ বলতে পারত না।
         */
        $sections = [];

        foreach ($this->modules->all() as $module) {
            $labels[$module->code] = $module->label();

            foreach ($module->menu as $section => $rows) {
                foreach ($rows as $row) {
                    $permission = $row['permission'] ?? null;

                    if (! is_string($permission) || $permission === '') {
                        continue;
                    }

                    /*
                     * ⓘ বিষয়টা অনুমতির নাম থেকে — শেষ অংশটা ক্রিয়া
                     * (`view`, `manage`), বাকিটা বিষয়।
                     *
                     * ⚠️ প্রথম ভাগটাই থাকে: একই বিষয় দুই ভাগে থাকলে
                     * (তালিকা `master`-এ, রিপোর্ট `reports`-এ) সারিটা
                     * দুইবার দেখানো হত, আর টিক দিলে একটা বসত অন্যটা নয়।
                     */
                    $parts = explode('.', $permission);
                    array_pop($parts);
                    $subject = implode('.', $parts);

                    $sections[$subject] ??= $section;
                }
            }
        }

        $matrix = [];

        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->pluck('name');

        foreach ($permissions as $name) {
            $parts = explode('.', $name);
            $verb = count($parts) > 1 ? array_pop($parts) : 'manage';
            $subject = implode('.', $parts);
            $module = $parts[0];

            $matrix[$module][$subject]['verbs'][$verb] = $name;
        }

        $grid = [];

        foreach ($matrix as $module => $subjects) {
            $rows = [];

            foreach ($subjects as $subject => ['verbs' => $verbs]) {
                $cells = [];

                foreach (self::COLUMNS as $column => $candidates) {
                    foreach ($candidates as $candidate) {
                        if (isset($verbs[$candidate])) {
                            $cells[$column] = $verbs[$candidate];
                            unset($verbs[$candidate]);
                            break;
                        }
                    }
                }

                /*
                 * `manage` তিন কলাম জুড়ে — কেবল যখন তৈরি/সম্পাদনা/মোছা
                 * আলাদা করে নেই। থাকলে ওরা নিজের ঘরে, আর manage বিশেষে।
                 */
                $spans = null;

                if (isset($verbs['manage']) && ! array_intersect_key($cells, array_flip(['create', 'update', 'delete']))) {
                    $spans = $verbs['manage'];
                    unset($verbs['manage']);
                }

                $rows[] = [
                    'key' => $subject,
                    'label' => $this->subjectLabel($subject),

                    /*
                     * ⓘ মেনুতে না থাকা অনুমতিগুলো `other`-এ — আর ওগুলো
                     * সত্যিই আছে: অনেক অনুমতি কোনো মেনু সারির সাথে
                     * জোড়া নয় (অনুমোদন, বিশেষ অধিকার)।
                     *
                     * ⛔ চুপচাপ বাদ দেওয়া যেত না — যে অনুমতি পর্দায় নেই
                     * সেটা কেউ দিতেও পারেন না, আর ব্যবস্থাটা তখন
                     * নীরবে অসম্পূর্ণ।
                     */
                    'section' => $sections[$subject] ?? 'other',
                    'cells' => $cells,
                    'manage' => $spans,
                    'special' => collect($verbs)
                        ->mapWithKeys(fn (string $name, string $verb) => [$name => $this->verbLabel($verb)])
                        ->all(),
                ];
            }

            /* ⓘ "পুরো মডিউল" সারিটা (দুই অংশের নাম) উপরে, বাকিগুলো নামের ক্রমে। */
            usort($rows, fn (array $a, array $b) => [str_contains($a['key'], '.'), $a['label']]
                <=> [str_contains($b['key'], '.'), $b['label']]);

            /*
             * ⭐ সারিগুলো দলে — মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬।
             *
             * ── ⛔ আগে সব সারি একটানা ছিল, আর কেন সেটা কাজ করত না ─────
             * হিসাব মডিউলে বাইশটা সারি একসাথে, কোনো মাথা ছাড়া। ⚠️ যিনি
             * *"সব রিপোর্ট দেখতে দাও, আর কিছু নয়"* চান, তাঁকে বাইশটা
             * নাম পড়ে বেছে নিতে হত — আর একটা ভুলে গেলে কেউ বলত না।
             *
             * ⭐ এখন প্রতিটা দলের নিজের মাথা ও নিজের "সব বাছুন", তাই
             * ঐ কাজটা **একটা ক্লিক**।
             *
             * ⓘ ক্রমটা স্থির (নিচের `SECTIONS`), মেনুর ক্রম নয় — প্রতিটা
             * মডিউলে একই ক্রম থাকলে চোখ জায়গাটা মনে রাখে।
             */
            $sectioned = [];

            foreach (array_keys(self::SECTIONS) as $section) {
                $inSection = array_values(array_filter($rows, fn (array $r) => $r['section'] === $section));

                if ($inSection === []) {
                    continue;
                }

                $sectioned[$section] = [
                    'label' => __(self::SECTIONS[$section]),
                    'rows' => $inSection,
                    'all' => collect($inSection)
                        ->flatMap(fn (array $r) => [
                            ...array_values($r['cells']),
                            ...array_filter([$r['manage']]),
                            ...array_keys($r['special']),
                        ])
                        ->all(),
                ];
            }

            $grid[$module] = [
                'label' => $labels[$module] ?? $this->subjectLabel($module),
                'rows' => $rows,
                'sections' => $sectioned,
                'all' => collect($rows)
                    ->flatMap(fn (array $r) => [...array_values($r['cells']), ...array_filter([$r['manage']]), ...array_keys($r['special'])])
                    ->all(),
            ];
        }

        /* ⓘ মেনুর ক্রমেই — মানুষ যে ক্রমে মডিউলগুলো চেনেন। */
        $order = array_flip(array_keys($labels));
        uksort($grid, fn (string $a, string $b) => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);

        return [
            'grid' => $grid,
            'roleList' => Role::query()
                ->where('company_id', CompanyContext::id())
                ->withCount('users')
                ->orderBy('name')
                ->get(),
            'ownerRole' => PermissionSyncer::SUPER_ADMIN_ROLE,
        ];
    }

    /**
     * ছকের চার কলাম, আর প্রতিটায় কোন কাজগুলো বসতে পারে — ক্রম ধরে।
     *
     * ⓘ `cancel` মোছার ঘরে: দলিল মোছা হয় না, বাতিল হয় — ক্রয় বিলের
     * "মোছা" মানে ওটাই।
     */
    private const COLUMNS = [
        'view' => ['view'],
        'create' => ['create'],
        'update' => ['update'],
        'delete' => ['delete', 'cancel'],
    ];

    /**
     * ⭐ অনুমতির পর্দার ভাগগুলো — মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬।
     *
     * ── ⓘ নামগুলো মেনুর নিজের ভাগ থেকেই ────────────────────────────
     * প্রতিটা `module.php`-র মেনু ইতিমধ্যে এই চাবিগুলোতে ভাগ করা।
     * ⚠️ হাতে আরেকটা তালিকা বানালে দুইটা একদিন আলাদা হয়ে যেত, আর তখন
     * অনুমতির পর্দা আর মেনু দুই রকম বলত।
     *
     * ── ⚠️ ক্রমটা স্থির, মেনুর ক্রম নয় ──────────────────────────────
     * প্রতিটা মডিউলে একই ক্রম থাকলে চোখ জায়গাটা মনে রাখে — "রিপোর্ট
     * সবসময় নিচে"। ⛔ মেনুর ক্রম ধরলে এক মডিউলে `master` আগে, অন্যটায়
     * `transactions` আগে হত।
     *
     * ⓘ `other` — যে অনুমতিগুলো কোনো মেনু সারির সাথে জোড়া নয়
     * (অনুমোদন, বিশেষ অধিকার)। ⛔ ওগুলো বাদ দেওয়া যেত না: যে অনুমতি
     * পর্দায় নেই সেটা কেউ দিতেও পারেন না।
     *
     * @var array<string, string>
     */
    private const SECTIONS = [
        'master' => 'system_admin::permission.sections.master',
        'transactions' => 'system_admin::permission.sections.transactions',
        'reports' => 'system_admin::permission.sections.reports',
        'settings' => 'system_admin::permission.sections.settings',
        'dashboard' => 'system_admin::permission.sections.dashboard',
        'other' => 'system_admin::permission.sections.other',
    ];

    private function subjectLabel(string $subject): string
    {
        $key = 'system_admin::permission.subjects.'.$subject;
        $label = __($key);

        return is_string($label) && $label !== $key
            ? $label
            : Str::headline(str_replace('.', ' ', Str::after($subject, '.')));
    }

    private function verbLabel(string $verb): string
    {
        $key = 'system_admin::permission.verbs.'.$verb;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : Str::headline($verb);
    }
}
