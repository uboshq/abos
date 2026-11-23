<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The party never knew whether the message arrived — WhatsApp/SMS step 3 (owner "ok" 23 Nov 2026 on sample
 * HUXqpfhxng8823eEFrvH54): one log for both channels with each message's state, automatic sends, and the Meta Cloud API.
 *
 * ⭐ party_messages gains its state:
 *   - `status`: opened (wa.me button — the browser cannot know more) · waiting · sent · delivered · read · failed;
 *   - `route`: wame · cloud · phone (the office SIM);
 *   - `via`: button · auto;
 *   - `rule`: due_reminder · payment_received (auto only);
 *   - plus the provider's id, the SMS queue row, and the row it fell back from ("WhatsApp নেই → SMS-এ গেছে").
 * ⭐ Templates gain the name Meta approved them under (Cloud API sends only approved templates).
 * ⭐ party_message_optouts — "বার্তা পাঠাবেন না" parties; automatic sends skip them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('party_messages', function (Blueprint $table) {
            $table->string('status', 12)->default('opened')->after('body');
            $table->string('route', 8)->default('wame')->after('status');
            $table->string('via', 8)->default('button')->after('route');
            $table->string('rule', 24)->nullable()->after('via');
            $table->string('error', 255)->nullable()->after('rule');
            $table->timestamp('sent_at')->nullable()->after('error');
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
            $table->timestamp('read_at')->nullable()->after('delivered_at');
            $table->string('provider_ref', 120)->nullable()->after('read_at');
            $table->unsignedBigInteger('sms_outbox_id')->nullable()->after('provider_ref');
            $table->unsignedBigInteger('fallback_of_id')->nullable()->after('sms_outbox_id');

            $table->index(['company_id', 'status', 'created_at'], 'pm_company_status_idx');
            $table->index(['provider_ref'], 'pm_provider_ref_idx');
            $table->index(['company_id', 'rule', 'party_type', 'party_id', 'created_at'], 'pm_rule_party_idx');
        });

        Schema::table('party_message_templates', function (Blueprint $table) {
            $table->string('wa_name', 120)->nullable()->after('body_en');
            $table->string('wa_status', 12)->nullable()->after('wa_name');
        });

        Schema::create('party_message_optouts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('party_type', 40);
            $table->unsignedBigInteger('party_id');
            $table->string('party_name', 191)->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'party_type', 'party_id'], 'pmo_company_party_unique');
        });

        /*
         * ⓘ The SMS rows written before today: their route is the office phone, and their state is the queue row's
         * (sms_outbox.source = 'party', source_id = the message id) — never "opened", which only a wa.me button means.
         */
        foreach (DB::table('sms_outbox')->where('source', 'party')->whereNotNull('source_id')
            ->get(['id', 'source_id', 'status', 'sent_at', 'delivered_at', 'error']) as $row) {
            DB::table('party_messages')->where('id', $row->source_id)->where('channel', 'sms')->update([
                'route' => 'phone',
                'sms_outbox_id' => $row->id,
                'status' => match (true) {
                    $row->status === 'failed' => 'failed',
                    $row->delivered_at !== null => 'delivered',
                    $row->status === 'sent' => 'sent',
                    default => 'waiting',
                },
                'sent_at' => $row->sent_at,
                'delivered_at' => $row->delivered_at,
                'error' => $row->error,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('party_message_optouts');

        Schema::table('party_message_templates', function (Blueprint $table) {
            $table->dropColumn(['wa_name', 'wa_status']);
        });

        Schema::table('party_messages', function (Blueprint $table) {
            $table->dropIndex('pm_company_status_idx');
            $table->dropIndex('pm_provider_ref_idx');
            $table->dropIndex('pm_rule_party_idx');
            $table->dropColumn(['status', 'route', 'via', 'rule', 'error', 'sent_at', 'delivered_at', 'read_at',
                'provider_ref', 'sms_outbox_id', 'fallback_of_id']);
        });
    }
};
