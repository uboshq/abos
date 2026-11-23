<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ মালিক, ২৩ নভেম্বর ২০২৬: "সুপার অ্যাডমিন লগ ইনে তার ফুল বিজনেসের যতজন ব্যবহারকারী আছে সবগুলাই শো করার কথা" — ব্যবহারকারীর
 * তালিকা সুপার অ্যাডমিনের নিজের প্রতিটা কোম্পানির মানুষ দেখায়, চলতি কোম্পানি বা শাখা যাই থাকুক।
 * ⛔ কিন্তু যে কোম্পানিতে তিনি নেই, তার মানুষ কখনো নয় — টেন্যান্ট বিচ্ছিন্নতা আগের মতোই।
 */
final class TheOwnerSeesTheWholeBusinessPeopleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_super_admin_sees_the_people_of_every_company_he_belongs_to_and_no_other(): void
    {
        $this->seed(DemoSeeder::class);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $here = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($here->id, $here->defaultBranch()?->id);

        $sister = Company::query()->whereKeyNot($here->id)->first();
        $this->assertNotNull($sister, 'the demo needs a second company');
        $owner->companies()->syncWithoutDetaching([$sister->id]);

        $there = User::factory()->create(['name' => 'Sister Company Clerk']);
        $there->companies()->attach($sister->id);

        $stranger = User::factory()->create(['name' => 'Outside Company Clerk']);
        $outside = Company::query()->whereKeyNot([$here->id, $sister->id])->first();
        if ($outside !== null) {
            $owner->companies()->detach($outside->id);
            $stranger->companies()->attach($outside->id);
        }

        $html = (string) $this->actingAs($owner->fresh())->get(route('system_admin.user.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Sister Company Clerk', $html, 'the owner does not see a person of his other company');
        if ($outside !== null) {
            $this->assertStringNotContainsString('Outside Company Clerk', $html, '⛔ a company the owner is not in leaked into his list');
        }
    }
}
