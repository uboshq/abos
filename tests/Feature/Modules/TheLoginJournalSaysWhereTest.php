<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\LoginPlace;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LoginAttempt;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SignsInPastTheSecondStep;
use Tests\TestCase;

/**
 * ঢোকার খাতা জায়গার নাম বলে — মালিক, ১ অক্টোবর ২০২৬: *"jaygar nam soho dekhabe, zemon bridge more, mymensingh"*।
 *
 * ⓘ মালিক বেছেছেন লগইনের সময় ব্রাউজার লোকেশন ([[LoginPlace]])। দাবিগুলো: লোকেশন দিলে স্থানাঙ্ক আর নাম বসে,
 * খাতায় নাম ও ম্যাপের লিংক দেখায়; না দিলে লগইন আগের মতো, ঘরে "দেয়নি"; বানানো বাজে মান নীরবে বাদ; আর
 * ব্রাউজারকে নিজের পাতায় লোকেশন চাইতে দেওয়া হয় (`geolocation=(self)`) — নাহলে ব্রাউজার না চেয়েই "না" বলত।
 */
final class TheLoginJournalSaysWhereTest extends TestCase
{
    use RefreshDatabase;
    use SignsInPastTheSecondStep;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'address' => ['road' => 'Bridge More', 'city' => 'Mymensingh', 'state' => 'Mymensingh Division'],
            ]),
        ]);
    }

    public function test_a_shared_location_is_written_with_its_name_and_shown_with_a_map_link(): void
    {
        $this->login(['geo_lat' => '24.756890', 'geo_lng' => '90.406510', 'geo_acc' => '18']);

        $row = LoginAttempt::query()->latestFirst()->firstOrFail();

        $this->assertTrue($row->succeeded, 'প্রস্তুতিটাই ভুল — লগইন হয়নি।');
        $this->assertSame('24.756890', (string) $row->latitude, '⛔ লোকেশন খাতায় বসেনি।');
        $this->assertSame('90.406510', (string) $row->longitude);
        $this->assertSame(18, $row->accuracy_m);
        $this->assertSame('Bridge More, Mymensingh', $row->place, '⛔ জায়গার নাম বসেনি।');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'lat=24.756890') && $request->hasHeader('User-Agent'));

        $page = $this->actingAs($this->owner)->get(route('governance.login.index'))->assertOk();
        $page->assertSee('Bridge More, Mymensingh');
        $page->assertSee('openstreetmap.org/?mlat=24.756890', false);
    }

    public function test_no_location_still_signs_in_and_says_so(): void
    {
        $this->login([]);

        $row = LoginAttempt::query()->latestFirst()->firstOrFail();

        $this->assertTrue($row->succeeded, '⛔ লোকেশন না দেওয়ায় লগইন আটকেছে — এটা খাতা, পাহারা নয়।');
        $this->assertNull($row->latitude);
        $this->assertNull($row->place);
        Http::assertNothingSent();

        $this->actingAs($this->owner)->get(route('governance.login.index'))->assertOk()
            ->assertSee(__('governance::message.no_place'));
    }

    /** ⛔ ঘরগুলো হাতে বানানো যায় — সীমার বাইরের মান নীরবে বাদ, খাতা ভাঙে না। */
    public function test_a_made_up_location_is_dropped_and_the_login_still_works(): void
    {
        $this->login(['geo_lat' => '999', 'geo_lng' => 'abc', 'geo_acc' => '-5']);

        $row = LoginAttempt::query()->latestFirst()->firstOrFail();

        $this->assertTrue($row->succeeded);
        $this->assertNull($row->latitude, '⛔ সীমার বাইরের স্থানাঙ্ক খাতায় বসেছে।');
    }

    public function test_the_browser_may_ask_for_location_on_our_own_pages_only(): void
    {
        $header = (string) $this->get(route('login'))->headers->get('Permissions-Policy');

        $this->assertStringContainsString('geolocation=(self)', $header, '⛔ ব্রাউজার লোকেশন চাইতেই পারবে না — না চেয়েই "না" বলবে।');
        $this->assertStringContainsString('camera=()', $header, 'বাকি যন্ত্রগুলো আগের মতোই বন্ধ থাকার কথা।');
    }

    public function test_the_name_keeps_the_nearby_place_then_the_town(): void
    {
        $place = app(LoginPlace::class);

        $this->assertSame('Bridge More, Mymensingh', $place->compose(['road' => 'Bridge More', 'city' => 'Mymensingh']));
        $this->assertSame('Ganginar Par, Mymensingh', $place->compose(['suburb' => 'Ganginar Par', 'county' => 'Mymensingh']));
        $this->assertNull($place->compose([]));
    }

    /** @param  array<string, string>  $geo */
    private function login(array $geo): TestResponse
    {
        return $this->post(route('login.store'), [
            'identifier' => $this->owner->email,
            'password' => 'password',
            'code' => $this->secondStepCode($this->owner->email, 0),
            ...$geo,
        ]);
    }
}
