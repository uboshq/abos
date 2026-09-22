<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * সইকারী গত সপ্তাহের সংখ্যাটা দেখছিলেন।
 *
 * ── ⛔ কী ঘটত ────────────────────────────────────────────────────────
 * `approvals.amount` বসে **অনুরোধের সময়**, আর তারপর আর বদলায় না। ⚠️
 * কিন্তু অনুমোদনের অপেক্ষায় থাকা কাগজ খসড়াই থাকে, আর খসড়া সম্পাদনা
 * করা যায়।
 *
 * ⓘ ফলে ইনবক্সে ৫০ হাজার লেখা থাকত, কাগজটা ততক্ষণে ৫ লাখ, আর সইকারী
 * **ভুল সংখ্যা দেখে** সই দিতেন।
 *
 * ── ⭐ টাকাটা তবু পাশ হয় না, তবু এটা জরুরি ───────────────────────────
 * [[Approval::covers()]] পোস্টের সময় আটকায় (`0a4a2cd9`)। ⚠️ কিন্তু
 * ঐটা **টাকা** বাঁচায়, **মানুষটাকে** নয়: খাতায় লেখা থাকে তিনি সই
 * দিয়েছিলেন, আর তিনি জানতেনই না কীসে সই দিচ্ছেন।
 *
 * ── ⓘ কেন `updated_at`, অঙ্ক মিলিয়ে নয় ──────────────────────────────
 * ইনবক্স সতেরো রকম কাগজ দেখায়, আর "অঙ্ক" প্রতিটায় আলাদা ঘরে। ⛔ ওদের
 * নাম জানতে গেলে কোরকে মডিউলের ভিতরে তাকাতে হত (§১৯.৭)। ⭐ *"কাগজটা কি
 * নড়েছে"* প্রশ্নটার উত্তর সব কাগজেই একভাবে আছে।
 */
final class TheSignerWasLookingAtLastWeeksNumberTest extends TestCase
{
    use RefreshDatabase;

    private User $asked;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['code' => 'SIG2', 'name_en' => 'Signer Co']);
        CompanyContext::set($company->id);

        $this->asked = User::create(['name' => 'Clerk', 'email' => 'c@sig2.test', 'password' => 'x']);
        $this->manager = User::create(['name' => 'Manager', 'email' => 'm@sig2.test', 'password' => 'x']);

        $this->manager->companies()->attach($company->id);
        $this->asked->companies()->attach($company->id);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── দুইটা দিকই মাপা হয়, নাহলে একটার মানে নেই ──────────────────────

    public function test_an_untouched_document_carries_no_warning(): void
    {
        [$approval] = $this->aPendingRequest();

        $html = $this->asManager()->get(route('approval.inbox.show', $approval->id))
            ->assertOk()
            ->getContent();

        /*
         * ⚠️ `assertStringContainsString` ব্যর্থ হলে **গোটা পাতাটা** বার্তায়
         * ছাপে — এখানে ৫০ কিলোবাইট। ⓘ তখন আসল কথাটা আবর্জনার নিচে চাপা
         * পড়ে, আর পরের জন কারণ খুঁজতে গিয়ে সময় নষ্ট করেন।
         */
        $this->assertFalse(
            $this->warns($html),
            'কাগজটা কেউ ছোঁয়নি, তবু সতর্কতা দেখানো হচ্ছে — রোজ দেখলে মানুষ ওটা পড়াই বন্ধ করবেন।'
        );
    }

    public function test_a_document_edited_after_the_request_says_so(): void
    {
        [$approval, $document] = $this->aPendingRequest();

        /*
         * ⓘ এক মিনিট পরে সম্পাদনা — `requested_at` আর `updated_at`
         * একই সেকেন্ডে পড়লে তুলনাটা অর্থহীন হত।
         */
        $this->travel(1)->minutes();

        $document->forceFill(['name_en' => 'Moved'])->save();

        $html = $this->asManager()->get(route('approval.inbox.show', $approval->id))
            ->assertOk()
            ->getContent();

        $this->assertTrue(
            $this->warns($html),
            'সই চাওয়ার পরে কাগজটা বদলেছে, অথচ পর্দা চুপ — সইকারী পুরনো অঙ্ক দেখে সই দেবেন।'
        );
    }

    /**
     * ⛔ যিনি কাগজটা খুলতেই পারেন না, তাঁকে এটা বলা হয় না।
     *
     * ⚠️ নিরীক্ষক অনুমোদনের হিসাব দেখেন, কাগজ নয়
     * ([[ApprovalInboxController::show()]]-এ `documentHidden`)। ⓘ তাঁকে
     * *"কাগজটা বদলেছে"* বললে এমন একটা জিনিসের কথা বলা হত যা তিনি
     * যাচাই করতে পারেন না — আর যাচাই করা যায় না এমন সতর্কতা কেবল
     * দুশ্চিন্তা।
     */
    public function test_an_auditor_who_cannot_open_the_paper_is_not_warned(): void
    {
        [$approval, $document] = $this->aPendingRequest();

        $this->travel(1)->minutes();
        $document->forceFill(['name_en' => 'Moved'])->save();

        $auditor = User::create(['name' => 'Auditor', 'email' => 'a@sig2.test', 'password' => 'x']);
        $auditor->companies()->attach(CompanyContext::id());
        $auditor->givePermissionTo($this->permission('approval.report'));

        $html = $this->actingAs($auditor)->get(route('approval.inbox.show', $approval->id))
            ->assertOk()
            ->getContent();

        $this->assertFalse($this->warns($html));
    }

    // ── হাতিয়ার ────────────────────────────────────────────────────────

    /** @return array{0: Approval, 1: Branch} */
    private function aPendingRequest(): array
    {
        $flow = ApprovalFlow::create([
            'module' => 'sales',
            'action' => 'discount',
            'threshold_amount' => null,
        ]);

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->manager->id,
        ]);

        // ⓘ ইঞ্জিন polymorphic — কোন কাগজ সেটা তার জানার কথা নয়।
        $document = Branch::create(['code' => 'B'.uniqid(), 'name_en' => 'Doc']);

        $approval = app(ApprovalEngine::class)->request(
            document: $document,
            module: 'sales',
            action: 'discount',
            amount: '50000',
            userId: $this->asked->id,
        );

        $this->assertNotNull($approval, 'অনুরোধই তৈরি হয়নি — পরীক্ষাটা কিছু মাপছে না।');

        return [$approval, $document];
    }

    private function asManager(): self
    {
        $this->manager->givePermissionTo($this->permission('approval.decide'));

        return $this->actingAs($this->manager);
    }

    /** ⓘ পাতায় সতর্কতাটা আছে কি না — হ্যাঁ/না, গোটা HTML নয়। */
    private function warns(string $html): bool
    {
        return str_contains($html, __('approval::message.changed_since_asked'));
    }

    private function permission(string $name): Permission
    {
        return Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]);
    }
}
