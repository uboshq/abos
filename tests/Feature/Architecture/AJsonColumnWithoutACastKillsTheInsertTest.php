<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

/**
 * JSON কলামে cast নেই, আর ইনসার্টটা মরে যায়।
 *
 * ── ⛔ যেদিন ধরা পড়ল, ১৮ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * মালিক পুঁজির খাতা থেকে *"টাকা নিন → রসিদ ভাউচার"* চেপে নোট গুনে ফর্মটা
 * জমা দিয়েছেন, আর পর্দা ভেঙে গেছে:
 *
 *     QueryException — Array to string conversion
 *
 * ⓘ `vouchers.note_counts` কলামটা MySQL-এ `json`, আর পর্দায় সে দশটা ঘর
 * (`note_counts[1000]`, `note_counts[500]` …) — অর্থাৎ একটা **অ্যারে**।
 * ⚠️ কিন্তু মডেলের `casts()`-এ ওর নাম ছিল না, তাই PDO অ্যারেটাকেই সরাসরি
 * bind করতে গিয়ে মারা গেছে।
 *
 * ── ⚠️ কেন তিন-তিনটা পাহারা সবুজ থেকেও কিছু ধরেনি ────────────────────
 * একটা মাপত `$fillable`, একটা মাপত পর্দার ঘর, একটা মাপত কলাম। ⛔ তিনটাই
 * সবুজ ছিল, কারণ তিনটা অংশই **সত্যিই ছিল** — কেবল জোড়াটা ছিল না।
 *
 * ⓘ এটাই এই প্রকল্পের সবচেয়ে চেনা ফাঁদ: *ঘর আছে, নাম আছে, কিছুই জোড়া
 * লাগেনি, আর কিছুই ভাঙে না* — যতক্ষণ না একজন আসল মানুষ নোট গুনছেন।
 *
 * ── ⭐ তাই দাবিটা জোড়ার উপর ──────────────────────────────────────────
 * মাইগ্রেশনে যে কলামটা `json`, আর মডেলে যেটা `$fillable` — সেটার
 * `casts()`-এ **অবশ্যই** নাম থাকতে হবে। ⚠️ `$fillable` শর্তটা জরুরি:
 * যে কলাম কেউ ভরতে পারে না, সে কখনো অ্যারে পায়ও না।
 */
final class AJsonColumnWithoutACastKillsTheInsertTest extends TestCase
{
    /**
     * ⓘ যেগুলো অ্যারে/অবজেক্টকে JSON বানিয়ে দেয়।
     *
     * ⚠️ `AsCollection`, `AsArrayObject`, কোনো কাস্টম `Castable` — সবই
     * চলে, তাই নামটা মেলানো হয় না, কেবল **থাকা** মাপা হয়।
     */
    public function test_every_fillable_json_column_is_cast(): void
    {
        $jsonColumns = $this->jsonColumnsByTable();

        $this->assertNotEmpty($jsonColumns,
            'একটাও json কলাম পাওয়া যায়নি — পাহারাটা অন্ধ হয়ে গেছে।');

        $models = $this->models();

        /*
         * ⛔ এই গোনাটা না থাকায় পাহারার **অর্ধেক** অন্ধ ছিল —
         * ২২ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ মাইগ্রেশনের দিকটা উপরের `assertNotEmpty` দিয়ে রক্ষিত
         * ছিল। ⚠️ কিন্তু মডেলের দিকটা নয়: [[models()]] পথে `/Models/`
         * আছে কি না দেখে, আর শর্তটা মিলা বন্ধ হলে তালিকা খালি
         * হয়, `$missing` খালি থাকে, আর দাবিটা পাস করে।
         *
         * ⭐ মেপে দেখা: `'/Models/'` বদলে অন্য একটা নাম বসালে
         * পাহারাটা **সবুজ থেকেছে**, দুইটা দাবি সহ।
         *
         * ⚠️ ফোল্ডারের নামে বিশ্বাসটা কাল্পনিক বিপদ নয়: আজ ১৫৫টা
         * মডেলই `Models/`-এ বসে, কিন্তু কোনোদিন একটা অন্য ফোল্ডারে
         * গেলে সে নীরবে বাদ পড়বে — আর গোটা তালিকা মরলে এই গোনাটাই
         * একমাত্র চিহ্ন।
         *
         * ⓘ মেঝে ১০০, আসল সংখ্যা ১৫৫ — রিফ্যাক্টরে কয়টা মডেল
         * কমতে পারে বলে হুবহু বসানো হয়নি।
         */
        $this->assertGreaterThan(100, count($models), implode("\n", [
            '⛔ পাহারাটা মাত্র '.count($models).'টা মডেল পেয়েছে।',
            '',
            'ⓘ [[models()]] পথে `/Models/` খোঁজে — কেউ কি মডেল সরিয়েছেন,',
            '   নাকি নামের নিয়ম বদলেছে?',
            '',
            '⚠️ এটা না ধরলে নিচের দাবিটা একটাও মডেল না দেখেই সবুজ থাকত।',
        ]));

        $missing = [];

        foreach ($models as $class) {
            $model = new $class;
            $columns = $jsonColumns[$model->getTable()] ?? [];

            if ($columns === []) {
                continue;
            }

            $fillable = $model->getFillable();
            $casts = $model->getCasts();

            foreach ($columns as $column) {
                if (in_array($column, $fillable, true) && ! array_key_exists($column, $casts)) {
                    $missing[] = $model->getTable().'.'.$column.'  →  '.$class;
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'এই JSON কলামগুলো ভরা যায়, কিন্তু cast নেই।',
            'অ্যারে বসালেই ইনসার্ট মরবে: "Array to string conversion"।',
            '',
            ...$missing,
            '',
            "সমাধান: মডেলের casts()-এ লিখুন — 'কলাম' => 'array',",
        ]));
    }

    /**
     * মাইগ্রেশন পড়ে: কোন টেবিলের কোন কলাম JSON।
     *
     * ⚠️ স্কিমা থেকে পড়া হয় না ইচ্ছে করেই — তাহলে পাহারাটা একটা
     * মাইগ্রেট-করা ডেটাবেসের উপর নির্ভর করত, আর `RefreshDatabase`
     * ছাড়া চুপচাপ খালি ফল দিত।
     *
     * @return array<string, list<string>>
     */
    private function jsonColumnsByTable(): array
    {
        $out = [];

        foreach (File::allFiles(base_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRealPath());

            if (! str_contains($path, '/Migrations/') && ! str_contains($path, '/migrations/')) {
                continue;
            }

            if (str_contains($path, '/vendor/')) {
                continue;
            }

            $table = null;

            /*
             * ⓘ ফাইলটা উপর থেকে নিচে পড়া হয়: `Schema::create('x')` বা
             * `Schema::table('x')` চলতি টেবিলের নাম ঠিক করে দেয়, আর
             * তার পরের `->json('y')` ঐ টেবিলেরই ঘর।
             */
            $pattern = "/Schema::(?:create|table)\(\s*'(\w+)'|->json(?:b)?\(\s*'(\w+)'\s*\)/";

            preg_match_all($pattern, (string) file_get_contents($path), $hits, PREG_SET_ORDER);

            foreach ($hits as $hit) {
                if (($hit[1] ?? '') !== '') {
                    $table = $hit[1];
                } elseif ($table !== null && ($hit[2] ?? '') !== '') {
                    $out[$table][] = $hit[2];
                }
            }
        }

        return array_map(static fn (array $c) => array_values(array_unique($c)), $out);
    }

    /**
     * @return list<class-string<Model>>
     */
    private function models(): array
    {
        $out = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRealPath());

            if (! str_contains($path, '/Models/')) {
                continue;
            }

            /*
             * ⓘ `app/Modules/Accounts/Models/Voucher.php` →
             * `App\Modules\Accounts\Models\Voucher` — প্রকল্পের
             * প্রতিটা মডেলই এই নিয়মে বসে।
             */
            $class = 'App'.str_replace(
                '/', '\\',
                substr($path, strpos($path, '/app/') + 4, -4),
            );

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $out[] = $class;
        }

        return $out;
    }
}
