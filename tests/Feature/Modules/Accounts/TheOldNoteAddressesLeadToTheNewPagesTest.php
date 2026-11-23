<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\NoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WritesNotesAgainstBills;
use Tests\TestCase;

/**
 * ⭐ নোট তিন ভাগে, তিন পাতায় — মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬; ⓘ পুরনো ঠিকানা আর পুরনো নোট ভাঙে না।
 *
 * দাবি:
 *  - `/accounts/notes?direction=credit` → ক্রেডিট নোটের তালিকা; `?direction=debit` → সরবরাহকারীর ডেবিট নোট;
 *    `?direction=debit&kind=customer` → গ্রাহকের ডেবিট নোট;
 *  - `/accounts/notes/create?direction=debit&kind=customer` → গ্রাহকের ডেবিট নোটের ফর্ম (পক্ষের ধরন সাথে যায়);
 *  - পুরনো এক-অঙ্কের পথে লেখা নোট ভাগ পায় (ক্রেডিট → cn, ডেবিট + গ্রাহক → cdn, বাকি ডেবিট → dn), আর মাইগ্রেশনের
 *    আগের সারি (ভাগ খালি) একই নিয়মে নিজের ভাগ বলে ([[Note::type()]])।
 */
final class TheOldNoteAddressesLeadToTheNewPagesTest extends TestCase
{
    use RefreshDatabase;
    use WritesNotesAgainstBills;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTheNoteScene();
    }

    public function test_the_old_list_address_follows_the_direction(): void
    {
        $this->get(route('accounts.note.index', ['direction' => Note::CREDIT]))
            ->assertStatus(301)->assertRedirect(route('accounts.note.cn.index'));

        $this->get(route('accounts.note.index', ['direction' => Note::DEBIT]))
            ->assertStatus(301)->assertRedirect(route('accounts.note.dn.index'));

        $this->get(route('accounts.note.index', ['direction' => Note::DEBIT, 'kind' => Note::KIND_CUSTOMER]))
            ->assertStatus(301)->assertRedirect(route('accounts.note.cdn.index'));

        $this->get(route('accounts.note.index'))->assertRedirect(route('accounts.note.cn.index'));
    }

    public function test_the_old_create_address_follows_the_direction_and_keeps_the_kind(): void
    {
        $this->get(route('accounts.note.create', ['direction' => Note::DEBIT, 'kind' => Note::KIND_CUSTOMER]))
            ->assertStatus(301)->assertRedirect(route('accounts.note.cdn.create', ['kind' => Note::KIND_CUSTOMER]));

        $this->get(route('accounts.note.create', ['direction' => Note::CREDIT]))
            ->assertStatus(301)->assertRedirect(route('accounts.note.cn.create'));

        // ⓘ ভাগে না চলা ধরন পথে থাকে না — সেবাদাতা ডেবিট নোটে নেই
        $this->get(route('accounts.note.create', ['direction' => Note::DEBIT, 'kind' => Note::KIND_SERVICE_PROVIDER]))
            ->assertStatus(301)->assertRedirect(route('accounts.note.dn.create'));
    }

    public function test_an_old_single_amount_note_gets_its_type(): void
    {
        $notes = app(NoteService::class);
        $base = ['trx_date' => now()->toDateString(), 'amount' => '100', 'tax_amount' => '0', 'reason' => 'price_correction'];

        $cn = $notes->create($base + ['direction' => Note::CREDIT, 'party_kind' => Note::KIND_CUSTOMER, 'party_id' => $this->customer->id]);
        $cdn = $notes->create($base + ['direction' => Note::DEBIT, 'party_kind' => Note::KIND_CUSTOMER, 'party_id' => $this->customer->id]);
        $dn = $notes->create($base + ['direction' => Note::DEBIT, 'party_kind' => Note::KIND_SUPPLIER, 'party_id' => $this->supplier->id]);

        $this->assertSame([Note::TYPE_CN, Note::TYPE_CDN, Note::TYPE_DN], [$cn->note_type, $cdn->note_type, $dn->note_type]);

        // ⓘ মাইগ্রেশনের আগের সারি — ভাগের ঘর খালি
        DB::table('acc_notes')->whereIn('id', [$cn->id, $cdn->id, $dn->id])->update(['note_type' => null]);

        $this->assertSame(
            [Note::TYPE_CN, Note::TYPE_CDN, Note::TYPE_DN],
            [Note::query()->findOrFail($cn->id)->type(), Note::query()->findOrFail($cdn->id)->type(), Note::query()->findOrFail($dn->id)->type()],
            '⛔ ভাগ-খালি পুরনো নোট ভুল পাতায় যায়।',
        );
    }
}
