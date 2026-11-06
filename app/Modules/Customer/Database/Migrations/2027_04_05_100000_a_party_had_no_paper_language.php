<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ গ্রাহক আর সরবরাহকারীর "কাগজের ভাষা" — বাংলা · English · দুই ভাষা; ফাঁকা মানে কোম্পানির ভাষা (চেকলিস্ট ভা-১৬; মালিকের উত্তর ১,
 * ২৪ অক্টোবর ২০২৬: "migration approved"; [[PaperLanguage]])। ⓘ পুরনো সারি ফাঁকা থাকে — কাগজ আগের মতোই কোম্পানির ভাষায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'suppliers'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'paper_language')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->string('paper_language', 4)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['customers', 'suppliers'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'paper_language')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->dropColumn('paper_language');
                });
            }
        }
    }
};
