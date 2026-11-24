<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Models\NoteLine;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WritesNotesAgainstBills;
use Tests\TestCase;

/**
 * ⛔ তালিকার মোটে কেবল পাকা নোট — খসড়া আর সইয়ের অপেক্ষা আলাদা লাইনে (মালিকের নিয়ম "মোটে কেবল চূড়ান্ত কাগজ", ২৪ অক্টোবর ২০২৬;
 * নকশা v5-এর তিন তালিকা, [[NoteListController]])।
 *
 * ⓘ দৃশ্যের `totals` আদায়ের তালিকার হুবহু গড়ন ([[\App\Modules\Sales\Http\Controllers\CollectionController]]):
 * `rows`, `money` (পাকা), `drafts` ['count', 'money'], `waiting` ['count', 'money']।
 *
 * দাবি: ক্রেডিট নোটের তালিকায় পাকা ১০০ মোটে; মালিকের সইয়ের অপেক্ষার ৪০ "অপেক্ষমাণ" লাইনে; না-পাঠানো খসড়া ২৫ খসড়ার লাইনে;
 * বাতিল ৯৯৯ আর অন্য ভাগের (সরবরাহকারীর ডেবিট নোট ৭৭) কোনোটাতেই নয়।
 */
final class TheNoteListsCountOnlyPostedNotesTest extends TestCase
{
    use RefreshDatabase;
    use WritesNotesAgainstBills;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTheNoteScene();
    }

    public function test_the_credit_note_total_counts_only_posted_notes_and_the_waiting_one_has_its_own_line(): void
    {
        $this->postNote($this->lump('100'));

        $cancelled = $this->postNote($this->lump('999'));
        app(NoteService::class)->cancel($cancelled, 'ভুল');

        $this->postNote($this->draftNote(Note::TYPE_DN, (int) $this->supplier->id, [
            ['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId('4300'), 'amount' => '77'],
        ]));

        $this->lump('25');

        // ⓘ শেষে — [[postNote()]] মালিকের সব অপেক্ষার ছাড়ে সই দেয়, তাই অপেক্ষার নোটটা তার পরে
        $waiting = $this->lump('40');
        app(NoteService::class)->confirm($waiting);
        $this->assertTrue($waiting->fresh()->isDraft(), 'দৃশ্যটাই ভুল — ক্রেডিট নোট মালিকের সইয়ের অপেক্ষায় থামেনি।');

        $this->get(route('accounts.note.cn.index'))->assertOk()->assertViewHas('totals', function (array $totals): bool {
            $this->assertMoney('100', (string) $totals['money'], '⛔ মোটে পাকা নয় এমন নোট গোনা হলো (বা পাকাটা বাদ পড়ল)।');
            $this->assertSame(1, (int) $totals['waiting']['count'], '⛔ সইয়ের অপেক্ষার নোট আলাদা লাইনে নেই।');
            $this->assertMoney('40', (string) $totals['waiting']['money']);
            $this->assertSame(1, (int) $totals['drafts']['count'], '⛔ না-পাঠানো খসড়া আলাদা লাইনে নেই।');
            $this->assertMoney('25', (string) $totals['drafts']['money']);
            $this->assertArrayHasKey('rows', $totals);

            return true;
        });
    }

    public function test_the_supplier_debit_note_list_counts_only_its_own_kind(): void
    {
        $this->postNote($this->lump('100'));
        $this->postNote($this->draftNote(Note::TYPE_DN, (int) $this->supplier->id, [
            ['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId('4300'), 'amount' => '77'],
        ]));

        $this->get(route('accounts.note.dn.index'))->assertOk()->assertViewHas('totals', function (array $totals): bool {
            $this->assertMoney('77', (string) $totals['money'], '⛔ সরবরাহকারীর ডেবিট নোটের তালিকায় ক্রেডিট নোট গোনা হলো।');

            return true;
        });
    }

    /** বিল ছাড়া এক খাতের ক্রেডিট নোট — ভ্যাট ০, তাই মোট = অঙ্ক */
    private function lump(string $amount): Note
    {
        return $this->draftNote(Note::TYPE_CN, (int) $this->customer->id, [
            ['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId(StandardChart::DISCOUNT_GIVEN), 'amount' => $amount],
        ]);
    }
}
