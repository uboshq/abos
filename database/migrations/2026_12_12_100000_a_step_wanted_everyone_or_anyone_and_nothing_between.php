<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * এক ধাপে হয় সবার সই লাগত, নয় একজনের।
 *
 * ── ⛔ যা ভাঙা ছিল, ২৪ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * `requires_all` — দুইটাই চরম। ⚠️ *"তিনজনের মধ্যে যেকোনো দুইজন"* বলা
 * যেত না, অথচ বাস্তবে কমিটি ওভাবেই চলে: একজন ছুটিতে থাকলেও কাজ আটকায় না।
 *
 * ⓘ ফল: হয় প্রতিটা সই লাগত (আর একজনের অনুপস্থিতিতে সব আটকাত), নয়
 * একজনের সইতেই কাজ হত (আর কমিটি বলে কিছু থাকত না)।
 *
 * ── ⭐ পুরনো আচরণ অবিকল ─────────────────────────────────────────────
 * ডিফল্ট ১, আর `requires_all` চালু থাকলে সে-ই জেতে — তাই আজকের কোনো
 * প্রবাহের আচরণ এক চুলও বদলায় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_flow_steps', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_approvals')->default(1)->after('requires_all');
        });
    }

    public function down(): void
    {
        Schema::table('approval_flow_steps', function (Blueprint $table) {
            $table->dropColumn('min_approvals');
        });
    }
};
