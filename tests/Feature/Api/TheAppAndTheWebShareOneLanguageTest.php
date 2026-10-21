<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\AuthController;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Core\Support\CompanyContext;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ফোন আর ওয়েব একই ভাষায় (০.৪.৪৩ ভাষা বদল; fe, ২১ অক্টোবর ২০২৬):
 *   · `PATCH /api/v1/me/locale` লেখে `users.locale` — পরের `/me` সেই ভাষা ফেরায়; ওয়েবে বদলালেও `/me` একই বলে
 *   · কেবল bn বা en — অন্য কিছু ৪২২, রেকর্ড বদলায় না
 *   · ঢোকা মানুষের দরজার বার্তা তাঁর ভাষায় (ইংরেজি বেছে নিলে ইংরেজি)
 *   · ঢোকা না থাকলে (লগইন) ফোনের `Accept-Language` — en হলে ইংরেজি, অচেনা ভাষায় বাংলা
 *   · বদলটা নিরীক্ষার খাতায় একবার, আগের আর নতুন ভাষাসহ; একই ভাষা আবার পাঠালে নতুন সারি নয়
 */
final class TheAppAndTheWebShareOneLanguageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->user = User::factory()->create([
            'current_company_id' => $this->company->id, 'is_active' => true, 'locale' => 'bn',
            'email' => 'lang-probe@abos.test', 'password' => bcrypt('right-password-1'),
        ]);
        $this->user->companies()->attach($this->company->id, ['is_active' => true]);
    }

    public function test_the_phone_sets_the_language_and_me_reads_it_back(): void
    {
        $this->api('PATCH', '/api/v1/me/locale', ['locale' => 'en'])->assertOk()->assertJsonPath('data.locale', 'en');

        $this->assertSame('en', $this->user->fresh()->locale);
        $this->api('GET', '/api/v1/me')->assertOk()->assertJsonPath('data.locale', 'en');
    }

    public function test_only_bangla_or_english_and_a_wrong_one_changes_nothing(): void
    {
        foreach (['fr', 'EN', '', 'bn-BD'] as $wrong) {
            $this->api('PATCH', '/api/v1/me/locale', ['locale' => $wrong])->assertStatus(422);
        }
        $this->assertSame('bn', $this->user->fresh()->locale, '⛔ অচেনা ভাষা রেকর্ডে বসেছে।');
    }

    public function test_a_signed_in_persons_messages_come_in_their_language(): void
    {
        // ⓘ বাংলা বেছে রাখা মানুষ ফোনের ইংরেজি হেডার পাঠালেও — রেকর্ডের ভাষাই আগে
        $bn = $this->api('PATCH', '/api/v1/me/locale', ['locale' => 'xx'], 'en')->assertStatus(422)->json('message');
        $this->assertMatchesRegularExpression('/[\x{0980}-\x{09FF}]/u', (string) $bn, '⛔ বাংলা বেছে নেওয়া মানুষ ইংরেজি বার্তা পেলেন।');

        $this->api('PATCH', '/api/v1/me/locale', ['locale' => 'en'])->assertOk();
        $en = $this->api('PATCH', '/api/v1/me/locale', ['locale' => 'xx'], 'bn')->assertStatus(422)->json('message');
        $this->assertDoesNotMatchRegularExpression('/[\x{0980}-\x{09FF}]/u', (string) $en, '⛔ ইংরেজি বেছে নেওয়া মানুষ বাংলা বার্তা পেলেন।');
    }

    public function test_without_a_session_the_phones_header_picks_the_language(): void
    {
        $login = fn (string $lang) => $this->withHeaders(['Accept-Language' => $lang])->postJson('/api/v1/auth/login', [
            'identifier' => 'lang-probe@abos.test', 'password' => 'wrong-password', 'deviceId' => 'probe-device-1',
        ])->json('message');

        $this->assertSame(__('auth.failed', [], 'en'), $login('en-US,en;q=0.9'));
        $this->assertSame(__('auth.failed', [], 'bn'), $login('bn'));
        $this->assertSame(__('auth.failed', [], 'bn'), $login('fr-FR'), '⛔ অচেনা ভাষায় বাংলা হওয়ার কথা।');
        $this->assertNotSame(__('auth.failed', [], 'en'), __('auth.failed', [], 'bn'), 'দুই ভাষার কথা আলাদা না হলে দাবিটা কিছুই মাপে না।');
    }

    public function test_a_change_on_the_web_is_the_phones_language_too(): void
    {
        $this->actingAs($this->user)->post(route('locale.switch'), ['locale' => 'en'])->assertRedirect();
        $this->app['auth']->forgetGuards();

        $this->api('GET', '/api/v1/me')->assertOk()->assertJsonPath('data.locale', 'en');
    }

    public function test_the_change_is_written_once_in_the_audit_trail(): void
    {
        $count = fn () => AuditTrail::query()->withoutGlobalScopes()
            ->where('auditable_type', $this->user->getMorphClass())->where('auditable_id', $this->user->id)
            ->where('action', 'locale_changed')->count();

        $this->api('PATCH', '/api/v1/me/locale', ['locale' => 'en'])->assertOk();
        $this->assertSame(1, $count(), '⛔ ভাষা বদল খাতায় নেই।');
        $this->api('PATCH', '/api/v1/me/locale', ['locale' => 'en'])->assertOk();
        $this->assertSame(1, $count(), 'একই ভাষা আবার — নতুন সারি নয়।');
    }

    private function api(string $method, string $uri, array $data = [], string $lang = 'bn'): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->user->fresh(), [AuthController::APP]);

        return $this->withHeaders(['Accept-Language' => $lang])->json($method, $uri, $data);
    }
}
