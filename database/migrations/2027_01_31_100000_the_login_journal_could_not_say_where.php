<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ঢোকার খাতা কোথা থেকে, তা বলতে পারত না — মালিক, ১ অক্টোবর ২০২৬।
 *
 * ⓘ মালিকের কথা: *"jaygar nam soho dekhabe, zemon bridge more, mymensingh"*। কেবল IP দিয়ে বড়জোর জেলা
 * (প্রায়ই ভুল — ইন্টারনেট কোম্পানির সার্ভার ঢাকায়), তাই মালিক বেছেছেন: লগইনের সময় ব্রাউজার লোকেশন চায়।
 * দিলে স্থানাঙ্ক বসে, আর পরে জায়গার নাম ([[LoginPlace]])। না দিলে সব খালি — লগইন আটকায় না।
 *
 * ⓘ টেবিলটা `login_history` ([[LoginAttempt]])। চারটা ঘরই খালি-যোগ্য, নতুন — পুরনো সারি ছোঁয়া হয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_history', function (Blueprint $table) {
            $table->decimal('latitude', 9, 6)->nullable()->after('user_agent');
            $table->decimal('longitude', 9, 6)->nullable()->after('latitude');
            $table->unsignedInteger('accuracy_m')->nullable()->after('longitude');
            $table->string('place', 191)->nullable()->after('accuracy_m');
        });
    }

    public function down(): void
    {
        Schema::table('login_history', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'accuracy_m', 'place']);
        });
    }
};
