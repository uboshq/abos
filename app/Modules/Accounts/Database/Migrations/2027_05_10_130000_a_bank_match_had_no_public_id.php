<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ⓘ ব্যাংক-সারি ↔ খাতার সারির মিল (`acc_bank_statement_matches`, ২১ নভেম্বর ২০২৬) — বাইরের কী ছাড়া জন্মেছিল (PublicIdTest,
 *   1.3.10-এর লাল, ২৩ নভেম্বর ২০২৬)। ঘর বসে, পুরনো সারিগুলো (এক-এক মিল থেকে সরানো) একটা করে uuid7 পায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('acc_bank_statement_matches')) {
            return;
        }

        if (! Schema::hasColumn('acc_bank_statement_matches', 'public_id')) {
            Schema::table('acc_bank_statement_matches', function (Blueprint $table) {
                $table->publicId();
            });
        }

        DB::table('acc_bank_statement_matches')->whereNull('public_id')->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('acc_bank_statement_matches')->where('id', $row->id)
                        ->update(['public_id' => (string) Str::uuid7()]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('acc_bank_statement_matches', 'public_id')) {
            Schema::table('acc_bank_statement_matches', function (Blueprint $table) {
                $table->dropUnique(['public_id']);
                $table->dropColumn('public_id');
            });
        }
    }
};
