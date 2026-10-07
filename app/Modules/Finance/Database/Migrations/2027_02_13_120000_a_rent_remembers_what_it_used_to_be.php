<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ ভাড়ার শর্তের ইতিহাস আর ঐচ্ছিক বৃদ্ধি % — মালিকের সিদ্ধান্ত, ৬ অক্টোবর ২০২৬ (সমন্বয়কের মারফত, প্র১): "বর্ষপূর্তিতে ভাড়া নিজে
 * বাড়বে না, শুধু মনে করিয়ে দেবে; শর্ত বদলের ইতিহাস রাখো, যাতে পুরনো মাস পুরনো দরে দেখায়"।
 *
 * ⛔ আগে শর্ত বদলালে চুক্তির মাসিক ভাড়া সরাসরি বদলে যেত, পুরনো অঙ্ক কোথাও থাকত না — তাই না-দেওয়া পুরনো মাস এখনকার দরে
 * দেখাত ([[RentalReports::SCHEDULE]])।
 *
 *   · `fin_rental_terms` — কোন মাস থেকে কত ভাড়া আর জামানত থেকে কত কাটে; এক চুক্তিতে এক মাসে একটাই সারি
 *   · `fin_rental_contracts.increase_percent` — চুক্তিতে বৃদ্ধির কথা থাকলে বছরে কত % (ঐচ্ছিক); থাকলে বর্ষপূর্তির আগে ঘণ্টা
 *
 * ⓘ পুরনো চুক্তি: শুরুর মাস থেকে এখনকার দরে একটা সারি — আগের ইতিহাস কোথাও ছিল না, তাই এর চেয়ে ভালো কিছু জানা নেই।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fin_rental_terms')) {
            Schema::create('fin_rental_terms', function (Blueprint $table): void {
                $table->id();
                $table->publicId();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('rental_contract_id')->constrained('fin_rental_contracts')->cascadeOnDelete();
                $table->date('effective_from');
                $table->decimal('monthly_rent', 18, 4);
                $table->decimal('monthly_adjustment', 18, 4)->default(0);
                $table->string('note', 500)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['rental_contract_id', 'effective_from'], 'fin_rental_term_month');
                $table->index(['company_id', 'effective_from']);
            });

            // ⓘ পুরনো চুক্তি — শুরুর মাস থেকে এখনকার দরে
            foreach (DB::table('fin_rental_contracts')->whereNull('deleted_at')->get() as $c) {
                DB::table('fin_rental_terms')->insert([
                    'public_id' => (string) \Illuminate\Support\Str::uuid7(),
                    'company_id' => $c->company_id,
                    'branch_id' => $c->branch_id,
                    'rental_contract_id' => $c->id,
                    'effective_from' => substr((string) $c->starts_on, 0, 7).'-01',
                    'monthly_rent' => $c->monthly_rent,
                    'monthly_adjustment' => $c->monthly_adjustment ?? 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (! Schema::hasColumn('fin_rental_contracts', 'increase_percent')) {
            Schema::table('fin_rental_contracts', function (Blueprint $table): void {
                $table->decimal('increase_percent', 5, 2)->nullable()->after('monthly_adjustment');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_rental_terms');

        if (Schema::hasColumn('fin_rental_contracts', 'increase_percent')) {
            Schema::table('fin_rental_contracts', fn (Blueprint $table) => $table->dropColumn('increase_percent'));
        }
    }
};
