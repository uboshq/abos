<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * খরচের ভাউচারের মাথায় নতুন ঘর — বিলের তারিখ, শেষ দিন, যে কর্মী টাকা দিলেন, মুদ্রা ও হার, বাজেটের কারণ ও ছবি, সতর্কতা, নমুনা
 * (খরচের ভাউচার ১০০%, ভাগ B; fe অনুমোদিত §১, ২৪ অক্টোবর ২০২৬)।
 *
 * ⓘ সবই nullable — পুরনো সারি অক্ষত, কোনো ডেটা সরানো নয়।
 * ⓘ পদ্ধতির নতুন ঘর নেই: "বাকিতে" আর "কর্মী দিলেন" `instrument`-এর দুটো মান (`credit`, `employee`) — পদ্ধতির নিয়ম এক জায়গায়
 * (fe-র সিদ্ধান্ত ১)। ⓘ `employee_id` আলাদা ঘর: "কর্মী দিলেন" হলে মাথার পক্ষ সরবরাহকারী (যাঁর বিল), টাকা দিলেন কর্মী — দুজন আলাদা।
 * ⓘ মুদ্রা `currency_id` (fe-র সিদ্ধান্ত ২) — হার `mdm_exchange_rates.currency_id` ধরে রাখা; `fx_date` কোন দিনের হার নেওয়া হলো
 * (ডিফল্ট trx_date, IAS 21), বদলানো হারের কারণসহ।
 * ⓘ `template_id` FK ছাড়া — নমুনার সারি ec-এর `recurring_journals`, যা post-freeze-এর পরে main-এ আসবে; FK সেখানে (§৭ ধাপ ৬)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->date('bill_date')->nullable()->after('bill_no');
            $table->date('due_date')->nullable()->after('bill_date');
            $table->foreignId('employee_id')->nullable()->constrained('hr_employees');
            $table->foreignId('currency_id')->nullable()->constrained('mdm_currencies');
            $table->decimal('fx_rate', 18, 6)->nullable();
            $table->date('fx_date')->nullable();
            $table->string('fx_rate_reason', 500)->nullable();
            $table->string('budget_reason', 500)->nullable();
            $table->json('budget_check')->nullable();
            $table->json('warnings')->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('employee_id');
            $table->dropConstrainedForeignId('currency_id');
            $table->dropColumn(['bill_date', 'due_date', 'fx_rate', 'fx_date', 'fx_rate_reason', 'budget_reason', 'budget_check', 'warnings', 'template_id']);
        });
    }
};
