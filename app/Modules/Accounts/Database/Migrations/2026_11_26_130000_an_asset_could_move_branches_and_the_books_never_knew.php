<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * সম্পদ এক শাখা থেকে আরেক শাখায় যেত, আর খাতা কিছুই জানত না।
 *
 * ── মানচিত্র §১৫: "সম্পদ স্থানান্তর" ─────────────────────────────────
 * ⛔ সম্পদের সারিতে `branch_id` আছে, কিন্তু বদলানোর কোনো পথ ছিল না। ⓘ
 * ফ্রিজটা ঢাকার দোকান থেকে খুলনার দোকানে চলে যেত, কাগজে কিছুই বসত না,
 * আর দুইটা শাখার স্থিতিপত্রই ভুল থাকত — একটায় নেই এমন জিনিস, অন্যটায়
 * আছে এমন জিনিসের অনুপস্থিতি।
 *
 * ── ⚠️ কেন আলাদা একটা টেবিল, কেবল একটা কলাম বদলানো নয় ───────────────
 * এক সম্পদ **বহুবার** সরতে পারে। ⓘ কেবল `branch_id` বদলালে ইতিহাসটা
 * হারাত: "গত বছর এটা কোথায় ছিল" প্রশ্নের উত্তর থাকত না, আর অবচয়ের
 * খরচ কোন শাখার ঘাড়ে ছিল তাও বলা যেত না।
 *
 * ⛔⛔ আর দ্বিতীয় কারণটা যান্ত্রিক, আর সেটাই বেশি জরুরি: প্রতিটা
 * স্থানান্তর খাতায় নিজের দাখিলা বসায়, আর পোস্টিং ইঞ্জিন এক
 * `(উৎস, আইডি)` জোড়ায় **একবারই** পোস্ট করতে দেয়
 * ([[PostingEngine::assertNotAlreadyPosted]])। ⚠️ সারিটা সম্পদের আইডিতে
 * বসালে দ্বিতীয় স্থানান্তরটা নীরবে আটকে যেত — ঠিক যে ফাঁদে সম্পদের
 * **বিদায়** পড়েছিল (২০ সেপ্টেম্বর ২০২৬, [[FixedAsset::disposalSourceType]])।
 *
 * ⓘ নিজের সারি মানে নিজের আইডি, তাই প্রতিটা স্থানান্তরের নিজের চাবি —
 * অবচয় যেভাবে বাঁচে ([[DepreciationEntry]])।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_asset_transfers', function (Blueprint $table) {
            $table->id();
            $table->publicId();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('acc_fixed_assets')->cascadeOnDelete();

            /*
             * ⓘ কোথা থেকে কোথায় — দুইটাই রাখা হয়, কারণ সম্পদের সারিতে
             * কেবল **এখনকার** শাখাটা থাকে। ⚠️ "কোথা থেকে" না রাখলে
             * ইতিহাসটা পড়া যেত না।
             */
            $table->foreignId('from_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->cascadeOnDelete();

            $table->date('moved_on');

            /*
             * ⓘ কেন সরল — ঐচ্ছিক, কিন্তু ছয় মাস পরে এটাই একমাত্র উত্তর।
             */
            $table->string('note', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /* ⚠️ নাম ছোট রাখা — ৬৪ অক্ষরের সীমা ([[NoIndexNameStandsAtTheEdgeTest]]) */
            $table->index(['company_id', 'asset_id', 'moved_on'], 'acc_asset_move_when');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_asset_transfers');
    }
};
