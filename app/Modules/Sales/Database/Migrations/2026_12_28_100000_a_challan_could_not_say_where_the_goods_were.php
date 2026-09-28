<?php

declare(strict_types=1);

use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Services\DeliveryStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ডেলিভারির ধাপ — চালান বলতে পারত না মালটা এখন কোথায় (NEXUS §২১–২২)।
 *
 * ── ⓘ কী ছিল ─────────────────────────────────────────────────────────
 * চালানের অবস্থা খাতার: খসড়া, নিশ্চিত, বাতিল। ট্রিপের সারি বলে গাড়ি
 * থেকে কী ফিরল। ⚠️ কিন্তু *"এই চালানের মাল এখন কোথায়"* — তাকে, প্যাকে,
 * গাড়িতে, না দোকানে — প্রশ্নটার উত্তর কোথাও একসাথে লেখা ছিল না, আর
 * কে কখন কাকে মাল বুঝিয়ে দিল তার কোনো সারিই ছিল না।
 *
 * ── ⭐ তিনটা টেবিল, চালানের টেবিলে হাত না দিয়ে ──────────────────────
 *   • `sal_delivery_states` — প্রতি চালানে একটা সারি: এখনকার ধাপ। তালিকা
 *     ও গোনা এটাই পড়ে, ইতিহাস হাঁটতে হয় না।
 *   • `sal_delivery_events` — প্রতিটা বদলের সারি: কে, কখন, কোথা থেকে কোথায়,
 *     কেন, আর কে মাল নিলেন (নাম ও ফোন)। কখনো বদলায় না।
 *   • `sal_delivery_event_lines` — আংশিক ডেলিভারিতে কোন সারির কতটা গেল।
 *
 * ⛔ কোনোটাই স্টকে হাত দেয় না — মাল নামে চালান নিশ্চিত হলে, ফেরে বাতিল
 * বা বিক্রয় ফেরতে। [[DeliveryStage]]-এ কারণ লেখা।
 *
 * ⚠️ সূচকের নাম হাতে ছোট রাখা — ৬০ অক্ষরের কাছে গেলে MariaDB-তে গোটা
 * migrate:fresh ভাঙে ([[NoIndexNameStandsAtTheEdgeTest]])।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sal_delivery_states', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignId('delivery_challan_id')
                ->constrained('sal_challans', indexName: 'sal_dlv_states_challan_fk')->restrictOnDelete();

            $table->string('stage', 32);
            $table->timestamp('stage_at')->nullable();
            $table->foreignId('updated_by')->nullable()
                ->constrained('users', indexName: 'sal_dlv_states_user_fk')->nullOnDelete();

            $table->timestamps();

            // এক চালান, এক ধাপ — ডাটাবেসেই, দুইজন একসাথে প্রথম সারি লিখলেও
            $table->unique('delivery_challan_id', 'sal_dlv_states_challan_uq');

            // "কোন ধাপে কয়টা" — তালিকার ট্যাবগুলো
            $table->index(['company_id', 'stage', 'stage_at'], 'sal_dlv_states_stage_idx');
        });

        Schema::create('sal_delivery_events', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignId('delivery_challan_id')
                ->constrained('sal_challans', indexName: 'sal_dlv_events_challan_fk')->restrictOnDelete();

            $table->string('from_stage', 32)->nullable();
            $table->string('to_stage', 32);

            // manual · challan · shipment · backfill — কে বদলাল
            $table->string('source', 16);
            $table->foreignId('shipment_id')->nullable()
                ->constrained('sal_shipments', indexName: 'sal_dlv_events_trip_fk')->nullOnDelete();

            // ব্যর্থতার কারণ — কারণের তালিকা থেকে, নাহলে লেখা
            $table->foreignId('reason_code_id')->nullable()
                ->constrained('mdm_reason_codes', indexName: 'sal_dlv_events_reason_fk')->nullOnDelete();
            $table->string('note', 500)->nullable();

            // প্রমাণ — কে মাল বুঝে নিলেন
            $table->string('receiver_name', 191)->nullable();
            $table->string('receiver_phone', 32)->nullable();

            $table->timestamp('occurred_at')->nullable();
            $table->foreignId('created_by')->nullable()
                ->constrained('users', indexName: 'sal_dlv_events_user_fk')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'delivery_challan_id', 'id'], 'sal_dlv_events_challan_idx');
        });

        Schema::create('sal_delivery_event_lines', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('delivery_event_id')
                ->constrained('sal_delivery_events', indexName: 'sal_dlv_lines_event_fk')->cascadeOnDelete();
            $table->foreignId('delivery_challan_line_id')
                ->constrained('sal_challan_lines', indexName: 'sal_dlv_lines_line_fk')->restrictOnDelete();

            /* পরিমাণ float নয় — [[MoneyIsNeverAFloatTest]]-এর নিয়মই */
            $table->decimal('delivered_qty', 18, 4);

            $table->timestamps();

            $table->unique(['delivery_event_id', 'delivery_challan_line_id'], 'sal_dlv_lines_uq');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_delivery_event_lines');
        Schema::dropIfExists('sal_delivery_events');
        Schema::dropIfExists('sal_delivery_states');
    }

    /**
     * ⭐ পুরনো চালানগুলোর প্রথম ধাপ — তথ্য থেকে গোনা, অনুমান নয়।
     *
     * ⓘ নিয়মটা [[DeliveryStage::derive()]]-এর, সেবাও সেটাই ডাকে। ⚠️ ব্যাকফিল
     * না করলে লাইভের প্রতিটা পুরনো চালান তালিকা থেকে অদৃশ্য থাকত, আর "পথে
     * কয়টা" ট্যাব শূন্য দেখাত — অথচ গাড়ি বাইরে।
     *
     * ⓘ প্রতিটা সারিতে `company_id` হাতে বসে — এখানে কোম্পানি-প্রসঙ্গ নেই।
     */
    private function backfill(): void
    {
        DB::table('sal_challans')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->select(['id', 'company_id', 'status', 'updated_at'])
            ->chunkById(500, function ($challans): void {
                $now = now();

                foreach ($challans as $challan) {
                    $trip = DB::table('sal_shipment_lines')
                        ->join('sal_shipments', 'sal_shipments.id', '=', 'sal_shipment_lines.shipment_id')
                        ->where('sal_shipment_lines.company_id', $challan->company_id)
                        ->where('sal_shipment_lines.delivery_challan_id', $challan->id)
                        ->whereNull('sal_shipments.deleted_at')
                        ->whereIn('sal_shipments.status', DocumentStatus::POSTED)
                        ->orderByDesc('sal_shipments.dispatched_at')
                        ->orderByDesc('sal_shipments.id')
                        ->select(['sal_shipments.id', 'sal_shipments.status', 'sal_shipment_lines.outcome',
                            'sal_shipment_lines.outcome_note'])
                        ->first();

                    $stage = DeliveryStage::derive((string) $challan->status, $trip?->status, $trip?->outcome);

                    DB::table('sal_delivery_states')->insert([
                        'public_id' => (string) Str::uuid7(),
                        'company_id' => $challan->company_id,
                        'delivery_challan_id' => $challan->id,
                        'stage' => $stage,
                        'stage_at' => $challan->updated_at ?? $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    DB::table('sal_delivery_events')->insert([
                        'public_id' => (string) Str::uuid7(),
                        'company_id' => $challan->company_id,
                        'delivery_challan_id' => $challan->id,
                        'from_stage' => null,
                        'to_stage' => $stage,
                        'source' => DeliveryStage::BY_BACKFILL,
                        'shipment_id' => $trip?->id,
                        'note' => $trip?->outcome_note,
                        'occurred_at' => $challan->updated_at ?? $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }
};
