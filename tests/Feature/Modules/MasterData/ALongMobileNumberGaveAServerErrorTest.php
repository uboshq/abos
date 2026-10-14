<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * লম্বা মোবাইল নম্বর বা চালকের ফোনে ৫০০ — মাস্টার লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ লেখার ঘরের সাধারণ সীমা ১৯১, অথচ `mobile` আর `driver_phone` কলাম ৩২, `driver_name` ১২০ — বেশি লিখলে ডাটাবেজের ভুলে ৫০০।
 * ⓘ ঠিক মাপের লেখা আগের মতোই বসে।
 */
final class ALongMobileNumberGaveAServerErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_text_boxes_keep_to_their_columns(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(SettingsService::class)->set('master_data.vehicle_enabled', true);
        tap(app(SettingsService::class))->flush();

        $this->post('/master-data/people', ['name_en' => 'Long mobile', 'mobile' => str_repeat('0', 40)])
            ->assertRedirect()->assertSessionHasErrors('mobile');
        $this->post('/master-data/people', ['name_en' => 'Short mobile', 'mobile' => '01711000000'])->assertSessionHasNoErrors();

        $this->post('/master-data/vehicles', ['name_en' => 'Truck', 'registration_no' => 'DHAKA-METRO-1', 'owner_type' => 'own',
            'driver_name' => str_repeat('ক', 130), 'driver_phone' => str_repeat('1', 40)])
            ->assertRedirect()->assertSessionHasErrors(['driver_name', 'driver_phone']);
    }
}
