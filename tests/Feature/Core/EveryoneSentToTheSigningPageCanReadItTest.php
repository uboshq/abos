<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Console\Commands\ApprovalsDue;
use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\DataScope;
use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalLimit;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ খবর যাঁকে সইয়ের পাতায় পাঠাত, পাতা তাঁকে প্রায়ই ৪০৩ দিত — আর কেন, কেউ বলত না (fe-র নিয়ম, ২৪ অক্টোবর ২০২৬: "খবর যাঁকে
 * কাগজে পাঠায় তিনি সেটা খোলেন; না পারলে কারণটা বাংলায় দেখেন — খালি ৪০৩/৪০৪ কখনো নয়")।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * অনুরোধের পাতা ([[ApprovalInboxController::show()]]) খুলত কেবল অনুরোধকারী, যিনি এখন সই দিতে পারেন ([[ApprovalEngine::canDecide()]])
 * আর নিরীক্ষক (`approval.report`)। কিন্তু খবর যায় আরও মানুষের কাছে:
 *  · উপরে-পাঠানোর গন্তব্য (রোলের সবাই) — যাঁর কর্তৃত্বের সীমা অঙ্কের চেয়ে ছোট, তিনি সই দিতে পারেন না;
 *  · মনে-করানো পাওয়া ধাপের সইকারী — সীমার বাইরে হলে, বা কাগজটা অন্যজনের হাতে দেওয়া থাকলে, সই দিতে পারেন না।
 * এঁরা খবর চাপলেই ৪০৩।
 *
 * ── ⭐ দাবি ──────────────────────────────────────────────────────────
 * তিনজনেই খবর খোলার দরজা দিয়ে অনুরোধের পাতায় যান, পাতা ২০০, আর পাতায় লেখা কেন সই দিতে পারছেন না (সীমার বাইরে / অন্যজনের
 * কাছে পাঠানো, নাম ধরে)। ⛔ সই দেওয়া আগের মতোই কেবল canDecide(): এঁদের "অনুমোদন" চাপ কাগজটা নড়ায় না।
 */
final class EveryoneSentToTheSigningPageCanReadItTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $clerk;

    private User $boss;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        // ⓘ অনুরোধকারী — নিজে সই দেন না; ধাপের সইকারী — সই দেওয়ার চাবি আছে, অনুমোদনের রিপোর্ট নেই
        $this->clerk = $this->person('Asking Clerk', []);
        $this->boss = $this->person('Step Boss', ['approval.decide']);
    }

    public function test_an_escalation_holder_past_their_ceiling_reads_the_page_and_sees_why(): void
    {
        $role = $this->role('Area Head Probe', ceiling: '10000');
        $chief = $this->person('Area Chief', ['approval.decide'], $role);

        $flow = $this->flow();
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->boss->id,
            'requires_all' => false, 'sla_hours' => 4, 'escalate_to_type' => ApprovalFlowStep::BY_ROLE, 'escalate_to_id' => $role->id,
        ]);
        $asked = $this->ask();
        $asked->update(['requested_at' => now()->subHours(9), 'due_at' => now()->subHours(5)]);

        $this->app['auth']->forgetGuards();
        $this->artisan('abos:approvals-due')->assertSuccessful();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $note = $this->noticeFor($chief, 'approval.escalated', $asked);
        $this->assertFalse(app(ApprovalEngine::class)->canDecide($asked->fresh(), $chief->fresh()),
            'প্রস্তুতিটাই ভুল — উপরের মানুষ ৫০ হাজারে সই দিতে পারেন; ১০ হাজারের সীমা বসেনি।');

        $this->as($chief);
        $this->readsThePageWithTheReason($note, $asked, trans('approval::message.beyond_your_authority', [], 'bn'), 'উপরে-পাঠানোর গন্তব্য');
        $this->cannotSign($asked, 'উপরে-পাঠানোর গন্তব্য');
    }

    public function test_a_reminded_signer_past_their_ceiling_reads_the_page_and_sees_why(): void
    {
        $role = $this->role('Small Signer Probe', ceiling: '10000');
        CompanyContext::forCompany($this->company->id, fn () => $this->boss->assignRole($role));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $flow = $this->flow();
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->boss->id,
            'requires_all' => false, 'sla_hours' => 4,
        ]);
        $asked = $this->ask();
        $this->assertFalse(app(ApprovalEngine::class)->canDecide($asked->fresh(), $this->boss->fresh()),
            'প্রস্তুতিটাই ভুল — ধাপের সইকারী ৫০ হাজারে সই দিতে পারেন; ১০ হাজারের সীমা বসেনি।');

        $this->remind($asked);
        $note = $this->noticeFor($this->boss, 'approval.reminder', $asked);

        $this->as($this->boss);
        $this->readsThePageWithTheReason($note, $asked, trans('approval::message.beyond_your_authority', [], 'bn'), 'সীমার বাইরের সইকারী');
        $this->cannotSign($asked, 'সীমার বাইরের সইকারী');
    }

    public function test_a_reminded_signer_whose_paper_was_passed_on_reads_the_page_and_sees_to_whom(): void
    {
        $deputy = $this->person('Deputy Signer', ['approval.decide']);

        $flow = $this->flow();
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->boss->id,
            'requires_all' => false, 'sla_hours' => 4,
        ]);
        // ⓘ পাঠানো যায় কেবল ছকে নাম থাকা মানুষের কাছে ([[ApprovalEngine::signers()]]) — তাই ডেপুটি দ্বিতীয় ধাপে
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 2, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $deputy->id,
        ]);
        $asked = $this->ask();

        $this->as($this->boss);
        app(ApprovalEngine::class)->forward($asked->fresh(), $this->boss->fresh(), $deputy->fresh(), 'অঙ্কটা আপনি মিলিয়ে দেখুন');
        $this->assertSame((int) $deputy->id, (int) $asked->fresh()->assigned_to, 'প্রস্তুতিটাই ভুল — কাগজটা ডেপুটির হাতে যায়নি।');
        $this->assertFalse(app(ApprovalEngine::class)->canDecide($asked->fresh(), $this->boss->fresh()),
            'প্রস্তুতিটাই ভুল — অন্যের হাতে দেওয়া কাগজে ধাপের সইকারী এখনো সই দিতে পারেন।');

        $this->remind($asked);
        $note = $this->noticeFor($this->boss, 'approval.reminder', $asked);

        $this->as($this->boss);
        $this->readsThePageWithTheReason($note, $asked,
            trans('approval::message.forwarded_to_other', ['name' => 'Deputy Signer'], 'bn'), 'যাঁর কাগজ অন্যের হাতে, সেই সইকারী');
        $this->cannotSign($asked, 'যাঁর কাগজ অন্যের হাতে, সেই সইকারী');
        $this->assertSame((int) $deputy->id, (int) $asked->fresh()->assigned_to, '⛔ পাতা খোলায় কাগজটা ডেপুটির হাত থেকে সরে গেল।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** খবর খোলার দরজা দিয়ে — অনুরোধের পাতায়, খবর পড়া, পাতা ২০০, আর কারণটা লেখা */
    private function readsThePageWithTheReason(Notification $note, Approval $asked, string $reason, string $who): void
    {
        $this->assertFalse(auth()->user()->can('approval.report'),
            "প্রস্তুতিটাই ভুল — {$who}-এর অনুমোদনের রিপোর্টের চাবি আছে, তাহলে নিরীক্ষক হিসেবে পাতা আগে থেকেই খুলত।");

        $opened = $this->call('GET', route('notifications.open', $note));
        $this->assertSame(302, $opened->status(), "⛔ {$who}-কে খবর অনুরোধের পাতায় পাঠায়নি (না-খোলার পাতা, বা ভুল)।");
        $this->assertSame(route('approval.inbox.show', $asked->id), $opened->headers->get('Location'), "⛔ {$who}-এর খবর অনুরোধের পাতায় যায় না।");
        $this->assertNotNull($note->fresh()->read_at, "⛔ {$who}-এর খবর খুলে পড়া হিসেবে বসেনি।");

        $page = $this->call('GET', route('approval.inbox.show', $asked->id));
        $this->assertSame(200, $page->status(), "⛔ খবর যাঁকে সইয়ের পাতায় পাঠাল, সেই {$who} পাতাটা খুলতে পারেন না (৪০৩)।");
        $this->assertStringContainsString($reason, (string) $page->getContent(),
            "⛔ {$who} পাতা খুলে জানেন না কেন সই দিতে পারছেন না — কারণটা পাতায় নেই।");
    }

    /** ⛔ পড়ার ছাড় সইয়ের ছাড় নয় — "অনুমোদন" চাপলেও কাগজ নড়ে না */
    private function cannotSign(Approval $asked, string $who): void
    {
        $tried = $this->call('POST', route('approval.inbox.approve', $asked->id), ['remarks' => 'চেষ্টা']);
        $this->assertNotSame(302, $tried->status(), "⛔ {$who}-এর অনুমোদন চাপ সফল বলে ফিরল।");
        $this->assertSame(Approval::PENDING, $asked->fresh()->status, "⛔ সই দিতে না-পারা {$who}-এর চাপে কাগজটা নড়ে গেল।");
        $this->assertSame(0, $asked->fresh()->decisions()->where('user_id', auth()->id())->where('decision', ApprovalDecision::APPROVED)->count(),
            "⛔ সই দিতে না-পারা {$who}-এর নামে সই বসে গেল।");
    }

    /** সময়সূচির মনে-করানো, এই অনুরোধে — কেউ লগইন নেই ([[ApprovalsDue::remind()]]) */
    private function remind(Approval $asked): void
    {
        $this->app['auth']->forgetGuards();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        (new \ReflectionMethod(ApprovalsDue::class, 'remind'))
            ->invoke(app(ApprovalsDue::class), $asked->fresh(), app(NotificationService::class));

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    private function noticeFor(User $user, string $type, Approval $asked): Notification
    {
        $note = Notification::query()->where('user_id', $user->id)->where('type', $type)->latest('id')->first();
        $this->assertNotNull($note, "প্রস্তুতিটাই ভুল — {$user->name} {$type} খবরটাই পাননি।");
        $this->assertSame(route('approval.inbox.show', $asked->id), $note->url, "প্রস্তুতিটাই ভুল — {$type} খবর অনুরোধের পাতায় পাঠায় না।");

        return $note;
    }

    private function flow(): ApprovalFlow
    {
        return ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'module' => 'customer', 'action' => 'credit_limit',
            'document_type' => 'Customer', 'threshold_amount' => '0', 'is_active' => true,
        ]);
    }

    /** ৫০ হাজারের বাকির সীমা — অনুরোধকারী কর্মী */
    private function ask(): Approval
    {
        $asked = app(ApprovalEngine::class)->request(Customer::query()->orderBy('id')->firstOrFail(), 'customer', 'credit_limit', '50000', null, 'test', $this->clerk->id);
        $this->assertNotNull($asked, 'প্রস্তুতিটাই ভুল — অনুরোধটাই বসেনি।');
        $this->assertSame(Approval::PENDING, $asked->status, 'প্রস্তুতিটাই ভুল — অনুরোধটা অপেক্ষায় নেই।');

        return $asked;
    }

    /** এই কোম্পানির একটা রোল, অনুমোদনের কর্তৃত্বের সীমাসহ ([[AuthorityService]]) */
    private function role(string $name, string $ceiling): Role
    {
        $role = Role::query()->create(['name' => $name, 'guard_name' => 'web', 'company_id' => $this->company->id]);
        ApprovalLimit::query()->create(['company_id' => $this->company->id, 'role_id' => $role->id, 'max_amount' => $ceiling]);

        return $role;
    }

    /** @param  list<string>  $keys */
    private function person(string $name, array $keys, ?Role $role = null): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true, 'locale' => 'bn']);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id])->save();
        CompanyContext::forCompany($this->company->id, function () use ($user, $keys, $role): void {
            $user->givePermissionTo(array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys));

            if ($role !== null) {
                $user->assignRole($role);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(DataScope::class)->forget();
        $this->actingAs($user->fresh());
    }
}
