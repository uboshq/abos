<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনের ভাউচারের পক্ষ বাছাই অফলাইনেও মোবাইল আর পয়েন্টে মেলে (২৩ নভেম্বর ২০২৬): `GET /accounts/vouchers/setup`-এর
 * প্রতিটা পক্ষের সারিতে `find` — ওয়েবের পিকারের একই খোঁজার লেখা ([[PartyRegistry::pickerFind()]])। অন্য কোম্পানির পক্ষ কখনো নয়।
 */
final class TheVoucherPartyPickerCarriesItsFindTextTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_each_party_row_carries_a_find_text_with_the_mobile_and_no_other_company_party_appears(): void
    {
        $shop = Customer::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id, 'code' => 'FIND-1',
            'name_en' => 'Find Me Store', 'phone' => '01711223344', 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);

        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        $stranger = CompanyContext::forCompany($other->id, fn () => Customer::query()->create([
            'company_id' => $other->id, 'branch_id' => $other->defaultBranch()?->id, 'code' => 'FIND-X',
            'name_en' => 'Stranger Store', 'phone' => '01899887766', 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]));
        $this->assertNotSame((int) $this->company->id, (int) $stranger->company_id, 'অন্য কোম্পানির পক্ষ বসেনি — দাবিটা কিছু মাপছে না');

        $writer = $this->person(['accounts.voucher.create']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($writer->fresh(), [AuthController::APP]);

        $groups = collect($this->getJson('/api/v1/accounts/vouchers/setup?type=journal')->assertOk()->json('parties'));
        $rows = $groups->flatMap(fn (array $g) => collect($g['options'])->map(fn (array $o) => [...$o, 'type' => $g['type']]));
        $this->assertNotEmpty($rows, 'পক্ষের তালিকা খালি — দাবিটা কিছু মাপছে না');

        foreach ($rows as $row) {
            $this->assertSame(['id', 'label', 'hint', 'find', 'type'], array_keys($row), '⛔ সারির ঘর বদলেছে: '.json_encode($row, JSON_UNESCAPED_UNICODE));
            $this->assertIsString($row['find']);
            $this->assertNotSame('', trim($row['find']), '⛔ খোঁজার লেখা খালি: '.json_encode($row, JSON_UNESCAPED_UNICODE));
        }

        $mine = $rows->first(fn (array $r) => $r['type'] === 'customer' && (int) $r['id'] === (int) $shop->id);
        $this->assertNotNull($mine, '⛔ নিজের কোম্পানির গ্রাহক তালিকায় নেই।');
        $this->assertStringContainsString('01711223344', $mine['find'], '⛔ খোঁজার লেখায় মোবাইল নেই — অফলাইনে মোবাইলে মিলবে না।');
        $this->assertStringContainsString('find-1', $mine['find'], 'কোডও খোঁজার লেখায় (ছোট হাতে)');

        $this->assertNull(
            $rows->first(fn (array $r) => $r['type'] === 'customer' && (int) $r['id'] === (int) $stranger->id),
            '⛔ অন্য কোম্পানির গ্রাহক ভাউচারের পক্ষ তালিকায়।',
        );
        $this->assertFalse(
            $rows->contains(fn (array $r) => str_contains((string) $r['find'], '01899887766') || str_contains((string) $r['label'], 'Stranger Store')),
            '⛔ অন্য কোম্পানির পক্ষের মোবাইল বা নাম ফোনে গেল।',
        );
    }

    private function person(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id])->save();
        foreach ($keys as $key) {
            CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
