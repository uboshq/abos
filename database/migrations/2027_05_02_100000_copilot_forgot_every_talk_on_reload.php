<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ The full-screen Copilot page keeps its talks (owner's web sample copilot-web-sample, tab খ, 21 Nov 2026).
 *
 * ── ⚠️ What was there before ─────────────────────────────────────────
 * The quick ask (top bar) and the old ask page forgot every question on reload. `assistant_events` holds each
 * question and answer as an audit line for the owner — not as a talk a person can reopen, and it keeps no rows,
 * no table, no sources.
 *
 * ── ⭐ What this adds ────────────────────────────────────────────────
 * `assistant_conversations` — one talk = one row, per person AND per company (the company wall comes from
 *   [[BelongsToCompany]], the person from `user_id`; the page reads only `user_id = me` in the current company).
 *   `messages` holds the questions and the answers exactly as they were shown (text, table, small chart, sources).
 * ⓘ No key is added: the page sits behind `assistant.use`, like the rest of Copilot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // ⓘ the first question, cut — the line in the history panel
            $table->string('title', 191);
            $table->json('messages');
            $table->timestamp('last_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'user_id', 'last_at'], 'assistant_conversations_mine_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_conversations');
    }
};
