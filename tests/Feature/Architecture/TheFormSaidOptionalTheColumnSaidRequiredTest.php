<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ফর্ম বলল ঐচ্ছিক, ঘর বলল বাধ্যতামূলক।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * `MasterListController`-এর যাচাইয়ে `name_bn` সবসময়ই `nullable`। কিন্তু
 * পঁচিশটা টেবিলের **চারটায়** ঘরটা `NOT NULL` ছিল — পরে যোগ হওয়া
 * টেবিলগুলোয় `->nullable()` লিখতে ভুল হয়েছিল।
 *
 * ফল: ফর্ম ফাঁকা ঘরটা মেনে নিত, তারপর ইনসার্টে ৫০০। ২৫ আগস্ট ২০২৬-এ
 * মালিক লাইভে "bKash" যোগ করতে গিয়ে **পাঁচবার** এতে আটকেছেন — আসল
 * ব্যবসার প্রথম দিনে।
 *
 * ── কেন কোনো পরীক্ষা এটা ধরেনি ───────────────────────────────────────
 * পর্দার পরীক্ষাগুলো ফর্মটা **ভরে** পাঠায়, কারণ পরীক্ষা লেখার সময়
 * সব ঘর ভরাই স্বাভাবিক মনে হয়। ভুলটা ঘটে ঠিক তখন, যখন কেউ একটা
 * ঐচ্ছিক ঘর **খালি রাখেন** — আর ওই পথটা কেউ লেখেনি।
 *
 * তাই এই পরীক্ষাটা ফর্ম দিয়ে যায় না। সে **স্কিমা ও যাচাই মুখোমুখি
 * বসায়**: যাচাই যে ঘরটাকে ঐচ্ছিক বলে, ডাটাবেজও সেটাকে ঐচ্ছিক মানে তো?
 *
 * ── কেন একটা নাম ধরে তালিকা নয় ───────────────────────────────────────
 * টেবিলের তালিকা হাতে লিখলে পরের নতুন টেবিলটা বাদ পড়ত — ঠিক যেভাবে
 * `->nullable()` বাদ পড়েছিল। তাই তালিকাটা **ডাটাবেজ থেকেই** নেওয়া:
 * যে টেবিলেই `name_bn` আছে, সে-ই এই পরীক্ষার আওতায়।
 */
class TheFormSaidOptionalTheColumnSaidRequiredTest extends TestCase
{
    use RefreshDatabase;

    /**
     * যে ঘরগুলো ফর্মে ঐচ্ছিক, ডাটাবেজেও ঐচ্ছিক।
     *
     * ── কেন `name_bn` ─────────────────────────────────────────────────
     * এটাই সেই ঘর যা প্রতিটা মাস্টার তালিকায় আছে, ফর্মে ঐচ্ছিক, আর
     * বাস্তবে প্রায়ই খালি থাকে — "bKash"-এর বাংলা নাম "bKash"-ই।
     * নতুন কোনো ঘর একই আচরণ পেলে এখানে যোগ করা যায়।
     *
     * @var list<string>
     */
    private const OPTIONAL_IN_THE_FORM = ['name_bn'];

    public function test_a_column_the_form_calls_optional_accepts_nothing(): void
    {
        $strict = [];

        foreach ($this->tables() as $table) {
            foreach (self::OPTIONAL_IN_THE_FORM as $column) {
                /*
                 * নামটা সরাসরি বসানো, বাঁধা প্যারামিটার নয়।
                 *
                 * MySQL-এর `SHOW COLUMNS ... LIKE` প্রস্তুত করা বিবৃতিতে
                 * প্যারামিটার নেয় না — `?` সেখানে আক্ষরিক `?` হয়ে যায়।
                 * মানটা এই ক্লাসের একটা ধ্রুবক, ব্যবহারকারীর লেখা নয়।
                 */
                $said = DB::selectOne("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

                if ($said === null) {
                    continue;
                }

                if ($said->Null !== 'YES') {
                    $strict[] = "{$table}.{$column}";
                }
            }
        }

        $this->assertSame([], $strict, implode("\n", [
            'এই ঘরগুলো ফর্মে ঐচ্ছিক, অথচ ডাটাবেজে বাধ্যতামূলক:',
            ...$strict,
            '',
            'ফর্ম ফাঁকা ঘরটা মেনে নেবে, তারপর সংরক্ষণে ৫০০ — আর ব্যবহারকারী',
            'কেবল একটা ভাঙা পর্দা দেখবেন। মাইগ্রেশনে `->nullable()` বসান,',
            'নয়তো যাচাইয়ে `required` — কিন্তু দুইটা একই কথা বলতে হবে।',
        ]));
    }

    /**
     * পাহারাটা সত্যিই টেবিলগুলো দেখছে।
     *
     * `tables()` কোনোদিন খালি ফেরালে উপরের পরীক্ষাটা চিরকাল সবুজ থাকত।
     * এই প্রকল্পে ঠিক ওরকম পাহারা আগে বেশ কয়েকবার ধরা পড়েছে।
     */
    public function test_the_guard_actually_sees_the_tables(): void
    {
        $tables = $this->tables();

        $this->assertGreaterThan(15, count($tables), 'টেবিলগুলো খুঁজে পাওয়া যাচ্ছে না।');
        $this->assertContains('mdm_payment_methods', $tables);
    }

    /**
     * যাচাইয়ের নিয়মটা সত্যিই `nullable` বলে।
     *
     * ── কেন এটা আলাদা করে মাপা ────────────────────────────────────────
     * উপরের পরীক্ষাটা বলে "ঘরটা নাল নেয়"। কিন্তু কেউ যদি একদিন
     * যাচাইয়ে `required` বসিয়ে দেন, তবে ঘর আর ফর্ম আবার আলাদা কথা
     * বলবে — এবার উল্টো দিকে, আর উপরের পরীক্ষাটা তা টের পাবে না।
     *
     * দুইটা ঘোষণা মিলিয়ে দেখতে হলে **দুইটাই** পড়তে হয়।
     */
    public function test_the_validator_still_calls_it_optional(): void
    {
        $source = (string) file_get_contents(
            app_path('Modules/MasterData/Http/Controllers/MasterListController.php')
        );

        $this->assertMatchesRegularExpression(
            "/'name_bn'\s*=>\s*\['nullable'/",
            $source,
            'যাচাই আর `name_bn`-কে ঐচ্ছিক বলছে না — তাহলে ঘরগুলোও কড়া করতে হবে।',
        );
    }

    /**
     * `name_bn` আছে এমন প্রতিটা টেবিল।
     *
     * @return list<string>
     */
    private function tables(): array
    {
        $found = [];

        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];

            if (DB::selectOne("SHOW COLUMNS FROM `{$table}` LIKE 'name_bn'") !== null) {
                $found[] = $table;
            }
        }

        sort($found);

        return $found;
    }
}
