<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১ — মূল ইঞ্জিন (মালিকের স্পেক, ১০ অক্টোবর ২০২৬, §১১)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * প্রতিটা খবর কেবল একজনের জন্য একটা সারি (`notifications`): কে পেলেন, কী লেখা, পড়েছেন কি না — সব এক জায়গায়। ঘটনাটা
 * নিজে কোথাও লেখা থাকত না। তাই একই ঘটনা দুইবার এলে (ক্রন দুইবার চলল, একই সই দুই জায়গা থেকে) দুইটা খবর যেত, কোন
 * কাগজের খবর তা জানা যেত না (শাখার দেয়াল বসানোর উপায় ছিল না), আর পড়া ছাড়া আর কোনো অবস্থা ছিল না।
 *
 * ── ⭐ এখন — স্পেকের টেবিলগুলো এই কোডবেসে কীভাবে বসল ─────────────────
 *   · `notification_events` = স্পেকের `notifications` — ঘটনার বিষয়বস্তু, একবারই: মডিউল, ধরন, শ্রেণি, গুরুত্ব, কোন
 *     কাগজ (`subject_type/subject_id`), কোন শাখা, আর idempotency চাবি (কোম্পানিতে অনন্য — একই ঘটনা দুইবার নয়)।
 *   · `notifications` = স্পেকের `notification_recipients` — প্রত্যেক প্রাপকের নিজের অবস্থা: পড়া, দেখা, আর্কাইভ।
 *     ⓘ শিরোনাম-বার্তা-ঠিকানা এখানেও থাকে (পুরনো সারি, ফোনের API আর চিঠি এগুলোই পড়ে) — ঘটনার কপি, নিজের ভাষায় নয়।
 *     একই ঘটনায় একজনের একটাই সারি (`event_id + user_id` অনন্য)।
 *   · `notification_audit_logs` — কে কখন কোন খবরে কী করলেন, আর ফল (§১৩): সব পড়া, আর্কাইভ, অনুমতি ছাড়া খোলার চেষ্টা,
 *     কেন্দ্রের কাজ। ⛔ গোপন কিছু নয় — কেবল সংখ্যা আর আইডি।
 *
 * ⓘ পুরনো সারি যেমন ছিল তেমনই চলে: `event_id` খালি, শ্রেণি-গুরুত্ব-মডিউল ধরন থেকে একবার বসানো হয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            /* কোন মডিউলের কোন ঘটনা — `sales`, `sales.order_awaits_you` */
            $table->string('module', 32);
            $table->string('type', 64);

            /* approval · task · system · update — ঘণ্টার ছাঁকনি; critical · high · normal · low */
            $table->string('category', 16);
            $table->string('priority', 8);

            $table->string('title', 191);
            $table->string('body', 500)->nullable();
            $table->string('url', 500)->nullable();

            /* কোন কাগজের খবর — খোলার আগে দেখা হয় প্রাপক কাগজটা দেখতে পান কি না */
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            /* একই ঘটনা দুইবার নয় — ডাকা জায়গা চাবি দেয় (যেমন `approval:12:rejected`) */
            $table->string('idempotency_key', 191)->nullable();

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('recipients')->default(0);

            /* কেন্দ্র থেকে আর্কাইভ — প্রাপকের নিজের ঘণ্টা বদলায় না */
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key'], 'notify_event_idem');
            $table->index(['company_id', 'created_at'], 'notify_event_when');
            $table->index(['company_id', 'module', 'type'], 'notify_event_kind');
            $table->index(['subject_type', 'subject_id'], 'notify_event_subject');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->after('user_id')
                ->constrained('notification_events')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('company_id')
                ->constrained('branches')->nullOnDelete();
            $table->string('module', 32)->nullable()->after('type');
            $table->string('category', 16)->default('task')->after('module');
            $table->string('priority', 8)->default('normal')->after('category');
            $table->string('subject_type', 64)->nullable()->after('url');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
            $table->timestamp('seen_at')->nullable()->after('read_at');
            $table->timestamp('archived_at')->nullable()->after('seen_at');

            $table->unique(['event_id', 'user_id'], 'notify_recipient_once');
            $table->index(['user_id', 'archived_at', 'read_at'], 'notify_recipient_inbox');
        });

        Schema::create('notification_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            /* read_all · bulk_read · archive · restore · open_denied · center_archive · center_restore … */
            $table->string('action', 48);
            $table->string('target_type', 32)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();

            /* done · denied · failed */
            $table->string('outcome', 16);

            /* ⛔ কেবল সংখ্যা আর আইডি — পাসওয়ার্ড, টোকেন বা বার্তার লেখা কখনো নয় */
            $table->json('detail')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'created_at'], 'notify_audit_when');
            $table->index(['company_id', 'action'], 'notify_audit_action');
        });

        $this->classifyOldRows();
    }

    /**
     * ⓘ পুরনো সারির শ্রেণি, গুরুত্ব আর মডিউল — ধরন ধরে একবার। নিয়মটা [[NotificationKinds::classify()]]-এর; এখানে তার
     * একটা স্থির কপি, কারণ মাইগ্রেশন পরে কোড বদলালেও একই ফল দেবে।
     */
    private function classifyOldRows(): void
    {
        $types = DB::table('notifications')->whereNull('module')->distinct()->pluck('type');

        foreach ($types as $type) {
            $type = (string) $type;
            $module = str_contains($type, '.') ? strstr($type, '.', true) : 'system';

            [$category, $priority] = match (true) {
                $type === 'approval.approved' => ['approval', 'low'],
                $type === 'approval.rejected' => ['approval', 'normal'],
                str_starts_with($type, 'approval.') => ['approval', 'high'],
                $type === 'sales.order_awaits_you' => ['approval', 'high'],
                in_array($type, ['sales.order_credit_held', 'sales.signed_challan_stuck', 'sales.signed_sale_stuck', 'finance.rent_overdue'], true) => ['task', 'high'],
                $type === 'sales.delivery_stage', $type === 'report_ready' => ['update', 'low'],
                $type === 'backup.failed' => ['system', 'critical'],
                default => ['task', 'normal'],
            };

            DB::table('notifications')->where('type', $type)->whereNull('module')
                ->update(['module' => $module, 'category' => $category, 'priority' => $priority]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_audit_logs');

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique('notify_recipient_once');
            $table->dropIndex('notify_recipient_inbox');
            $table->dropConstrainedForeignId('event_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['module', 'category', 'priority', 'subject_type', 'subject_id', 'seen_at', 'archived_at']);
        });

        Schema::dropIfExists('notification_events');
    }
};
