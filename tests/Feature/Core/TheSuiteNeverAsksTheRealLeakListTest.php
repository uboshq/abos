<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * পরীক্ষাগুলো সত্যিকারের ফাঁস-তালিকায় যায় না — ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ⓘ পাসওয়ার্ডের নিয়মে `uncompromised()` আসার পর নতুন পাসওয়ার্ড বসানো প্রতিটা
 * পরীক্ষা api.pwnedpasswords.com-এ যেত। ⭐ এই পরীক্ষা নিজে কোনো নকল বসায় না,
 * কেবল বাইরের অনুরোধ নিষেধ করে — তাই সবুজ থাকা মানেই [[TestCase::setUp()]]-এর
 * নকলটা সত্যিই সবার জন্য বসে আছে।
 */
final class TheSuiteNeverAsksTheRealLeakListTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_password_is_checked_against_the_stand_in_not_the_real_list(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $user = User::factory()->create([
            'current_company_id' => $company->id, 'is_active' => true, 'password' => Hash::make('old-pass-2026'),
        ]);
        $user->companies()->attach($company->id, ['is_active' => true]);

        Http::preventStrayRequests();

        $this->actingAs($user)->put(route('profile.password'), [
            'current_password' => 'old-pass-2026',
            'password' => 'fresh-pass-2027',
            'password_confirmation' => 'fresh-pass-2027',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('fresh-pass-2027', (string) $user->fresh()->password), 'পাসওয়ার্ড বদলায়নি — দাবিটা কিছু মাপছে না।');

        // ⓘ তালিকাটা সত্যিই জিজ্ঞেস করা হয়েছে — নকলের কাছে, আর কেবল পাঁচ অক্ষরের উপসর্গে
        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://api.pwnedpasswords.com/range/')
            && strlen(substr($r->url(), strlen('https://api.pwnedpasswords.com/range/'))) === 5);
    }
}
