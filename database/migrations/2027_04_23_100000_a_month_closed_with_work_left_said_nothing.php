<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ⭐ মাস বন্ধের সময় যা বাকি ছিল — তালায় লেখা থাকে (মাস বন্ধ যাচাই, ২০ নভেম্বর ২০২৬; [[PeriodLockController::close()]])।
 *
 * ⓘ আগে ব্যাংক না মেলা, নগদ না গোনা বা অবচয় না বসা মাসও চুপচাপ বন্ধ হত — কারণ ঐচ্ছিক, আর কী বাকি ছিল কোথাও লেখা থাকত না।
 *   এখন বাকি থাকলে কারণ লাগে (দিন বন্ধের একই নিয়ম, মালিক ১৮ নভেম্বর), আর বাকিগুলো এখানে: চেকলিস্টের চাবি => কতগুলো।
 *   খালি = সব পরিষ্কার ছিল। পুরনো তালায় খালি — তখন কেউ মাপেনি।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('period_locks', 'open_items')) {
            return;
        }

        Schema::table('period_locks', function (Blueprint $table): void {
            $table->json('open_items')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('period_locks', 'open_items')) {
            return;
        }

        Schema::table('period_locks', function (Blueprint $table): void {
            $table->dropColumn('open_items');
        });
    }
};
