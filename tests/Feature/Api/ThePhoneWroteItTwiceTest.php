<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\DepositClaim;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ নেট ধীর হলে বা দুবার চাপলে ফোনের লেখা দুবার বসত — পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⚠️১২।
 *
 * এখন `Idempotency-Key` ([[RemembersAPhoneWrite]]): একই চাবি আবার এলে কাজ আবার চলে না, প্রথম উত্তর ফেরে। চাবি ছাড়া আগের মতো;
 * অন্য মানুষের একই চাবি আলাদা; ভুল চাবি ৪২২।
 */
final class ThePhoneWroteItTwiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->shop = Customer::query()->orderBy('id')->firstOrFail();
    }

    public function test_the_same_key_writes_once_and_answers_the_same(): void
    {
        $sr = $this->person();
        $before = DepositClaim::query()->count();

        $first = $this->as($sr)->withHeader('Idempotency-Key', 'phone-key-0001')->postJson('/api/v1/sales/deposit-requests', $this->advice())->assertCreated();
        $again = $this->as($sr)->withHeader('Idempotency-Key', 'phone-key-0001')->postJson('/api/v1/sales/deposit-requests', $this->advice())->assertCreated();

        $this->assertSame($before + 1, DepositClaim::query()->count(), '⛔ একই চাবিতে দুটো বিজ্ঞপ্তি।');
        $this->assertSame($first->json('id'), $again->json('id'));
        $this->assertSame('true', $again->headers->get('Idempotent-Replay'));

        // ⓘ নতুন চাবি — নতুন কাজ
        $this->as($sr)->withHeader('Idempotency-Key', 'phone-key-0002')->postJson('/api/v1/sales/deposit-requests', $this->advice())->assertCreated();
        $this->assertSame($before + 2, DepositClaim::query()->count());
    }

    public function test_no_key_is_as_before_another_persons_same_key_is_their_own_and_a_bad_key_is_refused(): void
    {
        $sr = $this->person();
        $other = $this->person();
        $before = DepositClaim::query()->count();

        $this->as($sr)->postJson('/api/v1/sales/deposit-requests', $this->advice())->assertCreated();
        $this->as($sr)->postJson('/api/v1/sales/deposit-requests', $this->advice())->assertCreated();
        $this->assertSame($before + 2, DepositClaim::query()->count(), 'চাবি ছাড়া পুরনো অ্যাপ আগের মতো।');

        $this->as($sr)->withHeader('Idempotency-Key', 'shared-key-01')->postJson('/api/v1/sales/deposit-requests', $this->advice())->assertCreated();
        $this->as($other)->withHeader('Idempotency-Key', 'shared-key-01')->postJson('/api/v1/sales/deposit-requests', $this->advice())->assertCreated();
        $this->assertSame($before + 4, DepositClaim::query()->count(), '⛔ অন্যের একই চাবিতে তাঁর কাজ চাপা পড়ল।');

        $this->as($sr)->withHeader('Idempotency-Key', 'bad key!')->postJson('/api/v1/sales/deposit-requests', $this->advice())->assertStatus(422);
    }

    public function test_a_refused_write_is_not_remembered(): void
    {
        $sr = $this->person();
        $this->as($sr)->withHeader('Idempotency-Key', 'phone-key-0003')
            ->postJson('/api/v1/sales/deposit-requests', [...$this->advice(), 'amount' => '0'])->assertStatus(422);
        $this->as($sr)->withHeader('Idempotency-Key', 'phone-key-0003')
            ->postJson('/api/v1/sales/deposit-requests', $this->advice())->assertCreated();
    }

    /** @return array<string, string> */
    private function advice(): array
    {
        return ['customer' => (string) $this->shop->public_id, 'claimed_on' => now()->toDateString(), 'amount' => '500', 'method' => 'cash'];
    }

    private function person(): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate('sales.collection.create', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function as(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }
}
