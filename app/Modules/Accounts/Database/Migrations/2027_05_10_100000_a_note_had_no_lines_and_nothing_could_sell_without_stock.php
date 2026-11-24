<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ⭐ নোট আর ভাউচারের নকশা v5 — মালিক, ২৩ নভেম্বর ২০২৬: *"আমি ওকে করে দিলাম"* (আন্তর্জাতিক মানে)।
 *
 * ১. নোট তিন ভাগ: ক্রেডিট নোট (`cn`), গ্রাহকের ডেবিট নোট (`cdn`), সরবরাহকারীর ডেবিট নোট (`dn`) — নিজের তালিকা, ফর্ম, ছাপা।
 *    ⓘ নম্বর বদলায় না: CN আর DN ক্রম আগের মতোই; পুরনো নোট দিক আর পক্ষ দেখে ভাগে বসে (ক্রেডিট → cn; ডেবিট + গ্রাহক → cdn;
 *    বাকি ডেবিট → dn)। লাইভে UB-র ২২টা CN আর ১টা গ্রাহকের DN।
 * ২. নোটের সারি — পণ্য / সেবা / খাত; মূল বিলের সারি, ভ্যাটের হার, আর "মাল ফেরত" সারির ফেরতের কাগজ (SR/PR)।
 *    ⛔ ফেরতের সারির টাকা ফেরতের কাগজ তোলে, নোট নয় — তাই নোটের `amount/tax_amount/total` কেবল নোটের নিজের সারির;
 *    ফেরতের ভাগ `returned_total`-এ, কেবল দেখানোর জন্য।
 * ৩. সরবরাহকারীর মূসক-৬.৭ নম্বর ও তারিখ — থাকলে তবেই ডেবিট নোটে ক্রয়-ভ্যাট উল্টায়।
 * ৪. স্টক ছাড়া বিক্রয় ভাউচার (SV) আর ক্রয় ভাউচার (PUV) — টাকার ঘর নেই; বিল কেবল পাওনা/দেনা বানায়।
 * ৫. সুইচ `accounts.stock_free_vouchers` — মজুদ মডিউল বন্ধ যে কোম্পানিতে, সেখানে চালু; বাকিদের বন্ধ (ডিফল্ট)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_notes', function (Blueprint $table) {
            $table->string('note_type', 8)->nullable()->after('direction');
            $table->string('supplier_note_no', 64)->nullable()->after('against_no');
            $table->date('supplier_note_date')->nullable()->after('supplier_note_no');
            $table->decimal('returned_total', 18, 4)->default(0)->after('total');
            $table->index(['company_id', 'note_type', 'status'], 'acc_note_type_state');
        });

        DB::table('acc_notes')->where('direction', 'credit')->update(['note_type' => 'cn']);
        DB::table('acc_notes')->where('direction', 'debit')->where('party_type', 'customer')->update(['note_type' => 'cdn']);
        DB::table('acc_notes')->where('direction', 'debit')->whereNull('note_type')->update(['note_type' => 'dn']);

        Schema::create('acc_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('note_id')->constrained('acc_notes')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            // product · service · account
            $table->string('line_type', 12);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('item_name', 191)->nullable();
            $table->string('description', 255)->nullable();
            // ⓘ মূল বিলের সারি — বিক্রয় বিলের বা ক্রয় বিলের, নোটের `against_type` বলে কোনটা
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->decimal('billed_qty', 18, 4)->nullable();
            $table->decimal('qty', 18, 4)->default(0);
            $table->string('unit', 32)->nullable();
            $table->decimal('rate', 18, 4)->default(0);
            $table->decimal('amount', 18, 4)->default(0);
            $table->unsignedBigInteger('tax_id')->nullable();
            $table->decimal('vat_rate', 8, 4)->default(0);
            $table->decimal('vat_amount', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('goods_moved')->default(false);
            $table->string('return_type', 64)->nullable();
            $table->unsignedBigInteger('return_id')->nullable();
            $table->string('return_no', 64)->nullable();
            // ⓘ সরবরাহকারীর ডেবিট নোট: সারির মালের যত ভাগ এখনো মজুদে (১ = সব) — সেই ভাগ মজুদে, বাকি ক্রয়মূল্যের পার্থক্যে
            $table->decimal('stock_share', 10, 6)->nullable();
            // ⓘ পাকা হওয়ার মুহূর্তে মজুদের দামের স্তর যতটা কমল (Cr ১১২০ ঠিক এতটা) আর এককপ্রতি কমা — বাতিলে একই অঙ্কে ফেরে
            $table->decimal('stock_value', 18, 4)->nullable();
            $table->decimal('revalue_unit', 18, 6)->nullable();
            $table->timestamps();

            $table->index(['note_id', 'line_no'], 'acc_note_line_order');
            $table->index(['return_type', 'return_id'], 'acc_note_line_return');
        });

        Schema::create('acc_trade_vouchers', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('document_no', 64);
            // sale (SV) · purchase (PUV)
            $table->string('kind', 12);
            $table->date('trx_date');
            $table->string('party_type', 32);
            $table->unsignedBigInteger('party_id');
            $table->date('due_on')->nullable();
            // SV: গ্রাহকের রেফ / পিও নম্বর · PUV: সরবরাহকারীর বিল নম্বর
            $table->string('ref_no', 64)->nullable();
            // PUV: সরবরাহকারীর মূসক-৬.৩ নম্বর ও তারিখ
            $table->string('mushak_no', 64)->nullable();
            $table->date('mushak_date')->nullable();
            $table->decimal('amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            // PUV: বিলেই কাটা উৎসে কর (আয়কর আইন ২০২৩ — পরিশোধ বা জমা, যেটা আগে)
            $table->decimal('tds_amount', 18, 4)->default(0);
            // পক্ষের পাওনা/দেনা — মোট − উৎসে কর
            $table->decimal('party_total', 18, 4)->default(0);
            $table->string('narration', 500)->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'document_no'], 'acc_trade_voucher_no_unique');
            $table->index(['company_id', 'kind', 'status'], 'acc_trade_voucher_state');
            $table->index(['company_id', 'party_type', 'party_id'], 'acc_trade_voucher_party');
        });

        Schema::create('acc_trade_voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('trade_voucher_id')->constrained('acc_trade_vouchers')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('line_type', 12);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('item_name', 191)->nullable();
            $table->string('description', 255)->nullable();
            $table->decimal('qty', 18, 4)->default(0);
            $table->string('unit', 32)->nullable();
            $table->decimal('rate', 18, 4)->default(0);
            $table->decimal('amount', 18, 4)->default(0);
            $table->unsignedBigInteger('tax_id')->nullable();
            $table->decimal('vat_rate', 8, 4)->default(0);
            $table->decimal('vat_amount', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamps();

            $table->index(['trade_voucher_id', 'line_no'], 'acc_trade_voucher_line_order');
        });

        /*
         * ⓘ মজুদ মডিউল বন্ধ যে কোম্পানিতে — সেখানে স্টক ছাড়া ভাউচারই বিক্রি আর কেনার একমাত্র পথ, তাই সুইচ চালু।
         * মজুদ চালু কোম্পানিতে সারি লেখা হয় না: ডিফল্ট বন্ধ (মালিকের নকশা v5)।
         */
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $inventoryOn = DB::table('settings')->where('company_id', $companyId)->where('key', 'inventory.enabled')->value('value');

            if ($inventoryOn !== null && ! filter_var($inventoryOn, FILTER_VALIDATE_BOOLEAN)
                && ! DB::table('settings')->where('company_id', $companyId)->where('key', 'accounts.stock_free_vouchers')->exists()) {
                DB::table('settings')->insert([
                    'company_id' => $companyId,
                    'module' => 'accounts',
                    'key' => 'accounts.stock_free_vouchers',
                    'type' => 'boolean',
                    'value' => '1',
                    'group' => 'entry',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_trade_voucher_lines');
        Schema::dropIfExists('acc_trade_vouchers');
        Schema::dropIfExists('acc_note_lines');

        Schema::table('acc_notes', function (Blueprint $table) {
            $table->dropIndex('acc_note_type_state');
            $table->dropColumn(['note_type', 'supplier_note_no', 'supplier_note_date', 'returned_total']);
        });

        DB::table('settings')->where('key', 'accounts.stock_free_vouchers')->delete();
    }
};
