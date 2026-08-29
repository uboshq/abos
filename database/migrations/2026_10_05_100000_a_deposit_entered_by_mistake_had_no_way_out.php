<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ভুল করে বসানো একটা জমা থেকে বেরোনোর পথ ছিল না।
 *
 * ── কী ভাঙা ছিল, ২৯ আগস্ট ২০২৬ ───────────────────────────────────────
 * জমার পর্দাটা লাইভে যাচাই করতে গিয়ে একটা সত্যিকারের FD খুলতে হলো, আর
 * তারপর দেখা গেল **ওটা তোলার কোনো উপায় নেই**। দুইটা পথই ভুল:
 *
 *   • "ভাঙুন" একটা **ব্যবসায়িক ঘটনা** — ব্যাংক টাকা ফেরত দিয়েছে।
 *     ওটা চাপলে দ্বিতীয় একটা ভাউচার বসত, অর্থাৎ ভুলের সংখ্যা এক থেকে
 *     দুই হত।
 *   • সরাসরি সারিটা মুছে দিলে খাতার দাখিলাটা রয়ে যেত, আর "১১৬০ খাতে
 *     ২ লাখ কোথা থেকে" প্রশ্নের কোনো উত্তর থাকত না।
 *
 * ── কেন মুছে ফেলা নয়, বাতিল ──────────────────────────────────────────
 * নিয়ম ৫: বাতিল মানে **উল্টো দাখিলা, আর সারিটা থেকে যায়**
 * ([[DocumentStatus::CANCELLED]])। মুছে ফেললে অডিটে প্রশ্নটা থেকে যেত
 * অথচ উত্তরটা থাকত না — আর ঠিক সেই কারণেই এই ব্যবস্থায় কিছুই সত্যিকারের
 * অর্থে মোছা হয় না।
 *
 * ── কেন "কেন" ঘরটা বাধ্যতামূলক ───────────────────────────────────────
 * ছয় মাস পর কেউ বাতিল সারিটা দেখে জানতে চাইবেন কী হয়েছিল। কারণ না
 * থাকলে উত্তরটা কারও মাথায় থাকে, কাগজে নয় — আর ততদিনে তিনি হয়তো আর
 * এখানে কাজও করেন না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_deposits', function (Blueprint $table) {
            $table->text('cancel_reason')->nullable()->after('note');
            $table->timestamp('cancelled_at')->nullable()->after('cancel_reason');

            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fin_deposits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancel_reason', 'cancelled_at']);
        });
    }
};
