<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⛔ চূড়ান্ত হিসাব দেখার চাবিতেই বছর বন্ধ হত — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️৭)।
 *
 * ⓘ [[YearEndController]]-এর দরজা ছিল কেবল `accounts.report.final`। যিনি শুধু লাভ-ক্ষতি দেখেন, তিনিও বছর বন্ধ করে আয়-ব্যয় শূন্য
 * করতে পারতেন। এখন বন্ধ `accounts.year.close`-এ; চালু কোম্পানিতে চাবিটা পায় যার মাস বন্ধের চাবি আছে।
 */
final class ClosingTheYearHasItsOwnKeyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private FinancialYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->year = FinancialYear::query()->where('is_current', true)->firstOrFail();
    }

    public function test_a_reader_of_the_final_accounts_sees_the_page_but_cannot_close(): void
    {
        $reader = $this->staff(['accounts.report.final']);

        $this->actingAs($reader)->get(route('accounts.year_end.index'))
            ->assertOk()
            ->assertSee(__('accounts::message.year_close_needs_key'))
            ->assertDontSee(route('accounts.year_end.close', $this->year), false);

        $this->actingAs($reader)->post(route('accounts.year_end.close', $this->year), ['confirm' => $this->year->name])->assertForbidden();
        $this->assertFalse((bool) $this->year->fresh()->is_closed, '⛔ কেবল দেখার চাবিতে বছর বন্ধ হল');
    }

    public function test_the_close_key_closes(): void
    {
        $closer = $this->staff(['accounts.report.final', 'accounts.year.close']);

        $this->actingAs($closer)->get(route('accounts.year_end.index'))
            ->assertOk()
            ->assertSee(route('accounts.year_end.close', $this->year), false);

        $this->actingAs($closer)->post(route('accounts.year_end.close', $this->year), ['confirm' => $this->year->name])
            ->assertRedirect(route('accounts.year_end.index'));
        $this->assertTrue((bool) $this->year->fresh()->is_closed, 'বন্ধের চাবিতে বছর বন্ধ হয়নি');
    }

    public function test_the_migration_gives_the_key_to_month_closers_only(): void
    {
        $closers = Role::findOrCreate('Month Closer', 'web');
        $closers->givePermissionTo(Permission::findOrCreate('accounts.period.close', 'web'));
        $readers = Role::findOrCreate('Final Reader', 'web');
        $readers->givePermissionTo(Permission::findOrCreate('accounts.report.final', 'web'));

        // ⓘ চাবিটা মুছে আগের অবস্থা — তারপর মাইগ্রেশন
        DB::table('role_has_permissions')->whereIn('permission_id', DB::table('permissions')->where('name', 'accounts.year.close')->select('id'))->delete();

        (require base_path('app/Modules/Accounts/Database/Migrations/2027_02_15_100000_closing_the_year_has_its_own_key.php'))->up();

        $this->assertTrue($closers->fresh()->hasPermissionTo('accounts.year.close'), 'মাস বন্ধের ভূমিকা বছর বন্ধের চাবি পায়নি');
        $this->assertFalse($readers->fresh()->hasPermissionTo('accounts.year.close'), '⛔ কেবল দেখার ভূমিকা বছর বন্ধের চাবি পেল');

        // ⓘ দ্বিতীয়বার চালালে কিছুই দুইবার নয়
        (require base_path('app/Modules/Accounts/Database/Migrations/2027_02_15_100000_closing_the_year_has_its_own_key.php'))->up();
        $this->assertSame(1, DB::table('role_has_permissions')->where('role_id', $closers->id)
            ->whereIn('permission_id', DB::table('permissions')->where('name', 'accounts.year.close')->select('id'))->count());
    }

    /** @param  list<string>  $keys */
    private function staff(array $keys): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id])->save();

        foreach ($keys as $key) {
            $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
        }

        return $user;
    }
}
