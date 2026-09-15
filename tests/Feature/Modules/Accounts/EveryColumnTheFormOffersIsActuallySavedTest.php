<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Modules\Accounts\Services\VoucherService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ফর্মে আছে, কলামে আছে, তবু সেভ হয় না।
 *
 * ── ⛔ দুইবার, একই জায়গায় ────────────────────────────────────────────
 * [[VoucherService::create]] প্রতিটা ঘর **হাতে লেখে**, `...$data` নয় —
 * আর সেটা ইচ্ছাকৃত, কারণ অনুরোধে যা-ই আসুক খাতায় কেবল ঘোষিত ঘরগুলোই
 * বসা উচিত।
 *
 * ⚠️ কিন্তু তার দাম হলো: নতুন কলাম যোগ করে ঐ তালিকায় লাইনটা লিখতে ভুলে
 * গেলে ঘরটা **নীরবে হারায়**। কোনো ভুল বার্তা নেই, ভ্যালিডেশন পাস করে,
 * সারিটা সেভ হয় — কেবল ঘরটা খালি।
 *
 * ⛔ ১৪ সেপ্টেম্বর ২০২৬: পাঁচটা ঘর এভাবে হারিয়েছিল।
 * ⛔ ১৫ সেপ্টেম্বর ২০২৬: **আরও বারোটা**, আর ঐ পাঁচটার মন্তব্য ঠিক
 *    পাশেই লেখা ছিল। মন্তব্য পড়ে কেউ ভুলটা এড়ায়নি।
 *
 * ── ⭐ কেন মন্তব্য যথেষ্ট নয়, আর এই পরীক্ষাটা দরকার ─────────────────
 * একটা সতর্কবাণী কেবল তাকেই বাঁচায় যে সেটা পড়ে। ⓘ এই দাবিটা **গুনে
 * দেখে**, আর গোনা কাউকে ভুলতে দেয় না।
 *
 * ── ⓘ কেন `fillable` দেখে হবে না ────────────────────────────────────
 * `Voucher::$fillable`-এও ঘরগুলো নেই, কিন্তু সেটা এখানে অপ্রাসঙ্গিক —
 * `create()` স্পষ্ট অ্যারে পাঠায়, তাই mass-assignment পাহারা ওখানে
 * খাটেই না। ⚠️ `fillable` দেখে পরীক্ষা লিখলে সেটা **ভুল জায়গা** পাহারা
 * দিত, আর সবুজ থাকত।
 */
final class EveryColumnTheFormOffersIsActuallySavedTest extends TestCase
{
    /**
     * ভাউচারের যে ঘরগুলো ব্যবহারকারী পূরণ করেন — প্রতিটা `create()`-এ
     * বসতেই হবে।
     *
     * ⓘ তালিকাটা হাতে লেখা, আর সেটাই ঠিক: `vouchers`-এর সব কলাম
     * ব্যবহারকারীর নয় (`status`, `approved_by`, `public_id`…), তাই
     * স্কিমা থেকে আপনা-আপনি বানালে পরীক্ষাটা মিথ্যা অভিযোগ করত।
     *
     * @var list<string>
     */
    private const USER_FIELDS = [
        // টাকা চলাচলের ব্লক — ১৪ সেপ্টেম্বর ২০২৬
        'carried_by', 'moved_at', 'note_counts', 'wallet', 'wallet_medium',
        'counterparty_phone', 'charge_borne_by', 'transfer_mode_id',
        'from_branch', 'from_account_name', 'deposit_slip_no', 'lands_on',

        // খরচ ভাউচারের নিজের ঘর — ১৫ সেপ্টেম্বর ২০২৬
        'cost_centre_id', 'expense_account_id', 'bill_no',
        'gross_amount', 'ait_amount', 'vds_amount',

        // জাবেদার উল্টো দাখিলার তারিখ — ১৫ সেপ্টেম্বর ২০২৬
        'reverse_on',

        // আগে থেকেই ছিল — এগুলোও পাহারায় থাকুক, যাতে কেউ সরিয়ে না ফেলে
        'ref_date', 'party_type', 'party_id', 'narration',
        'instrument', 'instrument_no', 'instrument_date',
        'money_category_id', 'money_subcategory_id',
        'against_type', 'against_id', 'charge_amount',
        'from_bank', 'from_account_no',
    ];

    /**
     * ⛔ প্রতিটা ঘর `create()`-এর তালিকায় আছে।
     */
    public function test_every_user_field_is_written_when_a_voucher_is_created(): void
    {
        $source = $this->createMethodSource();

        $missing = [];

        foreach (self::USER_FIELDS as $field) {
            if (! str_contains($source, "'".$field."' =>")) {
                $missing[] = $field;
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'এই ঘরগুলো VoucherService::create()-এ লেখা হয় না।',
            '',
            '⛔ ফলে নতুন ভাউচারে ওগুলো নীরবে হারাবে — ভ্যালিডেশন পাস করবে,',
            'সারিটা সেভ হবে, কেবল ঘরটা খালি থাকবে।',
            '',
            'ⓘ সম্পাদনায় বসবে, কারণ update() স্প্রেড করে — তাই অভিযোগটা',
            'আসবে এই অদ্ভুত আকারে: "নতুন ভাউচারে থাকে না, এডিট করলে বসে"।',
            '',
            'প্রতিটার জন্য এক লাইন:   \'ঘরের_নাম\' => $data[\'ঘরের_নাম\'] ?? null,',
        ]));
    }

    /**
     * ⭐ আর প্রতিটা ঘরের কলামটাও সত্যিই আছে।
     *
     * ⓘ উল্টো দিকের পাহারা: কেউ `create()`-এ একটা লাইন লিখল কিন্তু
     * মাইগ্রেশন লিখল না। ⚠️ তখন সেভ করতে গিয়ে SQL ভাঙত — লাইভে,
     * প্রথম ভাউচারেই।
     */
    public function test_every_field_written_has_a_column_to_land_in(): void
    {
        $columns = Schema::getColumnListing('vouchers');

        $orphans = array_values(array_diff(self::USER_FIELDS, $columns));

        $this->assertSame([], $orphans, implode("\n", [
            'এই ঘরগুলোর কোনো কলাম নেই, অথচ সেভ করার কথা।',
            '',
            '⛔ সেভ করতে গিয়ে SQL ভাঙবে — আর সেটা লাইভে, প্রথম ভাউচারেই।',
            '',
            ...$orphans,
        ]));
    }

    /**
     * ⓘ তালিকাটা খালি নয় — শূন্য সংগ্রহে দাবি সবসময় সবুজ।
     */
    public function test_the_list_itself_is_not_empty(): void
    {
        $this->assertGreaterThan(30, count(self::USER_FIELDS),
            'পাহারার তালিকাটাই ছোট হয়ে গেছে — কেউ কি ঘর সরিয়েছে?');
    }

    /**
     * `create()` মেথডের উৎস — কেবল ঐটুকু, গোটা ফাইল নয়।
     *
     * ⚠️ গোটা ফাইল পড়লে `update()`-এর স্প্রেড আর অন্য মেথডের লাইনগুলো
     * মিলে যেত, আর পাহারাটা **ভুয়া সবুজ** হত।
     */
    private function createMethodSource(): string
    {
        $path = app_path('Modules/Accounts/Services/VoucherService.php');
        $source = (string) file_get_contents($path);

        $start = strpos($source, 'public function create(');
        $this->assertNotFalse($start, 'VoucherService::create() খুঁজে পাওয়া যায়নি।');

        $end = strpos($source, 'public function update(', $start);
        $this->assertNotFalse($end, 'create()-এর শেষ কোথায় তা বোঝা গেল না।');

        return substr($source, $start, $end - $start);
    }
}
