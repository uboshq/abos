<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ পণ্যের নিজের আনলোড দর (কার্টনপ্রতি) — মালিক, ১৮ নভেম্বর ২০২৬ ("ওকে")।
 *
 * ⓘ বড় কার্টন নামাতে ১ টাকা, খুব বড়টায় ৫ টাকা — বাকিরা সাধারণ দরে। দরটা পণ্যে বসানো থাকলে মাল গ্রহণ আর সরাসরি ক্রয়ের
 *   সারিতে নিজে থেকে বসে (হাতে বদলানো যায়); খালি = বাকিদের সাথে সমান ভাগ ([[UnloadingSplit]])।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_products', function (Blueprint $t): void {
            $t->decimal('unload_rate', 18, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inv_products', fn (Blueprint $t) => $t->dropColumn('unload_rate'));
    }
};
