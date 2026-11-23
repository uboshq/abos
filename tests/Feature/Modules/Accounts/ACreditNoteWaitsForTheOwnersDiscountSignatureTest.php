<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Models\NoteLine;
use App\Modules\Accounts\Services\AccountsSignature;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\WritesNotesAgainstBills;
use Tests\TestCase;

/**
 * ⭐ ক্রেডিট নোট গ্রাহককে ছাড় — মালিকের ছাড়ের সইয়ে যায় (মালিকের নিয়ম ১ অক্টোবর ২০২৬: *"বিলে কোনো ছাড় মালিকের অনুমতি ছাড়া
 * দিতে পারবে না"*; নকশা v5, ২৩ নভেম্বর ২০২৬; [[NoteService::waitsForASignature()]])।
 *
 * দাবি:
 *  - সারির ক্রেডিট নোট পাকা করলে `sales`/`discount` সই চাওয়া হয়, অঙ্ক = নোট + ফেরতের ভাগ; নোট খসড়া, খাতায় কিছু নেই;
 *  - মালিক সই দিলে নোট নিজেই খাতায় ([[FinishTheAccountsPaperOnTheLastSignature]]);
 *  - ছাড়ের ছক না থাকলে থামে (fail-closed) — খাতায় কিছু নয়;
 *  - গ্রাহকের আর সরবরাহকারীর ডেবিট নোট হিসাবের নোটের ছকে — ছক না থাকলে সাথে সাথে খাতায়।
 */
final class ACreditNoteWaitsForTheOwnersDiscountSignatureTest extends TestCase
{
    use RefreshDatabase;
    use WritesNotesAgainstBills;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTheNoteScene();
    }

    public function test_a_lines_credit_note_asks_the_owner_and_posts_on_his_signature(): void
    {
        $invoice = $this->salesInvoice('4', '200');
        $billLine = $invoice->lines->first();

        // ⓘ লেখেন হিসাবরক্ষক — মালিক নিজে নয়, তাই সই আলাদা হাতে
        $this->actingAs(User::query()->where('email', 'accounts@abos.test')->firstOrFail());

        $note = $this->draftNote(Note::TYPE_CN, (int) $this->customer->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $billLine->id, 'qty' => '1', 'goods_moved' => true],
            ['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId(StandardChart::DISCOUNT_GIVEN), 'amount' => '50'],
        ], ['against_no' => $invoice->document_no]);

        $note = app(NoteService::class)->confirm($note);

        $this->assertTrue($note->fresh()->isDraft(), '⛔ মালিকের সই ছাড়াই ক্রেডিট নোট খাতায় উঠল।');
        $this->assertSame(0, $this->rowsOf($note), '⛔ সইয়ের আগেই খাতায় সারি বসল।');
        $this->assertNull($note->fresh()->lines->firstWhere('goods_moved', true)->return_id, '⛔ সইয়ের আগেই বিক্রয় ফেরত বানানো হলো।');

        $approval = app(ApprovalEngine::class)->latestFor($note, NoteService::DISCOUNT_ACTION);
        $this->assertNotNull($approval, '⛔ মালিকের কাছে ছাড়ের সই চাওয়াই হয়নি।');
        $this->assertSame(NoteService::DISCOUNT_MODULE, $approval->module);
        $this->assertSame(Approval::PENDING, $approval->status);
        $this->assertMoney($note->fresh()->grandTotal(), (string) $approval->amount, '⛔ সইয়ের অঙ্কে ফেরতের ভাগ নেই — মালিক অর্ধেক সিদ্ধান্তে সই দেন।');
        $this->assertNull(app(ApprovalEngine::class)->latestFor($note, AccountsSignature::NOTE), '⛔ ক্রেডিট নোট হিসাবের নোটের ছকেও গেল।');

        $this->actingAs($this->owner);
        app(ApprovalEngine::class)->approve($approval, $this->owner);

        $fresh = Note::query()->findOrFail($note->id);
        $this->assertTrue($fresh->isConfirmed(), '⛔ মালিক সই দিলেন, অথচ নোট খসড়াই রইল।');
        $this->assertGreaterThan(0, $this->rowsOf($fresh));
        $this->assertNotNull($fresh->lines->firstWhere('goods_moved', true)->return_id, '⛔ সইয়ের পরে বিক্রয় ফেরত হয়নি।');
    }

    public function test_without_the_discount_flow_a_credit_note_is_refused(): void
    {
        ApprovalFlow::query()->where('company_id', $this->company->id)
            ->where('module', NoteService::DISCOUNT_MODULE)->where('action', NoteService::DISCOUNT_ACTION)
            ->get()->each(fn (ApprovalFlow $flow) => $flow->delete()); // ⓘ একটা একটা করে — মডেলের ঘটনায় ছকের ক্যাশ নতুন হয়
        $this->assertNull(app(ApprovalEngine::class)->firstSigner(NoteService::DISCOUNT_MODULE, NoteService::DISCOUNT_ACTION, '50', 'Note'),
            'দৃশ্যটাই ভুল — ছাড়ের ছক মোছার পরেও সইদাতা আছে (ক্যাশ বা অন্য ছকে ফেরা)।');

        $note = $this->draftNote(Note::TYPE_CN, (int) $this->customer->id, [
            ['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId(StandardChart::DISCOUNT_GIVEN), 'amount' => '50'],
        ]);

        try {
            app(NoteService::class)->confirm($note);
            $this->fail('⛔ ছাড়ের ছক নেই, তবু ক্রেডিট নোট এগোল — ছাড় মালিকের সই ছাড়া।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertTrue($note->fresh()->isDraft());
        $this->assertSame(0, $this->rowsOf($note));
    }

    public function test_debit_notes_use_the_accounts_note_flow_and_post_at_once_without_one(): void
    {
        foreach ([[Note::TYPE_CDN, (int) $this->customer->id, StandardChart::SALES], [Note::TYPE_DN, (int) $this->supplier->id, '4300']] as [$type, $party, $code]) {
            $note = app(NoteService::class)->confirm($this->draftNote($type, $party, [
                ['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId($code), 'amount' => '60'],
            ]));

            $this->assertTrue($note->fresh()->isConfirmed(), "⛔ {$type}: হিসাবের নোটের ছক নেই, তবু নোট আটকে গেল।");
            $this->assertNull(app(ApprovalEngine::class)->latestFor($note, NoteService::DISCOUNT_ACTION), "⛔ {$type}: ডেবিট নোট মালিকের ছাড়ের সই চাইল।");
            $this->assertGreaterThan(0, $this->rowsOf($note));
        }
    }

    private function rowsOf(Note $note): int
    {
        return LedgerEntry::query()->where('source_type', $note->sourceType())->where('source_id', $note->id)->count();
    }
}
