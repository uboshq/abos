<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * প্রতিটা টেবিলে বাইরের জগতের কী — একটা UUID, নিজে থেকে বসে।
 *
 * তালিকাটা হাতে লেখা হয়নি, স্কিমা নিজেই পড়া হয়। হাতে লিখলে আজ ঠিক
 * থাকত, আর তিন মাস পর নতুন টেবিল যোগ হলে কেউ তালিকায় বসাতে ভুলে যেত —
 * আর ভোলা টেবিলটা কোনো ভুল দেখাত না, শুধু API-তে অদৃশ্য থেকে যেত।
 *
 * কোন টেবিল পাবে: ফ্রেমওয়ার্কের নিজস্ব টেবিল (cache, jobs, sessions,
 * migrations) ছাড়া সব — ওগুলো কখনো API-তে যায় না।
 *
 * প্রথমে শর্তটা ছিল "যাদের company_id আছে", কিন্তু তাতে সন্তান-টেবিল
 * বাদ পড়ত: approval_flow_steps, voucher_lines — এগুলোর নিজের
 * company_id নেই, বাবার আছে। অথচ একটা ভাউচারের লাইন API-তে যাবেই, আর
 * তখন তার বাইরের কী দরকার। তাই নিয়ম সহজ করা হলো: সব টেবিল।
 *
 * ব্যাকফিল চাঙ্কে। এক আপডেটে পুরো টেবিল লিখতে গেলে বড় টেবিলে লক ধরে
 * থাকত, আর ওই সময়টাতে কেউ বিল কাটতে পারত না।
 */
return new class extends Migration
{
    /**
     * ফ্রেমওয়ার্ক ও প্যাকেজের নিজস্ব টেবিল — বাইরের কী লাগে না।
     *
     * @var list<string>
     */
    private const SKIP = [
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches',
        'failed_jobs', 'sessions', 'password_reset_tokens',
        'model_has_permissions', 'model_has_roles', 'role_has_permissions',
    ];

    public function up(): void
    {
        foreach ($this->tables() as $table) {
            if (Schema::hasColumn($table, 'public_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t): void {
                $t->publicId();
            });

            $this->backfill($table);
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $table) {
            if (! Schema::hasColumn($table, 'public_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t): void {
                $t->dropUnique(['public_id']);
                $t->dropColumn('public_id');
            });
        }
    }

    /**
     * কোন টেবিলগুলো বাইরের কী পাবে।
     *
     * @return list<string>
     */
    private function tables(): array
    {
        $tables = [];

        foreach (Schema::getTableListing() as $name) {
            // কিছু ড্রাইভারে নামের সাথে স্কিমা আসে (public.customers)
            $name = str_contains($name, '.') ? substr(strrchr($name, '.'), 1) : $name;

            if (in_array($name, self::SKIP, true)) {
                continue;
            }

            $tables[] = $name;
        }

        return $tables;
    }

    private function backfill(string $table): void
    {
        // প্রাইমারি কী না থাকলে chunkById চলে না; ওই টেবিলগুলোয় (pivot)
        // ব্যাকফিল এড়িয়ে যাওয়া হয় — নতুন সারি trait থেকেই কী পাবে
        if (! Schema::hasColumn($table, 'id')) {
            return;
        }

        DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use ($table): void {
            foreach ($rows as $row) {
                DB::table($table)
                    ->where('id', $row->id)
                    ->update(['public_id' => (string) Str::uuid7()]);
            }
        });
    }
};
