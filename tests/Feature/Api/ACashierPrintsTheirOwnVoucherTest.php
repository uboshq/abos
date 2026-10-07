<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Sync\SyncService;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ক্যাশিয়ার নিজের লেখা ভাউচার ফোনে ছাপেন — আদায় বা পরিশোধের রসিদ পক্ষের হাতে দেওয়া এই কাজেরই অংশ (সমন্বয়ক, ৭ অক্টোবর ২০২৬)।
 *
 * দাবি: রিপোর্টের চাবি ছাড়া কেবল নিজের লেখা ভাউচার (অন্যেরটায় ৪০৩); রিপোর্টের চাবিতে সব, আগের মতো; লেখার চাবিও না থাকলে কিছুই নয়।
 */
final class ACashierPrintsTheirOwnVoucherTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    public function test_a_cashier_prints_their_own_voucher_and_not_another_and_the_reader_prints_both(): void
    {
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);

        $cashier = $this->person(['accounts.voucher.create']);
        $other = $this->person(['accounts.voucher.create']);
        $reader = $this->person(['accounts.report']);

        $mine = $this->voucher($cashier, 'p-1');
        $theirs = $this->voucher($other, 'p-2');

        $this->phone($cashier)->get('/api/v1/documents/Voucher/'.$mine.'/pdf')->assertOk();
        $this->phone($cashier)->get('/api/v1/documents/Voucher/'.$theirs.'/pdf')->assertForbidden();

        $this->phone($reader)->get('/api/v1/documents/Voucher/'.$mine.'/pdf')->assertOk();
        $this->phone($reader)->get('/api/v1/documents/Voucher/'.$theirs.'/pdf')->assertOk();

        // ⓘ লেখার চাবিও কেড়ে নিলে নিজের ভাউচারও নয়
        CompanyContext::forCompany($this->company->id, fn () => $cashier->revokePermissionTo('accounts.voucher.create'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->phone($cashier)->get('/api/v1/documents/Voucher/'.$mine.'/pdf')->assertForbidden();
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id])->save();
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function voucher(User $writer, string $change): string
    {
        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();
        $this->app['auth']->forgetGuards();
        $this->actingAs($writer);
        $out = app(SyncService::class)->push($writer, 'phone-print', 'accounts', [[
            'changeId' => $change, 'entityType' => 'Voucher', 'operation' => 'CREATE',
            'payloadJson' => json_encode(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'রসিদ',
                'lines' => [['account_id' => $a->id, 'debit' => '100', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '100']]]),
        ]]);
        $this->assertSame('APPLIED', $out[0]['status'], json_encode($out, JSON_UNESCAPED_UNICODE));

        return (string) $out[0]['entityId'];
    }

    private function phone(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }
}
