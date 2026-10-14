<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Notification;
use App\Models\NotificationJob;
use App\Models\User;
use App\Models\UserDataScope;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ ফোনের "পাঠানো" তালিকা আর আবার-চেষ্টায় শাখার দেয়াল — Notification লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⓘ ওয়েবের পর্দা ১১ অক্টোবর থেকে কেবল নিজের শাখার পাঠানো দেখায় ([[TheDeliveryScreensStayInsideTheBranchWallTest]]), কিন্তু ফোনের দরজা
 * গোটা কোম্পানির দেখাত — ময়মনসিংহে আটকানো একজন নেত্রকোনার খবরের শিরোনাম আর প্রাপক দেখতেন, আর তার ব্যর্থ পাঠানো আবার চালাতে পারতেন।
 */
final class ThePhoneDeliveriesStayInsideTheBranchWallTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_phone_lists_and_retries_only_the_branchs_own_deliveries(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $mms = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $ntk = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        CompanyContext::set($company->id, $mms->id);

        $clerk = User::factory()->create(['current_company_id' => $company->id, 'current_branch_id' => $mms->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, fn () => $clerk->givePermissionTo(
            Permission::findOrCreate('notification.deliveries', 'web'), Permission::findOrCreate('notification.retry', 'web')));
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mms->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();

        $dead = function (Branch $branch, string $title) use ($company, $clerk): NotificationJob {
            $bell = Notification::query()->withoutGlobalScopes()->create(['company_id' => $company->id, 'user_id' => $clerk->id,
                'branch_id' => $branch->id, 'type' => 'sales.delivery_stage', 'title' => $title]);

            return NotificationJob::query()->withoutGlobalScopes()->create(['company_id' => $company->id, 'notification_id' => $bell->id,
                'user_id' => $clerk->id, 'channel' => 'email', 'status' => NotificationJob::DEAD, 'attempts' => 5, 'max_attempts' => 5,
                'dead_at' => now()]);
        };
        $dead($mms, 'OURS-MMS-PAPER');
        $theirs = $dead($ntk, 'THEIRS-NTK-PAPER');

        Sanctum::actingAs($clerk->fresh(), [AuthController::APP]);
        $titles = collect($this->getJson('/api/v1/notification-deliveries')->assertOk()->json('data'))->pluck('notification')->all();
        $this->assertContains('OURS-MMS-PAPER', $titles, 'নিজের শাখার পাঠানোও তালিকায় নেই — দেয়াল সব বন্ধ করেছে');
        $this->assertNotContains('THEIRS-NTK-PAPER', $titles, '⛔ ফোনে অন্য শাখার খবরের পাঠানো দেখা গেল');

        $this->postJson('/api/v1/notification-deliveries/'.$theirs->public_id.'/retry')->assertNotFound();
        $this->assertSame(5, (int) $theirs->fresh()->max_attempts, '⛔ ফোন থেকে অন্য শাখার পাঠানো আবার চালু হলো');
    }
}
