<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ সরবরাহকারীর ব্যাংক তথ্যের দুই চাবি — চালু কোম্পানিগুলোর পুরনো Accountant আর Manager রোলে (নকশা দফা #৬, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ `role_templates` কেবল নতুন রোল বানানোর দিন খাটে ([[PermissionSyncer::applyRoleTemplates()]]) — যে কোম্পানিতে রোলটা আগে
 * থেকেই আছে, সেখানে নতুন চাবি নিজে থেকে বসে না। ⛔ না বসালে ডিপ্লয়ের পর কেউই ব্যাংক তথ্যের বদলে সই দিতে পারতেন না, মালিক ছাড়া।
 *
 *   php artisan abos:grant-supplier-bank-keys --dry          সব কোম্পানি, কেবল দেখায়
 *   php artisan abos:grant-supplier-bank-keys --company=UB   একটা কোম্পানি, লেখে
 *
 * ⓘ বারবার চালানো নিরাপদ — যা আছে তা ছোঁয় না। ⛔ কেবল কোম্পানির নিজের রোল (`company_id` বসানো); সব-কোম্পানির রোলে দিলে
 * চাবি সব কোম্পানিতে ছড়াত, তাই সেটা সতর্ক করে বাদ দেয়। প্রতিটা বদল নিরীক্ষায়।
 */
final class GrantSupplierBankKeys extends Command
{
    public const GUARD = 'web';

    public const KEYS = ['supplier.bank.view', 'supplier.bank.approve'];

    public const ROLES = ['Accountant', 'Manager'];

    public const KEYS_ADDED = 'role_permissions_changed';

    protected $signature = 'abos:grant-supplier-bank-keys
                            {--company= : কেবল এই কোডের কোম্পানি (না দিলে সব)}
                            {--dry : কেবল দেখায়, কিছু লেখে না}';

    protected $description = 'Give the supplier bank-details keys (view, approve) to each company\'s existing Accountant and Manager roles';

    public function handle(AuditEngine $audit, ModuleRegistry $modules): int
    {
        $dry = (bool) $this->option('dry');
        $code = trim((string) ($this->option('company') ?? ''));

        $declared = collect($modules->all())->flatMap(fn ($m) => $m->permissions)->all();
        $stored = Permission::query()->where('guard_name', self::GUARD)->whereIn('name', self::KEYS)->pluck('name')->all();
        $missing = array_values(array_filter(self::KEYS, fn (string $k) => ! in_array($k, $declared, true) || ! in_array($k, $stored, true)));

        if ($missing !== []) {
            $this->error('এই চাবিগুলো ঘোষিত নয় বা অনুমতির টেবিলে নেই (আগে abos:sync-permissions চালান): '.implode(', ', $missing));

            return self::FAILURE;
        }

        $companies = Company::query()->when($code !== '', fn ($q) => $q->where('code', $code))->orderBy('id')->get();

        if ($companies->isEmpty()) {
            $this->error($code === '' ? 'কোনো কোম্পানি নেই।' : "এই কোডে কোনো কোম্পানি নেই: {$code}");

            return self::FAILURE;
        }

        $shared = Role::query()->where('guard_name', self::GUARD)->whereNull('company_id')->whereIn('name', self::ROLES)->pluck('name')->all();

        foreach ($shared as $name) {
            $this->warn("«{$name}» একটা সব-কোম্পানির রোল — চাবি দিলে সব কোম্পানিতে ছড়াত, তাই বাদ।");
        }

        $added = 0;

        foreach ($companies as $company) {
            $added += (int) CompanyContext::forCompany((int) $company->id, fn (): int => $this->inCompany($company, $dry, $audit));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info($dry
            ? "কেবল দেখানো হলো (--dry) — {$added}টা চাবি বসত, কিছু লেখা হয়নি।"
            : "হয়ে গেছে — {$added}টা চাবি বসল।");

        return self::SUCCESS;
    }

    private function inCompany(Company $company, bool $dry, AuditEngine $audit): int
    {
        $added = 0;

        $roles = Role::query()
            ->where('guard_name', self::GUARD)
            ->where('company_id', (int) $company->id)
            ->whereIn('name', self::ROLES)
            ->orderBy('name')
            ->get();

        if ($roles->isEmpty()) {
            $this->line("{$company->code}: Accountant/Manager রোল নেই — কিছু করার নেই।");

            return 0;
        }

        foreach ($roles as $role) {
            $has = $role->permissions()->pluck('name')->all();
            $new = array_values(array_diff(self::KEYS, $has));

            $this->line(sprintf('%s · %s: %s', $company->code, $role->name, $new === [] ? 'আগে থেকেই আছে' : implode(', ', $new).($dry ? ' (বসবে)' : ' (বসছে)')));

            if ($new === [] || $dry) {
                $added += count($new);

                continue;
            }

            DB::transaction(function () use ($role, $new, $audit): void {
                $role->givePermissionTo($new);
                $audit->record($role, self::KEYS_ADDED, [
                    'permissions_added' => [null, implode(', ', $new)],
                ], 'console: abos:grant-supplier-bank-keys');
            });

            $added += count($new);
        }

        return $added;
    }
}
