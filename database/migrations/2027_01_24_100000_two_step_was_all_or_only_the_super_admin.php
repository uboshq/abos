<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * দুই ধাপ ছিল হয় কারও জন্য নয়, নয় কেবল সুপার অ্যাডমিনের জন্য।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"ব্যবহারকারী লিস্টে ২ স্টেপের অন-অফ বোতাম দাও।"*
 *
 * ── ⓘ কেন একটা কলাম, একটা অনুমতি নয় ─────────────────────────────────
 * ⚠️ এটা "কে কী করতে পারে" নয় — এটা "এই অ্যাকাউন্টে দ্বিতীয় তালা
 * লাগবে কি না"। ⛔ অনুমতি দিয়ে করলে ভূমিকা-ছকে বসত, আর তখন একজনের
 * জন্য চালু করা মানে ঐ ভূমিকার সবার জন্য চালু — অথচ মালিক
 * **ব্যবহারকারী ধরে** চেয়েছেন।
 *
 * ── ⚠️ ডিফল্ট `false`, আর সেটা ইচ্ছাকৃত ──────────────────────────────
 * ⛔ `true` দিলে এই মাইগ্রেশনটাই প্রতিটা কর্মীকে বসানোর পর্দায় পাঠাত —
 * গুদামের যাঁর ফোনে অ্যাপ নেই, তিনি কাজই করতে পারতেন না, আর ব্যবসাটা
 * এক ডিপ্লয়ে থেমে যেত।
 *
 * ⓘ সুপার অ্যাডমিনের তালাটা এই কলামের উপর নির্ভর করে না — ওটা আগের
 * মতোই ভূমিকা ধরে খাটে ([[SuperAdminMustHaveTwoSteps]])। ⭐ এই কলামটা
 * কেবল **যোগ** করে: বাকি যে কারও জন্যও চালু করা যায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('two_step_required')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_step_required');
        });
    }
};
