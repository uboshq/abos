<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ব্যাংকের খাত জানত না সে কোন ব্যাংকের।
 *
 * ── কী ছিল ──────────────────────────────────────────────────────────
 * হিসাবের ছকে "IBBL Motijheel CD", "Islami Bank STD" — নামেই ব্যাংকটা,
 * আর কোথাও নয়। ⛔ তাই "ইসলামী ব্যাংকে আজ আমাদের মোট কত — চলতি হিসাবে,
 * FDR-এ, আর ঋণে" প্রশ্নের উত্তর পেতে তিনটা পর্দা ঘুরে হাতে যোগ করতে হত।
 *
 * ── কেন জোড়াটা অর্থে, খাতের সারিতে নয় ────────────────────────────
 * ⚠️ `accounts` টেবিলে `institution_id` বসালে হিসাব মডিউল অর্থের উপর
 * নির্ভর করত — আর হিসাব বাকি সবার নিচে দাঁড়ায়, কারও উপর নয়
 * ([[Tests\Feature\Architecture\BoundariesTest]])। তাই জোড়াটা অর্থের
 * নিজের টেবিলে; অর্থ বন্ধ থাকলে ছকের একটা ঘরও বদলায় না।
 *
 * ⓘ `account_id` ইউনিক: একটা খাত একটাই ব্যাংকের। একটা ব্যাংকের
 * অনেক খাত থাকতে পারে (চলতি, সঞ্চয়ী, শাখাভেদে)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_institution_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('institution_id')->constrained('fin_institutions')->cascadeOnDelete();
            $table->foreignId('account_id')->unique()->constrained('accounts')->cascadeOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'institution_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_institution_accounts');
    }
};
