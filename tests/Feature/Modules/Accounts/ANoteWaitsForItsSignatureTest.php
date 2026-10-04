<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\AccountsSignature;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * গ১ — ক্রেডিট/ডেবিট নোট সই ছাড়া খাতায় বসে না, আর শেষ সই পড়লে নিজেই বসে (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে নোট "পাকা" চাপলেই গ্রাহকের পাওনা কমত — কোনো সই ছাড়া, অথচ একই টাকা ভাউচারে নড়লে সই লাগত।
 *
 * ⓘ ছক বন্ধ থাকলে (UB-তে সব বন্ধ) আগের মতোই সাথে সাথে বসে — সেটাও এখানে মাপা।
 */
final class ANoteWaitsForItsSignatureTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $writer;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->signer = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->writer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->actingAs($this->signer);
        app(StandardChart::class)->install();
    }

    public function test_with_the_flow_on_the_note_waits_and_the_last_signature_posts_it(): void
    {
        $this->flowOn();
        $this->actingAs($this->writer);

        $note = app(NoteService::class)->confirm($this->draft());

        $this->assertTrue($note->fresh()->isDraft(), '⛔ সইয়ের ছক চালু, অথচ নোট সই ছাড়াই পাকা হলো।');
        $this->assertSame(0, $this->rows($note), '⛔ সইয়ের আগেই খাতায় সারি বসল।');

        $approval = app(ApprovalEngine::class)->latestFor($note, AccountsSignature::NOTE);
        $this->assertNotNull($approval, 'সই চাওয়াই হয়নি — নোট আটকে আছে অথচ কেউ জানে না।');
        $this->assertSame(Approval::PENDING, $approval->status);

        $this->actingAs($this->signer);
        app(ApprovalEngine::class)->approve($approval, $this->signer);

        $this->assertTrue($note->fresh()->isConfirmed(), '⛔ শেষ সই পড়ল, অথচ নোট খসড়াই রইল — কাউকে আবার চাপতে হবে।');
        $this->assertGreaterThan(0, $this->rows($note));
    }

    public function test_a_refused_note_stays_a_draft_and_out_of_the_books(): void
    {
        $this->flowOn();
        $this->actingAs($this->writer);

        $note = app(NoteService::class)->confirm($this->draft());
        $approval = app(ApprovalEngine::class)->latestFor($note, AccountsSignature::NOTE);

        $this->actingAs($this->signer);
        app(ApprovalEngine::class)->reject($approval, $this->signer, 'অঙ্ক ভুল');

        $this->assertTrue($note->fresh()->isDraft());
        $this->assertSame(0, $this->rows($note), '⛔ ফেরত দেওয়া নোট খাতায় বসল।');
    }

    public function test_with_the_flow_off_the_note_posts_at_once_as_before(): void
    {
        $this->actingAs($this->writer);

        $note = app(NoteService::class)->confirm($this->draft());

        $this->assertTrue($note->fresh()->isConfirmed(), '⛔ ছক বন্ধ, তবু নোট আটকে গেল — UB-র রোজকার কাজ থামত।');
        $this->assertGreaterThan(0, $this->rows($note));
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function flowOn(): void
    {
        $flow = ApprovalFlow::create([
            'company_id' => $this->company->id,
            'module' => AccountsSignature::MODULE,
            'action' => AccountsSignature::NOTE,
            'is_active' => true,
        ]);

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => 'user',
            'approver_id' => $this->signer->id,
        ]);
    }

    private function draft(): Note
    {
        return app(NoteService::class)->create([
            'direction' => Note::CREDIT,
            'party_type' => 'customer',
            'party_id' => Customer::query()->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'amount' => '700',
            'tax_amount' => '0',
            'reason' => 'agreed_discount',
            'narration' => 'গ১ নোট',
        ]);
    }

    private function rows(Note $note): int
    {
        return LedgerEntry::query()->where('source_type', $note->sourceType())->where('source_id', $note->id)->count();
    }
}
