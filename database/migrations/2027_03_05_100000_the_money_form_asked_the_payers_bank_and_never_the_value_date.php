<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ টাকা নেওয়া আর দেওয়ার তিন পর্দা একই পদ্ধতির ঘর পায় — মালিক, ২১ অক্টোবর ২০২৬ (fe-র মাধ্যমে; নকশা দফা #২, #৯:
 * [[components.money.method]], [[MoneyMethod]])।
 *
 * ⛔ আগে পর্দা জিজ্ঞেস করত টাকা যিনি দিলেন তাঁর ব্যাংক, ব্রাঞ্চ, হিসাবধারী আর হিসাব নম্বর — যা ব্যাংকের স্টেটমেন্টেই থাকে —
 * অথচ ব্যাংক টাকাটা আসলে কবে নাড়ল (মূল্য-তারিখ) জিজ্ঞেস করত না; আর সরবরাহকারী-পরিশোধ আর আদায়ে পদ্ধতির ঘরই ছিল কেবল
 * একটা খোলা লেখা (`instrument`) আর একটা নম্বর।
 *
 * ⓘ যা আসে:
 *  - তিন টেবিলে `value_date` — যেদিন ব্যাংকে টাকা নড়ল (ব্যাংক মেলানোর দিন); খাতার তারিখ `trx_date`/`date` আগের মতোই।
 *  - সরবরাহকারী-পরিশোধ আর আদায়ে ভাউচারের একই ঘর: `transfer_mode_id` (BEFTN/NPSB/RTGS), `deposit_slip_no`, `from_bank`
 *    (চেকের ব্যাংক); পরিশোধে চার্জের ঘর `charge_amount` (আদায়ের `bank_charge` আগে থেকেই আছে)।
 *
 * ⛔ কিছুই মোছা হয় না: ভাউচারের `from_branch`, `from_account_name`, `from_account_no`, `charge_borne_by` থাকে — পুরনো
 * ভাউচারের দেখানোর পাতা ওগুলো দেখায়, কেবল নতুন পর্দা আর জিজ্ঞেস করে না। পুরনো সারিতে `value_date` ফাঁকা — আন্দাজে
 * তারিখ বসানো হয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vouchers') && ! Schema::hasColumn('vouchers', 'value_date')) {
            Schema::table('vouchers', function (Blueprint $table) {
                $table->date('value_date')->nullable()->after('instrument_date');
            });
        }

        foreach (['pur_payments', 'sal_collections'] as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) use ($name) {
                if (! Schema::hasColumn($name, 'value_date')) {
                    $table->date('value_date')->nullable()->after('instrument_date');
                }
                if (! Schema::hasColumn($name, 'transfer_mode_id')) {
                    $table->foreignId('transfer_mode_id')->nullable()->after('instrument')
                        ->constrained('mdm_transfer_modes')->nullOnDelete();
                }
                if (! Schema::hasColumn($name, 'deposit_slip_no')) {
                    $table->string('deposit_slip_no', 64)->nullable()->after('instrument_no');
                }
                if (! Schema::hasColumn($name, 'from_bank')) {
                    $table->string('from_bank', 120)->nullable()->after('instrument_date');
                }
            });
        }

        if (Schema::hasTable('pur_payments') && ! Schema::hasColumn('pur_payments', 'charge_amount')) {
            Schema::table('pur_payments', function (Blueprint $table) {
                $table->decimal('charge_amount', 18, 4)->default(0)->after('amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pur_payments') && Schema::hasColumn('pur_payments', 'charge_amount')) {
            Schema::table('pur_payments', fn (Blueprint $table) => $table->dropColumn('charge_amount'));
        }

        foreach (['pur_payments', 'sal_collections'] as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) use ($name) {
                if (Schema::hasColumn($name, 'transfer_mode_id')) {
                    $table->dropConstrainedForeignId('transfer_mode_id');
                }
                foreach (['value_date', 'deposit_slip_no', 'from_bank'] as $column) {
                    if (Schema::hasColumn($name, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('vouchers') && Schema::hasColumn('vouchers', 'value_date')) {
            Schema::table('vouchers', fn (Blueprint $table) => $table->dropColumn('value_date'));
        }
    }
};
