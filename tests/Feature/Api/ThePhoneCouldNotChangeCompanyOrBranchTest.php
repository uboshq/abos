<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\SyncState;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SignsInPastTheSecondStep;
use Tests\TestCase;

/**
 * ফোনে কোম্পানি বা শাখা বদলানোর কোনো পথই ছিল না — মালিক, ১ অক্টোবর ২০২৬:
 * *"app e company & branch change hoyna"*।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * ওয়েবের হেডারে সুইচার আছে ([[WorkspaceController::switchCompany()]],
 * `switchBranch()`), কিন্তু `/api/v1`-এ কোনো দরজা ছিল না, আর `/me` বলত না
 * মানুষটা আর কোন কোম্পানিতে বা কোন শাখায় যেতে পারেন। দুই কোম্পানির
 * মালিক ফোনে চিরকাল একটাতেই আটকে থাকতেন — ওয়েবে গিয়ে বদলে আসা ছাড়া।
 *
 * ⭐ এখন `POST /api/v1/workspace` — নিয়মগুলো ওয়েবেরই ([[User::switchCompany()]]),
 * নতুন করে লেখা নয়; আর কোম্পানি বদলালে যন্ত্রের জলচিহ্ন গোড়ায় ফেরে।
 */
final class ThePhoneCouldNotChangeCompanyOrBranchTest extends TestCase
{
    use RefreshDatabase;
    use SignsInPastTheSecondStep;

    private const DEVICE = 'handset-workspace';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    /** @return array{accessToken: string, refreshToken: string} */
    private function signIn(string $who, string $device = self::DEVICE): array
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', [
            'identifier' => $who,
            'password' => 'password',
            'code' => $this->secondStepCode($who),
            'deviceId' => $device,
            'appVersion' => '0.4.2',
            'platform' => 'android',
        ])->assertOk()->json();
    }

    private function me(string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    }

    /** @param  array<string, mixed>  $body */
    private function switchTo(string $token, array $body): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson('/api/v1/workspace', $body);
    }

    private function company(string $code): Company
    {
        return Company::query()->where('code', $code)->firstOrFail();
    }

    private function branch(string $code): Branch
    {
        return Branch::acrossAllCompanies()->where('code', $code)->firstOrFail();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    /** এই যন্ত্রের একটা জলচিহ্ন — "আগে একবার টানা হয়েছে"। */
    private function watermark(string $device, string $companyCode): void
    {
        SyncState::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company($companyCode)->id,
            'device_id' => $device,
            'module' => 'customer',
            'last_synced_at' => now(),
        ]);
    }

    private function watermarks(string $device): int
    {
        return SyncState::query()->withoutGlobalScopes()->where('device_id', $device)->count();
    }

    public function test_me_names_every_company_and_branch_the_person_can_go_to(): void
    {
        $body = $this->me($this->signIn('owner@abos.test')['accessToken'])->json();

        $this->assertEqualsCanonicalizing(
            ['TDEPOT', 'FMART'],
            array_column($body['companies'], 'code'),
        );
        $this->assertEqualsCanonicalizing(
            ['MMS', 'NTK', 'DMD', 'KDA'],
            array_column($body['branches'], 'code'),
            'the branches are those of the company the owner sits in now',
        );
        $this->assertIsBool($body['viewAllBranches']);

        // ⛔ গোনার আইডি কখনো নয় — কেবল public_id
        foreach ([...$body['companies'], ...$body['branches']] as $row) {
            $this->assertArrayNotHasKey('id', $row);
            $this->assertNotEmpty($row['public_id']);
        }
    }

    public function test_the_owner_moves_to_the_other_company_and_me_follows(): void
    {
        $tokens = $this->signIn('owner@abos.test');
        $this->assertSame('TDEPOT', $this->me($tokens['accessToken'])->json('company.code'));

        $this->watermark(self::DEVICE, 'TDEPOT');
        $this->watermark('someone-elses-handset', 'TDEPOT');

        $this->switchTo($tokens['accessToken'], [
            'company' => $this->company('FMART')->public_id,
            'branch' => 'all',
        ])->assertOk()->assertJsonPath('company.code', 'FMART');

        $body = $this->me($tokens['accessToken'])->json();
        $this->assertSame('FMART', $body['company']['code']);
        $this->assertSame(['MAIN'], array_column($body['branches'], 'code'));
        $this->assertTrue($body['viewAllBranches']);

        // ⭐ অন্য কোম্পানি = এই যন্ত্র গোড়া থেকে টানবে; অন্য যন্ত্র অক্ষত
        $this->assertSame(0, $this->watermarks(self::DEVICE));
        $this->assertSame(1, $this->watermarks('someone-elses-handset'));
    }

    /**
     * ⛔ বদলের পরে টানায় কেবল নতুন কোম্পানির সারি — সমন্বয়কের নিরাপত্তা-শর্ত।
     *
     * ⓘ কোম্পানি ফোনের পাঠানো কিছু থেকে আসে না, ব্যবহারকারীর সারি থেকে আসে; তাই
     * বদলের দরজা ঠিক থাকলে টানাও ঠিক থাকে। ⚠️ দুই দিকেই সারি থাকতে হয় — খালি
     * তালিকায় "কেবল FMART-এর" দাবিটা কিছুই মাপত না।
     */
    public function test_after_the_move_a_pull_brings_only_the_new_companys_rows(): void
    {
        $fmart = $this->company('FMART');
        $this->actingAs($this->user('owner@abos.test'));
        $mine = CompanyContext::forCompany($fmart->id, fn () => app(CustomerService::class)->create([
            'name_en' => 'Family Mart Walk-in Shop',
            'name_bn' => 'ফ্যামিলি মার্টের দোকান',
            'credit_limit' => 0,
            'credit_days' => 0,
        ]));
        $this->app['auth']->forgetGuards();

        $ids = fn (string $code): array => Customer::query()->withoutGlobalScopes()
            ->where('company_id', $this->company($code)->id)->pluck('public_id')->map(fn ($id) => (string) $id)->all();

        $tokens = $this->signIn('owner@abos.test');
        $pulled = function () use ($tokens): array {
            $this->app['auth']->forgetGuards();

            return array_values(array_unique(array_map(
                fn (array $record) => (string) $record['entityId'],
                array_filter(
                    $this->withToken($tokens['accessToken'])
                        ->getJson('/api/v1/sync/customer/pull?limit=1000&deviceId='.self::DEVICE)
                        ->assertOk()->json('records'),
                    fn (array $record) => ($record['entityType'] ?? null) === 'Customer',
                ),
            )));
        };

        $before = $pulled();
        $this->assertNotEmpty($before);
        $this->assertSame([], array_diff($before, $ids('TDEPOT')), 'before the move: TDEPOT only');

        $this->switchTo($tokens['accessToken'], ['company' => $fmart->public_id, 'branch' => 'all'])->assertOk();

        $after = $pulled();
        $this->assertContains((string) $mine->public_id, $after, 'the new company\'s rows arrive, from the start');
        $this->assertSame([], array_diff($after, $ids('FMART')), 'after the move: FMART only');
        $this->assertSame([], array_intersect($after, $ids('TDEPOT')), 'not one TDEPOT customer');
    }

    public function test_a_company_the_person_is_not_in_is_refused_and_nothing_moves(): void
    {
        $tokens = $this->signIn('sales@abos.test');
        $before = $this->user('sales@abos.test')->only(['current_company_id', 'current_branch_id', 'view_all_branches']);
        $this->watermark(self::DEVICE, 'TDEPOT');

        $this->switchTo($tokens['accessToken'], [
            'company' => $this->company('FMART')->public_id,
            'branch' => 'all',
        ])->assertForbidden()->assertJsonStructure(['message']);

        // ⓘ অজানা public_id-ও একই উত্তর — "আছে কিন্তু আপনার নয়" আর "নেই" আলাদা করা যায় না
        $this->switchTo($tokens['accessToken'], [
            'company' => 'no-such-company',
            'branch' => 'all',
        ])->assertForbidden();

        $this->assertSame($before, $this->user('sales@abos.test')->only(array_keys($before)));
        $this->assertSame('TDEPOT', $this->me($tokens['accessToken'])->json('company.code'));
        $this->assertSame(1, $this->watermarks(self::DEVICE));
    }

    public function test_a_branch_of_another_company_is_refused(): void
    {
        $tokens = $this->signIn('owner@abos.test');
        $before = $this->user('owner@abos.test')->only(['current_company_id', 'current_branch_id']);

        $this->switchTo($tokens['accessToken'], [
            'company' => $this->company('TDEPOT')->public_id,
            'branch' => $this->branch('MAIN')->public_id,
        ])->assertUnprocessable()->assertJsonValidationErrors('branch');

        $this->assertSame($before, $this->user('owner@abos.test')->only(array_keys($before)));
    }

    public function test_a_branch_outside_the_persons_reach_is_refused_and_not_even_listed(): void
    {
        $sales = $this->user('sales@abos.test');
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company('TDEPOT')->id,
            'user_id' => $sales->id,
            'scope_type' => UserDataScope::BRANCH,
            'scope_id' => $this->branch('NTK')->id,
        ]);
        // ⓘ নাগালের উত্তর অনুরোধজুড়ে মনে রাখা থাকে; পরীক্ষায় অ্যাপটা একটাই, তাই ভুলিয়ে দেওয়া
        app(DataScope::class)->forget();

        $tokens = $this->signIn('sales@abos.test');
        $this->assertSame(['NTK'], array_column($this->me($tokens['accessToken'])->json('branches'), 'code'));

        $before = $this->user('sales@abos.test')->only(['current_company_id', 'current_branch_id', 'view_all_branches']);

        $this->switchTo($tokens['accessToken'], [
            'company' => $this->company('TDEPOT')->public_id,
            'branch' => $this->branch('MMS')->public_id,
        ])->assertUnprocessable()->assertJsonValidationErrors('branch');

        $this->assertSame($before, $this->user('sales@abos.test')->only(array_keys($before)));

        $this->switchTo($tokens['accessToken'], [
            'company' => $this->company('TDEPOT')->public_id,
            'branch' => $this->branch('NTK')->public_id,
        ])->assertOk();

        $this->assertSame($this->branch('NTK')->id, $this->user('sales@abos.test')->current_branch_id);
    }

    public function test_all_branches_and_one_branch_flip_the_view_like_the_web_header(): void
    {
        $tokens = $this->signIn('owner@abos.test');
        $tdepot = $this->company('TDEPOT')->public_id;
        $this->watermark(self::DEVICE, 'TDEPOT');

        $this->switchTo($tokens['accessToken'], ['company' => $tdepot, 'branch' => $this->branch('NTK')->public_id])
            ->assertOk()
            ->assertJsonPath('branch.code', 'NTK')
            ->assertJsonPath('viewAllBranches', false);

        $owner = $this->user('owner@abos.test');
        $this->assertFalse($owner->view_all_branches);
        $this->assertSame($this->branch('NTK')->id, $owner->current_branch_id);
        $this->assertSame('NTK', $this->me($tokens['accessToken'])->json('branch.code'));
        $this->assertFalse($this->me($tokens['accessToken'])->json('viewAllBranches'));

        $this->switchTo($tokens['accessToken'], ['company' => $tdepot, 'branch' => 'all'])
            ->assertOk()
            ->assertJsonPath('viewAllBranches', true);

        $owner->refresh();
        $this->assertTrue($owner->view_all_branches);
        $this->assertSame($this->branch('NTK')->id, $owner->current_branch_id, '"all" changes what is seen, not where one works');

        // ⓘ একই কোম্পানির ভেতরে শাখা বদল — জলচিহ্ন থাকে, নইলে প্রতি বদলে গোটা তালিকা আবার নামত
        $this->assertSame(1, $this->watermarks(self::DEVICE));
    }

    public function test_bad_input_is_a_json_422_not_a_redirect(): void
    {
        $tokens = $this->signIn('owner@abos.test');

        $this->switchTo($tokens['accessToken'], [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['company', 'branch']);

        $this->switchTo($tokens['accessToken'], ['company' => $this->company('TDEPOT')->public_id, 'branch' => 'every'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch');
    }

    public function test_a_refresh_token_does_not_open_this_door(): void
    {
        $tokens = $this->signIn('owner@abos.test');

        $this->switchTo($tokens['refreshToken'], [
            'company' => $this->company('FMART')->public_id,
            'branch' => 'all',
        ])->assertForbidden();

        $this->assertSame($this->company('TDEPOT')->id, $this->user('owner@abos.test')->current_company_id);
    }
}
