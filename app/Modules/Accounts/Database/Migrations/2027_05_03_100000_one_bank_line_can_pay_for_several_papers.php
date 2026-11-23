<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ একটা ব্যাংক-সারি কয়েকটা কাগজের টাকা — মালিক, ২১ নভেম্বর ২০২৬ (আন্তর্জাতিক one-to-many reconciliation)।
 *
 * ── কী ঘটল ──────────────────────────────────────────────────────────
 * ব্যাংকে এক সারি ৳১,০০,০০০ (রেফারেন্স 175300556) — দুই ডিলারের ৳৫০,০০০ করে, দুই রসিদ RCV-0537 আর RCV-0538। ⛔ আগে ব্যাংকের
 * সারির একটাই ঘর ছিল (`matched_line_id`) — এক সারি, এক খাতার সারি; ৳১,০০,০০০-এর সারি ৳৫০,০০০-এর কোনোটার সাথেই মিলত না।
 *
 * ── নতুন টেবিল `acc_bank_statement_matches` ─────────────────────────
 *   · এক ব্যাংক-সারির কয়েকটা খাতার সারি (ভাউচার লাইন), প্রতিটার অঙ্কসহ। মোট = ব্যাংক-সারির অঙ্ক, হুবহু ([[BankStatementService]])।
 *   · ⛔ `voucher_line_id` unique — একটা রসিদ একবারই মেলে।
 *   · `matched_line_id` থাকে — "সারিটা খাতায় মিলেছে" চিহ্ন (দলের প্রথম সারি); পুরনো পর্দা, বোর্ড আর `unmatched` ছাঁকনি বদলায় না।
 *
 * ── পুরনো এক-এক মিল ─────────────────────────────────────────────────
 * প্রতিটা `matched_line_id` নতুন টেবিলে এক সারি হয়ে যায়। ⚠️ ৪ অক্টোবরের আগের ভুলে একই খাতার সারি দুই ব্যাংক-সারিতে বসে থাকতে
 * পারে — তখন প্রথমটা (ছোট id) টেবিলে যায়, বাকিগুলো লগে; ব্যাংক-সারির চিহ্ন হাত দেওয়া হয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('acc_bank_statement_matches')) {
            Schema::create('acc_bank_statement_matches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('statement_line_id')->constrained('acc_bank_statement_lines')->cascadeOnDelete();
                $table->foreignId('voucher_line_id')->constrained('voucher_lines')->cascadeOnDelete();
                $table->decimal('amount', 18, 4);
                $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('matched_at')->nullable();
                $table->timestamps();

                $table->unique('voucher_line_id', 'acc_stmt_match_book_once');
                $table->index(['company_id', 'statement_line_id'], 'acc_stmt_match_line');
            });
        }

        $moved = 0;
        $twice = [];

        DB::table('acc_bank_statement_lines as l')
            ->join('voucher_lines as vl', 'vl.id', '=', 'l.matched_line_id')
            ->whereNotNull('l.matched_line_id')
            ->orderBy('l.id')
            ->select(['l.id', 'l.company_id', 'l.matched_line_id', 'l.matched_by', 'l.matched_at', 'vl.debit', 'vl.credit'])
            ->chunk(500, function ($rows) use (&$moved, &$twice): void {
                foreach ($rows as $row) {
                    if (DB::table('acc_bank_statement_matches')->where('voucher_line_id', $row->matched_line_id)->exists()) {
                        $twice[] = (int) $row->id;

                        continue;
                    }

                    $amount = bccomp((string) $row->debit, '0', 4) > 0 ? (string) $row->debit : (string) $row->credit;

                    DB::table('acc_bank_statement_matches')->insert([
                        'company_id' => $row->company_id,
                        'statement_line_id' => $row->id,
                        'voucher_line_id' => $row->matched_line_id,
                        'amount' => $amount,
                        'matched_by' => $row->matched_by,
                        'matched_at' => $row->matched_at,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $moved++;
                }
            });

        Log::info('Bank statement: one-to-one matches moved to acc_bank_statement_matches', [
            'moved' => $moved,
            'book_line_already_on_another_bank_line' => $twice,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_bank_statement_matches');
    }
};
