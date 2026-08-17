<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PermissionSyncer;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
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
            'roles' => Role::query()->withCount(['permissions', 'users'])->orderBy('name')->get(),
            'ownerRole' => PermissionSyncer::OWNER_ROLE,
        ]);
    }

    public function create(Request $request): View
    {
        return view('system_admin::role.form', [
            'menu' => $this->menu->forUser($request->user()),
            'role' => new Role,
            'held' => [],
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
        $this->assertNotTheOwnerRole($role);

        return view('system_admin::role.form', [
            'menu' => $this->menu->forUser($request->user()),
            'role' => $role,
            'held' => $role->permissions->pluck('name')->all(),
            ...$this->formData(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
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
    private function assertNotTheOwnerRole(Role $role): void
    {
        if ($role->name === PermissionSyncer::OWNER_ROLE) {
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
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('roles', 'name')->ignore($role?->id)],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::exists('permissions', 'name')],
        ], [
            // নামটা কোডে বসে (`$user->can(...)`), তাই ছাঁচটা বাঁধা
            'name.regex' => __('system_admin::validation.role_name_shape'),
        ]);
    }

    /**
     * অনুমতিগুলো মডিউল ধরে সাজানো।
     *
     * ── কেন সাজানো লাগে ─────────────────────────────────────────────
     * একশো ঊনিশটা অনুমতি একটা লম্বা তালিকায় দিলে কেউ পড়ত না, আর
     * পড়ে না দেখে টিক দেওয়া মানে ভুল অধিকার দেওয়া। মডিউলের নামের
     * নিচে থাকলে "বিক্রয়ের কী কী পারবেন" এক নজরে দেখা যায়।
     *
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        $labels = [];

        foreach ($this->modules->all() as $module) {
            $labels[$module->code] = $module->label();
        }

        $grouped = Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $permission) => explode('.', $permission->name)[0]);

        return [
            'grouped' => $grouped,
            'moduleNames' => $labels,
        ];
    }
}
