<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⛔ ফোনে অন্য শাখার গ্রাহক আর বকেয়া — মালিক, ৬ অক্টোবর ২০২৬: *"অ্যাপে অন্য ব্রাঞ্চের কাস্টমার আর কাস্টমার ব্যালেন্সও
 * হিসাবে ঢুকেছে"* ([[SyncService::sawAnotherView()]])।
 *
 * ওয়েবের হেডারে শাখা বদলালে ফোনের দেখাও বদলায় (একই মানুষ, একই সারি), অথচ জলচিহ্ন আগের দেখার — টানায় আসত কেবল নতুন
 * দেখার বদল, আগের শাখার সারি ফোনে রয়ে যেত। ⭐ এখন প্রতিটা জলচিহ্ন জানে কোন দেখায় লেখা; অমিল হলে গোড়া থেকে।
 * ⭐ আর আজকের মিশে থাকা ক্যাশ সারাতে সব যন্ত্রের জলচিহ্ন একবার মোছা — প্রতিটা ফোন পরের টানায় গোড়া থেকে পায়
 * (নতুন অ্যাপ তখন নিজের ক্যাশও মোছে)। ⓘ দাম একবার পুরো তালিকা নামা; কোনো সারি হারায় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sync_states', 'view_key')) {
            return;
        }

        Schema::table('sync_states', function (Blueprint $table): void {
            $table->string('view_key', 191)->nullable()->after('page_cursor');
        });

        DB::table('sync_states')->delete();
    }

    public function down(): void
    {
        if (Schema::hasColumn('sync_states', 'view_key')) {
            Schema::table('sync_states', fn (Blueprint $table) => $table->dropColumn('view_key'));
        }
    }
};
