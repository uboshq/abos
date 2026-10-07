<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কোনো কোম্পানির সদস্য নন এমন কর্মী লগইন করতেই ভাঙা পাতা পেতেন — লাইভ, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ⛔ ডেমো কোম্পানি মোছার পর তিনজন কর্মী কোনো কোম্পানিতে রইলেন না। একজন লগইন
 * করতেই হোম স্ক্রিন ৫০০ দিল — *"No company in context while querying CashTill"* —
 * আর মালিকের চোখে সেটা "erp চলছে না"।
 *
 * ⓘ একই মানুষ দুইবার: আগে কোম্পানি ছাড়া (পরিষ্কার ৪০৩, কারণসহ; বেরোনো যায়), তারপর
 * একটা কোম্পানিতে যোগ করে (হোম স্ক্রিন খোলে) — কেবল সদস্যপদটাই আলাদা।
 */
final class AStaffMemberWithNoCompanyGotABrokenPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_company_gives_a_clear_page_not_a_crash_and_membership_opens_it(): void
    {
        $this->seed(DemoSeeder::class);

        $person = User::query()->create([
            'name' => 'Nobody Yet',
            'email' => 'nobody.yet@example.test',
            'password' => bcrypt('a-long-password-12'),
            'locale' => 'bn',
        ]);

        $this->actingAs($person)->get(route('dashboard'))
            ->assertStatus(403)
            ->assertSee(__('core.no_company', [], 'bn'));

        /* ⓘ বেরোনোর দরজা খোলা — নাহলে মানুষটা আটকে থাকতেন */
        $this->actingAs($person)->post(route('logout'))->assertRedirect();

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $person->companies()->attach($company->id, ['is_active' => true]);

        $this->actingAs($person->fresh())->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(__('core.no_company', [], 'bn'));
    }
}
