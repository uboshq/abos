<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ একই স্লিপ হুবহু দুবার — বাইটের ছাপে খোঁজার সূচি (২০ নভেম্বর ২০২৬; মালিক, ১৬ নভেম্বর: "deposit report flexible")।
 *
 * ⓘ চেহারার ছাপ (`fingerprint`, [[2027_04_17_100000_the_same_slip_came_twice_through_whatsapp]]) কেবল ছবির হয় — PDF স্লিপ দুবার
 *   এলে কিছুই ধরা পড়ত না। ⭐ এখন [[AttachmentEngine::lookalikes()]] `checksum` (sha256, পরিষ্কার করা বাইটের) সমান-তুলনাতেও খোঁজে —
 *   এই সূচি সেই খোঁজকে কোম্পানির ভিতরে সরাসরি নিয়ে যায়।
 * ⓘ নতুন ঘর নেই; পুরনো সারির `checksum` আগে থেকেই আছে।
 */
return new class extends Migration
{
    private const INDEX = 'attachments_company_checksum';

    public function up(): void
    {
        if (! Schema::hasIndex('attachments', self::INDEX)) {
            Schema::table('attachments', fn (Blueprint $table) => $table->index(['company_id', 'checksum'], self::INDEX));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('attachments', self::INDEX)) {
            Schema::table('attachments', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }
    }
};
