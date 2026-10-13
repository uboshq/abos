<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\Location;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ এলাকার দায়িত্ব কেবল এই কোম্পানির মানুষকে — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (মাস্টার ২৩; [[LocationController]])।
 *
 * ⓘ `exists:users,id` যেকোনো কোম্পানির লগইন মেনে নিত: অন্য কোম্পানির একজনকে এই কোম্পানির দেশের দায়িত্বে বসানো যেত, আর এলাকা ধরে
 * যাওয়া খবর আর রিপোর্ট তাঁর কাছে যেত।
 */
final class AnAreaIsGivenOnlyToThisCompanysPeopleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_of_another_company_cannot_hold_an_area(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $other = Company::create(['code' => 'OTHR', 'name_en' => 'Other Co']);
        $stranger = User::factory()->create(['email' => 'stranger@other.test']);
        $stranger->companies()->attach($other->id, ['is_active' => true]);

        $this->post(route('master_data.location.store'), ['level' => 'country', 'name_en' => 'Stranger Land', 'code' => 'STR-C', 'assigned_to' => $stranger->id])
            ->assertSessionHasErrors('assigned_to');
        $this->assertFalse(Location::query()->where('code', 'STR-C')->exists(), '⛔ অন্য কোম্পানির মানুষ এই কোম্পানির এলাকার দায়িত্বে বসলেন');

        $ours = User::factory()->create(['email' => 'ours@area.test']);
        $ours->companies()->attach($company->id, ['is_active' => true]);
        $this->post(route('master_data.location.store'), ['level' => 'country', 'name_en' => 'Our Land', 'code' => 'OUR-C', 'assigned_to' => $ours->id])
            ->assertSessionHasNoErrors();
    }
}
