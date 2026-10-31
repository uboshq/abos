<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * খরচের ব্যবসার লাইন — "কী কিনলাম, কত, কোন খাতে, কোন কেন্দ্রে, কত ভ্যাট" (খরচের ভাউচার ১০০%, ভাগ B; fe অনুমোদিত §১,
 * ২৪ অক্টোবর ২০২৬; expense-100-schema-section-1.md)।
 *
 * ── কী ঘটত ──────────────────────────────────────────────────────────
 * খরচের ভাউচারে একটাই খাত আর একটাই অঙ্ক — একটা বিলে বিদ্যুৎ আর পানি, বা দুই শাখার ভাড়া আলাদা খাতে বসানো যেত না, আর ভ্যাট
 * আলাদা দেখানো যেত না।
 *
 * ── কেন আলাদা টেবিল, `voucher_lines`-এ ঘর বাড়িয়ে নয় ─────────────────
 * জাবেদার সারি (`voucher_lines`) এই লাইন থেকে **বানানো** হয়: এক লাইন থেকে দুই-তিন সারি (খরচ + সমন্বয়যোগ্য ভ্যাট 1139-এ, বা
 * আগাম খাতে)। এক টেবিলে রাখলে "ব্যবসার লাইন" আর "জাবেদার সারি" গুলিয়ে যেত, আর ভ্যাটের সারি কোন লাইনের তা বলা যেত না।
 *
 * ⓘ ভ্যাটের হার কপি নয় — `vat_tax_id` → `mdm_taxes` (হার আর খাত সেখানে); `vat_amount` কাগজ যা বলেছিল তা-ই থাকে, পরে হার বদলালেও।
 * ⓘ আগাম (ec, ভাগ H): `is_prepaid` + মাস + শুরু লাইনে; সূচি (`acc_prepaid_schedules.expense_line_id`, ইউনিক) লাইনকে ধরে —
 * তাই লাইনে সূচির id নেই।
 * ⓘ পুরনো ভাউচারে কোনো লাইন নেই — এক-লাইনের পুরনো পথে পড়া হয় (এক সংস্করণ); ডেটা সরানো নয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_voucher_expense_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('expense_account_id')->constrained('accounts');
            $table->foreignId('cost_center_id')->nullable()->constrained('acc_cost_centers');
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 18, 4);
            $table->foreignId('vat_tax_id')->nullable()->constrained('mdm_taxes');
            $table->decimal('vat_amount', 18, 4)->default(0);
            $table->boolean('vat_recoverable')->default(false);
            $table->decimal('fx_amount', 18, 4)->nullable();
            $table->boolean('is_prepaid')->default(false);
            $table->unsignedSmallInteger('prepaid_months')->nullable();
            $table->date('prepaid_start')->nullable();
            $table->timestamps();

            $table->index(['voucher_id', 'sort']);
            // ⓘ নিজের ছোট নাম — গড়া নামটা ৬১ অক্ষর, ৬৪-এর সীমার গায়ে ([[NoIndexNameStandsAtTheEdgeTest]]); মাইগ্রেশন এখনো কোথাও চলেনি
            $table->index(['company_id', 'expense_account_id'], 'acc_vexp_lines_company_head');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_voucher_expense_lines');
    }
};
