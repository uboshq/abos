<?php

declare(strict_types=1);

use App\Core\Security\LedgerChain;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * খাতা বদলে দেওয়া যেত, আর কেউ জানত না।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * খতিয়ান শুধু-যোগের — সারি সম্পাদনা হয় না, মোছা হয় না। **কিন্তু ওটা
 * অ্যাপের নিয়ম, ডাটাবেজের নয়।** একটা `UPDATE`, একটা সম্পাদনা করা
 * ব্যাকআপ, বা DBA-র এক মুহূর্ত — যেকোনোটাই একটা অঙ্ক বদলে দিতে পারত,
 * আর কোথাও কোনো চিহ্ন থাকত না।
 *
 * অর্থাৎ "আমাদের খাতা কেউ বদলায়নি" কথাটা **বলা যেত, প্রমাণ করা যেত
 * না** — আর নিরীক্ষায় ওই দুইটার পার্থক্যই সব।
 *
 * ── দুইটা ঘর ও একটা মাথা ─────────────────────────────────────────────
 * প্রতিটা সারিতে `prev_hash` ও `row_hash`; আর কোম্পানি প্রতি একটা
 * মাথার সারি, যেটা `lockForUpdate()`-এ ধরা হয় যাতে দুইটা একসাথে চলা
 * পোস্টিং চেইনটা দুই ভাগ করে না ফেলে ([[LedgerChain]])।
 *
 * ── পুরনো সারিগুলোও চেইনে টানা হয় ────────────────────────────────────
 * না টানলে আজকের আগের প্রতিটা সারি চিরকাল যাচাইয়ের বাইরে থাকত, আর
 * "চেইন ঠিক আছে" কথাটার মানে দাঁড়াত "আজকের পর থেকে ঠিক আছে" — যা
 * শুনতে একই, কিন্তু নয়।
 *
 * ⚠️ **এটা অতীতকে প্রমাণ করে না।** ব্যাকফিল আজকের সারিগুলো যেমন আছে
 * তেমন ধরেই চেইন বানায়; কেউ গতকাল কিছু বদলে থাকলে সেটা এখন "ঠিক"
 * হিসেবেই বাঁধা পড়বে। চেইনটা **আজ থেকে সামনের** পাহারা, আর সেটা
 * লিখে রাখা দরকার যাতে কেউ বেশি দাবি না করে।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ledger_entries')) {
            return;
        }

        if (! Schema::hasColumn('ledger_entries', 'row_hash')) {
            Schema::table('ledger_entries', function (Blueprint $t): void {
                $t->char('prev_hash', 64)->nullable()->after('created_by');
                $t->char('row_hash', 64)->nullable()->after('prev_hash');
            });
        }

        if (! Schema::hasTable('ledger_chain_heads')) {
            Schema::create('ledger_chain_heads', function (Blueprint $t): void {
                /*
                 * কোম্পানি আইডিই প্রাথমিক চাবি।
                 *
                 * প্রতি কোম্পানির ঠিক একটা মাথা, আর সেটাই এই টেবিলের
                 * পুরো চুক্তি। আলাদা `id` রাখলে একদিন দুইটা সারি বসত,
                 * আর তখন কোনটা আসল মাথা তা বলার উপায় থাকত না।
                 */
                $t->unsignedBigInteger('company_id')->primary();
                $t->char('last_hash', 64)->nullable();
                $t->unsignedBigInteger('entries')->default(0);
            });
        }

        // ── পুরনো সারিগুলো চেইনে ─────────────────────────────────────
        $companies = DB::table('ledger_entries')->distinct()->pluck('company_id');

        foreach ($companies as $companyId) {
            $previous = null;
            $count = 0;

            DB::table('ledger_entries')
                ->where('company_id', $companyId)
                ->orderBy('id')
                ->chunkById(500, function ($rows) use (&$previous, &$count): void {
                    foreach ($rows as $row) {
                        $hash = LedgerChain::hash($previous, (array) $row);

                        DB::table('ledger_entries')->where('id', $row->id)->update([
                            'prev_hash' => $previous,
                            'row_hash' => $hash,
                        ]);

                        $previous = $hash;
                        $count++;
                    }
                });

            DB::table('ledger_chain_heads')->updateOrInsert(
                ['company_id' => $companyId],
                ['last_hash' => $previous, 'entries' => $count],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_chain_heads');

        if (Schema::hasTable('ledger_entries') && Schema::hasColumn('ledger_entries', 'row_hash')) {
            Schema::table('ledger_entries', fn (Blueprint $t) => $t->dropColumn(['prev_hash', 'row_hash']));
        }
    }
};
