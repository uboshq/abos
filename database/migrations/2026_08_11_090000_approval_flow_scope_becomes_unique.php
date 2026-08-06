<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * একই কাজে দুইটা ছক আর বসানো যাবে না।
 *
 * ── কেন এটা ভাঙা ছিল ────────────────────────────────────────────────
 * টেবিলটায় শুরু থেকেই একটা unique index ছিল — (company_id, module,
 * action, document_type)। কিন্তু "সব ধরনের ডকুমেন্টে" বোঝাতে
 * document_type-এ NULL বসত, আর MySQL-এ NULL কখনো আরেকটা NULL-এর সমান
 * নয়। ফলে একই মডিউলের একই কাজে যত খুশি ছক বসানো যেত, আর index-টা
 * কিছুই আটকাত না।
 *
 * আটকাত না বলে ক্ষতিটা নীরব: flowFor() প্রথমটা নিয়ে নিত, আর দ্বিতীয়
 * ছকটা পর্দায় থেকে যেত যেন সে-ও কাজ করছে। কেউ ছাড়ের সীমা ২০০০ করতে
 * নতুন একটা ছক বসালে পুরনো ১০০০-এর ছকটাই চলত, আর তিনি ভাবতেন সীমা
 * বেড়েছে।
 *
 * ── কেন NULL-এর বদলে খালি লেখা ──────────────────────────────────────
 * "সব ধরনে" কথাটা একটা আসল মান, অনুপস্থিতি নয়। খালি লেখায় বসালে
 * index-টা যা করার কথা ছিল তা-ই করে, আর কোনো বাড়তি পাহারা লাগে না।
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('approval_flows')->whereNull('document_type')->update(['document_type' => '']);

        Schema::table('approval_flows', function (Blueprint $table) {
            $table->string('document_type', 64)->nullable(false)->default('')->change();
        });
    }

    public function down(): void
    {
        Schema::table('approval_flows', function (Blueprint $table) {
            $table->string('document_type', 64)->nullable()->change();
        });

        DB::table('approval_flows')->where('document_type', '')->update(['document_type' => null]);
    }
};
