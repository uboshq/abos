<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * রুটের হিসাব — কোন বারে কে যান, আর মাসে কত করার কথা (NEXUS §২৭)।
 *
 * ── কী ছিল না ───────────────────────────────────────────────────────
 * রুট এলাকার গাছের সবচেয়ে নিচের ধাপ (`mdm_locations`, স্তর `route`), আর
 * ডিলার `customers.location_id` দিয়ে সেখানে বসেন। ⚠️ কিন্তু **কোন বারে
 * কে ঐ রুটে যান** আর **রুটের মাসের লক্ষ্য কত** — দুইটার কোনোটাই কোথাও
 * লেখা হত না। `Location.assigned_to` একজন মানুষ রাখে, বার নয়, তারিখ নয়,
 * আর কেউ তা পড়েও না (বাঁধনের নকশা, ভাগ ক)।
 *
 * ── কেন বার আর তারিখ, একটা "দায়িত্বে" ঘর নয় ─────────────────────────
 * ডিপোতে রুট মানে সাপ্তাহিক ছক: শনিবার কেন্দুয়া, রবিবার ডুমডি। একই রুটে
 * একাধিক SR থাকতে পারেন (মালিক, ২৬ সেপ্টেম্বর: *একই পয়েন্টে একাধিক SR*)।
 * ⭐ আর হাতবদলে ইতিহাস থাকে (মালিকের উত্তর ৫) — তাই সারি মোছা হয় না,
 * `effective_to` বসে। মার্চে কে যেতেন, সেই প্রশ্নের উত্তর তখনো থাকে।
 *
 * ── কেন রুটের লক্ষ্য আলাদা টেবিল, `sal_targets`-এ ঘর নয় ──────────────
 * `sal_targets` একজন **মানুষের** লক্ষ্য (একজনের এক মাসে একটাই — unique)।
 * রুটের লক্ষ্য একটা **জায়গার**, আর একজন SR কয়েকটা রুট চালান। একই টেবিলে
 * `location_id` ঘর বসালে unique নিয়মটা ভাঙতে হত, আর "লক্ষ্য কত" প্রশ্নের
 * উত্তর দুই রকম সারি মিলিয়ে দিতে হত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sal_route_visits', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // ⓘ কেবল `route` স্তরের সারি — নিয়মটা সেবায় (স্তর ডাটাবেসে বাঁধা যায় না)
            $table->foreignId('route_id')->constrained('mdm_locations')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            // ⓘ Carbon-এর ক্রম: ০ = রবিবার … ৬ = শনিবার; পর্দা শনিবার থেকে সাজায়
            $table->unsignedTinyInteger('weekday');

            $table->date('effective_from');

            // null = এখনো চলছে। ⛔ সারি মোছা হয় না — হাতবদলের ইতিহাস এখানেই
            $table->date('effective_to')->nullable();

            $table->string('narration', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // রুটের পাতা: "এই রুটে কে কবে" — নাম ছোট, ৬৪-র অনেক নিচে
            $table->index(['company_id', 'route_id', 'weekday'], 'sal_rv_route_day_idx');
            $table->index(['company_id', 'user_id'], 'sal_rv_user_idx');
        });

        Schema::create('sal_route_targets', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('route_id')->constrained('mdm_locations')->restrictOnDelete();

            // মাসের প্রথম তারিখ — সবসময় ([[SalesTarget::monthOf()]]-এর একই নিয়ম)
            $table->date('month');
            $table->decimal('amount', 18, 4);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * এক রুটের এক মাসে একটাই লক্ষ্য — ডাটাবেসেই।
             *
             * দুইজন একই সময়ে বসালে সেবার পরীক্ষা দুইবার পাশ করত, আর "লক্ষ্য
             * কত" প্রশ্নের দুইটা উত্তর থাকত।
             */
            $table->unique(['company_id', 'route_id', 'month'], 'sal_rt_route_month_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_route_targets');
        Schema::dropIfExists('sal_route_visits');
    }
};
