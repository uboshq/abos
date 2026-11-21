<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff talked about receipts and deposits on WhatsApp, and the company kept nothing — messenger phase 1
 * (owner "ok", 21 Nov 2026; sample 3nuwRUdn v5; contract docs/Contract — বার্তা ও কল API).
 *
 * ⭐ Five tables:
 *   - threads (one-to-one and groups)
 *   - members (with the delivered and read marks, and mute)
 *   - messages
 *   - files (on the private disk)
 *   - calls (the log; the media itself runs on the office LiveKit and is never stored)
 *
 * ⓘ A member's marks are plain message ids. Delivered and read are "up to this id", never one row per message,
 * so a group of 20 does not write 20 rows for every message.
 *
 * ⚠️ Index names are written out by hand, under 64 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messenger_threads', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);                     // direct | group
            $table->string('title', 120)->nullable();       // groups only
            /* ⛔ one direct thread per pair: "smaller id:larger id"; null for groups */
            $table->string('direct_key', 40)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'direct_key'], 'msg_threads_direct_unique');
            $table->index(['company_id', 'last_at'], 'msg_threads_company_last');
        });

        Schema::create('messenger_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained('messenger_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->boolean('is_admin')->default(false);
            $table->boolean('muted')->default(false);
            $table->unsignedBigInteger('delivered_id')->default(0);
            $table->unsignedBigInteger('read_id')->default(0);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['thread_id', 'user_id'], 'msg_members_thread_user');
            $table->index(['user_id', 'company_id'], 'msg_members_user_company');
        });

        Schema::create('messenger_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained('messenger_threads')->cascadeOnDelete();
            /* ⓘ null on system lines ("X added Y") */
            $table->foreignId('sender_id')->nullable()->constrained('users')->restrictOnDelete();
            /* ⭐ the phone's own id — the same send twice stores one message */
            $table->string('client_id', 64)->nullable();
            $table->string('kind', 10);                     // text | image | file | voice | paper | location | system
            $table->text('text')->nullable();
            $table->unsignedBigInteger('reply_to_id')->nullable();
            /* ⓘ an ERP paper: the server's class name + public_id, with its number and title kept at send time */
            $table->string('paper_type', 80)->nullable();
            $table->string('paper_id', 64)->nullable();
            $table->string('paper_no', 60)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->json('meta')->nullable();
            /* ⓘ a copy sent on from another thread (owner, sample v7) */
            $table->boolean('forwarded')->default(false);
            /*
             * ⭐ "delete for everyone" = a tombstone: members see "এই বার্তাটি মুছে ফেলা হয়েছে"; the row and its file stay
             * for the written-reason audit door only ([[MessengerAudit]]).
             */
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['sender_id', 'client_id'], 'msg_messages_sender_client');
            $table->index(['thread_id', 'id'], 'msg_messages_thread_id');
            $table->index(['thread_id', 'kind'], 'msg_messages_thread_kind');
        });

        Schema::create('messenger_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('messenger_messages')->cascadeOnDelete();
            $table->string('kind', 10);                     // image | file | voice
            $table->string('path');                         // private disk
            $table->string('thumb_path')->nullable();
            $table->string('name', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('waveform')->nullable();
            $table->timestamps();
        });

        /* ⓘ "delete for me" — hidden for that one member only */
        Schema::create('messenger_hidden', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('messenger_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['message_id', 'user_id'], 'msg_hidden_message_user');
            $table->index(['user_id', 'message_id'], 'msg_hidden_user_message');
        });

        /*
         * ⭐ Message info ("পৌঁছেছে 10:41 · পড়েছেন 10:44") — every time a member's mark moves, one row: up to which
         * message, and when. ⓘ A message's delivered_at for a member = the first row of that kind whose `up_to` ≥ it.
         * One row per mark move, not one per message per member.
         */
        Schema::create('messenger_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('messenger_members')->cascadeOnDelete();
            $table->string('kind', 10);                     // delivered | read
            $table->unsignedBigInteger('up_to');
            $table->timestamp('at');

            $table->index(['member_id', 'kind', 'up_to'], 'msg_marks_member_kind_upto');
        });

        Schema::create('messenger_calls', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->nullable()->constrained('messenger_threads')->nullOnDelete();
            $table->foreignId('caller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('callee_id')->constrained('users')->restrictOnDelete();
            $table->boolean('video')->default(false);
            $table->string('status', 10);                   // ringing | answered | declined | missed | ended
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_s')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'msg_calls_status_created');
            $table->index(['callee_id', 'created_at'], 'msg_calls_callee_created');
            $table->index(['caller_id', 'created_at'], 'msg_calls_caller_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messenger_calls');
        Schema::dropIfExists('messenger_marks');
        Schema::dropIfExists('messenger_hidden');
        Schema::dropIfExists('messenger_files');
        Schema::dropIfExists('messenger_messages');
        Schema::dropIfExists('messenger_members');
        Schema::dropIfExists('messenger_threads');
    }
};
