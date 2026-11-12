<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Assistant;

use App\Models\Company;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⭐ মালিক, ১২ নভেম্বর ২০২৬: "আমার আইডি ছাড়া অন্য কারো আইডিতেই কোপাইলট দেখাচ্ছে না" — আর নতুন DSM মজুদ দেখতে পাচ্ছিলেন না।
 * ⓘ কোপাইলট প্রত্যেককে কেবল নিজের চাবির তথ্য দেখায় ([[CopilotSeesOnlyWhatItsPersonMaySeeTest]]), তাই চাবিটা সবার; খাতা মালিকের।
 */
final class EveryoneGetsTheCopilotTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_staff_role_has_the_copilot_and_the_dsm_sees_stock_without_cost(): void
    {
        $this->seed(DemoSeeder::class);
        // ⓘ ডিপ্লয়ের মতো — ডেমো রোল আগে থেকেই থাকে, টেমপ্লেট কেবল নতুন রোলে বসে; আগের রোলে বসায় মাইগ্রেশন
        (require database_path('migrations/2027_04_12_100000_only_the_owner_saw_the_copilot.php'))->up();
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $roles = Role::query()->where('company_id', $company->id)->with('permissions')->get();

        // ⭐ নতুন কোম্পানির জন্য টেমপ্লেটেও — প্রতিটা কর্মীর রোলে কোপাইলট, DSM-এ মজুদ
        $templates = app(\App\Core\Services\RoleTemplateRegistry::class)->all();
        foreach (['Accountant', 'DSM', 'Manager', 'Warehouse', 'Field Sales'] as $name) {
            $this->assertContains('assistant.use', $templates[$name] ?? [], "⛔ নতুন কোম্পানির \"{$name}\" টেমপ্লেটে কোপাইলট নেই।");
        }
        $this->assertContains('inventory.stock.view', $templates['DSM'] ?? [], '⛔ নতুন কোম্পানির DSM টেমপ্লেটে মজুদ দেখা নেই।');
        $this->assertGreaterThan(5, $roles->count(), 'প্রস্তুতিটাই ভুল — ডেমোতে রোল নেই।');

        $dsm = $roles->firstWhere('name', 'DSM');
        $this->assertNotNull($dsm, 'প্রস্তুতিটাই ভুল — DSM রোল নেই।');
        $keys = $dsm->permissions->pluck('name');
        $this->assertTrue($keys->contains('inventory.stock.view') && $keys->contains('inventory.report'), '⛔ DSM মজুদ বা মজুদের রিপোর্ট দেখতে পান না।');
        $this->assertFalse($keys->contains('inventory.cost.view'), '⛔ DSM কেনা দাম দেখেন — মালিক চাননি।');

        foreach ($roles->whereNotIn('name', ['super_admin']) as $role) {
            $names = $role->permissions->pluck('name');
            $this->assertTrue($names->contains('assistant.use'), "⛔ \"{$role->name}\" রোলে কোপাইলট নেই।");
            $this->assertFalse($names->contains('assistant.audit'), "⛔ \"{$role->name}\" রোল কোপাইলটের খাতা দেখে — কেবল মালিকের।");
        }
    }

    /** ⓘ মাইগ্রেশন — আগের রোলেও বসায়, দুইবার চালালে দুইবার বসায় না */
    public function test_the_migration_gives_the_key_to_roles_made_before_and_only_once(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $old = Role::query()->create(['name' => 'Old Clerk', 'guard_name' => 'web', 'company_id' => $company->id]);

        $migration = require database_path('migrations/2027_04_12_100000_only_the_owner_saw_the_copilot.php');
        $migration->up();
        $migration->up();

        $use = DB::table('permissions')->where('name', 'assistant.use')->value('id');
        $this->assertSame(1, DB::table('role_has_permissions')->where('role_id', $old->id)->where('permission_id', $use)->count(),
            '⛔ আগের রোলে কোপাইলট বসেনি, বা দুইবার বসেছে।');
    }
}
