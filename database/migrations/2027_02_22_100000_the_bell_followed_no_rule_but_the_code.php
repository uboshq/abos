<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৩ — নিয়ম আর টেমপ্লেট (মালিকের স্পেক §৪, §৮, §৯গ, §১১, §১৪; ১০ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * কে কোন খবর পাবেন, কী লেখায়, কোন মাধ্যমে — সব কোডে বাঁধা। "এক লাখের বেশি ফেরত গেলে হিসাবরক্ষককেও জানাও" বলতে হলে
 * প্রোগ্রামার লাগত; লেখা বদলাতে হলেও তাই। রাত তিনটায় সাধারণ খবরও ফোনে বাজত, আর কেউ দিনে একবার একসাথে পেতে পারতেন না।
 *
 * ── ⭐ এখন ───────────────────────────────────────────────────────────
 *   · `notification_templates` + `notification_template_versions` — বাংলা ও ইংরেজি লেখা, কেবল অনুমোদিত চলক
 *     (`{amount}`), সংস্করণ, প্রকাশ, আগের সংস্করণে ফেরা।
 *   · `notification_rules` + `notification_rule_versions` — কোন মডিউলের কোন ঘটনায়, কোন শর্তে, কার কাছে, কোন মাধ্যমে,
 *     কোন গুরুত্বে, কোন টেমপ্লেটে, কত দেরিতে, কতক্ষণ বৈধ; চালু/বন্ধ, কার্যকর তারিখ, প্রতিটা বদলের পুরো ছবি।
 *   · `notification_recipient_groups` — নাম দেওয়া প্রাপক-দল: মানুষ, রোল, শাখা, বিভাগ।
 *   · `notification_schedules` — সূচিমতো খবর: কখন, কোন সময় অঞ্চলে, কতবার ফিরে আসে।
 *   · `notification_preferences` — প্রত্যেকের নিজের পছন্দ: মাধ্যম, চুপ করা শ্রেণি, কত ঘন ঘন, নীরব সময়।
 *   · `notification_digests` — দিনে বা সপ্তাহে একবারের সারসংক্ষেপ চিঠি।
 *   · `notification_suppressions` — কোন খবর কেন আটকানো হলো (একই খবর বারবার, নীরব সময়, পছন্দ)।
 *   · ঘটনায় (`notification_events`): কোন টেমপ্লেট-সংস্করণ, চলকের মান, নিয়মের ঠিক করা মাধ্যম, কোন নিয়মগুলো খাটল, মেয়াদ,
 *     আর বাইরের মাধ্যমে কখন থেকে।
 *
 * ⛔ কোনো AI নেই — শর্ত মানে কেবল তুলনা (=, ≠, >, ≥, <, ≤, এর-মধ্যে)। নিয়ম কেবল জানায়, কোনো কাগজ বদলায় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 48);
            $table->string('name', 120);
            $table->string('category', 16)->default('task');
            /* কোন মাধ্যমের জন্য লেখা — খালি মানে সবগুলো */
            $table->json('channels')->nullable();
            /* প্রকাশিত সংস্করণ — এটাই খবরে বসে; খালি মানে এখনো খসড়া */
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'notify_tpl_code');
        });

        Schema::create('notification_template_versions', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('notification_templates')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('subject_bn', 191)->nullable();
            $table->string('subject_en', 191)->nullable();
            $table->string('title_bn', 191);
            $table->string('title_en', 191);
            $table->string('body_bn', 1000)->nullable();
            $table->string('body_en', 1000)->nullable();
            /* লেখায় যে চলকগুলো আছে — সংরক্ষণের সময় যাচাই করা, অনুমোদিত তালিকার বাইরে কিছু নয় */
            $table->json('variables')->nullable();
            $table->string('note', 191)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['template_id', 'version'], 'notify_tpl_version');
        });

        Schema::create('notification_recipient_groups', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 120);
            /* {users: [..], roles: [..], branches: [..], departments: [..]} — প্রতিটা আইডি */
            $table->json('members');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('module', 32);
            /* কোন ঘটনা — বিজ্ঞপ্তির ধরন (`approval.rejected`) */
            $table->string('event', 64);
            /* খালি মানে সব শাখা; থাকলে কেবল সেই শাখার কাগজের ঘটনা */
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            /* [{field, op, value}] — সবগুলো মিললে তবে নিয়ম খাটে */
            $table->json('conditions')->nullable();
            /* {users, roles, branches, departments, groups, responsible} */
            $table->json('recipients')->nullable();
            /* খালি মানে স্বাভাবিক পথ (গুরুত্ব আর পছন্দ ধরে) */
            $table->json('channels')->nullable();
            $table->string('priority', 8)->nullable();
            $table->foreignId('template_id')->nullable()->constrained('notification_templates')->nullOnDelete();
            /* বাইরের মাধ্যমে কত মিনিট পরে; ঘণ্টায় সাথে সাথেই */
            $table->unsignedInteger('delay_minutes')->default(0);
            /* কত মিনিট পরে খবরটা আর বাইরে পাঠানোর মানে নেই */
            $table->unsignedInteger('expires_minutes')->nullable();
            /* একই কাগজের একই খবর একজনের কাছে এতক্ষণের মধ্যে আবার নয় */
            $table->unsignedInteger('cooldown_minutes')->default(0);
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'event', 'is_active'], 'notify_rule_event');
        });

        Schema::create('notification_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('rule_id')->constrained('notification_rules')->cascadeOnDelete();
            $table->unsignedInteger('version');
            /* সেই সংস্করণের পুরো ছবি */
            $table->json('snapshot');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['rule_id', 'version'], 'notify_rule_version');
        });

        Schema::create('notification_schedules', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 120);
            $table->foreignId('template_id')->constrained('notification_templates')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('notification_recipient_groups')->cascadeOnDelete();
            $table->string('priority', 8)->default('normal');
            $table->string('timezone', 48)->default('Asia/Dhaka');
            /* none · daily · weekly · monthly */
            $table->string('recurrence', 8)->default('none');
            /* পরের পাঠানো — অ্যাপের সময় অঞ্চলে রাখা (বাকি সব সময়ের মতো); হিসাব সূচির সময় অঞ্চল ধরে */
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('runs')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'next_run_at'], 'notify_schedule_due');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            /* {email: bool, web_push: bool, mobile_push: bool, sms: bool} — না থাকা মানে চালু */
            $table->json('channels')->nullable();
            /* এই শ্রেণিগুলো বাইরের মাধ্যমে চুপ (ঘণ্টায় থাকে); জরুরি কখনো চুপ হয় না */
            $table->json('muted_categories')->nullable();
            /* instant · daily · weekly — সাধারণ আর কম গুরুত্বের চিঠি */
            $table->string('frequency', 8)->default('instant');
            $table->unsignedTinyInteger('digest_hour')->default(9);
            /* সাপ্তাহিকে কোন দিন — ০ রবিবার … ৬ শনিবার */
            $table->unsignedTinyInteger('digest_day')->default(0);
            $table->boolean('quiet_enabled')->default(false);
            $table->time('quiet_start')->nullable();
            $table->time('quiet_end')->nullable();
            $table->string('timezone', 48)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id'], 'notify_pref_user');
        });

        Schema::create('notification_digests', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            /* daily · weekly */
            $table->string('period', 8);
            /* কোন দিনের বা সপ্তাহের — একই সময়ের সারসংক্ষেপ একবারই */
            $table->string('period_key', 16);
            $table->unsignedInteger('items')->default(0);
            /* sent · failed */
            $table->string('status', 8);
            $table->string('error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'company_id', 'period_key'], 'notify_digest_once');
        });

        Schema::create('notification_suppressions', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('notification_events')->nullOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('notification_rules')->nullOnDelete();
            $table->string('type', 64);
            $table->string('channel', 16)->nullable();
            /* cooldown · quiet_hours · preference · muted · expired · digest */
            $table->string('reason', 16);
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'created_at'], 'notify_suppress_when');
            $table->index(['company_id', 'reason'], 'notify_suppress_reason');
        });

        Schema::table('notification_events', function (Blueprint $table) {
            $table->unsignedBigInteger('template_version_id')->nullable()->after('url');
            /* টেমপ্লেটের চলকের মান — কেবল অনুমোদিত চলক, ছোট লেখা; গোপন কিছু নয় */
            $table->json('data')->nullable()->after('template_version_id');
            $table->json('channels')->nullable()->after('data');
            $table->json('rule_ids')->nullable()->after('channels');
            $table->timestamp('deliver_after')->nullable()->after('rule_ids');
            $table->timestamp('expires_at')->nullable()->after('deliver_after');
        });

        Schema::table('notification_jobs', function (Blueprint $table) {
            /* ডাইজেস্টে ধরে রাখা চিঠি কোন সারসংক্ষেপে গেল */
            $table->foreignId('digest_id')->nullable()->after('event_id')->constrained('notification_digests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notification_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('digest_id');
        });

        Schema::table('notification_events', function (Blueprint $table) {
            $table->dropColumn(['template_version_id', 'data', 'channels', 'rule_ids', 'deliver_after', 'expires_at']);
        });

        Schema::dropIfExists('notification_suppressions');
        Schema::dropIfExists('notification_digests');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_schedules');
        Schema::dropIfExists('notification_rule_versions');
        Schema::dropIfExists('notification_rules');
        Schema::dropIfExists('notification_recipient_groups');
        Schema::dropIfExists('notification_template_versions');
        Schema::dropIfExists('notification_templates');
    }
};
