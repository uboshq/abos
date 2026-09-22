<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Finance\Models\ProfitShare;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * মুনাফার ভাগের সারিগুলোর বাইরের কোনো নাম ছিল না।
 *
 * ── ⚠️ কেন এই পরীক্ষাটা আলাদা করে লাগল ──────────────────────────────
 * [[PublicIdTest]] জিজ্ঞেস করে *"কলামটা আছে কি"* — আর সেটাই যথেষ্ট
 * মনে হয়। ⛔ কিন্তু কলাম থাকা আর সারিগুলোর নাম থাকা এক জিনিস নয়।
 *
 * ⓘ লাইভে ঘোষণাগুলো **আগেই বসে গেছে** (২২ সেপ্টেম্বর ২০২৬-এর রাতের
 * ডিপ্লয়ে)। কলামটা nullable, তাই পরে যোগ করলে পুরনো সারিগুলো `null`
 * থেকেই যেত — আর `PublicIdTest` তবু সবুজ, কারণ সে কলাম খোঁজে, মান নয়।
 *
 * ⚠️ ফল: যে ঘোষণাগুলো নিয়ে অংশীদার সবচেয়ে বেশি প্রশ্ন করবেন — পুরনো
 * গুলো — ঠিক সেগুলোর দিকেই বাইরে থেকে কোনো লিংক যেত না।
 *
 * ── ⓘ এই ফাইলটা যা মাপে না ──────────────────────────────────────────
 * মাইগ্রেশনটা লাইভে সত্যি চলেছে কি না, সেটা এখান থেকে জানা যায় না।
 * ⭐ এটা কেবল বলে: **ডাকা হলে ব্যাকফিলটা কাজ করে**।
 */
final class TheProfitRowsHadNoOutsideNameTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⭐ নাম-হীন পুরনো সারি নাম পায়, আর নাম-থাকা সারি বদলায় না।
     *
     * ── ⚠️ দুই পাশ কেন ─────────────────────────────────────────────
     * ⛔ কেবল "খালিটা ভরেছে" মাপলে একটা মাইগ্রেশন যা **সবাইকে** নতুন
     * নাম দেয় সেটাও সবুজ থাকত — আর তখন পুরনো লিংকগুলো একরাতে মরে যেত।
     */
    public function test_the_backfill_names_the_rows_that_had_none(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->switchCompany((int) $company->id);
        $this->be($owner);

        $this->assertTrue(Schema::hasColumn('acc_profit_shares', 'public_id'), implode("\n", [
            '`acc_profit_shares`-এ `public_id` নেই।',
            '',
            'ⓘ তাহলে ফলো-আপ মাইগ্রেশনটাই চলেনি, আর নিচের দাবিটা',
            'কিছুই মাপত না।',
        ]));

        $nameless = $this->share('PDS-OLD');
        $named = $this->share('PDS-NEW');

        // ⓘ লাইভের অবস্থাটা বানানো: কলামটা আছে, সারিটার নাম নেই
        DB::table('acc_profit_shares')->where('id', $nameless->id)->update(['public_id' => null]);

        $keep = (string) DB::table('acc_profit_shares')->where('id', $named->id)->value('public_id');

        $this->assertNotSame('', $keep, 'নতুন সারিটাই নাম পায়নি — [[HasPublicId]] বসেনি?');

        /*
         * ⭐ মাইগ্রেশনটা নিজে ডাকা। ⓘ ব্যাকফিলটা শর্তের বাইরে রাখা
         * হয়েছে বলেই দ্বিতীয়বার ডাকা যায় — নাহলে কলাম আছে দেখে
         * আগেভাগে ফিরে যেত, আর এই দাবিটা ফাঁকা হয়ে যেত।
         */
        $migration = require base_path(
            'app/Modules/Finance/Database/Migrations/2026_12_02_100000_the_profit_rows_had_no_outside_name.php'
        );

        $migration->up();

        $filled = DB::table('acc_profit_shares')->where('id', $nameless->id)->value('public_id');

        $this->assertNotNull($filled, implode("\n", [
            'পুরনো সারিটা এখনো নাম-হীন।',
            '',
            '⛔ কলামটা আছে বলে পাহারা সবুজ, অথচ সারিটার দিকে বাইরে থেকে',
            'কোনো লিংক যাবে না।',
        ]));

        $this->assertSame(36, strlen((string) $filled), 'নামটা uuid-এর আকারের নয়: '.$filled);

        $this->assertSame($keep, (string) DB::table('acc_profit_shares')->where('id', $named->id)->value('public_id'),
            implode("\n", [
                'যে সারির নাম আগেই ছিল সেটাও বদলে গেছে।',
                '',
                '⛔ তাহলে বাইরে দেওয়া পুরনো প্রতিটা লিংক এক রাতে মরত।',
            ]));
    }

    private function share(string $documentNo): ProfitShare
    {
        $person = Person::query()->create([
            'code' => $documentNo,
            'name_en' => $documentNo,
            'name_bn' => $documentNo,
            'is_active' => true,
        ]);

        return ProfitShare::query()->create([
            'branch_id' => Branch::query()->firstOrFail()->id,
            'document_no' => $documentNo,
            'trx_date' => now()->subDay()->toDateString(),
            'person_id' => $person->id,
            'share_percent' => '100',
            'profit_base' => '1000',
            'amount' => '1000',
            'status' => ProfitShare::POSTED,
            'posted_at' => now(),
        ]);
    }
}
