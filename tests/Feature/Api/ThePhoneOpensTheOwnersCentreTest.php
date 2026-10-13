<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Executive\Services\Board;
use App\Modules\Executive\Services\Figures;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনে মালিকের কেন্দ্র — মালিক, ১৩ অক্টোবর ২০২৬: "app e baki module & menu"।
 *
 * দাবি ([[ExecutiveApiController]]):
 *  - আটটা সংখ্যা আর কোম্পানির ছক ওয়েবের "আজ" পাতার সেবারই ([[Board::build()]]) — একই সংখ্যা, একই ক্রম; ঢাকা ঘর "hidden"।
 *  - কোম্পানি আর শাখা কেবল `public_id`-তে, ক্রমিক সংখ্যায় কখনো নয়।
 *  - `executive.view` ছাড়া ৪০৩; যাঁর চাবি কেবল এক কোম্পানিতে, তিনি অন্য কোম্পানির নামটাও পান না।
 *  - ফোনে মালিকের কেন্দ্র বন্ধ থাকলে ৪০৩ `module_off`।
 */
final class ThePhoneOpensTheOwnersCentreTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $alpha;

    private Company $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->beta = Company::query()->where('code', 'FMART')->firstOrFail();
        $this->owner->forceFill(['current_company_id' => $this->alpha->id])->save();

        $this->switchOn($this->alpha, true);
        CompanyContext::set((int) $this->alpha->id, $this->alpha->defaultBranch()?->id);
    }

    public function test_the_figures_and_the_grid_are_the_web_boards_own(): void
    {
        foreach ([Figures::TODAY, Figures::MONTH] as $period) {
            $body = $this->phone($this->owner, '/api/v1/executive/today?period='.$period)->assertOk()->json();
            $board = app(Board::class)->build($this->owner->fresh(), $period);

            $this->assertSame($period, $body['period']);
            $this->assertSame(Figures::KEYS, array_column($body['figures'], 'key'), '⛔ আটটা সংখ্যার ক্রম ওয়েবের নয়।');

            foreach ($body['figures'] as $figure) {
                $web = $board['total'][$figure['key']];
                $expected = $web === Figures::HIDDEN ? 'hidden' : $web;
                $this->assertSame($expected, $figure['value'], "⛔ '{$figure['key']}' ({$period}) — ফোন আর ওয়েবের 'আজ' পাতা দুই সংখ্যা বলছে।");
            }

            $this->assertSame(array_column($board['companies'], 'name'), array_column($body['companies'], 'name'),
                '⛔ ফোনের কোম্পানির তালিকা ওয়েবের ছকের নয়।');
            $this->assertGreaterThanOrEqual(2, count($body['companies']), 'দাবির ভিত্তি নেই — মালিক গ্রুপের দুই কোম্পানিও দেখছেন না।');

            foreach ($body['companies'] as $i => $company) {
                $this->assertTrue(Str::isUuid($company['id']), '⛔ কোম্পানির ঠিকানা public_id নয়: '.$company['id']);
                $this->assertSame($board['companies'][$i]['values'][Figures::SALES] === Figures::HIDDEN ? 'hidden' : $board['companies'][$i]['values'][Figures::SALES],
                    $company['values'][Figures::SALES], '⛔ কোম্পানির ঘর ওয়েবের ছকের নয়।');

                foreach ($company['rows'] as $row) {
                    $this->assertTrue(Str::isUuid($row['id']), '⛔ শাখার ঠিকানা public_id নয়।');
                }
            }
        }

        $kinds = array_column($this->phone($this->owner, '/api/v1/executive/today')->json('alerts'), 'kind');
        $this->assertNotEmpty($kinds, '⛔ সতর্কতার তালিকা ফোনে নেই।');
    }

    public function test_without_the_key_it_is_refused_and_one_companys_key_shows_only_that_company(): void
    {
        $stranger = $this->member();
        $this->phone($stranger, '/api/v1/executive/today')->assertForbidden();

        CompanyContext::forCompany((int) $this->alpha->id, fn () => $stranger->givePermissionTo(Permission::findOrCreate('executive.view', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $response = $this->phone($stranger->fresh(), '/api/v1/executive/today')->assertOk();

        $this->assertSame([$this->alpha->name()], array_column($response->json('companies'), 'name'),
            '⛔ এক কোম্পানির চাবিতে আরেক কোম্পানির ছক এল।');
        $this->assertStringNotContainsString($this->beta->name(), (string) $response->getContent(), '⛔ অন্য কোম্পানির নামটাও ফোনে গেল।');
    }

    public function test_switched_off_on_the_phone_it_says_so(): void
    {
        $this->switchOn($this->alpha, false);

        $this->phone($this->owner, '/api/v1/executive/today')->assertForbidden()->assertJsonPath('reason', PhoneModules::REASON);
    }

    private function member(): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->alpha->id]);
        $user->companies()->attach($this->alpha, ['is_active' => true]);
        $user->companies()->attach($this->beta, ['is_active' => true]);

        return $user;
    }

    private function switchOn(Company $company, bool $on): void
    {
        CompanyContext::forCompany((int) $company->id, function () use ($on): void {
            app(SettingsService::class)->set(PhoneModules::key('executive'), $on);
            app(SettingsService::class)->flush();
        });
    }

    private function phone(User $user, string $url): TestResponse
    {
        app(SettingsService::class)->flush();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->getJson($url);
    }
}
