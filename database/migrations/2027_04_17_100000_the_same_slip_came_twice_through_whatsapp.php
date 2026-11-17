<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ সংযুক্তির "চেহারার ছাপ" — মালিক, ১৬ নভেম্বর ২০২৬: স্লিপ হোয়াটসঅ্যাপে কয়েক হাত ঘুরে আসে, একই স্লিপ দুবার আসতে পারে।
 *
 * ⓘ `checksum` (sha256) বাইটের ছাপ — হোয়াটসঅ্যাপ ছবি আবার চাপলে বদলায়, তাই অকেজো। ⭐ `fingerprint` ছবির চেহারার ছাপ
 *   (dHash, ১৬ হেক্স অক্ষর; [[App\Core\Engines\Image\ImageEngine::fingerprint()]]) — ছোট-বড় বা আবার-চাপা হলেও প্রায় একই।
 * ⓘ সূচি নেই: খোঁজা হয় বিটের তফাতে ([[ImageEngine::distance()]]), সমান-তুলনায় নয় — সূচি কাজে লাগত না।
 * ⓘ পুরনো সারি null থাকে — পুরনো ছবি আবার পড়ে ছাপ বসানো হয় না (শেয়ার্ড হোস্টে হাজার ছবি খোলা); নতুন থেকে শুরু।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('attachments', 'fingerprint')) {
            Schema::table('attachments', function (Blueprint $table) {
                $table->char('fingerprint', 16)->nullable()->after('checksum');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('attachments', 'fingerprint')) {
            Schema::table('attachments', fn (Blueprint $table) => $table->dropColumn('fingerprint'));
        }
    }
};
