<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ⭐ সময়-বাঁধা সদস্যপদ — ছুটির বদলি, নিরীক্ষার এক সপ্তাহ (মালিকের অনুমোদিত রোলের পাতার নমুনা, ২০ নভেম্বর ২০২৬ "ok")।
 *
 * ⛔ আগে একজনকে কয়েক দিনের জন্য রোল দিলে সেটা চিরকাল থাকত — কারও মনে রাখতে হত তুলে নিতে, আর কেউ মনে রাখত না।
 * ⓘ একটা সারি = এক মানুষের এক রোলের মেয়াদ (এক কোম্পানিতে): থেকে · পর্যন্ত (খালি = স্থায়ী)। রোলটা নিজে spatie-র
 *   `model_has_roles`-এই থাকে — এই টেবিল কেবল মেয়াদ বলে, তাই রোল বসানোর বাকি পথ (ব্যবহারকারীর পাতা, `syncRoles`) বদলায় না।
 * ⓘ অবস্থা: `pending` (শুরুর দিন আসেনি — রোল এখনো বসেনি) · `active` · `ended` (মেয়াদ শেষে নিজেই তোলা)। সারি মোছে না —
 *   কে কবে পর্যন্ত ছিলেন, পরে দেখা যায়। ⓘ শেষের আগের দিন মালিক আর ঐ মানুষ খবর পান (`warned_for` = কোন শেষ দিনের খবর গেছে)।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sys_role_terms')) {
            return;
        }

        Schema::create('sys_role_terms', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('note', 200)->nullable();
            $table->string('state', 12)->default('active');   // pending · active · ended
            $table->date('warned_for')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'user_id', 'role_id'], 'sys_role_terms_once');
            $table->index(['company_id', 'state', 'valid_until'], 'sys_role_terms_due');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_role_terms');
    }
};
