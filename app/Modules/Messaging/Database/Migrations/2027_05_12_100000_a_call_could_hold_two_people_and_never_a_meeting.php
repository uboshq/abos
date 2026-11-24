<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meetings and group calls — one engine (plan handoff/meeting-plan.md, sample VxwPoxm77whS6ToNqsyZPU tab 3).
 *
 * ⭐ A meeting, its people (one row each), and an append-only book of what the hosts did. The voice and picture run on the
 *   office LiveKit and are never stored; nothing is recorded (decided 24 Nov 2026).
 * ⓘ A group call is a meeting with `thread_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messenger_meetings', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies', 'id', 'msg_meet_company_fk')->cascadeOnDelete();
            $table->foreignId('thread_id')->nullable()->constrained('messenger_threads', 'id', 'msg_meet_thread_fk')->nullOnDelete();
            $table->foreignId('host_id')->constrained('users', 'id', 'msg_meet_host_fk')->restrictOnDelete();
            // ⓘ the page's own id for the press — two presses make one meeting
            $table->string('client_key', 40);
            $table->string('title', 120);
            $table->boolean('video')->default(false);
            $table->boolean('locked')->default(false);
            $table->boolean('lobby')->default(true);
            // everyone | hosts
            $table->string('allow_share', 10)->default('everyone');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('empty_since')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->unique(['host_id', 'client_key'], 'msg_meet_one_per_press');
            $table->index(['thread_id', 'ended_at'], 'msg_meet_thread_open');
            $table->index(['ended_at', 'empty_since'], 'msg_meet_open_empty');
        });

        Schema::create('messenger_meeting_people', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies', 'id', 'msg_meetp_company_fk')->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained('messenger_meetings', 'id', 'msg_meetp_meeting_fk')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users', 'id', 'msg_meetp_user_fk')->restrictOnDelete();
            // host | cohost | member
            $table->string('role', 8)->default('member');
            // invited | waiting | in | left | removed | declined
            $table->string('state', 10);
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            // ⓘ the LiveKit session of this person — an old session's "left" must not put out a newer one
            $table->string('session', 64)->nullable();
            $table->unsignedInteger('seconds')->default(0);
            $table->timestamps();

            $table->unique(['meeting_id', 'user_id'], 'msg_meetp_once');
            $table->index(['user_id', 'state'], 'msg_meetp_user_state');
        });

        Schema::create('messenger_meeting_events', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies', 'id', 'msg_meete_company_fk')->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained('messenger_meetings', 'id', 'msg_meete_meeting_fk')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users', 'id', 'msg_meete_actor_fk')->restrictOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('users', 'id', 'msg_meete_person_fk')->restrictOnDelete();
            $table->string('what', 24);
            $table->timestamps();

            $table->index(['meeting_id', 'id'], 'msg_meete_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messenger_meeting_events');
        Schema::dropIfExists('messenger_meeting_people');
        Schema::dropIfExists('messenger_meetings');
    }
};
