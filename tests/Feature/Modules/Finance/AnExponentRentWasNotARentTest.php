<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ভাড়ার টাকার ঘরে "1e5" — `numeric` নিত, তারপর bcmath ValueError ছুড়ে ৫০০ (Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬)।
 *
 * ⭐ দাবি: ভাড়ার চুক্তি আর ভাড়াটের ফর্ম টাকার ঘরে কেবল দশমিক অঙ্ক নেয় — বাকিটা কারণ বলে থামে।
 */
final class AnExponentRentWasNotARentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_the_rent_forms_refuse_an_exponent_amount(): void
    {
        $this->post(route('finance.rental.store'), ['deposit_amount' => '1e5', 'monthly_rent' => '2e4', 'monthly_adjustment' => '1e3'])
            ->assertSessionHasErrors(['deposit_amount', 'monthly_rent', 'monthly_adjustment']);

        $this->post(route('finance.tenancy.store'), ['monthly_rent' => '1e5', 'deposit_amount' => '1e5'])
            ->assertSessionHasErrors(['monthly_rent', 'deposit_amount']);
    }
}
