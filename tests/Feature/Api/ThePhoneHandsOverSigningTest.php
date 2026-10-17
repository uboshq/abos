<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ সইয়ের ভার দেওয়া ফোনের নিজের পর্দায় — মালিক, ১৭ অক্টোবর ২০২৬: *"direct webpage app e bujajayna"*
 * ([[ApprovalDelegationApiController]])।
 *
 * দাবি: ফোনে দেওয়া ভার ওয়েবের একই সেবায় বসে আর দুই দিকে দেখা যায় (আমার দেওয়া · আমার কাছে); ⛔ অন্য কোম্পানির মানুষ, নিজেকে, আর
 * গতকাল থেকে — তিনটাই ৪২২; ⛔ অন্যের দেওয়া ভার ফেরানো যায় না (৪০৩), নিজেরটা যায়; একই অনুরোধ দুবার এলে ভার একটাই
 * (Idempotency-Key); চাবি ছাড়া ৪০৩; ⭐ দেওয়া আর ফেরানো দুটোই অডিটে, কার হাতে; ⛔ ভার দেওয়া মানুষের চেয়ে বেশি ক্ষমতা দেয় না —
 * ভারপ্রাপ্ত সই দিতে পারেন কেবল সেই ধাপে যেখানে দাতা নিজে সইকারী।
 */
final class ThePhoneHandsOverSigningTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->colleague = User::query()->create(['name' => 'Colleague', 'email' => 'deleg-colleague@abos.test', 'password' => 'x', 'is_active' => true]);
        $this->colleague->companies()->attach($this->company->id, ['is_active' => true]);
    }

    public function test_a_hand_over_from_the_phone_lands_through_the_webs_service_and_shows_on_both_sides(): void
    {
        $setup = $this->phone($this->owner)->getJson('/api/v1/approvals/delegations')->assertOk();
        $this->assertContains((string) $this->colleague->public_id, array_column($setup->json('people'), 'id'), 'সহকর্মী তালিকায় নেই।');
        $this->assertNotContains((string) $this->owner->public_id, array_column($setup->json('people'), 'id'), '⛔ নিজেকেই বাছা যায়।');
        $this->assertNotEmpty($setup->json('modules'));

        $key = (string) Str::uuid();
        $body = ['to' => (string) $this->colleague->public_id, 'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(3)->toDateString(), 'reason' => 'ছুটিতে'];
        $made = $this->phone($this->owner)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/approvals/delegations', $body)->assertCreated();
        $this->phone($this->owner)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/approvals/delegations', $body)->assertSuccessful();

        $this->assertSame(1, ApprovalDelegation::query()->where('from_user_id', $this->owner->id)->where('to_user_id', $this->colleague->id)->count(),
            '⛔ একই অনুরোধ দুবার এসে দুটো ভার বসল।');
        $this->assertTrue($made->json('delegation.active'));

        $given = $this->phone($this->owner)->getJson('/api/v1/approvals/delegations')->assertOk()->json('given');
        CompanyContext::forCompany($this->company->id, fn () => $this->colleague->givePermissionTo(Permission::findOrCreate('approval.decide', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame('Colleague', $given[0]['person']);
        $held = $this->phone($this->colleague)->getJson('/api/v1/approvals/delegations')->assertOk()->json('held');
        $this->assertSame([$made->json('delegation.id')], array_column($held, 'id'), '⛔ ভারপ্রাপ্তের "আমার কাছে"-তে ভারটা নেই।');

        // ⛔ অন্যের দেওয়া ভার ফেরানো যায় না
        $this->phone($this->colleague)->deleteJson('/api/v1/approvals/delegations/'.$made->json('delegation.id'))->assertForbidden();
        $revokeKey = (string) Str::uuid();
        $first = $this->phone($this->owner)->withHeader('Idempotency-Key', $revokeKey)
            ->deleteJson('/api/v1/approvals/delegations/'.$made->json('delegation.id'))->assertOk();
        $revokedAt = ApprovalDelegation::query()->where('public_id', $made->json('delegation.id'))->value('revoked_at');
        $this->assertNotNull($revokedAt);

        // ⭐ আবার পাঠানো — একই চাবিতে প্রথম উত্তর, চাবি ছাড়াও; ফেরানোর সময় সরে না (a6-এর পর্যালোচনা, fe ১৭ অক্টোবর ২০২৬)
        $this->travel(5)->minutes();
        $this->phone($this->owner)->withHeader('Idempotency-Key', $revokeKey)
            ->deleteJson('/api/v1/approvals/delegations/'.$made->json('delegation.id'))->assertOk()->assertExactJson($first->json());
        $this->phone($this->owner)->deleteJson('/api/v1/approvals/delegations/'.$made->json('delegation.id'))->assertOk();
        $this->assertSame((string) $revokedAt,
            (string) ApprovalDelegation::query()->where('public_id', $made->json('delegation.id'))->value('revoked_at'),
            '⛔ আবার ফেরানোয় ফেরানোর সময় বদলে গেল — নিরীক্ষার সময় সরল।');

        // ⭐ অডিট — দেওয়া আর ফেরানো, দুটোই মালিকের নামে
        $row = ApprovalDelegation::query()->where('public_id', $made->json('delegation.id'))->firstOrFail();
        $trail = AuditTrail::query()->where('auditable_type', $row->getMorphClass())->where('auditable_id', $row->id);
        $this->assertSame(1, (clone $trail)->where('action', AuditTrail::CREATED)->where('user_id', $this->owner->id)->count(),
            '⛔ ফোনে দেওয়া ভার অডিটে নেই।');
        $this->assertSame(1, (clone $trail)->where('action', '!=', AuditTrail::CREATED)->where('user_id', $this->owner->id)->count(),
            '⛔ ফোনে ফেরানো ভার অডিটে নেই।');
    }

    public function test_a_hand_over_gives_no_more_power_than_the_giver_has(): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $this->colleague->givePermissionTo(Permission::findOrCreate('approval.decide', 'web')));
        $stranger = User::query()->create(['name' => 'Stranger', 'email' => 'deleg-stranger@abos.test', 'password' => 'x', 'is_active' => true]);
        $stranger->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk = User::query()->create(['name' => 'Clerk', 'email' => 'deleg-clerk@abos.test', 'password' => 'x', 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->grant($this->owner, ['to' => (string) $this->colleague->public_id, 'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(3)->toDateString()])->assertCreated();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $ask = function (User $signer, string $action) use ($clerk) {
            $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => 'sales', 'action' => $action, 'is_active' => true]);
            ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1,
                'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id]);
            /* ⓘ ইঞ্জিন ছকগুলো মনে রাখে ($flowCache) — নতুন ছক দেখাতে নতুন ইঞ্জিন, লাইভে পরের অনুরোধের মতো */
            $this->app->forgetInstance(ApprovalEngine::class);

            return app(ApprovalEngine::class)->request(\App\Models\Branch::query()->create(['code' => 'D'.uniqid(), 'name_en' => 'Paper '.$action]),
                'sales', $action, '5000', null, 'test', $clerk->id);
        };

        $owners = $ask($this->owner, 'deleg_owner_step');
        $strangers = $ask($stranger, 'deleg_stranger_step');

        $this->assertTrue(app(ApprovalEngine::class)->canDecide($owners, $this->colleague->fresh()),
            'দাবির ভিত্তি নেই — ফোনে দেওয়া ভারে মালিকের ধাপে সই দেওয়া যায় না।');
        $this->assertFalse(app(ApprovalEngine::class)->canDecide($strangers, $this->colleague->fresh()),
            '⛔ ভারপ্রাপ্ত এমন ধাপে সই দিতে পারছেন যেখানে দাতা নিজে সইকারী নন — ভার দাতার চেয়ে বেশি ক্ষমতা দিল।');
    }

    public function test_an_outsider_oneself_and_a_past_start_are_refused(): void
    {
        $outsider = User::query()->create(['name' => 'Outsider', 'email' => 'deleg-outsider@elsewhere.test', 'password' => 'x']);
        $base = ['starts_on' => now()->toDateString(), 'ends_on' => now()->addDays(2)->toDateString()];

        $this->grant($this->owner, ['to' => (string) $outsider->public_id] + $base)->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->grant($this->owner, ['to' => (string) $this->owner->public_id] + $base)->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->grant($this->owner, ['to' => (string) $this->colleague->public_id, 'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('starts_on');

        $this->assertSame(0, ApprovalDelegation::query()->count(), '⛔ বাতিল অনুরোধেও ভার বসেছে।');
    }

    public function test_without_the_signing_key_the_door_is_shut(): void
    {
        $this->phone($this->colleague)->getJson('/api/v1/approvals/delegations')->assertForbidden();
    }

    /** @param  array<string, mixed>  $body */
    private function grant(User $user, array $body): TestResponse
    {
        return $this->phone($user)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/approvals/delegations', $body);
    }

    private function phone(User $user): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }
}
