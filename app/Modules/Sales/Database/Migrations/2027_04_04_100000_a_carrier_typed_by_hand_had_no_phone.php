<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ হাতে লেখা বাহকের মোবাইল — মালিক, ৭ নভেম্বর ২০২৬ (নমুনা "ok"): কাউন্টারের "গাড়ি ও ভাড়া"-য় বাহকের মোবাইলের ঘর ধূসর ছিল,
 * লেখা যেত না। তালিকার বাহকের নম্বর পক্ষের সারিতেই থাকে (দ্বিতীয় কপি নয়); তালিকায় না থাকা নামের নম্বর এখন চালানে।
 * ⓘ খালি-যোগ্য — পুরনো চালানে কিছু বদলায় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_challans', function (Blueprint $table) {
            $table->string('carrier_phone', 32)->nullable()->after('carrier_name');
        });
    }

    public function down(): void
    {
        Schema::table('sal_challans', fn (Blueprint $table) => $table->dropColumn('carrier_phone'));
    }
};
