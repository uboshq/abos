<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ⭐ ডেবিট/ক্রেডিট নোট সব পক্ষে, দুই দিকেই — মালিক, ৩ অক্টোবর ২০২৬: *"সব পক্ষেই ডেবিট ক্রেডিট হয়, দুই পক্ষেরই লাগে"*।
 *
 * ⓘ আগে দিক থেকেই পক্ষ ঠিক হত (ক্রেডিট = গ্রাহক, ডেবিট = সরবরাহকারী) আর খাত দুটো কোডে বাঁধা। এখন নোট নিজের
 * পক্ষের ধরন (`party_kind`: customer · supplier · service_provider · person) আর দুই খাত (`control_account_id`
 * — পক্ষের খাত, `other_account_id` — অন্য পাশ) মনে রাখে, যাতে পর্দা আর ছাপা দুটোই খাত দেখায় ([[NoteAccounts]])।
 * পুরনো নোটে ঘরগুলো খালি — নিশ্চিত করার সময় আগের নিয়মেই বসে।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('acc_notes') || Schema::hasColumn('acc_notes', 'party_kind')) {
            return;
        }

        Schema::table('acc_notes', function (Blueprint $t) {
            $t->string('party_kind', 24)->nullable()->after('party_id');
            $t->foreignId('control_account_id')->nullable()->after('party_kind')->constrained('accounts')->nullOnDelete();
            $t->foreignId('other_account_id')->nullable()->after('control_account_id')->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('acc_notes', 'party_kind')) {
            return;
        }

        Schema::table('acc_notes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('control_account_id');
            $t->dropConstrainedForeignId('other_account_id');
            $t->dropColumn('party_kind');
        });
    }
};
