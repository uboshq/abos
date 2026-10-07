<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নোট গোনা হলো, আর রসিদটা ৫০০ দিয়ে মারা গেল।
 *
 * ── ⛔ যা হয়েছিল, ১৮ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * মালিক পুঁজির খাতা থেকে *"টাকা নিন → রসিদ ভাউচার"* চেপে ফর্মটা জমা
 * দিয়েছেন, আর পেয়েছেন `QueryException — Array to string conversion`।
 *
 * ⓘ নোটের ঘরগুলো `note_counts[1000]`, `note_counts[500]` … আকারে আসে,
 * অর্থাৎ একটা **অ্যারে**। ⚠️ কলামটা MySQL-এ `json`, কিন্তু মডেলের
 * `casts()`-এ ওর নাম ছিল না — তাই PDO অ্যারেটাকেই bind করতে গেছে।
 *
 * ── ⚠️ কেন তিন-তিনটা পাহারা সবুজ থেকেও কিছু ধরেনি ────────────────────
 * ⛔ [[EveryColumnTheFormOffersIsActuallySavedTest]] মাপত `$fillable`,
 * [[TheReceiptFormAsksAllEighteenTest]] মাপত পর্দার ঘর, আর
 * [[MoneyMovementHasEveryFieldTheOwnerAskedForTest]] মাপত কলাম —
 * **তিনজনের কেউ একবারও অ্যারেটা ভরে সেভ করে দেখেনি**।
 *
 * ⭐ তাই এই ফাইলের দাবি একটাই আর সেটা কঠিন: ফর্মটা সত্যিই জমা হোক,
 * তারপর **সারিটা ডেটাবেস থেকে পড়ে** দেখা হোক নোটগুলো ঠিক আছে কিনা।
 */
final class TheNoteCountWasAnArrayAndTheInsertDiedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($user);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
    }

    /**
     * ⭐ নোট গুনে জমা দিলে রসিদটা বসে, আর সারিতে গোনাটাও থাকে।
     */
    public function test_a_counted_receipt_saves_and_the_notes_come_back(): void
    {
        $this->post(route('accounts.voucher.store', Voucher::RECEIPT), [
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'from_account_id' => $this->account(StandardChart::OWNER_CAPITAL),
            'to_account_id' => $this->moneyAccount(),
            'amount' => '2500',
            'narration' => 'NOTE-COUNT',
            'instrument' => 'cash',

            /*
             * ⓘ ঠিক পর্দার আকারেই: দশটা ঘর, দুইটা ভরা, বাকিগুলো খালি।
             * ⚠️ খালিগুলো যদি ছেঁটে না যায় তবে JSON-এ `null` জমবে।
             */
            'note_counts' => [
                '1000' => '2',
                '500' => '1',
                '100' => '',
                '50' => null,
                '20' => '0',
            ],

            'save_as_draft' => '1',
        ])->assertSessionHasNoErrors();

        $voucher = Voucher::query()->latest('id')->firstOrFail();

        /*
         * ⛔ আসল দাবি: সারিটা আছে — অর্থাৎ ইনসার্ট আর মরেনি।
         */
        $this->assertSame('NOTE-COUNT', $voucher->narration,
            'রসিদটাই বসেনি — ইনসার্ট আবার ভেঙেছে।');

        /*
         * ⭐ আর গোনাটা **অ্যারে হয়েই ফিরে আসে**, JSON স্ট্রিং নয়।
         * ⓘ নাহলে সম্পাদনার পর্দায় `$voucher->note_counts[1000]` একটা
         * স্ট্রিং-অফসেট হয়ে যেত, আর ঘরগুলো খালি দেখাত।
         */
        $this->assertIsArray($voucher->note_counts,
            'গোনাটা অ্যারে হয়ে ফেরেনি — cast আবার নেই।');

        /*
         * ⚠️ `assertEquals`, `assertSame` নয় — MySQL-এর `json` টাইপ
         * চাবিগুলো **নিজের নিয়মে সাজিয়ে** রাখে (ছোট চাবি আগে), তাই যে
         * ক্রমে পাঠানো হয়েছে সে ক্রমে ফেরে না। ⓘ এখানে ক্রমটা কোনো
         * দাবি নয় — কোন নোট কয়টা, সেটাই দাবি।
         */
        $this->assertEquals(['1000' => '2', '500' => '1'], $voucher->note_counts,
            'খালি ঘরগুলোও JSON-এ জমেছে — "গোনা হয়নি" আর "গুনে শূন্য" আলাদা থাকল না।');
    }

    /**
     * ⭐ একটাও না গুনলে ঘরটা `null` থাকে, খালি অ্যারে নয়।
     *
     * ⚠️ খালি অ্যারেও একটা বৈধ JSON, আর ওটা *"নোট গোনা হয়েছে"* বলে
     * দাবি করত — অথচ কেউ গোনেননি।
     */
    public function test_an_uncounted_receipt_stores_nothing_at_all(): void
    {
        $this->post(route('accounts.voucher.store', Voucher::RECEIPT), [
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'from_account_id' => $this->account(StandardChart::OWNER_CAPITAL),
            'to_account_id' => $this->moneyAccount(),
            'amount' => '700',
            'narration' => 'NOT-COUNTED',
            'note_counts' => ['1000' => '', '500' => '', '100' => ''],
            'save_as_draft' => '1',
        ])->assertSessionHasNoErrors();

        $voucher = Voucher::query()->latest('id')->firstOrFail();

        $this->assertSame('NOT-COUNTED', $voucher->narration);
        $this->assertNull($voucher->note_counts,
            'কেউ গোনেননি, অথচ খাতা বলছে গোনা হয়েছে।');
    }

    /**
     * ⭐ ঘরগুলো পর্দাতেই আছে — অ্যারের নামেই।
     */
    public function test_the_note_boxes_are_on_the_receipt_screen(): void
    {
        $page = $this->get(route('accounts.voucher.create', ['type' => Voucher::RECEIPT]));

        $page->assertOk();
        $page->assertSee('name="note_counts[1000]"', escape: false);
        $page->assertSee('name="note_counts[1]"', escape: false);
    }

    private function moneyAccount(): int
    {
        return (int) Account::query()->money()->where('is_group', false)->value('id');
    }

    private function account(string $code): int
    {
        return (int) Account::query()->where('code', $code)->value('id');
    }
}
