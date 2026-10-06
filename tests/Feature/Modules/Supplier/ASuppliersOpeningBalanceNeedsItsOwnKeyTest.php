<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * সরবরাহকারীর শুরুর দেনা — নিজের চাবি `supplier.opening_balance` (অডিট ⛔১২, ৬ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে সরবরাহকারী বানানোর চাবিতেই যেকোনো অঙ্কের দেনা খাতায় বসত, আর তারপর সেটা পরিশোধও করা যেত।
 * দাবি, একই মানুষ দুইবার: কেবল `supplier.create` → শুরুর দেনাসহ বানানো ফেরে, খাতায় কিছু বসে না; শূন্য দেনায় বানানো চলে;
 * চাবি দিলে → বানানো চলে আর খাতায় ঠিক সেই অঙ্ক বসে (দাবিটা সত্যিই তাকায়)।
 */
final class ASuppliersOpeningBalanceNeedsItsOwnKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_opening_balance_key_puts_a_suppliers_opening_debt_in_the_books(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $clerk = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        $this->grant($clerk, 'supplier.create');
        $this->actingAs($clerk->fresh());

        $books = fn (string $code) => (string) (DB::table('ledger_entries')->where('company_id', $company->id)
            ->where('party_type', Supplier::drillSourceType())
            ->whereIn('party_id', Supplier::query()->where('code', $code)->select('id'))
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as n')->value('n') ?? '0');

        // ⛔ চাবি ছাড়া — ফেরে, কারণসহ
        try {
            app(SupplierService::class)->create(['code' => 'OPN-1', 'name_en' => 'Opening One', 'opening_balance' => '125000', 'opening_date' => now()->toDateString()]);
            $this->fail('⛔ চাবি ছাড়াই শুরুর দেনা বসল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('opening_balance', $e->errors());
        }
        $this->assertFalse(Supplier::query()->where('code', 'OPN-1')->exists(), '⛔ ফেরানোর পরেও সরবরাহকারী রয়ে গেল।');

        // ⓘ শূন্য দেনায় চাবি লাগে না — সরবরাহকারী বানানো আটকায় না
        app(SupplierService::class)->create(['code' => 'OPN-0', 'name_en' => 'Opening Zero', 'opening_balance' => '0']);
        $this->assertTrue(Supplier::query()->where('code', 'OPN-0')->exists());

        // ⭐ চাবি দিলে — বসে, ঠিক সেই অঙ্ক
        $this->grant($clerk, 'supplier.opening_balance');
        $this->actingAs($clerk->fresh());
        app(SupplierService::class)->create(['code' => 'OPN-2', 'name_en' => 'Opening Two', 'opening_balance' => '125000', 'opening_date' => now()->toDateString()]);
        $this->assertSame(0, bccomp($books('OPN-2'), '125000', 4), 'চাবি থাকতেও শুরুর দেনা খাতায় বসেনি — দাবি অন্ধ।');
    }

    private function grant(User $user, string $key): void
    {
        $user->unsetRelation('permissions');
        $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
