<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * দুইটা সূচকের নাম ঠিক ৬৪ অক্ষরে দাঁড়িয়ে ছিল।
 *
 * ── ⚠️ কেন এটা একটা সময়-বোমা ────────────────────────────────────────
 * MySQL-এ শনাক্তকারীর সীমা ৬৪, আর Laravel সূচকের নাম নিজে বানায় টেবিল ও
 * কলামের নাম জুড়ে। ⓘ ঠিক ৬৪-তে দাঁড়ানো নাম **আজ চলে** — কিন্তু ঐ
 * টেবিলে একটা কলাম যোগ করলেই নামটা বাড়ে, আর `migrate:fresh` ভাঙে
 * **সবার মেশিনে একসাথে**।
 *
 * ⚠️ ১৯ সেপ্টেম্বর ২০২৬-এ ঠিক এটাই ঘটেছিল, আর ভুলবার্তাটা দেখতে
 * মাইগ্রেশনের ভুলের মতো লাগত না — চারটা সেশন একসাথে আটকে গিয়েছিল।
 *
 * ── কী করা হচ্ছে ────────────────────────────────────────────────────
 * মূল মাইগ্রেশন দুইটায় এখন নিজের ছোট নাম বসানো, তাই **নতুন** ডেটাবেসে
 * ঠিক নামটাই তৈরি হয়। ⓘ কিন্তু চলতি ডেটাবেসে পুরনো লম্বা নামটা বসে
 * আছে — এই মাইগ্রেশন সেটাকেই বদলায়।
 *
 * ⛔ সারিটা `if` দিয়ে ঘেরা: নতুন ইনস্টলে পুরনো নামটা থাকেই না, আর তখন
 * `RENAME INDEX` ছুঁড়ে ফেলত।
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> টেবিল => [পুরনো, নতুন] */
    private const RENAMES = [
        'hr_salary_structures' => [
            'hr_salary_structures_company_id_employee_id_effective_from_index',
            'hr_salary_effective',
        ],
        'acc_bank_reconciliations' => [
            'acc_bank_reconciliations_company_id_bank_account_id_status_index',
            'acc_bank_recon_state',
        ],
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $table => [$old, $new]) {
            $this->rename($table, $old, $new);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $table => [$old, $new]) {
            $this->rename($table, $new, $old);
        }
    }

    private function rename(string $table, string $from, string $to): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        /*
         * ⓘ `information_schema` ধরে দেখা হয়, কারণ `Schema` শ্রেণিতে
         * "এই সূচকটা আছে কি না" জিজ্ঞেস করার সরাসরি পথ নেই।
         */
        $exists = DB::table('information_schema.statistics')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $from)
            ->exists();

        if (! $exists) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` RENAME INDEX `{$from}` TO `{$to}`");
    }
};
