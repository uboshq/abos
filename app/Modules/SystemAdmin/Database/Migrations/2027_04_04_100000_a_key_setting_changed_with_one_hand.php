<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ জরুরি সেটিংয়ের বদল দ্বিতীয় হাতের অপেক্ষায় — কন্ট্রোল প্যানেলের পরিকল্পনা, দফা ৭ ("অনুমোদন, বাকির সীমা আর বেতনের সেটিং
 * বদলালে দ্বিতীয় সই লাগে — আপনার আগের সিদ্ধান্ত"); মালিক, ৭ নভেম্বর ২০২৬: "baki kaj gulo kore rako"।
 *
 * ⓘ একটা সারি = একটা চাওয়া বদল: কোন সেটিং, আগে কী ছিল, কী হবে, কে চাইলেন; অন্য কেউ অনুমোদন দিলে তবেই সেটিংয়ে বসে
 * ([[SignedSettings]])। ⛔ মুছে ফেলা হয় না — অবস্থা বদলায় (pending · signed · rejected · superseded), নিরীক্ষায় থাকে।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sys_setting_changes')) {
            return;
        }

        Schema::create('sys_setting_changes', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('key', 120);
            $table->text('old_value')->nullable();
            $table->text('new_value');
            $table->string('status', 16)->default('pending');   // pending · signed · rejected · superseded
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'sys_setting_changes_queue');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_setting_changes');
    }
};
