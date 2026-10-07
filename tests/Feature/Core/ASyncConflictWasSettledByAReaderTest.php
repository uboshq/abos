<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\SyncConflict;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * সিঙ্কের দ্বন্দ্ব **দেখার** চাবিতে মেটানো যেত, আর মেটানোটা আবার মেটানো যেত — ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল (নিরাপত্তা-অডিট ২৯ সেপ্টেম্বর, খোঁজ ৮) ──────────────
 * - মেটানোর দরজা চাইত `governance.audit.view` — নিরীক্ষকের **পড়ার** চাবি। যিনি কেবল
 *   দেখবেন, তিনি দ্বন্দ্বটা "মেটানো" বলে সারি থেকে সরিয়ে দিতে পারতেন।
 * - মেটানো দ্বন্দ্ব আবার মেটালে "কে মেটালেন" আর নোট বদলে যেত — প্রথম সিদ্ধান্তের
 *   দাগ মুছত।
 *
 * ── ⭐ কীভাবে মাপা ──────────────────────────────────────────────────────
 * একই মানুষ: কেবল পড়ার চাবিতে ৪০৩; মেটানোর চাবি দিলে মেটে; দ্বিতীয়বার ৪২২ আর
 * প্রথম সিদ্ধান্ত অক্ষত। ⚠️ মালিক (super_admin) সব চাবি পান — তিনি কখনো আটকান না।
 */
final class ASyncConflictWasSettledByAReaderTest extends TestCase
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

    public function test_reading_the_conflicts_is_not_settling_them(): void
    {
        $conflict = $this->conflict();
        $auditor = $this->userWith(['governance.audit.view']);

        Sanctum::actingAs($auditor, [AuthController::ACCESS]);
        $this->getJson(route('api.sync.conflicts'))->assertOk();
        $this->postJson(route('api.sync.conflicts.resolve', $conflict->public_id), ['note' => 'looked'])
            ->assertForbidden();
        $this->assertSame(SyncConflict::PENDING, $conflict->fresh()->status, '⛔ পড়ার চাবিতে দ্বন্দ্ব মিটে গেল।');

        $this->grant($auditor, 'governance.sync.resolve');

        Sanctum::actingAs($auditor->fresh(), [AuthController::ACCESS]);
        $this->postJson(route('api.sync.conflicts.resolve', $conflict->public_id), ['note' => 'checked with the shop'])
            ->assertOk();
        $this->assertSame(SyncConflict::RESOLVED, $conflict->fresh()->status);
        $this->assertSame($auditor->id, (int) $conflict->fresh()->resolved_by);
    }

    public function test_a_settled_conflict_is_not_settled_again(): void
    {
        $conflict = $this->conflict();
        $first = $this->userWith(['governance.audit.view', 'governance.sync.resolve']);
        $second = $this->userWith(['governance.audit.view', 'governance.sync.resolve']);

        Sanctum::actingAs($first, [AuthController::ACCESS]);
        $this->postJson(route('api.sync.conflicts.resolve', $conflict->public_id), ['note' => 'first decision'])
            ->assertOk();

        Sanctum::actingAs($second, [AuthController::ACCESS]);
        $this->postJson(route('api.sync.conflicts.resolve', $conflict->public_id), ['note' => 'overwrite'])
            ->assertStatus(422);

        $row = $conflict->fresh();
        $this->assertSame($first->id, (int) $row->resolved_by, '⛔ দ্বিতীয়জন মেটানো দ্বন্দ্ব আবার মিটিয়ে প্রথম সিদ্ধান্ত মুছলেন।');
        $this->assertSame('first decision', $row->note);
    }

    public function test_the_owner_settles_without_being_granted_anything(): void
    {
        $conflict = $this->conflict();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        Sanctum::actingAs($owner, [AuthController::ACCESS]);
        $this->postJson(route('api.sync.conflicts.resolve', $conflict->public_id), ['note' => 'owner'])
            ->assertOk();
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function conflict(): SyncConflict
    {
        return SyncConflict::query()->create([
            'company_id' => $this->company->id,
            'device_id' => 'phone-a',
            'module' => 'sales',
            'entity_type' => 'SalesOrder',
            'entity_id' => 'probe',
            'reason' => 'both sides changed',
            'status' => SyncConflict::PENDING,
            'detected_at' => now(),
        ]);
    }

    /** @param  list<string>  $keys */
    private function userWith(array $keys): User
    {
        $user = User::factory()->create([
            'current_company_id' => $this->company->id,
            'current_branch_id' => $this->company->defaultBranch()?->id,
            'is_active' => true,
        ]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        foreach ($keys as $key) {
            $this->grant($user, $key);
        }

        return $user->fresh();
    }

    private function grant(User $user, string $key): void
    {
        $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
