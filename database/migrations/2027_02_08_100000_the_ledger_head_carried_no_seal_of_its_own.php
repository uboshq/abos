<?php

declare(strict_types=1);

use App\Core\Security\LedgerChain;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * খাতার মাথার নিজের সিল — অডিট গ৮, ৪ অক্টোবর ২০২৬ ([[LedgerChain::headSeal()]])।
 *
 * ⛔ মাথা (শেষ ছাপ আর সারির সংখ্যা) সিল ছাড়া ছিল: শেষের সারি মুছে মাথা মিলিয়ে দিলে, বা মাথাটাই মুছে দিলে,
 * যাচাই "অক্ষত" বলত। ⭐ এখন প্রতিটা মাথা আজকের সিল-চাবিতে সিল হয় — এখানে একবার, তারপর প্রতিটা পোস্টিংয়ে।
 * ⓘ সিল বসানো হয় মাথার **এখনকার** অবস্থার উপর; মাথাটা আগে থেকেই ভুল হলে যাচাই সেটা আগের মতোই ধরে
 * (শেষ ছাপ বা সংখ্যা চেইনের সাথে মেলে না) — সিল ভুলটা ঢাকে না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ledger_chain_heads')) {
            return;
        }

        if (! Schema::hasColumn('ledger_chain_heads', 'head_seal')) {
            Schema::table('ledger_chain_heads', function (Blueprint $t): void {
                $t->char('head_seal', 64)->nullable()->after('entries');
                $t->unsignedTinyInteger('head_seal_version')->nullable()->after('head_seal');
            });
        }

        $version = LedgerChain::sealVersion();

        foreach (DB::table('ledger_chain_heads')->get() as $head) {
            DB::table('ledger_chain_heads')->where('company_id', $head->company_id)->update([
                'head_seal' => LedgerChain::headSeal((int) $head->company_id, $head->last_hash, (int) $head->entries, $version),
                'head_seal_version' => $version,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ledger_chain_heads', 'head_seal')) {
            Schema::table('ledger_chain_heads', function (Blueprint $t): void {
                $t->dropColumn(['head_seal', 'head_seal_version']);
            });
        }
    }
};
