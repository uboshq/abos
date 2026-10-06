<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * হোমে কেবল নিজের কাজের টাইল — নকশার পর্যালোচনা, মালিক, ১ অক্টোবর ২০২৬ (ধাপ ৭ · ১, [[DashboardEngine::overall()]])।
 *
 * ⛔ আগে প্রতিটা মডিউলের টাইল সবাই পেতেন, সংখ্যা ঢাকা — বিক্রয়কর্মীর হোমে ব্যাকআপ, বেতন, সিস্টেমের ঘর।
 * ⓘ একই মানুষ: বিক্রয়কর্মী হিসেবে ঐ ঘরগুলো নেই; হিসাবরক্ষকের ভূমিকা যোগ হলে হিসাবের ঘর আসে। মালিক সব পান।
 * ⓘ আর মানটা কাটা নয় — "২৬ দিন আগে" আটটা সরু ঘরে "২৬ দিন…" হত।
 */
final class TheHomeShowsOnlyYourOwnWorkTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_person_sees_the_tiles_of_the_roles_they_hold_and_the_owner_sees_all(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $asSalesman = $this->tiles($sales);
        $this->assertNotEmpty($asSalesman, 'বিক্রয়কর্মী একটাও টাইল পান না — নিজের কাজেরও না।');
        foreach (['backup', 'system_admin', 'hr', 'restaurant', 'accounts'] as $foreign) {
            $this->assertNotContains($foreign, $asSalesman, "⛔ বিক্রয়কর্মীর হোমে \"{$foreign}\"-এর টাইল।");
        }

        $sales->assignRole('accountant');
        $this->assertContains('accounts', $this->tiles($sales->fresh()), 'হিসাবরক্ষকের ভূমিকা যোগ হলেও হিসাবের টাইল আসেনি।');

        $all = $this->tiles($owner);
        $this->assertGreaterThan(count($asSalesman), count($all), 'মালিক বিক্রয়কর্মীর চেয়ে বেশি দেখেন না।');
        $this->assertContains('accounts', $all);

        $html = (string) $this->actingAs($owner)->get(route('dashboard'))->getContent();
        // ⓘ ৬ অক্টোবর ২০২৬ থেকে মান ভাঙেও না (whitespace-nowrap), লম্বা হলে অক্ষর ছোট — কাটা তো নয়ই
        $this->assertStringContainsString('hm-kpi-value tabular mt-1 block whitespace-nowrap', $html, 'টাইলের মান এক লাইনে নয়।');
        $this->assertDoesNotMatchRegularExpression('/hm-kpi-value[^"]*truncate/', $html, 'টাইলের মান এখনো কেটে যায় (truncate)।');
    }

    /** @return list<string> টাইলের মডিউলগুলো */
    private function tiles(User $user): array
    {
        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        return array_values(array_map(fn (array $row) => (string) $row['module'], $response->viewData('overall')));
    }
}
