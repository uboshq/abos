<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\PartyType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * পরিবেশকের কোড বদলে নিয়ম নীরবে বন্ধ করা যেত — মাস্টার লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ দুই পথে: (ক) সরাসরি `DISTRIB` → `DIST` লেখা চলত, অথচ [[PartyType::isDistributor()]] কোড পড়ে — "এক পয়েন্টে একজন পরিবেশক"
 * থেমে যেত; (খ) কোডের ঘর খালি করে নাম বদলালে কন্ট্রোলার নাম থেকে নতুন কোড বানাত, যদিও সেবার নিয়ম "খালি মানে হাত দিও না"।
 */
final class TheDistributorCodeCouldBeRenamedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_party_type_code_stays_and_a_blank_code_on_edit_keeps_the_old_one(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $type = PartyType::query()->where('code', 'DISTRIB')->firstOrFail();

        $this->put('/master-data/party-types/'.$type->id, ['name_en' => $type->name_en, 'code' => 'DIST', 'applies_to' => $type->applies_to])
            ->assertSessionHasErrors('code');
        $this->assertSame('DISTRIB', $type->fresh()->code, 'পরিবেশকের কোড বদলে গেল — নিয়মটা নীরবে বন্ধ।');

        $this->put('/master-data/party-types/'.$type->id, ['name_en' => 'Main distributor', 'code' => '', 'applies_to' => $type->applies_to])
            ->assertSessionHasNoErrors();
        $this->assertSame('DISTRIB', $type->fresh()->code, 'খালি কোডের ঘরে নাম থেকে নতুন কোড বসে গেল।');
        $this->assertSame('Main distributor', $type->fresh()->name_en, 'নামটাও বদলায়নি — দাবিটা কিছু মাপছে না।');
    }
}
