<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নমুনায় চৌদ্দটা ঘর, পর্দায় ছিল তিনটা।
 *
 * ── ⛔ কীভাবে ধরা পড়ল, ১৫ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * মালিক নমুনা আর আসল পর্দা পাশাপাশি রেখে দেখালেন, আর বললেন:
 * "সবগুলোই এরকম মেলেনি। আমি বলেছিলাম ১০০% মানে ১০০%, ৯৯.৯৯%-ও না।"
 *
 * ⚠️ গুনে দেখা গেল খরচ ভাউচারের নমুনার চৌদ্দটা ঘরের মাত্র তিনটা পর্দায়
 * ছিল। ⓘ কারণ পাঁচটা ভাউচারের চারটাই একটাই simple-form ব্যবহার করত,
 * আর সেখানে টাইপভেদে আলাদা কিছুই ছিল না।
 *
 * ── ⭐ কেন একটা পরীক্ষা লাগল, মনে রাখা যথেষ্ট নয় ────────────────────
 * আগের দফায় কাজটা "হয়ে গেছে" ধরে নেওয়া হয়েছিল, কারণ টাকা চলাচলের
 * ব্লকটা সত্যিই বসেছিল। ⛔ কিন্তু কেউ গুনে দেখেনি।
 *
 * ⓘ এই পরীক্ষাটা নমুনার ঘরগুলোর নাম ধরে ধরে পর্দায় খোঁজে। ⚠️ একটাও না
 * মিললে লাল — অর্থাৎ "৯৯.৯৯%" এখানে পাস নয়, আর সেটাই মালিকের চাওয়া।
 *
 * ── ⓘ তালিকাটা হাতে লেখা কেন ───────────────────────────────────────
 * নমুনার ফাইলটা রেপোর বাইরে (Audite Report ফোল্ডারে), আর CI-তে সেটা
 * থাকবে না। ⚠️ তাই নমুনা বদলালে এই তালিকাটাও হাতে বদলাতে হবে — আর
 * সেই বাধ্যবাধকতাটাই ইচ্ছাকৃত: তালিকা বদলানো মানে কেউ নমুনাটা সত্যিই
 * খুলে দেখেছে।
 */
final class TheSampleAskedFourteenAndTheScreenAskedThreeTest extends TestCase
{
    use RefreshDatabase;

    /** খরচ ভাউচার — নমুনার s-exp অংশ। @var array<string, string> */
    private const EXPENSE = [
        'trx_date' => 'তারিখ',
        'expense_account_id' => 'খরচের খাত',
        'cost_centre_id' => 'খরচের কেন্দ্র',
        'payee_name' => 'কাকে দেওয়া হলো',
        'bill_no' => 'বিল / ভাউচার নম্বর',
        'gross_amount' => 'বিলের মোট',
        'ait_amount' => 'AIT',
        'vds_amount' => 'VDS',
        'from_account_id' => 'যে খাত থেকে',
        'attachment' => 'সংযুক্তি',
        'narration' => 'বিবরণ',
        'amount' => 'টাকার অঙ্ক',
    ];

    /** জাবেদা — নমুনার s-jrn অংশ। @var array<string, string> */
    private const JOURNAL = [
        'trx_date' => 'তারিখ',
        'narration' => 'বিবরণ',
        'reverse_on' => 'উল্টো দাখিলার তারিখ',
        'attachment' => 'সংযুক্তি',
    ];

    /** কন্ট্রা — নমুনার s-con অংশ। @var array<string, string> */
    private const CONTRA = [
        'trx_date' => 'তারিখ',
        'from_account_id' => 'যে খাত থেকে',
        'to_account_id' => 'যে খাতে',
        'deposit_slip_no' => 'জমা স্লিপ / রেফারেন্স',
        'carried_by' => 'কে হাতে নিয়ে গেল',
        'charge_amount' => 'ব্যাংক চার্জ',
        'amount' => 'টাকার পরিমাণ',
        'narration' => 'বিবরণ',
    ];

    /** রসিদ ও পরিশোধ — নমুনার s-party অংশ। @var array<string, string> */
    private const PARTY = [
        'trx_date' => 'লেনদেনের তারিখ',
        'carried_by' => 'কার মাধ্যমে',
        'moved_at' => 'কখন',
        'party_type' => 'ডিপোজিটরের ধরন',
        'party_id' => 'ডিপোজিটরের নাম',
        'wallet' => 'ওয়ালেট',
        'wallet_medium' => 'মাধ্যম',
        'counterparty_phone' => 'প্রেরকের মোবাইল নম্বর',
        'instrument_no' => 'ট্রানজেকশন আইডি',
        'charge_amount' => 'চার্জ',
        'charge_borne_by' => 'চার্জটা কে দিয়েছে',
        'transfer_mode_id' => 'ট্রান্সফার মোড',
        'from_bank' => 'যে ব্যাংক থেকে',
        'from_account_no' => 'হিসাব নম্বর',
        'from_branch' => 'ব্রাঞ্চের নাম',
        'from_account_name' => 'হিসাবধারীর নাম',
        'deposit_slip_no' => 'জমা স্লিপ নম্বর',
        'lands_on' => 'কবে পৌঁছাবে',
        'instrument_date' => 'চেকের তারিখ',
        'amount' => 'টাকার পরিমাণ',
        'narration' => 'বিবরণ',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_the_expense_voucher_asks_everything_the_sample_asks(): void
    {
        $this->assertScreenAsks('expense', self::EXPENSE);
    }

    public function test_the_journal_voucher_asks_everything_the_sample_asks(): void
    {
        $this->assertScreenAsks('journal', self::JOURNAL);
    }

    public function test_the_contra_voucher_asks_everything_the_sample_asks(): void
    {
        $this->assertScreenAsks('contra', self::CONTRA);
    }

    public function test_the_receipt_voucher_asks_everything_the_sample_asks(): void
    {
        $this->assertScreenAsks('receipt', self::PARTY);
    }

    public function test_the_payment_voucher_asks_everything_the_sample_asks(): void
    {
        $this->assertScreenAsks('payment', self::PARTY);
    }

    /**
     * ⭐ ফাইলের ঘর থাকলে ফর্মটা ফাইল নিতেও পারে।
     *
     * ── ⛔ কেন এই দাবিটা আলাদা ──────────────────────────────────────
     * enctype ছাড়া ব্রাউজার ফাইলের কেবল নামটা পাঠায়, বাইটগুলো নয়।
     * ⚠️ সার্ভারে কোনো ভুল দেখা যেত না: file('attachment') হত null, আর
     * কোড ভাবত ব্যবহারকারী কিছু দেননি। ⓘ তিনি দেখতেন ভাউচারটা সেভ
     * হয়েছে, কেবল ছবিটা নেই — আর কেন, তার কোনো চিহ্নও থাকত না।
     */
    public function test_a_form_that_offers_a_file_can_actually_carry_one(): void
    {
        foreach (['expense', 'journal'] as $type) {
            $this->assertStringContainsString(
                'enctype="multipart/form-data"',
                $this->screen($type),
                $type.' ফর্মে ফাইলের ঘর আছে, কিন্তু enctype নেই — ব্রাউজার বাইটগুলো পাঠাবেই না।',
            );
        }
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function assertScreenAsks(string $type, array $fields): void
    {
        $html = $this->screen($type);

        $missing = [];

        foreach ($fields as $name => $label) {
            /*
             * ⓘ name="x" বা name="x[ — দ্বিতীয়টা অ্যারের ঘরের জন্য
             * (note_counts[500])। ⚠️ কেবল প্রথমটা খুঁজলে অ্যারের
             * ঘরগুলো "নেই" বলে মিথ্যা অভিযোগ আসত।
             */
            if (preg_match('/name="'.preg_quote($name, '/').'(\[|")/', $html) !== 1) {
                $missing[] = $name.'   ('.$label.')';
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            $type.' ভাউচারের পর্দায় নমুনার এই ঘরগুলো নেই:',
            '',
            '⛔ মালিকের নিয়ম: ১০০% মানে ১০০%, ৯৯.৯৯%-ও নয়।',
            '',
            'ⓘ নমুনা: Audite Report ফোল্ডারের "পাঁচটা ভাউচার — নকশা.html"',
            '',
            ...$missing,
        ]));

        // ⚠️ শূন্য তালিকায় দাবি সবসময় সবুজ — সংখ্যাটা আগে দাবি করা
        $this->assertGreaterThan(3, count($fields),
            $type.'-এর তালিকাটাই ছোট হয়ে গেছে — কেউ কি ঘর মুছেছে?');
    }

    private function screen(string $type): string
    {
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $user->switchCompany($company->id);

        $response = $this->actingAs($user)->get('/accounts/vouchers/'.$type.'/create');

        $response->assertOk();

        return (string) $response->getContent();
    }
}
