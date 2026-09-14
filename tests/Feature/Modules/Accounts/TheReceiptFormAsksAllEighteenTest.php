<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * পর্দাটা সত্যিই আঠারোটা প্রশ্ন করে কি না।
 *
 * ── ⭐ কেন এটা আলাদা পরীক্ষা ─────────────────────────────────────────
 * [[MoneyMovementHasEveryFieldTheOwnerAskedForTest]] দেখে **কম্পোনেন্টে**
 * ঘরগুলো আছে কি না। ⓘ কিন্তু কম্পোনেন্ট থাকা আর **পর্দায় আসা** দুইটা
 * আলাদা সত্য — মাঝখানে কন্ট্রোলার, রুট, অনুমতি আর লেআউট।
 *
 * ⛔ আর ঠিক ঐ মাঝখানেই আজ একটা ভুল হয়েছিল: কম্পোনেন্ট বসানোর পর
 * `carriers` ও `transferModes` কন্ট্রোলার থেকে পাঠানো হয়নি। ⚠️ ফল হত
 * দুইটা **খালি ড্রপডাউন** — দেখা যেত, ভিতরে কিছু থাকত না, আর কোনো
 * ত্রুটিও আসত না, কারণ কম্পোনেন্টের ডিফল্ট `[]`।
 *
 * ── ⚠️ আর কার্ল দিয়ে এটা যাচাই করা যায় না ────────────────────────────
 * প্রথমে `curl` দিয়ে পাতাটা টেনে দেখা হয়েছিল — **HTTP ২০০** এল, আর
 * একটাও ঘর পাওয়া গেল না। ⓘ কারণ কার্ল লগইন করা নয়, তাই ২০০-টা ছিল
 * **লগইন পাতার**। ⭐ সবুজ সংখ্যাটা সত্য ছিল, কেবল অন্য প্রশ্নের উত্তর।
 */
final class TheReceiptFormAsksAllEighteenTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> মালিকের তালিকা, হুবহু */
    private const FIELDS = [
        'carried_by', 'moved_at', 'instrument', 'note_counts',
        'wallet', 'wallet_medium', 'counterparty_phone',
        'instrument_no', 'instrument_date',
        'charge_amount', 'charge_borne_by', 'transfer_mode_id',
        'from_bank', 'from_branch', 'from_account_name', 'from_account_no',
        'deposit_slip_no', 'lands_on',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * ⛔ আঠারোটার আঠারোটাই পর্দায়।
     */
    public function test_the_receipt_form_asks_every_one_of_them(): void
    {
        $html = $this->get(route('accounts.voucher.create', ['type' => 'receipt']))
            ->assertOk()
            ->getContent();

        $this->assertIsString($html);

        $missing = [];

        foreach (self::FIELDS as $field) {
            if (! str_contains($html, 'name="'.$field.'"') && ! str_contains($html, 'name="'.$field.'[')) {
                $missing[] = $field;
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'রসিদের ফর্মে এই ঘরগুলো নেই:',
            '',
            '⚠️ কম্পোনেন্টে থাকা আর পর্দায় আসা দুইটা আলাদা সত্য।',
            '',
            ...$missing,
        ]));
    }

    /**
     * ⭐ দুইটা তালিকা সত্যিই ভরা — খালি ড্রপডাউন নয়।
     *
     * ⛔ এটাই আজকের ভুলটা ছিল: ঘর দুইটা এল, ভিতরে কিছু নেই। ⓘ পর্দা
     * দেখতে ঠিক, আর ব্যবহারকারী বেছে নিতেই পারেন না — কোনো ত্রুটি ছাড়া।
     */
    public function test_the_two_lists_are_not_empty(): void
    {
        $html = (string) $this->get(route('accounts.voucher.create', ['type' => 'receipt']))
            ->assertOk()
            ->getContent();

        /*
         * ⓘ বাহকের তালিকায় অন্তত ডেমোর ব্যবহারকারীরা থাকার কথা, আর
         * ট্রান্সফার মোডে BEFTN/RTGS — দুইটাই সিডারে বসানো।
         */
        foreach (['carried_by', 'transfer_mode_id'] as $name) {
            $at = strpos($html, 'name="'.$name.'"');
            $this->assertNotFalse($at, "{$name} ঘরটাই নেই।");

            $chunk = substr($html, $at, 4000);
            $end = strpos($chunk, '</select>');
            $this->assertNotFalse($end, "{$name} একটা select নয়।");

            $options = substr_count(substr($chunk, 0, $end), '<option');

            $this->assertGreaterThan(1, $options, implode("\n", [
                "{$name} ড্রপডাউনে কেবল {$options}টা বিকল্প — কার্যত খালি।",
                '',
                '⛔ কন্ট্রোলার তালিকাটা পাঠায়নি, আর কম্পোনেন্টের ডিফল্ট `[]` বলে',
                'কোনো ত্রুটিও আসেনি। ⓘ পর্দা দেখতে ঠিক, বাছাই করা যায় না।',
            ]));
        }
    }

    /**
     * পাঁচটা মাধ্যমই বাছা যায়, আর নামগুলো ব্যবস্থার নিজের।
     *
     * ⚠️ আজ কম্পোনেন্টে `online` লেখা হয়েছিল যেখানে ব্যবস্থার নাম
     * `transfer` — ⛔ ভ্যালিডেশন ওটা ফিরিয়ে দিত, আর ব্যবহারকারী বুঝতেন
     * না কেন ব্যাংক ট্রান্সফারের রসিদ কিছুতেই সেভ হচ্ছে না।
     */
    public function test_the_five_instruments_match_what_validation_accepts(): void
    {
        $html = (string) $this->get(route('accounts.voucher.create', ['type' => 'receipt']))
            ->assertOk()
            ->getContent();

        foreach (Voucher::INSTRUMENTS as $way) {
            $this->assertStringContainsString('value="'.$way.'"', $html,
                "মাধ্যম '{$way}' পর্দায় নেই, অথচ ভ্যালিডেশন সেটা মানে।");
        }

        $this->assertCount(5, Voucher::INSTRUMENTS);
    }

    /**
     * ⭐ পরিশোধে লেখাটা "প্রাপকের", রসিদে "প্রেরকের"।
     *
     * ⓘ একই কলাম, দুই অর্থ — আর অর্থটা আসে ভাউচারের ধরন থেকে। ⚠️ ভুল
     * হলে ঘরটা ভরা হত, কেবল ভুল মানুষের নম্বর দিয়ে, আর সেটা ধরা পড়ত
     * যেদিন কাউকে ফোন করতে হত।
     */
    public function test_the_phone_label_turns_around_for_a_payment(): void
    {
        $receipt = (string) $this->get(route('accounts.voucher.create', ['type' => 'receipt']))->getContent();
        $payment = (string) $this->get(route('accounts.voucher.create', ['type' => 'payment']))->getContent();

        $this->assertStringContainsString(__('accounts::field.sender_phone', [], 'bn'), $receipt);
        $this->assertStringContainsString(__('accounts::field.receiver_phone', [], 'bn'), $payment);
    }
}
