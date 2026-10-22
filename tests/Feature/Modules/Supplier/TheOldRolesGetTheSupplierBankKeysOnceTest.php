<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Console\Commands\GrantSupplierBankKeys;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ নকশা দফা #৬ — ডিপ্লয়ের ধাপ: চালু কোম্পানির পুরনো Accountant আর Manager রোল ব্যাংক তথ্যের দুই চাবি পায়
 * (`abos:grant-supplier-bank-keys`; [[GrantSupplierBankKeys]])।
 *
 * ⛔ `role_templates` কেবল নতুন রোলে বসে — পুরনো রোলে চাবি না দিলে ডিপ্লয়ের পর মালিক ছাড়া কেউ সই দিতে পারতেন না।
 * দাবি: চাবি সরানো পুরনো রোল; `--dry` কিছু লেখে না; আসল চালানোয় দুই রোলেই দুই চাবি, নিরীক্ষায়; আবার চালালে কিছু বদলায় না;
 * `--company` দিলে অন্য কোম্পানি ছোঁয় না।
 */
final class TheOldRolesGetTheSupplierBankKeysOnceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_writes_nothing_the_run_grants_both_keys_and_a_second_run_changes_nothing(): void
    {
        $this->seed(DemoSeeder::class);
        $tdepot = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $fmart = Company::query()->where('code', 'FMART')->firstOrFail();

        // ⓘ ডিপ্লয়ের আগের অবস্থা — রোল আছে, চাবি নেই
        foreach ([$tdepot, $fmart] as $company) {
            CompanyContext::forCompany($company->id, function () use ($company): void {
                foreach ($this->roles($company->id) as $role) {
                    foreach (GrantSupplierBankKeys::KEYS as $key) {
                        if ($role->hasPermissionTo($key)) {
                            $role->revokePermissionTo($key);
                        }
                    }
                }
            });
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertNotEmpty($this->roles($tdepot->id), 'ⓘ TDEPOT-এ Accountant/Manager রোলই নেই — দাবি কিছু মাপছে না।');

        $this->artisan('abos:grant-supplier-bank-keys', ['--dry' => true, '--company' => 'TDEPOT'])->assertSuccessful();
        $this->assertSame(0, $this->keysOn($tdepot->id), '⛔ --dry চাবি লিখে দিল।');

        $this->artisan('abos:grant-supplier-bank-keys', ['--company' => 'TDEPOT'])->assertSuccessful();
        $roles = $this->roles($tdepot->id);
        $this->assertSame(count($roles) * 2, $this->keysOn($tdepot->id), '⛔ প্রতিটা রোলে দুই চাবি বসেনি।');
        $this->assertSame(0, $this->keysOn($fmart->id), '⛔ --company=TDEPOT অন্য কোম্পানির রোল ছুঁয়ে দিল।');

        $audited = AuditTrail::query()->withoutGlobalScopes()->where('action', GrantSupplierBankKeys::KEYS_ADDED)
            ->where('auditable_type', Role::class)->whereIn('auditable_id', collect($roles)->pluck('id'))->count();
        $this->assertSame(count($roles), $audited, '⛔ চাবি বসল, নিরীক্ষায় নেই।');

        // ⓘ আবার — কিছু বদলায় না
        $this->artisan('abos:grant-supplier-bank-keys', ['--company' => 'TDEPOT'])->assertSuccessful();
        $this->assertSame(count($roles) * 2, $this->keysOn($tdepot->id));
        $this->assertSame($audited, AuditTrail::query()->withoutGlobalScopes()->where('action', GrantSupplierBankKeys::KEYS_ADDED)
            ->where('auditable_type', Role::class)->whereIn('auditable_id', collect($roles)->pluck('id'))->count(),
            '⛔ দ্বিতীয় চালানো আবার লিখল — কমান্ডটা বারবার চালানো নিরাপদ নয়।');
    }

    /** @return list<Role> */
    private function roles(int $companyId): array
    {
        return Role::query()->where('guard_name', 'web')->where('company_id', $companyId)
            ->whereIn('name', GrantSupplierBankKeys::ROLES)->get()->all();
    }

    private function keysOn(int $companyId): int
    {
        $n = 0;

        foreach ($this->roles($companyId) as $role) {
            $n += $role->permissions()->whereIn('name', GrantSupplierBankKeys::KEYS)->count();
        }

        return $n;
    }
}
