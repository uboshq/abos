<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Notification;
use App\Models\NotificationJob;
use App\Models\User;
use App\Models\UserDataScope;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ পাঠানোর পর্দা (কিউ, ব্যর্থ, লগ) আর আবার-চেষ্টা/বাতিলে শাখার দেয়াল ছিল না (cb-র রিভিউ, cloud/notification-management মেশানোর আগে,
 * ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ ময়মনসিংহে আটকানো একজন ব্যর্থ-তালিকা খুললে নেত্রকোনার খবরের শিরোনাম দেখতেন, আর আবার চেষ্টা বা বাতিল করতে পারতেন। এখন কেবল
 * নিজের শাখার; অন্যটার আবার-চেষ্টা ৪০৪। ⓘ একই মানুষ: শাখা দিলে খোলে।
 */
final class TheDeliveryScreensStayInsideTheBranchWallTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_limited_person_sees_and_retries_only_their_branchs_deliveries(): void
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
        $limit = UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mms->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();

        $dead = function (Branch $branch, string $title) use ($company, $clerk): NotificationJob {
            $bell = Notification::query()->withoutGlobalScopes()->create(['company_id' => $company->id, 'user_id' => $clerk->id,
                'branch_id' => $branch->id, 'type' => 'sales.delivery_stage', 'title' => $title]);

            return NotificationJob::query()->withoutGlobalScopes()->create(['company_id' => $company->id, 'notification_id' => $bell->id,
                'user_id' => $clerk->id, 'channel' => 'email', 'status' => NotificationJob::DEAD, 'attempts' => 5, 'max_attempts' => 5,
                'dead_at' => now()]);
        };
        $ours = $dead($mms, 'OURS-MMS-PAPER');
        $theirs = $dead($ntk, 'THEIRS-NTK-PAPER');

        $this->actingAs($clerk->fresh())->get(route('notification.deliveries.failed'))->assertOk()
            ->assertSee('OURS-MMS-PAPER')->assertDontSee('THEIRS-NTK-PAPER');
        $this->actingAs($clerk->fresh())->post(route('notification.deliveries.retry', $theirs))->assertNotFound();
        $this->assertSame(NotificationJob::DEAD, $theirs->fresh()->status, '⛔ অন্য শাখার পাঠানো আবার চালু হলো।');
        // ⓘ নিজের শাখার — আবার চেষ্টা নেওয়া হয় (চেষ্টার সীমা নতুন করে বসে; পরীক্ষার কিউ সাথে সাথে চালায়, তাই অবস্থা নয়, সীমা দেখা)
        $this->actingAs($clerk->fresh())->post(route('notification.deliveries.retry', $ours))->assertRedirect()->assertSessionHas('saved');
        $this->assertGreaterThan(5, (int) $ours->fresh()->max_attempts, '⛔ নিজের শাখার পাঠানোও আবার চালু করা গেল না।');
        $this->assertSame(5, (int) $theirs->fresh()->max_attempts);

        // ⓘ একই মানুষ — শাখা-সীমা তুলে দিলে দেখা যায়
        $limit->delete();
        app(DataScope::class)->forget();
        $this->actingAs($clerk->fresh())->get(route('notification.deliveries.failed'))->assertOk()->assertSee('THEIRS-NTK-PAPER');
    }
}
