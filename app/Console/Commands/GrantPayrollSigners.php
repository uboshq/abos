<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ বেতনের দ্বিতীয় সই আর "ব্যাংক এই লাইন দেয়নি"-র চাবি এক কোম্পানির একটা রোলের মানুষদের — মালিক, ২১ অক্টোবর ২০২৬।
 *
 * ⓘ মালিক বললেন UB-র "Manager"-রা সই দেবেন। চাবিগুলো **"Manager" রোলে দেওয়া হয় না**, দেওয়া হয় একটা নতুন,
 * কেবল ঐ কোম্পানির রোলে ("বেতনের সই"), আর রোলটা বসে ঐ কোম্পানিতে যাঁরা "Manager" তাঁদের হাতে।
 *
 * ── ⛔ কেন "Manager" রোলে নয় ─────────────────────────────────────────────────────────────────
 * রোল-অনুমতির টেবিলে (`role_has_permissions`) কোম্পানির কলাম নেই। ⚠️ যে "Manager" সারির `company_id` খালি, সে
 * spatie-র চোখে **সব কোম্পানির** রোল (HasRoles::roles() খালি-কোম্পানির রোল সবখানে তোলে) — তাতে চাবি দিলে ADI আর
 * AVA-র Manager-রাও পেতেন। ⓘ কোম্পানির নিজের "Manager"-এ দিলেও রোলটা ছাঁচের রোল, পরে কেউ ছাঁচ মেলালে চাবির
 * উৎস হারিয়ে যায়। ⭐ আলাদা রোলে চাবির নামটাই বলে কেন আছে, আর রোলের পর্দায় দেখা যায় কার হাতে।
 *
 * ⛔ সরাসরি মানুষের হাতে চাবি নয় (fe, ২১ অক্টোবর ২০২৬): সরাসরি চাবি পরে কারও চোখে পড়ে না, আর নতুন Manager পান না।
 *
 * ── ⓘ কে "Manager" ────────────────────────────────────────────────────────────────────────────
 * বরাদ্দের সারি (`model_has_roles.company_id`) এই কোম্পানির, আর রোলটা এই কোম্পানির **বা** খালি-কোম্পানির — spatie
 * নিজে যেভাবে "এই কোম্পানিতে কার কোন রোল" পড়ে, অবিকল সেভাবে। অন্য কোম্পানির Manager এখানে আসেন না।
 */
final class GrantPayrollSigners extends Command
{
    /** ⓘ নতুন রোলের নাম — কোম্পানির নিজের, রোলের পর্দায় "নিজের বানানো" ভাগে বসে। */
    public const ROLE = 'বেতনের সই';

    public const GUARD = 'web';

    /** ⭐ মালিকের বলা ছয়টা চাবি — বেতনের দ্বিতীয় সই, বেতনের অঙ্ক, ঠিকানার বদল আর "ব্যাংক এই লাইন দেয়নি"। */
    public const KEYS = [
        'hr.payroll.manage',
        'hr.salary.view',
        'hr.salary.manage',
        'hr.employee.manage',
        'hr.identity.view',
        'approval.decide',
    ];

    /** ⓘ নিরীক্ষার কাজের নাম — ২৪ অক্ষরের ভিতরে, governance::action-এ দুই ভাষায় নাম আছে। */
    public const ROLE_MADE = 'payroll_signer_made';

    public const KEYS_ADDED = 'role_permissions_changed';

    public const GIVEN = 'payroll_signer_given';

    protected $signature = 'abos:grant-payroll-signers
                            {--company= : কোম্পানির কোড (যেমন UB)}
                            {--role= : যাঁদের হাতে এই রোল, তাঁরা সই পাবেন (যেমন Manager)}
                            {--dry : কেবল দেখায়, কিছু লেখে না}';

    protected $description = "Give one company's holders of a role the payroll second-signature keys, through a company-only role";

    public function handle(AuditEngine $audit, ModuleRegistry $modules): int
    {
        $code = trim((string) ($this->option('company') ?? ''));
        $roleName = trim((string) ($this->option('role') ?? ''));
        $dry = (bool) $this->option('dry');

        if ($code === '' || $roleName === '') {
            $this->error('--company আর --role দুইটাই লাগে।');

            return self::FAILURE;
        }

        $undeclared = $this->undeclaredKeys($modules);

        if ($undeclared !== []) {
            $this->error('এই চাবিগুলো কোনো মডিউলে ঘোষিত নয় বা অনুমতির টেবিলে নেই: '.implode(', ', $undeclared));

            return self::FAILURE;
        }

        $company = Company::query()->where('code', $code)->first();

        if ($company === null) {
            $this->error("এই কোডে কোনো কোম্পানি নেই: {$code}");

            return self::FAILURE;
        }

        return (int) CompanyContext::forCompany((int) $company->id, fn (): int => $this->inCompany($company, $roleName, $dry, $audit));
    }

    private function inCompany(Company $company, string $roleName, bool $dry, AuditEngine $audit): int
    {
        $companyId = (int) $company->id;

        if (! $this->roleIsHere($companyId, $roleName)) {
            $this->error("{$company->code}-এ «{$roleName}» নামে কোনো রোল নেই।");

            return self::FAILURE;
        }

        if (strcasecmp($roleName, self::ROLE) === 0) {
            $this->error('সইয়ের রোলটা নিজেই উৎস হতে পারে না।');

            return self::FAILURE;
        }

        /*
         * ⛔ খালি-কোম্পানির "বেতনের সই" থাকলে থামা — সেটা সব কোম্পানির রোল, তাতে চাবি দেওয়া মানে সবার হাতে দেওয়া।
         * ⓘ আর spatie-র Role::create() ঐ সারি দেখে "আছে" বলে থামত।
         */
        $shared = Role::query()->where('name', self::ROLE)->where('guard_name', self::GUARD)->whereNull('company_id')->exists();

        if ($shared) {
            $this->error('«'.self::ROLE.'» নামে একটা সব-কোম্পানির রোল আছে — চাবি দিলে সব কোম্পানিতে ছড়াত। আগে সেটা সরান।');

            return self::FAILURE;
        }

        $signer = Role::query()
            ->where('name', self::ROLE)
            ->where('guard_name', self::GUARD)
            ->where('company_id', $companyId)
            ->first();

        $hasKeys = $signer === null ? [] : $signer->permissions()->pluck('name')->all();
        $newKeys = array_values(array_diff(self::KEYS, $hasKeys));

        $holders = $this->holders($companyId, $roleName);
        $already = $signer === null ? [] : $this->holdersOf($companyId, (int) $signer->id);
        $toGive = $holders->reject(fn (User $u) => in_array((int) $u->id, $already, true))->values();

        $this->line(sprintf('%s · «%s» → «%s»', $company->code, $roleName, self::ROLE));
        $this->line($signer === null
            ? '  রোল: '.($dry ? 'তৈরি হবে' : 'তৈরি হচ্ছে')
            : "  রোল: আছে (#{$signer->id})");
        $this->line('  চাবি: '.implode(', ', array_map(
            fn (string $k) => in_array($k, $newKeys, true) ? $k.' (নতুন)' : $k,
            self::KEYS,
        )));

        foreach ($holders as $user) {
            $gets = $toGive->contains('id', $user->id);
            $this->line(sprintf(
                '  %s [%s] — %s%s',
                $user->name,
                implode(', ', $this->rolesHere($user)),
                $gets ? ($dry ? 'রোল পাবেন' : 'রোল পাচ্ছেন') : 'আগে থেকেই আছে',
                $gets ? '; নতুন চাবি: '.(implode(', ', $this->keysNewFor($user)) ?: 'নেই') : '',
            ));
        }

        if ($holders->isEmpty()) {
            $this->warn("  {$company->code}-এ «{$roleName}» কারও হাতে নেই।");
        }

        if ($signer !== null && $newKeys === [] && $toGive->isEmpty()) {
            $this->info('কিছু করার নেই — রোল, চাবি আর মানুষ সব আগে থেকেই বসানো।');

            return self::SUCCESS;
        }

        if ($dry) {
            $this->info('কেবল দেখানো হলো (--dry) — কিছু লেখা হয়নি।');

            return self::SUCCESS;
        }

        DB::transaction(function () use (&$signer, $newKeys, $toGive, $roleName, $audit): void {
            if ($signer === null) {
                // ⓘ Role::create() চলতি টিম (= এই কোম্পানি) বসায় — PermissionSyncer আর রোলের পর্দার একই পথ
                $signer = Role::create(['name' => self::ROLE, 'guard_name' => self::GUARD]);
                $signer->givePermissionTo($newKeys);
                $audit->record($signer, self::ROLE_MADE, [
                    'permissions_added' => [null, implode(', ', $newKeys)],
                ], "console: holders of {$roleName}");
            } elseif ($newKeys !== []) {
                $signer->givePermissionTo($newKeys);
                $audit->record($signer, self::KEYS_ADDED, [
                    'permissions_added' => [null, implode(', ', $newKeys)],
                ], 'console: '.self::ROLE);
            }

            foreach ($toGive as $user) {
                $user->assignRole($signer);
                $user->unsetRelation('roles')->unsetRelation('permissions');
                $audit->recordAction($user, self::GIVEN, "console: {$roleName} → ".self::ROLE);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info(sprintf('হয়ে গেছে — %dটা নতুন চাবি রোলে, %dজন রোল পেলেন।', count($newKeys), $toGive->count()));

        return self::SUCCESS;
    }

    /**
     * ⓘ ঘোষিত = কোনো মডিউলের `permissions` তালিকায়, **আর** অনুমতির টেবিলে বসানো (নাহলে givePermissionTo থামত)।
     *
     * @return list<string>
     */
    private function undeclaredKeys(ModuleRegistry $modules): array
    {
        $declared = [];

        foreach ($modules->all() as $module) {
            foreach ($module->permissions as $name) {
                $declared[$name] = true;
            }
        }

        $stored = Permission::query()->where('guard_name', self::GUARD)->whereIn('name', self::KEYS)->pluck('name')->all();

        return array_values(array_filter(
            self::KEYS,
            fn (string $k) => ! isset($declared[$k]) || ! in_array($k, $stored, true),
        ));
    }

    /** ⓘ এই কোম্পানিতে এই নামের রোল দেখা যায় কি না — কোম্পানির নিজের, বা খালি-কোম্পানির (spatie দুইটাই তোলে)। */
    private function roleIsHere(int $companyId, string $roleName): bool
    {
        return Role::query()
            ->where('name', $roleName)
            ->where('guard_name', self::GUARD)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
            ->exists();
    }

    /**
     * ⭐ এই কোম্পানিতে যাঁরা এই রোল ধরেন — বরাদ্দ এই কোম্পানির, সদস্যপদ চালু, অ্যাকাউন্ট চালু।
     *
     * @return Collection<int, User>
     */
    private function holders(int $companyId, string $roleName): Collection
    {
        $ids = DB::table('model_has_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('mr.company_id', $companyId)
            ->where('mr.model_type', (new User)->getMorphClass())
            ->where('r.name', $roleName)
            ->where('r.guard_name', self::GUARD)
            ->where(fn ($q) => $q->whereNull('r.company_id')->orWhere('r.company_id', $companyId))
            ->distinct()
            ->pluck('mr.model_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return User::query()
            ->whereKey($ids)
            ->whereHas('companies', fn ($q) => $q->whereKey($companyId))
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<int>
     */
    private function holdersOf(int $companyId, int $roleId): array
    {
        return DB::table('model_has_roles')
            ->where('company_id', $companyId)
            ->where('role_id', $roleId)
            ->where('model_type', (new User)->getMorphClass())
            ->pluck('model_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return list<string> */
    private function rolesHere(User $user): array
    {
        $user->unsetRelation('roles');

        return $user->roles()->pluck('name')->sort()->values()->all();
    }

    /** @return list<string> */
    private function keysNewFor(User $user): array
    {
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $has = $user->getAllPermissions()->pluck('name')->all();
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return array_values(array_diff(self::KEYS, $has));
    }
}
