<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ ফোনের দরজায় সীমা ছিল না, আর "না" বলত ইংরেজিতে — পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⓘ১৯।
 *
 * ⭐ দাবি: জনপ্রতি মিনিটে ৩০০ — ৩০১তম ৪২৯, বাংলায়; অন্য জন তখনো চলেন (থলে টোকেনের মালিকের, IP-র নয়); ফোনের সব গ্রুপ এক
 * থলেতে। চাবির দেয়াল, টোকেনের চাবি আর খালি ৪০৩ বাংলায়; দরজার নিজের কারণ (`module_off`) যেমন ছিল।
 * ([[AppServiceProvider]] `app`, bootstrap/app.php-এর `respond`)
 */
final class ThePhoneDoorsHadNoSpeedLimitAndSpokeEnglishTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    public function test_a_closed_door_says_no_in_bengali_and_a_doors_own_reason_stays(): void
    {
        $nobody = $this->person();
        $body403 = __('core.error.body_403');
        $this->assertMatchesRegularExpression('/\p{Bengali}/u', $body403, 'দাবির ভিত্তি নেই — বার্তাটা বাংলায় নয়।');

        // চাবির দেয়াল (`can:`)
        $this->phone($nobody)->getJson('/api/v1/sales/orders')->assertForbidden()->assertJsonPath('message', $body403);
        // খালি `abort(403)`
        $this->phone($nobody)->getJson('/api/v1/purchase/purchases?kind=receipt')->assertForbidden()->assertJsonPath('message', $body403);
        // খালি `abort(403)` — অন্যের খসড়া আদেশ
        $body = ['customer' => (string) Customer::query()->orderBy('id')->value('public_id'),
            'lines' => [['product' => (string) Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('public_id'), 'qty' => '2']]];
        $order = $this->phone($this->owner())->postJson('/api/v1/sales/orders', $body)->assertCreated()->json('id');
        $writer = $this->person();
        CompanyContext::forCompany($this->company->id, fn () => $writer->givePermissionTo(array_map(
            fn (string $k) => Permission::findOrCreate($k, 'web'), ['sales.order.create', 'sales.order.view'])));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->phone($writer)->putJson('/api/v1/sales/orders/'.$order, $body)->assertForbidden()->assertJsonPath('message', $body403);
        // টোকেনের চাবি — refresh টোকেনে ফোনের দরজা নয়
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($nobody->fresh(), [AuthController::REFRESH]);
        $this->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('message', $body403);

        // ⓘ দরজার নিজের কারণ বদলায় না
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner())->put(route('system_admin.control-panel.update'), [
            'scope' => ['mobile.modules.hr'], 'settings' => ['mobile.modules.hr' => '0'],
        ]);
        app(SettingsService::class)->flush();
        $own = $this->phone($this->owner())->getJson('/api/v1/hr/claims/heads')->assertForbidden()->assertJsonPath('reason', 'module_off');
        $this->assertNotSame($body403, $own->json('message'), '⛔ দরজার নিজের কারণ মুছে সাধারণ বার্তা বসল।');
    }

    public function test_the_three_hundred_and_first_call_in_a_minute_is_refused_in_bengali_and_another_person_still_goes_through(): void
    {
        $this->freezeTime();
        $busy = $this->owner();
        $other = $this->person();

        for ($i = 0; $i < 150; $i++) {
            $this->phone($busy)->getJson('/api/v1/notices/bar')->assertOk();
            // ⓘ মডিউলের দরজাও একই থলে থেকে খরচ করে
            $this->phone($busy)->getJson('/api/v1/sales/quotations')->assertOk();
        }

        $this->phone($busy)->getJson('/api/v1/notices/bar')->assertStatus(429)->assertJsonPath('message', __('core.error.body_429'));
        $this->phone($busy)->getJson('/api/v1/sync/capabilities')->assertStatus(429);
        $this->phone($other)->getJson('/api/v1/notices/bar')->assertOk();

        $this->travel(61)->seconds();
        $this->phone($busy)->getJson('/api/v1/notices/bar')->assertOk();
        RateLimiter::clear('app');
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    private function person(): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user->fresh();
    }

    private function phone(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP, AuthController::ACCESS]);

        return $this;
    }
}
