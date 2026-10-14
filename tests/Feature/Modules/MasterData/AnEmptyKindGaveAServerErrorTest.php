<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * করের "ধরন" বা পক্ষের ধরনের "কার উপর খাটে" "—" রাখলে ৫০০ — মাস্টার লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ প্রতিটা বাছাই-ঘর `nullable`, অথচ এই দুইটা কলাম NOT NULL। ব্রাউজার "—"-কে `''` পাঠায়, Laravel `null` বানায়, আর ডাটাবেজ
 * "cannot be null" — ঘরের পাশে বার্তার বদলে ত্রুটির পাতা। ⓘ [[HalfAFormShouldNeverBeAServerErrorTest]] কেবল `name_en` পাঠায়, তাই
 * ঘরটা না-পাঠানো (ডিফল্ট বসে) আর খালি-পাঠানো (null বসে) আলাদা — এটা দ্বিতীয়টা মাপে।
 */
final class AnEmptyKindGaveAServerErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_required_choice_is_a_field_error(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->post('/master-data/taxes', ['name_en' => 'Empty kind tax', 'rate' => '5', 'kind' => ''])
            ->assertSessionHasErrors('kind');

        $this->post('/master-data/party-types', ['name_en' => 'Empty applies type', 'applies_to' => ''])
            ->assertSessionHasErrors('applies_to');
    }
}
