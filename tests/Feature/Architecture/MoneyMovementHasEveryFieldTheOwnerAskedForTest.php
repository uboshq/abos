<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Modules\Accounts\Models\CashCount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * মালিক আঠারোটা ঘর চেয়েছিলেন, ফর্ম ছয়টা জানত।
 *
 * ── ⭐ কেন এই পরীক্ষাটা গুনে দেখে ────────────────────────────────────
 * ১৪ সেপ্টেম্বর ২০২৬-এ মালিক রসিদ ও পরিশোধের ঘরগুলো লিখে দিয়ে বললেন:
 *
 *     "স্যাম্পলের সাথে ১০০% মিল থাকতে হবে — একদম ১০০%, ৯৯.৯৯% হলেও হবে না।"
 *
 * ⓘ ঐ শর্তটা চোখে দেখে রক্ষা করা যায় না। বারোটা ঘরের একটা বাদ পড়লে
 * পর্দাটা **দিব্যি কাজ করবে** — কেবল প্রশ্নটা করা হবে না, আর উত্তরটা
 * কোথাও থাকবে না।
 *
 * ⚠️ তাই দাবিটা সংখ্যার: আঠারোটা ঘর, আর প্রতিটার **তিনটা অংশ** থাকতে হবে —
 * ডাটাবেজের কলাম, পর্দার ঘর, আর দুই ভাষার লেখা। ⛔ যেকোনো একটা না থাকলে
 * জিনিসটা অর্ধেক, আর অর্ধেক মানে একদিন নীরবে হারিয়ে যাওয়া।
 *
 * ── ⓘ শুরুর সংখ্যাটা লিখে রাখা ──────────────────────────────────────
 * কাজ শুরুর দিন মিল ছিল **৩৩.৩%** (১৮টার ৬টা)। সংখ্যাটা এখানে লেখা
 * থাকল যাতে "হয়ে গেছে" দাবিটা প্রমাণযোগ্য থাকে।
 */
final class MoneyMovementHasEveryFieldTheOwnerAskedForTest extends TestCase
{
    /*
     * ⛔ এই ট্রেইটটা প্রথমে ছিল না, আর তার ফলটা শেখার মতো।
     *
     * স্কিমা ছাড়া `Schema::hasColumn()` প্রতিটা ঘরের জন্য **false**
     * ফেরায় — কোনো ত্রুটি নয়, কেবল "নেই"। ⓘ ফল: আঠারোটার আঠারোটাই
     * অনুপস্থিত দেখাল, যার মধ্যে `instrument_no` আর `from_bank`
     * মাসখানেক ধরে বসানো।
     *
     * ⚠️ দাবিটা ঠিক ছিল, মাপার যন্ত্রটা ভুল। ⭐ ধরা পড়েছে সময় দেখে —
     * ১.০৭ সেকেন্ড, আর ১৫৯টা টেবিল বসাতে তার চেয়ে ঢের বেশি লাগে।
     */
    use RefreshDatabase;

    /**
     * মালিকের তালিকা — ঘর => [কলাম আছে কি না দেখতে হবে?, অনুবাদের চাবি]
     *
     * ⚠️ `note_counts` কলাম ধরে যায় না — ওটা একটা JSON, আর পর্দায় দশটা
     * আলাদা ঘর (`note_counts[1000]` …)। তাই তার নিজের দাবি আলাদা।
     *
     * @var array<string, string>
     */
    private const FIELDS = [
        'carried_by' => 'accounts::field.carried_by',
        'moved_at' => 'accounts::field.moved_at',
        'note_counts' => 'accounts::field.note_breakdown',
        'wallet' => 'accounts::field.wallet',
        'wallet_medium' => 'accounts::field.wallet_medium',
        'counterparty_phone' => 'accounts::field.sender_phone',
        'instrument_no' => 'accounts::field.transaction_id',
        'instrument_date' => 'accounts::field.cheque_date',
        'charge_amount' => 'accounts::field.charge',
        'charge_borne_by' => 'accounts::field.charge_borne_by',
        'transfer_mode_id' => 'accounts::field.transfer_mode',
        'from_bank' => 'accounts::field.from_bank',
        'from_branch' => 'accounts::field.branch',
        'from_account_name' => 'accounts::field.account_holder',
        'from_account_no' => 'accounts::field.account_no',
        'deposit_slip_no' => 'accounts::field.deposit_slip',
        'lands_on' => 'accounts::field.lands_on',
        'instrument' => 'accounts::field.how_it_moved',
    ];

    /**
     * ⛔ আঠারোটার প্রতিটার একটা করে কলাম আছে।
     */
    public function test_every_field_the_owner_asked_for_has_a_column(): void
    {
        $this->assertCount(18, self::FIELDS,
            'তালিকাটাই বদলে গেছে — মালিকের নকশায় আঠারোটা ঘর।');

        $missing = [];

        foreach (array_keys(self::FIELDS) as $column) {
            if (! Schema::hasColumn('vouchers', $column)) {
                $missing[] = $column;
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'এই ঘরগুলোর কলাম নেই:',
            '',
            '⛔ কলাম ছাড়া পর্দার ঘরটা থাকতে পারে, ভরাও যায় — আর জমা দিলে',
            'মানটা নীরবে হারিয়ে যায়। কোনো ত্রুটি নয়।',
            '',
            ...$missing,
        ]));
    }

    /**
     * ⭐ প্রতিটা ঘর সত্যিই পর্দায় আছে — কম্পোনেন্টে।
     *
     * ⓘ কলাম থাকা আর ঘর থাকা দুইটা আলাদা সত্য। ⚠️ আজ একাধিকবার দেখা
     * গেছে কলামটা এক মাস ধরে বসানো অথচ কোনো ফর্মে ঘরটা নেই —
     * `mdm_transfer_modes` ঠিক সেটাই ছিল।
     */
    public function test_every_field_is_actually_on_the_screen(): void
    {
        $blades = collect([
            'money-movement',
            'charge-bearer',
        ])->map(fn (string $f) => File::get(resource_path("views/components/ui/{$f}.blade.php")))
            ->implode("\n");

        $missing = [];

        foreach (array_keys(self::FIELDS) as $field) {
            // note_counts[1000] আকারে বসে, তাই নামটা দিয়ে খোঁজা
            if (! str_contains($blades, "name=\"{$field}\"") && ! str_contains($blades, "\"{$field}[")) {
                $missing[] = $field;
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'এই ঘরগুলো কম্পোনেন্টে নেই:',
            '',
            '⚠️ কলামটা থাকলেও প্রশ্নটা কেউ করবে না, আর উত্তরটা কোথাও থাকবে না।',
            '',
            ...$missing,
        ]));
    }

    /**
     * দুই ভাষাতেই লেখা আছে।
     *
     * ⛔ একটা চাবি অনুপস্থিত থাকলে `__()` চাবিটাই ছাপায় — পর্দায়
     * `accounts::field.branch` লেখা দেখা যায়। ⓘ পাতাটা ২০০ দেয়,
     * কিছুই ভাঙে না, আর দেখতে হাস্যকর।
     */
    public function test_every_field_has_words_in_both_languages(): void
    {
        $missing = [];

        foreach (self::FIELDS as $field => $key) {
            foreach (['bn', 'en'] as $lang) {
                if (! trans()->hasForLocale($key, $lang)) {
                    $missing[] = "{$lang}: {$key}  ({$field})";
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'এই চাবিগুলোর লেখা নেই:',
            '',
            ...$missing,
        ]));
    }

    /**
     * ⭐ নোট গোনার দশটা ঘরই আছে, আর তালিকাটা একটাই।
     *
     * ⚠️ দুই জায়গায় দুই তালিকা হলে একদিন একটায় দুই টাকার নোট থাকত,
     * অন্যটায় না — আর গণনা দুইটা আলাদা যোগফল দিত।
     */
    public function test_the_note_list_is_the_same_one_the_cash_count_uses(): void
    {
        $blade = File::get(resource_path('views/components/ui/money-movement.blade.php'));

        $this->assertStringContainsString('CashCount::DENOMINATIONS', $blade,
            'নোটের তালিকা হাতে লেখা হয়েছে — CashCount-এর তালিকাটাই ব্যবহার করতে হবে।');

        $this->assertCount(10, CashCount::DENOMINATIONS,
            'নোটের সংখ্যা বদলেছে — নকশায় দশটা।');
    }
}
