<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⛔ আনলোডিংয়ের টাকা "এখনই নগদে দিলাম" — কিন্তু কাকে, তা কোথাও লেখা থাকত না (মালিক, ২৬ নভেম্বর ২০২৬: "কাকে দিচ্ছে? হাতে নাম
 *   লাগবে")। ⓘ ঠিকাদার তালিকা থেকে বাছলে `unload_contractor_id`-এ বসে (আগে থেকেই ঘর ছিল); তালিকায় না থাকলে হাতে লেখা নাম এখানে।
 * ⓘ online: কেবল একটা nullable ঘর যোগ — কোনো সারি ছোঁয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pur_bills', 'unload_payee')) {
            Schema::table('pur_bills', fn (Blueprint $t) => $t->string('unload_payee', 120)->nullable()->after('unload_reason'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pur_bills', 'unload_payee')) {
            Schema::table('pur_bills', fn (Blueprint $t) => $t->dropColumn('unload_payee'));
        }
    }
};
