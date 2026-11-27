<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ অফারের মেইল বন্ধ করা (NEXUS-এর পর্যালোচনা R2, ২৭ নভেম্বর ২০২৬; Gmail/Yahoo-র ২০২৪-এর নিয়ম, RFC 8058):
 *   অফার, উৎসব আর দামের তালিকার মেইলে এক চাপে "আর চাই না" — মেইলের নিচের লিংক বা মেইল প্রোগ্রামের নিজের "Unsubscribe" বোতাম।
 *   বন্ধ করা ঠিকানায় এরপর এমন মেইল যায় না; বিল, রসিদ, হিসাবের মেইল (লেনদেনের) যায় — ওগুলো এই নিয়মের বাইরে।
 * ⓘ কোম্পানি ধরে — এক কোম্পানিতে বন্ধ করলে অন্য কোম্পানির মেইল আটকায় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_optouts', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('email', 191);
            $table->string('source', 64)->nullable();        // কোন টেমপ্লেটের মেইল থেকে বন্ধ করা
            $table->string('via', 16)->default('link');       // link = নিচের লিংক, one-click = মেইল প্রোগ্রামের বোতাম
            $table->timestamps();

            $table->unique(['company_id', 'email'], 'mail_optouts_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_optouts');
    }
};
