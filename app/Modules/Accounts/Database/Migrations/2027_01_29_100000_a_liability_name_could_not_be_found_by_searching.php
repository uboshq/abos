<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * একটা দায়ের খাতের নাম খুঁজে পাওয়া যেত না — "প্রদেয় মুনাফা" (২১৯০)।
 *
 * ── ⛔ কী ভাঙা ছিল, ২৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * [[StandardChart]]-এ নামটা লেখা ছিল একক-অক্ষরের য় (U+09DF) দিয়ে, যা NFC
 * রূপ নয় — NFC-তে য় হয় য + ় (U+09AF U+09BC)। ⚠️ খোঁজার ঘরে যা টাইপ হয়
 * সেটা NFC-তে বদলে যায় ([[NormalizeUnicodeInput]]), তাই "প্রদেয়" লিখে
 * খুঁজলে এই খাত কোনোদিন মিলত না — আর কেন মিলছে না, কেউ বলতে পারত না।
 *
 * ⓘ উৎসটা (StandardChart) একই কমিটে ঠিক করা হয়েছে; এই মাইগ্রেশন ঠিক করে
 * যেসব কোম্পানিতে ভাঙা নামটা আগেই বসে গেছে। ⭐ সব খাত একবারে দেখা হয়,
 * কেবল ২১৯০ নয় — আর কোনো নাম একই রোগে ভুগলে সেটাও সারে।
 *
 * ⚠️ `DB::table` দিয়ে, মডেল দিয়ে নয়: নাম বদলের একটা নিরীক্ষা-সারি এখানে
 * মিথ্যা কথা বলত — কেউ নাম বদলায়নি, কেবল অক্ষরের রূপ বদলেছে। ⓘ আর
 * audit_trails-এর পুরনো সারি ইতিহাস, ওগুলো ছোঁয়া হয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        // ext-intl না থাকলে সারানোর উপায় নেই — চুপচাপ পাশ কাটানো, অ্যাপ বন্ধ নয়
        if (! class_exists(Normalizer::class)) {
            return;
        }

        DB::table('accounts')
            ->select(['id', 'name_en', 'name_bn'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $changes = [];

                    foreach (['name_en', 'name_bn'] as $column) {
                        $value = $row->{$column};

                        if (is_string($value) && ! Normalizer::isNormalized($value, Normalizer::FORM_C)) {
                            $changes[$column] = Normalizer::normalize($value, Normalizer::FORM_C);
                        }
                    }

                    if ($changes !== []) {
                        DB::table('accounts')->where('id', $row->id)->update($changes);
                    }
                }
            });
    }

    /** ⓘ ফেরার কিছু নেই — NFC-ই ঠিক রূপ, ভাঙা রূপে ফেরানো মানে বাগটা ফিরিয়ে আনা। */
    public function down(): void {}
};
