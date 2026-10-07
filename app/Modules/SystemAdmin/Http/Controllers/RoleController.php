<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\ApprovalFlowStep;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
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

    /**
     * ⭐ তালিকা আর সম্পাদনা এখন **এক পর্দা** — ২৪ সেপ্টেম্বর ২০২৬।
     *
     * ── ⛔ মালিকের প্রশ্ন ───────────────────────────────────────────
     * *"ekoi jinis dui porda keno?"* — আর কথাটা ঠিক ছিল। ⓘ তালিকার
     * পর্দায় রোলের নাম, গুনতি আর একটা "সম্পাদনা" লিংক; সম্পাদনার
     * পর্দায় **বাঁ কলামে ঠিক সেই তালিকাটাই** আবার। ⚠️ অর্থাৎ একই
     * জিনিস দুইবার, আর দুইটা আলাদা করে রক্ষণাবেক্ষণ করতে হত।
     *
     * ⛔ দাম কেবল সদৃশতা নয়: রোল বদলাতে গেলে তালিকা → সম্পাদনা →
     * সংরক্ষণ → তালিকা, প্রতিবার পুরো পাতা নতুন করে। ⚠️ পরপর তিনটা
     * রোল গোছাতে ন'বার পাতা বদলাত।
     *
     * ⭐ এখন একটাই পর্দা (স্পেক §২.০): বাঁয়ে তালিকা, মাঝে ছক, ডানে
     * বিবরণ। ⓘ `index` মানে *"কোনো রোল বাছা হয়নি"* — তাই `role` শূন্য,
     * আর মাঝের কলাম বাছাই করতে বলে।
     */
    public function index(Request $request): View
    {
        return view('system_admin::role.form', [
            'menu' => $this->menu->forUser($request->user()),
            'role' => null,
            'held' => [],
            'members' => collect(),
            'approvalPower' => [],

            /* ⓘ ছকটা এখানে আঁকা হয় না — কারণসহ [[formData()]]-এ। */
            ...$this->formData(withGrid: false),
        ]);
    }

    public function create(Request $request): View
    {
        return view('system_admin::role.form', [
            'menu' => $this->menu->forUser($request->user()),
            'role' => new Role,
            'held' => [],
            'members' => collect(),
            'approvalPower' => [],
            ...$this->formData(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $role->syncPermissions($data['permissions'] ?? []);

        /*
         * ⭐ সংরক্ষণের পর রোলটাতেই থাকা — ২৪ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ আগে তালিকায় ফিরত, কারণ তালিকা আর সম্পাদনা আলাদা পর্দা ছিল।
         * ⚠️ এখন একটাই পর্দা, তাই তালিকায় ফেরা মানে **সদ্য বানানো
         * রোলটা ছেড়ে দেওয়া** — আর প্রায় সবসময়ই পরের কাজটা ঐ রোলেই।
         */
        return redirect()
            ->route('system_admin.role.edit', $role)
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
            'approvalPower' => $this->approvalPower($role),
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

        /* ⓘ কারণটা [[store()]]-এ একবারই লেখা। */
        return redirect()
            ->route('system_admin.role.edit', $role)
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
            /*
             * ⛔ নামের ছাঁচের নিয়মটা **উঠে গেল** — ২৪ সেপ্টেম্বর ২০২৬।
             *
             * ── ⚠️ মালিকের প্রশ্ন, আর নিয়মটা মেপে দেখা ─────────────
             * পর্দায় লেখা ছিল *"ছোট হাতের ইংরেজি অক্ষর ও আন্ডারস্কোর
             * (store_keeper)"*, আর নিয়মটা ছিল `^[a-z][a-z0-9_]*$`।
             *
             * ⛔ কিন্তু **ব্যবস্থাটা নিজেই ঐ নিয়ম মানে না**। মডিউলের
             * ঘোষিত রোল টেমপ্লেটগুলোর নাম: `Manager`, `Field Sales`,
             * `HR`, `Warehouse` — একটাও ছাঁচে পড়ে না, অথচ ওগুলোই
             * প্রতিটা কোম্পানিতে বসে।
             *
             * ⚠️ আগের মেরামতটা ছিল *"নাম না বদলালে ছাঁচ দেখব না"* —
             * অর্থাৎ নিয়মটা টিকিয়ে রেখে তার ফলটা লুকানো। ⓘ ফল:
             * `Field Sales` রোলটা খোলা যেত, কিন্তু নামের একটা অক্ষর
             * বদলালেই পর্দা বলত ছাঁচ ভুল — অথচ ঐ নামটাই কোড নিজে বসায়।
             *
             * ── ⓘ ছাঁচটা কী পাহারা দিত, তা খুঁজে দেখা ───────────────
             * রোলের নাম কোডে মেলানো হয় কেবল **এক জায়গায়**:
             * `PermissionSyncer::SUPER_ADMIN_ROLE` (`hasRole()`, চারটা
             * ফাইল)। ⓘ আর ঐ রোলটা এই পর্দা থেকে ছোঁয়াই যায় না
             * ([[assertNotTheOwnerRole()]])। ⛔ অর্থাৎ ছাঁচটা যা
             * পাহারা দিত বলে দাবি করত, তার কিছুই সে পাহারা দিত না।
             *
             * ⭐ যে নিয়ম পণ্যের নিজের ডেটা ভাঙে, সেটা নিয়ম নয় — ভুল।
             * ⓘ অদ্বিতীয়তা আর দৈর্ঘ্য থাকল; ওগুলোর পিছনে আসল কারণ আছে।
             *
             * ── ⚠️ নামের অদ্বিতীয়তা কোম্পানির ভেতরে, বিশ্বজুড়ে নয় ──
             * ⓘ teams চালু হওয়ার পর প্রতিটা কোম্পানির নিজের
             * "বিক্রয়কর্মী" আছে। ⛔ ছাঁকনি ছাড়া নিয়মটা বলত *"এই নামে
             * একটা রোল আছে"* — অন্য কারও কোম্পানিতে — আর ব্যবহারকারী
             * নিজের কোম্পানিতে সেই নামটা বসাতেই পারতেন না। ⚠️ আর
             * ফাঁসও: বার্তাটা জানিয়ে দিত অন্য কোথাও ঐ নাম আছে কি না।
             */
            'name' => ['required', 'string', 'max:64',
                Rule::unique('roles', 'name')
                    ->where('company_id', CompanyContext::id())
                    ->ignore($role?->id)],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::exists('permissions', 'name')],
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
     * ── ⓘ তালিকার পর্দায় ছকটা বানানো হয় না ──────────────────────────
     * ⚠️ `index` কোনো রোল বাছে না, তাই পর্দায় ছকটা আঁকাই হয় না। ⛔ তবু
     * এখানে সেটা বানালে প্রতিবার চারশোর বেশি অনুমতি তুলে, চোদ্দটা
     * মডিউলে ভাগ করে, সাজিয়ে — তারপর ফেলে দেওয়া হত।
     *
     * ⓘ বাঁ কলামের তালিকা আর মাথার কার্ডগুলো দুই পর্দাতেই লাগে, তাই
     * ওগুলো সবসময়ই আসে।
     *
     * @return array<string, mixed>
     */
    private function formData(bool $withGrid = true): array
    {
        if (! $withGrid) {
            $roles = $this->rolesOfThisCompany();

            return [
                'grid' => [],
                'roleList' => $this->grouped($roles),
                'ownerRole' => PermissionSyncer::SUPER_ADMIN_ROLE,
                'summary' => $this->summary($roles),
            ];
        }

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

        $roles = $this->rolesOfThisCompany();

        return [
            'grid' => $grid,
            'roleList' => $this->grouped($roles),
            'ownerRole' => PermissionSyncer::SUPER_ADMIN_ROLE,
            'summary' => $this->summary($roles),
        ];
    }

    /**
     * ⛔ চলতি কোম্পানির রোলই — ৭ সেপ্টেম্বর ২০২৬।
     *
     * ── ⚠️ কেন এটা লাগে ─────────────────────────────────────────────
     * এতদিন রোল ছিল **বিশ্বজনীন**, তাই ছাঁকনির প্রশ্নই ছিল না।
     * ⓘ spatie teams চালু হওয়ায় প্রতিটা কোম্পানি নিজের কপি পেয়েছে।
     *
     * ⛔ ছাঁকনি ছাড়া বাঁ কলামটা **সব ক্রেতার রোল** দেখাত, আর
     * `update()` অন্য কোম্পানিরটা বদলাতে দিত — অর্থাৎ যে ফাঁকটা বন্ধ
     * করতে teams চালু করা হলো, সেটাই এই পর্দায় খোলা থাকত।
     *
     * ⚠️ spatie নিজে `Role::query()`-তে কোনো global scope বসায় না — সে
     * টিমটা দেখে **বরাদ্দ ও যাচাইয়ের সময়**। ⓘ তালিকা ছাঁকা আমাদের
     * কাজ, আর হাতের কাজ ভুলে যাওয়া যায়।
     *
     * ⓘ `permissions_count` বাঁ কলামের সারিতে বসে — স্পেক §২.৩-এ
     * প্রতিটা সারিতে নাম · গুনতি। ⛔ আগে এটা কেবল পুরনো তালিকার পর্দায়
     * গোনা হত, আর ঐ পর্দাটা এখন নেই।
     *
     * @return Collection<int, Role>
     */
    private function rolesOfThisCompany(): Collection
    {
        return Role::query()
            ->where('company_id', CompanyContext::id())
            ->withCount(['users', 'permissions'])
            ->orderBy('name')
            ->get();
    }

    /**
     * ⭐ বাঁ কলামের তিন ভাগ — মালিকের স্পেক §২.৩, ২৪ সেপ্টেম্বর ২০২৬।
     *
     * ```
     * ⭐ সিস্টেম রোল     🏢 ব্যবসায়িক রোল     📦 নিজের বানানো রোল
     * ```
     *
     * ── ⚠️ ভাগটা মেপে, ধরে নিয়ে নয় ──────────────────────────────────
     * ⛔ নামের তালিকা হাতে লেখা যেত (`'Manager', 'HR', …`) আর পর্দাটা
     * হুবহু নকশার মতো দেখাত। ⚠️ কিন্তু নতুন মডিউল একটা টেমপ্লেট আনলে
     * তার রোলটা *"নিজের বানানো"* ভাগে গিয়ে বসত — আর **কোনো ভুল
     * দেখাত না**, কেবল একটা সারি ভুল জায়গায় চুপ করে থাকত।
     *
     * ⭐ তাই ভাগটা রেজিস্ট্রি থেকেই আসে: যে নামটা কোনো মডিউলের
     * `role_templates`-এ ঘোষিত, সেটা ব্যবসায়িক; মালিকের রোলটা
     * সিস্টেমের; বাকি সব নিজের বানানো। ⓘ কোরে কোনো মডিউলের নাম
     * লেখা হয় না (§১৯.৭)।
     *
     * @param  Collection<int, Role>  $roles
     * @return array<string, array{label: string, roles: Collection<int, Role>}>
     */
    private function grouped($roles): array
    {
        $fromModules = [];

        foreach ($this->modules->all() as $module) {
            foreach (array_keys($module->roleTemplates) as $name) {
                $fromModules[(string) $name] = true;
            }
        }

        $of = function (Role $role) use ($fromModules): string {
            if ($role->name === PermissionSyncer::SUPER_ADMIN_ROLE) {
                return 'system';
            }

            return isset($fromModules[$role->name]) ? 'business' : 'custom';
        };

        $out = [];

        foreach (self::ROLE_GROUPS as $key => $label) {
            $inGroup = $roles->filter(fn (Role $r) => $of($r) === $key)->values();

            /* ⓘ খালি ভাগের মাথা দেখানো হয় না — তিনটা মাথা, শূন্য সারি। */
            if ($inGroup->isEmpty()) {
                continue;
            }

            $out[$key] = ['label' => __($label), 'roles' => $inGroup];
        }

        return $out;
    }

    /**
     * ⭐ এই রোল কোন কাগজে অনুমোদন দিতে পারে — স্পেক §২.৬, §৬।
     *
     * ── ⛔ কেন এটা পর্দায় থাকতেই হবে ─────────────────────────────────
     * ⓘ অনুমোদনের ক্ষমতা অনুমতির ছকে **আসেই না**: ওটা বসে
     * [[ApprovalFlowStep]]-এ, রোলের আইডি ধরে — একটা সম্পূর্ণ আলাদা
     * পর্দায় (অনুমোদন প্রবাহ)।
     *
     * ⚠️ ফল: রোলের পর্দা দেখে কেউ বুঝতেই পারতেন না যে এই রোলটা
     * পঞ্চাশ লাখ টাকার কাগজ ছাড়তে পারে। ⛔ অর্থাৎ পর্দাটা *"এই রোল কী
     * পারে"* প্রশ্নের উত্তর দিত বলে দেখাত, অথচ সবচেয়ে দামি ক্ষমতাটা
     * সেখানে ছিল না।
     *
     * ── ⓘ সংখ্যাগুলো মাপা, কল্পনা নয় ─────────────────────────────────
     * স্পেকের নমুনায় *"৳১,০০,০০০ ✓ · ৳৫,০০,০০০ ✓ · তার উপরে ✕"* লেখা।
     * ⛔ ওগুলো বসিয়ে দেওয়া যেত আর পর্দাটা হুবহু নকশার মতো দেখাত —
     * কিন্তু তখন ঘরটা **সাজসজ্জা**। ⭐ এখানে যা দেখা যায় তার প্রতিটা
     * সারি ডাটাবেসের একটা সত্যিকারের ধাপ।
     *
     * ⚠️ নিষ্ক্রিয় প্রবাহ বাদ (`is_active`): ⛔ ওগুলো দেখালে পর্দা
     * বলত রোলটা এমন কিছু পারে যা আজ কেউ তার কাছে পাঠায়ই না।
     *
     * ── ⓘ নামটা রেজিস্ট্রি থেকে, Approval মডিউল থেকে নয় ──────────────
     * ⚠️ `ApprovalFlowService::choices()` ঠিক এই কাজটাই করে, কিন্তু সে
     * Approval মডিউলের। ⛔ এখান থেকে তাকে ডাকলে SystemAdmin-কে
     * `depends_on`-এ Approval লিখতে হত ([[BoundariesTest]]), আর তখন
     * Approval মডিউল বন্ধ করা কোম্পানিতে **রোলের পর্দাই খুলত না**।
     *
     * ⭐ ঐ সেবাটাও তো রেজিস্ট্রি থেকেই পড়ে (`$module->approvals`) —
     * তাই এখানে সরাসরি সেটাই পড়া হয়। ⓘ দুইটা কপি নয়, একই উৎস।
     *
     * @return list<array{label: string, module: string, level: int, threshold: ?string}>
     */
    private function approvalPower(Role $role): array
    {
        if (! $role->exists) {
            return [];
        }

        $names = [];

        foreach ($this->modules->all() as $module) {
            foreach ($module->approvals as $action => $key) {
                $names[$module->code.'.'.$action] = [__($key), $module->label()];
            }
        }

        return ApprovalFlowStep::query()
            ->where('approver_type', ApprovalFlowStep::BY_ROLE)
            ->where('approver_id', $role->id)
            ->whereHas('flow', fn ($q) => $q->where('is_active', true))
            ->with('flow')
            ->get()
            ->map(function (ApprovalFlowStep $step) use ($names): array {
                $module = (string) $step->flow?->module;
                $action = (string) $step->flow?->action;

                /*
                 * ⚠️ নাম না মিললে কাঁচা নামটাই — ⛔ চাবিটা ছাপা হয় না।
                 * ⓘ একটা মডিউল বন্ধ থাকলে বা ঘোষণাটা সরে গেলে সারিটা
                 * তবু পড়া যায়, আর ক্ষমতাটা লুকিয়ে যায় না।
                 */
                [$label, $moduleLabel] = $names[$module.'.'.$action] ?? [$action, $module];

                return [
                    'label' => $label,
                    'module' => $moduleLabel,
                    'level' => $step->level,
                    'threshold' => $step->flow?->threshold_amount === null
                        ? null
                        : (string) $step->flow->threshold_amount,
                ];
            })
            ->sortBy([['module', 'asc'], ['level', 'asc']])
            ->values()
            ->all();
    }

    /** @var array<string, string> */
    private const ROLE_GROUPS = [
        'system' => 'system_admin::permission.group_system',
        'business' => 'system_admin::permission.group_business',
        'custom' => 'system_admin::permission.group_custom',
    ];

    /**
     * মাথার সারাংশ — মালিকের স্পেকের Overview Card।
     *
     * ── ⚠️ প্রতিটা সংখ্যা মেপে, কল্পনা নয় ─────────────────────────────
     * ⓘ স্পেকে উদাহরণ হিসেবে *"২৪৮ · ৩২ · ৮৬"* লেখা। ⛔ ওগুলো বসিয়ে
     * দেওয়া যেত আর পর্দাটা দেখতে হুবহু নকশার মতো হত — কিন্তু তখন
     * কার্ডগুলো **সাজসজ্জা**, আর একদিন কেউ ওগুলো বিশ্বাস করে সিদ্ধান্ত
     * নিতেন।
     *
     * ⓘ যে ঘরগুলোর পিছনে এখনো কোনো ব্যবস্থা নেই (অস্থায়ী অনুমতি,
     * ঝুঁকির মাত্রা), সেগুলো এখানে **নেই** — ⚠️ শূন্য দেখানোও একটা
     * উত্তর, আর ওটা মিথ্যা: শূন্য মানে *"একটাও নেই"*, *"এখনো বানানো
     * হয়নি"* নয়।
     *
     * @param  Collection<int, Role>  $roles
     * @return array<string, int>
     */
    private function summary($roles): array
    {
        return [
            /*
             * ⛔ চলতি কোম্পানির ব্যবহারকারীই — ২৪ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ এখানে লেখা ছিল খালি `User::query()->count()`, আর সেটা
             * **সব ক্রেতার ব্যবহারকারী** গুনত। ⓘ কার্ডটা তখন বলত
             * *"২৪৮ জন"*, অথচ এই প্রতিষ্ঠানে হয়তো বারো জন।
             *
             * ⛔ ক্ষতিটা কেবল ভুল সংখ্যা নয়: কার্ডগুলো বানানোই হয়েছিল
             * *"সাজসজ্জা নয়, মেপে পাওয়া"* বলে — আর একটা মেপে-পাওয়া
             * সংখ্যা যদি অন্য কোম্পানির মানুষ গোনে, তবে সে সবচেয়ে
             * খারাপ ধরনের মিথ্যা: বিশ্বাসযোগ্য।
             *
             * ⓘ [[User]] [[BaseEntity]]-র গ্লোবাল স্কোপ পায় না — সে
             * `companies` পিভটে ঝোলে, তাই ছাঁকনিটা **হাতে বসাতে হয়**,
             * আর হাতের কাজ ভুলে যাওয়া যায়। ⚠️ ধরেছে
             * [[EveryUserListAsksWhichCompanyTest]], আর ছাঁচটা
             * [[UserController::index()]]-এর হুবহু একই।
             */
            'users' => User::query()
                ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
                ->count(),
            'roles' => $roles->count(),
            'permissions' => Permission::query()->count(),
            'unassigned' => $roles->where('users_count', 0)->count(),
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
