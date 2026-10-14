<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সংখ্যা থেকে উৎসে যাওয়ার দরজা ঠিকানা আগে বানায়, কোম্পানি পরে বদলায় — ec, Executive লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ আগে কোম্পানি বদলে তারপর ঠিকানা বানানো হতো: দরকারি অংশ না থাকলে পাতা ৫০০ দিত, আর হেডার ততক্ষণে অন্য কোম্পানিতে।
 */
final class ABrokenAddressDoesNotMoveTheHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_route_missing_its_part_is_refused_and_the_company_stays(): void
    {
        $this->seed(DemoSeeder::class);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();
        $owner->forceFill(['current_company_id' => $alpha->id])->save();

        // ⓘ `executive.report.show` চায় {slug} — এখানে দেওয়া নেই
        $this->actingAs($owner->fresh())
            ->from(route('executive.today'))
            ->post(route('executive.open'), ['company' => $beta->id, 'route' => 'executive.report.show'])
            ->assertSessionHasErrors('route');

        $this->assertSame((int) $alpha->id, (int) $owner->fresh()->current_company_id,
            '⛔ ঠিকানা বানানো যায়নি, অথচ হেডার অন্য কোম্পানিতে চলে গেছে।');
    }
}
