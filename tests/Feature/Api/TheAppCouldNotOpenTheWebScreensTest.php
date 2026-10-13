<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Security\Totp;
use App\Core\Services\SettingsService;
use App\Core\Services\WebInsideApp;
use App\Core\Support\CompanyContext;
use App\Models\LoginAttempt;
use App\Models\SyncDevice;
use App\Models\User;
use App\Models\WebHandoff;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ⭐ অ্যাপের ভিতরে ওয়েবের পর্দা — মালিক, ১৩ অক্টোবর ২০২৬: *"web e na dhuke jar jar kaj 100% app ei korte pare"*।
 *
 * ফোন একবারের চাবি চায় (`POST /api/v1/web-handoff`), অ্যাপের ভিতরের ওয়েব-জানালা সেটা দিয়ে একবার ঢোকে
 * (`GET /handoff/{token}`)। এখানে প্রতিটা দেয়াল একবার করে ঠেলে দেখা হয়: একবারই, ৬০ সেকেন্ড, ফোনে বাঁধা, মানুষে বাঁধা,
 * কোম্পানিতে বাঁধা, সুইচ, বাইরের ঠিকানা নয়, দুই ধাপ এড়ানো নয়, খাতায় লেখা, আর SR মজুদ দেখেন না।
 */
final class TheAppCouldNotOpenTheWebScreensTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = 'handset-web-inside';

    private const OTHER_PHONE = 'handset-somebody-else';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    // ── চাবি আর ঢোকা ────────────────────────────────────────────────────────

    public function test_the_owner_opens_a_web_screen_inside_the_app_signed_in_where_he_asked(): void
    {
        $access = $this->ownerToken();
        $path = $this->menuPath($access, 'accounts.coa.index');

        $url = $this->key($access, $path)->assertOk()->assertJsonPath('expiresInSeconds', 60)->json('url');

        $this->open($url, self::PHONE)->assertRedirect($path);
        $this->assertAuthenticatedAs($this->user('owner@abos.test'), 'web');
        $this->assertTrue(session(WebInsideApp::SESSION), 'অ্যাপের ভিতরের সেশনে দাগ বসেনি।');

        $row = LoginAttempt::query()->latest('id')->firstOrFail();
        $this->assertTrue($row->succeeded);
        $this->assertSame('app-web:owner@abos.test', $row->identifier, '⛔ ঢোকাটা খাতায় ওঠেনি।');
    }

    public function test_the_key_opens_only_once(): void
    {
        $access = $this->ownerToken();
        $url = $this->key($access, $this->menuPath($access, 'accounts.coa.index'))->json('url');

        $this->open($url, self::PHONE)->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->open($url, self::PHONE)->assertForbidden();
    }

    public function test_the_key_dies_after_a_minute(): void
    {
        $access = $this->ownerToken();
        $url = $this->key($access, $this->menuPath($access, 'accounts.coa.index'))->json('url');

        $this->travel(61)->seconds();

        $this->open($url, self::PHONE)->assertForbidden();
        $this->assertGuest('web');
    }

    public function test_the_key_is_bound_to_the_phone_that_asked_and_a_wrong_phone_burns_it(): void
    {
        $access = $this->ownerToken();
        $url = $this->key($access, $this->menuPath($access, 'accounts.coa.index'))->json('url');

        $this->open($url, self::OTHER_PHONE)->assertForbidden();
        $this->assertGuest('web');

        // ⛔ ফাঁস হওয়া লিংক একবার ভুল ফোনে খুললেই মরা — আসল ফোনেও আর নয়
        $this->open($url, self::PHONE)->assertForbidden();
        $this->assertGuest('web');
    }

    public function test_a_key_without_the_phone_header_does_not_open(): void
    {
        $access = $this->ownerToken();
        $url = $this->key($access, $this->menuPath($access, 'accounts.coa.index'))->json('url');

        $this->app['auth']->forgetGuards();
        $this->get($url)->assertForbidden();
        $this->assertGuest('web');
    }

    public function test_the_key_is_never_kept_as_written(): void
    {
        $access = $this->ownerToken();
        $url = $this->key($access, $this->menuPath($access, 'accounts.coa.index'))->json('url');
        $plain = basename((string) parse_url($url, PHP_URL_PATH));

        $this->assertSame(43, strlen($plain), 'চাবি ২৫৬-বিটের নয়।');
        $this->assertSame(0, WebHandoff::query()->where('token_hash', $plain)->count());
        $this->assertSame(1, WebHandoff::query()->where('token_hash', hash('sha256', $plain))->count());
    }

    public function test_somebody_elses_key_does_not_open_on_your_phone_and_your_token_makes_only_your_key(): void
    {
        $owner = $this->ownerToken();
        $ownerUrl = $this->key($owner, $this->menuPath($owner, 'accounts.coa.index'))->json('url');

        // ⓘ হিসাবরক্ষক নিজের ফোনে মালিকের লিংক খোলেন — নিজের ফোনের হেডারে
        $this->login('accounts@abos.test', self::OTHER_PHONE);
        $this->open($ownerUrl, self::OTHER_PHONE)->assertForbidden();
        $this->assertGuest('web');

        // ⓘ নিজের টোকেনে চাবি — ঢোকেন নিজেই, মালিক নন
        $mine = $this->login('accounts@abos.test', self::OTHER_PHONE)->json('accessToken');
        $url = $this->key($mine, $this->menuPath($mine, 'accounts.coa.index'))->assertOk()->json('url');
        $this->open($url, self::OTHER_PHONE)->assertRedirect();
        $this->assertAuthenticatedAs($this->user('accounts@abos.test'), 'web');
    }

    public function test_a_refresh_token_gets_no_key(): void
    {
        $refresh = $this->login('accounts@abos.test')->json('refreshToken');

        $this->key($refresh, '/')->assertForbidden();
        $this->assertSame(0, WebHandoff::query()->count());
    }

    // ── কোম্পানি ও শাখা ──────────────────────────────────────────────────────

    public function test_the_key_lands_in_the_company_it_was_made_for(): void
    {
        $access = $this->ownerToken();
        $owner = $this->user('owner@abos.test');
        $alpha = (int) $owner->current_company_id;
        $url = $this->key($access, $this->menuPath($access, 'accounts.coa.index'))->json('url');

        // ⓘ মাঝে অন্য যন্ত্রে অন্য কোম্পানিতে সরে গেছেন
        $beta = (int) $owner->companies()->where('companies.id', '!=', $alpha)->value('companies.id');
        $owner->switchCompany($beta);

        $this->open($url, self::PHONE)->assertRedirect();
        $this->assertSame($alpha, (int) $owner->fresh()->current_company_id, '⛔ চাবি এক কোম্পানির, ঢোকাল আরেকটায়।');
    }

    public function test_a_key_for_a_company_he_was_taken_out_of_does_not_open(): void
    {
        $access = $this->login('accounts@abos.test')->json('accessToken');
        $url = $this->key($access, $this->menuPath($access, 'accounts.coa.index'))->json('url');

        $accountant = $this->user('accounts@abos.test');
        $accountant->companies()->detach((int) $accountant->current_company_id);

        $this->open($url, self::PHONE)->assertForbidden();
        $this->assertGuest('web');
    }

    // ── সুইচ ─────────────────────────────────────────────────────────────────

    public function test_the_company_switch_off_gives_no_key_no_menu_path_and_kills_a_key_already_made(): void
    {
        $access = $this->login('accounts@abos.test')->json('accessToken');
        $path = $this->menuPath($access, 'accounts.coa.index');
        $url = $this->key($access, $path)->assertOk()->json('url');

        $this->switchOff((int) $this->user('accounts@abos.test')->current_company_id);

        $this->key($access, $path)->assertForbidden()->assertJsonPath('reason', 'web_inside_app_off');
        $this->open($url, self::PHONE)->assertForbidden();
        $this->assertGuest('web');

        $me = $this->me($access)->assertOk()->assertJsonPath('webInsideApp', false);
        $this->assertSame([], array_values(array_filter($this->rows($me->json('menu')), fn (array $r) => $r['web'] !== null)));
    }

    // ── কোথায় যাওয়া যায় ─────────────────────────────────────────────────────

    public function test_no_key_is_made_for_somewhere_outside_or_for_the_doors_themselves(): void
    {
        $access = $this->login('accounts@abos.test')->json('accessToken');
        $real = $this->menuPath($access, 'accounts.coa.index');

        foreach ([
            // ⛔ একটা আসল পর্দার পথ, অন্যের ঠিকানায় — পথ মিললেও বাইরে নয়
            'https://evil.example'.$real,
            '//evil.example'.$real,
            'https://evil.example/x',
            '//evil.example/x',
            '/\\evil.example',
            'javascript:alert(1)',
            'evil.example/x',
            '/%0d%0aLocation:%20https://evil.example',
            "/ evil",
            '/@evil.example',
            '/login',
            '/logout',
            '/no-such-screen-anywhere',
            '/'.str_repeat('a', 600),
        ] as $path) {
            $this->key($access, $path)->assertStatus(422);
        }

        $this->assertSame(0, WebHandoff::query()->count(), '⛔ বাইরের ঠিকানায় চাবি বেরিয়েছে।');
    }

    // ── দুই ধাপ ──────────────────────────────────────────────────────────────

    public function test_a_phone_that_never_passed_the_second_step_must_give_the_code_like_the_web_login(): void
    {
        $access = $this->ownerToken();
        $path = $this->menuPath($access, 'accounts.coa.index');
        $secret = (string) $this->user('owner@abos.test')->mfa_secret;

        // ⓘ দুই ধাপ চালুর আগের লগইন — হ্যান্ডসেটে কোড পেরোনোর দাগ নেই
        SyncDevice::query()->withoutGlobalScopes()->where('device_id', self::PHONE)->update(['second_step_at' => null]);

        $this->key($access, $path)->assertStatus(409)->assertJsonPath('needsCode', true)->assertJsonPath('codeWasWrong', false);
        $this->key($access, $path, '000000')->assertStatus(409)->assertJsonPath('codeWasWrong', true);
        $this->assertSame(LoginAttempt::WRONG_CODE, LoginAttempt::query()->latest('id')->firstOrFail()->reason);
        $this->assertSame(0, WebHandoff::query()->count(), '⛔ কোড ছাড়াই চাবি বেরিয়েছে।');

        $this->key($access, $path, Totp::codeFor($secret, time() + 30))->assertOk();

        // ⓘ একবার পেরোলে এই ফোনে আবার নয়
        $this->key($access, $path)->assertOk();
    }

    public function test_two_step_turned_on_after_the_phone_signed_in_is_asked_for(): void
    {
        $access = $this->login('accounts@abos.test')->json('accessToken');
        $path = $this->menuPath($access, 'accounts.coa.index');

        $secret = Totp::newSecret();
        $this->user('accounts@abos.test')->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => now()])->save();

        $this->key($access, $path)->assertStatus(409)->assertJsonPath('needsCode', true);
        $this->key($access, $path, Totp::codeFor($secret))->assertOk();
    }

    public function test_a_person_whose_two_step_is_required_but_not_set_up_gets_no_key(): void
    {
        $access = $this->login('accounts@abos.test')->json('accessToken');
        $path = $this->menuPath($access, 'accounts.coa.index');

        $this->user('accounts@abos.test')->forceFill(['two_step_required' => true])->save();

        $this->key($access, $path)->assertForbidden()->assertJsonPath('reason', 'two_step_missing');
        $this->assertSame(0, WebHandoff::query()->count());
    }

    public function test_a_locked_name_gets_no_code_check_and_no_key(): void
    {
        $access = $this->ownerToken();
        $path = $this->menuPath($access, 'accounts.coa.index');
        SyncDevice::query()->withoutGlobalScopes()->where('device_id', self::PHONE)->update(['second_step_at' => null]);

        // ⓘ তালা গোনে শেষ সফল ঢোকার **পরের** ভুল — একই সেকেন্ডের নয়
        $this->travel(2)->seconds();

        for ($i = 0; $i < 8; $i++) {
            $this->key($access, $path, '000000')->assertStatus(409);
        }

        $secret = (string) $this->user('owner@abos.test')->mfa_secret;
        $this->key($access, $path, Totp::codeFor($secret, time() + 30))->assertStatus(429);
        $this->assertSame(0, WebHandoff::query()->count());
    }

    // ── খাতা ─────────────────────────────────────────────────────────────────

    public function test_a_refused_key_is_written_down(): void
    {
        $this->app['auth']->forgetGuards();
        $this->get('/handoff/'.str_repeat('A', 43), [\App\Http\Controllers\Auth\WebHandoffController::DEVICE_HEADER => self::PHONE])
            ->assertForbidden()
            ->assertSee(__('mobile.web_handoff_refused_title'));

        $row = LoginAttempt::query()->latest('id')->firstOrFail();
        $this->assertFalse($row->succeeded);
        $this->assertSame(LoginAttempt::HANDOFF_REFUSED, $row->reason);
    }

    public function test_the_door_has_a_speed_limit(): void
    {
        $access = $this->login('accounts@abos.test')->json('accessToken');

        for ($i = 0; $i < 30; $i++) {
            $this->key($access, '/no-such-screen-anywhere')->assertStatus(422);
        }

        $this->key($access, '/no-such-screen-anywhere')->assertStatus(429);
    }

    // ── কার মেনু কী ──────────────────────────────────────────────────────────

    public function test_the_accountant_gets_only_his_own_web_rows_and_no_key_for_the_admin_screens(): void
    {
        $access = $this->login('accounts@abos.test')->json('accessToken');
        $rows = $this->rows($this->me($access)->assertOk()->json('menu'));

        $this->assertNotSame([], array_filter($rows, fn (array $r) => $r['web'] !== null), 'হিসাবরক্ষক অ্যাপে কোনো ওয়েব-পর্দাই পাননি।');
        $this->assertSame([], array_filter($rows, fn (array $r) => str_starts_with($r['route'], 'system_admin.')));

        $this->key($access, route('system_admin.user.index', [], false))->assertStatus(422);
        $this->assertSame(0, WebHandoff::query()->count());
    }

    public function test_the_salesman_gets_no_admin_rows_and_no_stock_screens(): void
    {
        $access = $this->login('sales@abos.test')->json('accessToken');
        $rows = $this->rows($this->me($access)->assertOk()->json('menu'));

        $this->assertSame([], array_filter($rows, fn (array $r) => str_starts_with($r['route'], 'system_admin.')));

        $inside = app(WebInsideApp::class);
        $salesman = $this->user('sales@abos.test');

        foreach ($rows as $row) {
            if ($inside->hidesFrom($salesman, $row['route'])) {
                $this->assertNull($row['web'], "⛔ মজুদ-দেখানো পর্দা {$row['route']} SR-এর অ্যাপের ভিতরে খুলছে।");
            }
        }

        $invoices = array_values(array_filter($rows, fn (array $r) => $r['route'] === 'sales.invoice.index' && $r['web'] !== null));
        $this->assertNotSame([], $invoices, 'বিক্রয়কর্মী নিজের বিলের তালিকাও অ্যাপের ভিতরে পাননি।');

        // ⓘ ঠিকানা আন্দাজ করেও নয় — কাউন্টার মজুদ দেখায়, বিলের তালিকা দেখায় না
        $this->key($access, route('sales.direct.create', [], false))->assertStatus(422);
        $this->key($access, (string) $invoices[0]['web'])->assertOk();
    }

    public function test_the_owner_who_sees_stock_keeps_the_stock_screens(): void
    {
        $access = $this->ownerToken();
        $rows = $this->rows($this->me($access)->assertOk()->json('menu'));

        $stock = array_values(array_filter($rows, fn (array $r) => $r['route'] === 'inventory.stock.index'));
        $this->assertNotSame([], $stock);
        $this->assertNotNull($stock[0]['web']);
    }

    public function test_inside_the_app_the_salesman_meets_a_wall_at_a_stock_screen_while_the_browser_is_unchanged(): void
    {
        $salesman = $this->user('sales@abos.test');
        $url = route('inventory.stock.index');

        $this->app['auth']->forgetGuards();
        $this->actingAs($salesman)->withSession([WebInsideApp::SESSION => true])->get($url)
            ->assertForbidden()
            ->assertSee(__('mobile.stock_hidden_in_app'));

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($salesman)->get($url)->assertDontSee(__('mobile.stock_hidden_in_app'));
    }

    // ── সাহায্য ──────────────────────────────────────────────────────────────

    private function ownerToken(): string
    {
        $secret = (string) $this->user('owner@abos.test')->mfa_secret;

        return (string) $this->login('owner@abos.test', self::PHONE, Totp::codeFor($secret))->assertOk()->json('accessToken');
    }

    private function login(string $email, string $device = self::PHONE, ?string $code = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', array_filter([
            'identifier' => $email,
            'password' => 'password',
            'code' => $code,
            'deviceId' => $device,
        ]))->assertOk();
    }

    private function key(string $token, string $path, ?string $code = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson('/api/v1/web-handoff', array_filter(['path' => $path, 'code' => $code]));
    }

    private function open(string $url, string $device): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutToken()->get($url, [\App\Http\Controllers\Auth\WebHandoffController::DEVICE_HEADER => $device]);
    }

    private function me(string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/v1/me');
    }

    private function menuPath(string $token, string $route): string
    {
        foreach ($this->rows($this->me($token)->assertOk()->json('menu')) as $row) {
            if ($row['route'] === $route && $row['web'] !== null) {
                return (string) $row['web'];
            }
        }

        $this->fail("প্রস্তুতিটাই ভুল — /me-তে {$route}-এর ওয়েব-পথ নেই।");
    }

    /**
     * @param  list<array<string, mixed>>  $menu
     * @return list<array<string, mixed>>
     */
    private function rows(array $menu): array
    {
        $out = [];

        foreach ($menu as $module) {
            foreach ($module['groups'] as $rows) {
                foreach ($rows as $row) {
                    $out[] = $row;
                }
            }
        }

        return $out;
    }

    private function switchOff(int $companyId): void
    {
        CompanyContext::forCompany($companyId, fn () => app(SettingsService::class)->set(WebInsideApp::SETTING, false));
        $this->app->forgetInstance(SettingsService::class);
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
