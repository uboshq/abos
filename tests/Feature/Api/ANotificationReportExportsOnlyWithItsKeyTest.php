<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ ফোনের রপ্তানির দরজা দিয়ে বিজ্ঞপ্তির রিপোর্ট রপ্তানির চাবি ছাড়াই ফাইলে নেওয়া যেত (cb-র রিভিউ, cloud/notification-management
 * মেশানোর আগে, ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ ওয়েবে রপ্তানি `notification.reports.export` চায়; ফোনের দরজা কেবল দেখার চাবি দেখত — প্রাপকের নাম, প্রদানকারীর রেফারেন্স আর ভুলসহ
 * এক লাখ সারি পর্যন্ত। ⓘ একই মানুষ: রপ্তানির চাবি ছাড়া ৪০৩, চাবি দিলে ফাইল।
 */
final class ANotificationReportExportsOnlyWithItsKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_phone_export_of_a_notification_report_needs_the_export_key(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $reader = User::factory()->create(['current_company_id' => $company->id, 'current_branch_id' => $company->defaultBranch()?->id, 'is_active' => true]);
        $reader->companies()->attach($company->id, ['is_active' => true]);
        $grant = function (string $key) use ($company, $reader): void {
            CompanyContext::forCompany($company->id, fn () => $reader->givePermissionTo(Permission::findOrCreate($key, 'web')));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        };
        $grant('notification.reports');
        // ⓘ ফোনে বিজ্ঞপ্তি মডিউল নিজে থেকে বন্ধ ([[PhoneModules::ON_BY_DEFAULT]]) — মালিক চালু করলে দরজাটা খোলে, তখনকার কথা
        app(SettingsService::class)->set(PhoneModules::PREFIX.'notification', true);
        app(SettingsService::class)->flush();

        $url = '/api/v1/reports/notification.attempt_history/export?format=csv';

        // ⓘ দেখার চাবিতে রিপোর্টটা খোলে — তাই নিচের ৪০৩ রপ্তানির চাবির জন্যই, অন্য কিছুর নয়
        Sanctum::actingAs($reader->fresh(), [AuthController::APP]);
        $this->getJson('/api/v1/reports/notification.attempt_history')->assertOk();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($reader->fresh(), [AuthController::APP]);
        $this->getJson($url)->assertForbidden();

        $grant('notification.reports.export');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($reader->fresh(), [AuthController::APP]);
        $this->get($url)->assertOk();
    }
}
