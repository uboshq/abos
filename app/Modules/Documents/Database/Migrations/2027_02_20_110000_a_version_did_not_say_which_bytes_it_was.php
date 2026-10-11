<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * একটা ভার্সন বলত না সে ঠিক কোন বাইটগুলো — ডকুমেন্টের প্রথম ধাপের শেষ টুকরো (৯ অক্টোবর ২০২৬)।
 *
 * ⭐ পরিকল্পনা §২১: `file_hash`। প্রতিটা ভার্সনের ফাইলের SHA-256, ভার্সনের নিজের সারিতে।
 *
 * ⓘ সংযুক্তির খাতাও হ্যাশ রাখে (`attachments.checksum`), কিন্তু ভার্সনের সারি কখনো বদলায় না
 * ([[DocumentVersion]]) — তাই এখানে লেখা হ্যাশটাই সাক্ষী: সই (§১১) ঠিক কোন বাইটে পড়ল, আর
 * ফাইলটা পরে কেউ ডিস্কে বদলালে মিলিয়ে ধরা যায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dms_document_versions', function (Blueprint $table) {
            $table->char('file_hash', 64)->nullable()->after('attachment_id');
        });

        // ⓘ আগে তোলা ভার্সনগুলোর হ্যাশ — সংযুক্তির খাতা থেকে, একবার
        DB::table('dms_document_versions')
            ->join('attachments', 'attachments.id', '=', 'dms_document_versions.attachment_id')
            ->whereNull('dms_document_versions.file_hash')
            ->update(['dms_document_versions.file_hash' => DB::raw('attachments.checksum')]);
    }

    public function down(): void
    {
        Schema::table('dms_document_versions', function (Blueprint $table) {
            $table->dropColumn('file_hash');
        });
    }
};
