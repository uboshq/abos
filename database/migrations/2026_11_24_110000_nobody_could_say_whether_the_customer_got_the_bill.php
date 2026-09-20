<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * গ্রাহক বিলটা পেয়েছে কি না, কেউ বলতে পারত না।
 *
 * ── কী ছিল ──────────────────────────────────────────────────────────
 * ছাপার গোনা ছিল কেবল বিক্রয়ের কাগজে (`sal_print_jobs`), আর সেটাও
 * কেবল "ছাপা হয়েছে" বলত। ⛔ ভাউচার, বেতন-স্লিপ, লেবেল, ক্রয়ের বিল —
 * কোনোটাই গোনা হত না, আর কাগজ পাঠানোর কোনো পথই ছিল না।
 *
 * ── মালিকের চাওয়া, ২০ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * *"কয়টা কাগজ প্রিন্ট হল কয়টা শেয়ার হইল এটা যাতে একটা হিসাব থাকে"*,
 * আর সাথে: *"sathe pdf o zate dwa zay"*।
 *
 * ── দুইটা টেবিল, আর কেন ──────────────────────────────────────────────
 * `doc_deliveries` — প্রতিটা ঘটনা একটা সারি: ছাপা হলো, নামানো হলো,
 * নাকি গ্রাহক লিংক খুলল। ⓘ গোনা নয়, **ঘটনা** — "কবে" প্রশ্নের উত্তর
 * গোনায় থাকে না, আর "গ্রাহক পেয়েছে" আর "আমরা পাঠিয়েছি" দুইটা আলাদা
 * সত্য।
 *
 * `doc_shares` — একটা গোপন লিংক: কোন কাগজ, কোন মাপে, কবে মরে যাবে।
 * ⚠️ চাবিটাই একমাত্র পাহারা, তাই সেটা অনুমান করা যায় না এমন হতে হবে
 * (৬৪ অক্ষর), আর নিজে থেকেই মেয়াদ শেষ হয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_shares', function (Blueprint $table) {
            $table->id();
            $table->publicId();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            /*
             * ⓘ কাগজটা কোন রুটে আঁকা হয়, সেটাই রাখা — আলাদা করে দ্বিতীয়
             * একটা আঁকার পথ নয়। ⚠️ দুইটা পথ থাকলে একদিন একটায় ভ্যাটের
             * সারি যোগ হত, অন্যটায় না, আর গ্রাহকের কপি আর আমাদের কপি
             * দুই রকম হয়ে যেত — কেউ পর্দা দেখে ধরতেও পারত না।
             */
            $table->string('route_name', 120);
            $table->json('route_params');

            // কোন নথি — গোনা আর তালিকার জন্য
            $table->string('document_type', 40);
            $table->unsignedBigInteger('document_id');
            $table->string('document_no', 60)->nullable();
            $table->string('paper', 16);

            /*
             * ⛔ চাবিটা ক্রমিক নয়, নথির নম্বরও নয় — এলোমেলো ৬৪ অক্ষর।
             * ⓘ নম্বর ধরে বানানো লিংক মানে একজন গ্রাহক পরের গ্রাহকের বিল
             * অনুমান করে খুলে ফেলতে পারতেন।
             */
            $table->char('token', 64)->unique();

            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('opened_count')->default(0);
            $table->timestamp('last_opened_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'document_type', 'document_id'], 'doc_shares_document');
        });

        Schema::create('doc_deliveries', function (Blueprint $table) {
            $table->id();
            $table->publicId();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('document_type', 40);
            $table->unsignedBigInteger('document_id');
            $table->string('document_no', 60)->nullable();
            $table->string('paper', 16);

            // printed · downloaded · shared · opened
            $table->string('how', 16);

            $table->foreignId('share_id')->nullable()->constrained('doc_shares')->nullOnDelete();

            /*
             * ⓘ লিংক খোলার সময় কেউ লগ-ইন থাকে না, তাই `created_by` ফাঁকা।
             * ⚠️ তখন "কোথা থেকে" বলার একমাত্র সূত্র IP — পুরো ঠিকানা নয়,
             * কেবল যতটুকু "গ্রাহক নাকি অন্য কেউ" বুঝতে লাগে।
             */
            $table->string('from_ip', 45)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'document_type', 'document_id'], 'doc_deliveries_document');
            $table->index(['company_id', 'how', 'created_at'], 'doc_deliveries_how');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_deliveries');
        Schema::dropIfExists('doc_shares');
    }
};
