<?php

declare(strict_types=1);

use App\Modules\MasterData\Models\ReasonCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * কারণটা নিজে বলতে পারত না যে তার সাথে আর কী লাগে।
 *
 * ── ⭐ NEXUS §২৪ — বিক্রয় ফেরতের কারণ ────────────────────────────────
 * "অন্যান্য" বাছলে দুই লাইন লেখা লাগবে — নাহলে ঐ একটা সারিই সবচেয়ে বড়
 * হয়ে দাঁড়ায়, আর "কেন ফেরত আসে" প্রশ্নের উত্তর আবার হারায়।
 * "মেয়াদোত্তীর্ণ" বাছলে লট লাগবে — কোন লটের মেয়াদ গেল সেটা না জানলে
 * সরবরাহকারীর কাছে দাবিও করা যায় না, রিকলও হয় না।
 *
 * ── ⓘ কেন কোড ধরে নয়, ঘর ধরে ────────────────────────────────────────
 * ABOS অনেক ব্যবসায় বিক্রি হয়, আর প্রতিটা কোম্পানি নিজের কারণ বানায়।
 * `code === 'OTHER'` লিখে রাখলে কেউ নিজের "বিবিধ" বানালে নিয়মটা ওখানে
 * খাটত না। ঘর হলে কোম্পানি নিজেই টিক দেয়।
 *
 * ── ⚠️ পুরনো EXPIRED সারি ─────────────────────────────────────────────
 * সিঙ্ক ([[SyncReasonCodes]]) বসানো সারি ছোঁয় না, তাই চলমান কোম্পানির
 * EXPIRED-এ টিকটা এখানেই বসে — নাহলে নিয়মটা কেবল নতুন কোম্পানিতে খাটত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdm_reason_codes', function (Blueprint $table): void {
            $table->boolean('needs_note')->default(false)->after('needs_approval');
            $table->boolean('needs_lot')->default(false)->after('needs_note');
        });

        DB::table('mdm_reason_codes')
            ->where('context', ReasonCode::SALES_RETURN)
            ->where('code', 'EXPIRED')
            ->update(['needs_lot' => true]);
    }

    public function down(): void
    {
        Schema::table('mdm_reason_codes', function (Blueprint $table): void {
            $table->dropColumn(['needs_note', 'needs_lot']);
        });
    }
};
