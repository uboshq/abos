<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ সংরক্ষিত সূচির সময়কাল — ১৭ অক্টোবর ২০২৬ (a5-এর পর্যালোচনা; fe)।
 *
 * ⛔ শুরুর তারিখ ছাড়া সংরক্ষিত প্রতিটা সূচি এতদিন "এই মাস" পেত — ইঞ্জিনের তখনকার একমাত্র ডিফল্ট। ⓘ বিক্রয়ের মাসওয়ারি,
 * লাভ-ক্ষতি আর মাসওয়ারি নগদের ডিফল্ট অর্থবছরে গেলে ঐ সূচিগুলোর মেইল নীরবে বছর-থেকে-আজ হয়ে যেত। ⭐ তাই আগের সূচিতে
 * সেই "মাস"-টাই লিখে রাখা হয় — যা নিয়ে সেগুলো সংরক্ষিত হয়েছিল। ⓘ শুরুর তারিখ থাকলে কিছু বদলায় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('report_schedules')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $filters = json_decode((string) ($row->filters ?? '[]'), true);
                $filters = is_array($filters) ? $filters : [];

                if (($filters['from'] ?? '') !== '' || isset($filters['_period'])) {
                    continue;
                }

                $filters['_period'] = 'month';

                DB::table('report_schedules')->where('id', $row->id)
                    ->update(['filters' => json_encode($filters, JSON_UNESCAPED_UNICODE)]);
            }
        });
    }

    public function down(): void
    {
        DB::table('report_schedules')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $filters = json_decode((string) ($row->filters ?? '[]'), true);

                if (is_array($filters) && ($filters['_period'] ?? null) === 'month') {
                    unset($filters['_period']);
                    DB::table('report_schedules')->where('id', $row->id)
                        ->update(['filters' => $filters === [] ? null : json_encode($filters, JSON_UNESCAPED_UNICODE)]);
                }
            }
        });
    }
};
