<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * ⭐ নতুন "ABOS" (`rose` চাবি) সবার রূপ — মালিকের অনুমোদিত নমুনা, ১৯ নভেম্বর ২০২৬ (claude.ai artifact AAjAfjZ8TnCfnjtt3Kthjz v11)।
 *
 * ── কারা সরেন ─────────────────────────────────────────────────────────
 * ⭐ সবাই — মালিক, ১৯ নভেম্বর ২০২৬: *"সবার জন্য বাই ডিফল্ট … একদম সবার জন্য … সকালবেলায় এটাই ওপেন হবে"*। আগে কে কোন রূপ বেছেছিলেন
 * (ABOS Navy, ABOS Blue, Dynamic, SAP …) বা কিছুই বাছেননি (খালি/null) — সবার ঘরে `rose`। ⓘ পরে যে কেউ চেহারার পাতা থেকে
 * নিজে অন্য রূপ বাছতে পারেন; কেবল শুরুটা সবার এক।
 *
 * ── ফেরার পথ ───────────────────────────────────────────────────────────
 * আগের ঘরগুলো হারায় না: `up()` প্রথমে প্রতিটা মানুষের আগের `ui` একটা JSON-এ রাখে (`storage/app/ui-before-abos-look.json`,
 * id => আগের মান), আর `down()` সেখান থেকেই প্রত্যেককে নিজের আগের রূপে ফেরায়। ⓘ ফাইলটা না থাকলে (অন্য যন্ত্রে ফেরানো হলে)
 * `rose`-এ বসা সবাই আগের ডিফল্ট `blue`-তে নামেন — সেটা এখানে লেখা রইল, চুপচাপ নয়।
 */
return new class extends Migration
{
    private const BACKUP = 'ui-before-abos-look.json';

    public function up(): void
    {
        $before = DB::table('users')->orderBy('id')->pluck('ui', 'id')->all();

        // ⓘ আগের একটা ফাইল থাকলে তার উপর লেখা নয় — দুইবার চালালে প্রথমবারের আসল মানগুলোই থাকে
        if (! Storage::disk('local')->exists(self::BACKUP)) {
            Storage::disk('local')->put(self::BACKUP, (string) json_encode($before, JSON_PRETTY_PRINT));
        }

        DB::table('users')->update(['ui' => 'rose']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('ui', 64)->default('rose')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ui', 64)->default('blue')->change();
        });

        $saved = Storage::disk('local')->exists(self::BACKUP)
            ? (array) json_decode((string) Storage::disk('local')->get(self::BACKUP), true)
            : null;

        if ($saved === null) {
            DB::table('users')->where('ui', 'rose')->update(['ui' => 'blue']);

            return;
        }

        foreach ($saved as $id => $ui) {
            DB::table('users')->where('id', (int) $id)->update(['ui' => $ui]);
        }

        Storage::disk('local')->delete(self::BACKUP);
    }
};
