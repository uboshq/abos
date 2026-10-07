<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Sales\Models\Lead;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনে লিড — সমন্বয়কের ক্রম "ঘ" (৫ অক্টোবর ২০২৬; [[LeadApiController]])।
 *
 * ⭐ দাবি:
 *   একই মানুষ — চাবি ছাড়া ৪০৩, `sales.lead.view` দিলে লেখেন; মালিক তিনি নিজে, নম্বর সার্ভারের;
 *   অন্য মাঠকর্মী (ব্যবস্থাপক নন) লিডটা দেখেন না — তালিকায় নেই, খুললে ৪০৪, বদলালে ৪০৪;
 *   হারানো বললে কারণ লাগে; উৎস তালিকার বাইরের হলে ফেরে।
 */
final class TheFieldWritesALeadOnThePhoneTest extends TestCase
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

    public function test_the_same_person_needs_the_key_writes_in_their_own_name_and_another_cannot_see_it(): void
    {
        $sr = $this->staff([]);
        $body = ['name' => 'নতুন মুদি দোকান', 'phone' => '01700000001', 'source' => 'field_visit', 'notes' => 'বাজারের মোড়ে'];

        Sanctum::actingAs($sr, [AuthController::APP]);
        $this->postJson('/api/v1/sales/leads', $body)->assertForbidden();

        $sr = $this->staff(['sales.lead.view'], $sr);
        Sanctum::actingAs($sr, [AuthController::APP]);
        $this->assertNotEmpty($this->getJson('/api/v1/sales/leads/setup')->assertOk()->json('sources'));
        $made = $this->postJson('/api/v1/sales/leads', $body)->assertCreated()->json();
        $this->assertSame('new', $made['status']);
        $this->assertNotSame('', $made['no'], 'নম্বর সার্ভারের সিরিজ থেকে');
        $lead = Lead::query()->where('public_id', $made['id'])->firstOrFail();
        $this->assertSame((int) $sr->id, (int) $lead->owner_user_id, 'মালিক লেখক নিজে।');
        $this->assertSame([$made['id']], array_column($this->getJson('/api/v1/sales/leads?'.http_build_query(['q' => 'মুদি']))->assertOk()->json('leads'), 'id'));

        $other = $this->staff(['sales.lead.view']);
        Sanctum::actingAs($other, [AuthController::APP]);
        $this->assertSame([], $this->getJson('/api/v1/sales/leads')->assertOk()->json('leads'), '⛔ অন্যের লিড তালিকায় এলো।');
        $this->getJson('/api/v1/sales/leads/'.$made['id'])->assertNotFound();
        $this->putJson('/api/v1/sales/leads/'.$made['id'], $body + ['status' => 'contacted'])->assertNotFound();
        $this->assertSame('new', $lead->fresh()->status, '⛔ অন্যের লিডের অবস্থা বদলে গেল।');
    }

    public function test_a_lost_lead_needs_its_reason_and_an_unknown_source_is_refused(): void
    {
        $sr = $this->staff(['sales.lead.view']);
        Sanctum::actingAs($sr, [AuthController::APP]);

        $this->postJson('/api/v1/sales/leads', ['name' => 'এক দোকান', 'source' => 'billboard'])->assertStatus(422)
            ->assertJsonValidationErrors('source');
        $made = $this->postJson('/api/v1/sales/leads', ['name' => 'এক দোকান', 'source' => 'walk_in'])->assertCreated()->json();

        $this->putJson('/api/v1/sales/leads/'.$made['id'], ['name' => 'এক দোকান', 'source' => 'walk_in', 'status' => 'lost'])
            ->assertStatus(422)->assertJsonValidationErrors('lost_reason');
        $lost = $this->putJson('/api/v1/sales/leads/'.$made['id'], [
            'name' => 'এক দোকান', 'source' => 'walk_in', 'status' => 'lost', 'lost_reason' => 'অন্য কোম্পানির মাল রাখেন',
        ])->assertOk()->json();
        $this->assertSame('lost', $lost['status']);
        $this->assertSame('অন্য কোম্পানির মাল রাখেন', $lost['lost_reason']);
    }

    /** @param  list<string>  $keys */
    private function staff(array $keys, ?User $user = null): User
    {
        if ($user === null) {
            $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
            $user->companies()->attach($this->company->id, ['is_active' => true]);
        }
        foreach ($keys as $key) {
            CompanyContext::forCompany($this->company->id,
                fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
