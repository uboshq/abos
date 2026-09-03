<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * ফর্ম "না" বললে সেটা যেন ব্যবহারকারীর ভাষায় হয়, ডাটাবেসের নয়।
 *
 * ── কোন ভুল থেকে এই ফাইলটা এসেছে ─────────────────────────────────────
 * ৩ সেপ্টেম্বর ২০২৬ পর্যন্ত `lang/bn/validation.php` **ছিলই না**। ফলে
 * Laravel তার নিজের ইংরেজি বার্তা দেখাত, আর ঘরের নামের জায়গায় বসাত
 * **ডাটাবেসের কলামের নাম**:
 *
 *     পর্দা পুরো বাংলা, লেবেল "নাম (ইংরেজি)"
 *     ঘর খালি রেখে সেভ  →  "The name en field is required."
 *
 * লাইভে মেপে দেখা গেছে **আটটা মাস্টার-ডাটা ফর্মেই এক অবস্থা**, আর
 * `reason-codes` বলত *"The context field is required"* — সেখানে
 * `context` শব্দটা পর্দার কোথাও লেখা নেই।
 *
 * ⚠️ **আর এটা আটটা ফর্মের নয়, অ্যাপের প্রতিটা ফর্মের সমস্যা ছিল।**
 * নতুন ব্যবসার প্রথম কাজই মাস্টার ডাটা বসানো, আর সবাই একবার না একবার
 * ঘর খালি রেখে সেভ চাপেন — **ক্রেতা প্রথম দিনেই বাংলা ব্যবস্থায় ইংরেজি
 * ধমক পেতেন, আর "name en" পড়ে বুঝতেই পারতেন না কোন ঘরটা।**
 *
 * ── কেন পরের কেউ আবার এটা ভাঙতে পারে ─────────────────────────────────
 * ফাইলটা এখন আছে, কিন্তু **`attributes` তালিকায় নাম না থাকলে Laravel
 * চুপচাপ কলামের নামেই ফিরে যায়** — কোনো ত্রুটি নয়, কোনো সতর্কতা নয়।
 * অর্থাৎ একটা নতুন ঘর যোগ করলেই ফাঁকটা ফিরে আসে, আর ধরা পড়ে কেবল
 * সেদিন যেদিন কেউ ওই ঘরটা খালি রেখে সেভ চাপেন।
 *
 * এই পরীক্ষাটা তাই বার্তা **তৈরি করে দেখে** — অনুবাদ ফাইলটা পড়ে নয়।
 */
class ARefusalSpeaksTheUsersLanguageTest extends TestCase
{
    /**
     * যে ঘরগুলো বহু ফর্মে ঘুরেফিরে আসে।
     *
     * এখানে নাম ধরে লেখা আছে ইচ্ছাকৃতভাবে: এগুলোই সেই ঘর যেগুলোর কলামের
     * নাম আর পর্দার নাম সবচেয়ে বেশি আলাদা, তাই ভুলটা এখানেই সবচেয়ে
     * বিভ্রান্তিকর। `context` আর `account_id` লাইভে সত্যিই ধরা পড়েছে।
     */
    private const SHARED_FIELDS = [
        'name_en', 'name_bn', 'code', 'context', 'account_id',
        'customer_id', 'product_id', 'warehouse_id', 'unit_id',
        'trx_date', 'qty', 'rate', 'amount', 'phone', 'identifier',
    ];

    public function test_no_refusal_shows_a_database_column_name(): void
    {
        app()->setLocale('bn');

        $raw = [];

        foreach (self::SHARED_FIELDS as $field) {
            $message = Validator::make([], [$field => 'required'])->errors()->first($field);

            /*
             * কলামের নাম চেনার উপায়: Laravel `name_en` থেকে বানায়
             * `name en` — আন্ডারস্কোরের বদলে ফাঁক, আর বাকিটা হুবহু।
             * ওই লেখাটা বার্তায় থাকা মানে অনুবাদটা পাওয়া যায়নি।
             */
            $columnish = str_replace('_', ' ', $field);

            if (str_contains($message, $columnish)) {
                $raw[] = "{$field}: \"{$message}\"";
            }
        }

        $this->assertSame([], $raw, implode("\n", array_merge(
            ['এই ঘরগুলোর ভুল-বার্তায় ডাটাবেসের কলামের নাম দেখা যাচ্ছে —',
                'ব্যবহারকারী পর্দায় এক নাম দেখেন, বার্তায় আরেকটা:'],
            $raw,
            ['',
                '`lang/bn/validation.php`-এর `attributes` তালিকায় সারি যোগ করুন',
                '(আর `lang/en/validation.php`-এও, নিয়ম ৯)।'],
        )));
    }

    /**
     * বার্তাটা বাংলায় তো?
     *
     * উপরেরটা কেবল ঘরের নাম দেখে। বার্তার শরীরটা ইংরেজি থেকে গেলে —
     * অর্থাৎ `lang/bn/validation.php` মুছে গেলে — সেটা ধরা পড়ত না।
     */
    public function test_the_refusal_itself_is_in_bangla(): void
    {
        app()->setLocale('bn');

        $english = [];

        foreach (['required', 'string', 'numeric', 'date', 'boolean', 'email'] as $rule) {
            $message = Validator::make(
                ['x' => $rule === 'required' ? null : []],
                ['x' => $rule === 'required' ? 'required' : $rule],
            )->errors()->first('x');

            /*
             * "The … field is …" — Laravel-এর ইংরেজি ছাঁচটার চিহ্ন।
             * বাংলা অনুবাদ থাকলে ওটা কখনো আসে না।
             */
            if (str_contains($message, ' field ') || str_starts_with($message, 'The ')) {
                $english[] = "{$rule}: \"{$message}\"";
            }
        }

        $this->assertSame([], $english, implode("\n", array_merge(
            ['এই নিয়মগুলোর বার্তা এখনো ইংরেজিতে আসছে:'],
            $english,
            ['', '`lang/bn/validation.php` আছে তো? নিয়মটা ওখানে লেখা আছে তো?'],
        )));
    }
}
