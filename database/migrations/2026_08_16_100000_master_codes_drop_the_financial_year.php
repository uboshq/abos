<?php

declare(strict_types=1);

use App\Core\Module\ModuleRegistry;
use App\Models\NumberSeries;
use Illuminate\Database\Migrations\Migration;

/**
 * মাস্টারের কোড থেকে অর্থবছর তুলে নেওয়া।
 *
 * ── কেন ─────────────────────────────────────────────────────────────
 * একটা বিল ২০২৬-২৭ অর্থবছরের — তারিখটা তার পরিচয়ের অংশ। কিন্তু একটা
 * দোকান বা পণ্য কোনো বছরের নয়। CUS-2026-2027-0001 তালিকার কলামে দুই
 * লাইনে ভেঙে যায়, আর চোখ প্রতিবার একটা অপ্রাসঙ্গিক সংখ্যা পার হয়।
 * এখন থেকে CUS-0001।
 *
 * ── আগে বসে যাওয়া কোডগুলো বদলানো হয় না ──────────────────────────────
 * শুধু ছকটা বদলায়, পরের নম্বর থেকে কাজে লাগে। পুরনো গ্রাহকের কোড
 * কাগজে, চালানে, হয়তো মানুষের মুখেও আছে — সেটা বদলে দিলে পুরনো নথির
 * সাথে মিল হারাত। দুই ধরনের কোড পাশাপাশি থাকবে কিছুদিন, আর সেটাই সৎ:
 * কোডটা কবেকার, সেটাই বলে দেয়।
 *
 * ── কোন সিরিজগুলো মাস্টারের, কোর সেটা জানে কীভাবে ────────────────────
 * লেবেলের চাবি "…_code"-এ শেষ হলে (NumberSeriesProvisioner::formatFor)।
 * মডিউল নিজেই নামটা বেছে দেয়, কোর শুধু নিয়মটা পড়ে — কোরকে কোনো
 * মডিউলের নাম জানতে হয় না (নিয়ম ১৯.৭)।
 */
return new class extends Migration
{
    public function up(): void
    {
        $masterTypes = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->docTypes as $docType => $labelKey) {
                if (str_ends_with($labelKey, '_code')) {
                    $masterTypes[] = $docType;
                }
            }
        }

        if ($masterTypes === []) {
            return;
        }

        /*
         * withoutGlobalScopes — মাইগ্রেশনে কোনো কোম্পানি প্রেক্ষাপটে নেই,
         * আর এটা সব কোম্পানির সিরিজেই সমান খাটে।
         */
        NumberSeries::withoutGlobalScopes()
            ->whereIn('doc_type', $masterTypes)
            ->where('format', '{PREFIX}-{FY}-{SEQ}')
            ->update(['format' => '{PREFIX}-{SEQ}']);
    }

    public function down(): void
    {
        $masterTypes = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->docTypes as $docType => $labelKey) {
                if (str_ends_with($labelKey, '_code')) {
                    $masterTypes[] = $docType;
                }
            }
        }

        if ($masterTypes === []) {
            return;
        }

        NumberSeries::withoutGlobalScopes()
            ->whereIn('doc_type', $masterTypes)
            ->where('format', '{PREFIX}-{SEQ}')
            ->update(['format' => '{PREFIX}-{FY}-{SEQ}']);
    }
};
