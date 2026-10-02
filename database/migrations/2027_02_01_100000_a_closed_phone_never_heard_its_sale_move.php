<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ⭐ অ্যাপ বন্ধ থাকলেও বার্তা — মালিকের আদেশ, ২ অক্টোবর ২০২৬ (ডেলিভারি ট্র্যাকিং, ধাপ ৩)।
 *
 * ⓘ ফোনের FCM টোকেন — ফোনপ্রতি একটা সারি আগেই আছে (`sync_devices`, user ও কোম্পানিসহ); নতুন টেবিল নয়।
 * সমন্বয়কের অনুমোদন (abos-63): কেবল যোগ, nullable, ছোট টেবিল।
 * ⓘ লগআউটে বা FCM "আর নেই" বললে null; একই টোকেন অন্য সারিতে থাকলে নতুন নিবন্ধনে আগেরটা null।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_devices', function (Blueprint $table) {
            $table->string('push_token', 255)->nullable()->after('platform');
            $table->timestamp('push_token_at')->nullable()->after('push_token');
        });
    }

    public function down(): void
    {
        Schema::table('sync_devices', function (Blueprint $table) {
            $table->dropColumn(['push_token', 'push_token_at']);
        });
    }
};
