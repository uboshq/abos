<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * কাউন্টারের জমা ভুলে যেত কখন এল আর কার হাত দিয়ে।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৫ সেপ্টেম্বর ২০২৬ ──────────────────────────
 * *"জমা যোগ botam clic korle eirokom 100% same pop up open hobe"* —
 * অর্থাৎ কাউন্টারের জমা আদায় ভাউচারের মতোই পূর্ণ হবে।
 *
 * ⓘ আর তিনটা ঘর বাদ, তাঁর নিজের সিদ্ধান্তে: ডিপোজিটরের ধরন ও নাম, আর
 * কোন বিলের বিপরীতে — তিনটার উত্তরই চালান থেকে আগে থেকেই জানা।
 *
 * ── ⛔ দাবিটা কেন সারির উপরে, পর্দার উপরে নয় ────────────────────────
 * ⚠️ চেইনে **চারটা হাতে-বাছা তালিকা** আছে (যাচাই · সারি · ভাউচার ·
 * ইনসার্ট), আর একটাতেও নাম না বসালে ঘরটা নীরবে `null` থাকে। ⓘ পর্দা
 * তখনো ঠিক দেখাত আর ফর্ম ৩০২ দিত — ব্যর্থতাটা দেখা যেত কেবল সারিটা
 * পড়লে।
 *
 * ⓘ abos-13 আজ ঠিক এই আকারে `batch_id` হারিয়েছেন: `fillable`-এ ছিল,
 * যাচাইও হত, তবু `replaceLines()`-এর তালিকায় নাম ছিল না — আর মাল
 * বেরোত অন্য লট থেকে।
 */
final class TheCounterDepositForgotWhenAndFromWhoseHandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        CompanyContext::set((int) Company::query()->where('code', 'TDEPOT')->value('id'));
    }

    /**
     * তিনটা ঘরই সারিতে পৌঁছায়।
     */
    public function test_the_three_fields_reach_the_row(): void
    {
        $row = $this->rowFor([
            'moved_at' => '14:25',
            'carried_by' => $this->owner()->id,
            'note_counts' => ['1000' => 1, '500' => 1],
        ]);

        $this->assertSame('14:25', $row['moved_at'] ?? null, implode(PHP_EOL, [
            '`moved_at` সারিতে পৌঁছায়নি।',
            '',
            '⛔ চেইনে চারটা হাতে-বাছা তালিকা — একটাতেও নাম না বসালে',
            'ঘরটা নীরবে হারায়, আর ফর্ম তবুও ৩০২ দেয়।',
        ]));

        $this->assertSame((int) $this->owner()->id, (int) ($row['carried_by'] ?? 0),
            '`carried_by` সারিতে পৌঁছায়নি।');

        $this->assertSame(['1000' => 1, '500' => 1], $row['note_counts'] ?? null,
            '`note_counts` সারিতে পৌঁছায়নি।');
    }

    /**
     * ⭐ শূন্য গোনাগুলো ফেলে দেওয়া হয়।
     *
     * ⚠️ পর্দা দশটা ঘরই পাঠায়, বিক্রেতা দুই-তিনটা ভরেন। ⛔ সব রাখলে
     * প্রতিটা নগদ জমার সাথে দশটা `0` খতিয়ানে বসত, আর *"৫০০-র নোট কয়টা
     * এসেছিল"* খুঁজতে গিয়ে শূন্যের সারি পেরোতে হত।
     */
    public function test_the_zero_counts_are_dropped(): void
    {
        $row = $this->rowFor([
            'note_counts' => ['1000' => 2, '500' => 0, '100' => 0, '50' => 3],
        ]);

        $this->assertSame(['1000' => 2, '50' => 3], $row['note_counts'] ?? null,
            'শূন্য গোনাগুলো ফেলে দেওয়া হয়নি।');
    }

    /**
     * ⛔ কিছুই গোনা না হলে উত্তর `null`, খালি অ্যারে নয়।
     *
     * ⓘ কলামটা `json` আর `nullable`। ⚠️ `[]` বসালে সেটা পড়া যেত
     * *"গোনা হয়েছে, কিছু পাওয়া যায়নি"* — দুইটা আলাদা কথা, আর নগদ
     * মেলানোর সময় পার্থক্যটা জরুরি।
     */
    public function test_nothing_counted_means_null_not_an_empty_list(): void
    {
        /*
         * ⛔ `?? 'x'` দিয়ে লেখা যায় না, আর প্রথমে আমি তাই লিখেছিলাম।
         *
         * ⚠️ `??` **null আর অনুপস্থিত দুইটাকেই এক ধরে**, তাই ঘরটা সত্যিই
         * `null` হলেও উত্তর আসত `'x'` — আর দাবিটা লাল হত যদিও কোড ঠিক।
         * ⓘ ভুলটা কোডে ছিল না, দাবিতে ছিল।
         *
         * ⭐ তাই দুইটা আলাদা প্রশ্ন আলাদা করে করা হয়: ঘরটা **আছে** কি
         * (`array_key_exists`), আর থাকলে তার মান কী।
         */
        $allZero = $this->rowFor(['note_counts' => ['500' => 0, '100' => 0]]);

        $this->assertArrayHasKey('note_counts', $allZero,
            'ঘরটাই সারিতে নেই — থাকার কথা, মান `null` হলেও।');

        $this->assertNull($allZero['note_counts'],
            'সব শূন্য হলেও খালি অ্যারে বসেছে — `null` হওয়ার কথা।');

        $none = $this->rowFor([]);

        $this->assertArrayHasKey('note_counts', $none);
        $this->assertNull($none['note_counts'],
            'নোটের ঘর না পাঠালেও `null` হওয়ার কথা।');
    }

    /**
     * ⓘ পুরনো ঘরগুলো অক্ষত — নতুন তিনটা যোগ করতে গিয়ে কিছু হারায়নি।
     *
     * ⚠️ দাবিটা আলাদা করে দরকার: তালিকাটা হাতে বাছা, আর সেখানে একটা
     * লাইন যোগ করতে গিয়ে আরেকটা সরে যাওয়া নীরব হত।
     */
    public function test_the_older_fields_still_travel(): void
    {
        $row = $this->rowFor([
            'reference' => 'TRX-77',
            'narration' => 'কাউন্টারে নগদ',
            'ref_date' => '2026-09-25',
        ]);

        $this->assertSame('TRX-77', $row['reference'] ?? null);
        $this->assertSame('কাউন্টারে নগদ', $row['narration'] ?? null);
        $this->assertSame('2026-09-25', $row['ref_date'] ?? null);
    }

    /**
     * একটা জমার সারি বানিয়ে ফেরত দেয়।
     *
     * ⓘ `depositRows()` private, তাই reflection — কিন্তু দাবিটা
     * সেবার **ভেতরের** আচরণ নিয়েই, আর গোটা বিক্রয় বসালে ব্যর্থতার
     * কারণ খুঁজতে দশটা জায়গা দেখতে হত।
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function rowFor(array $extra): array
    {
        $service = app(DirectSaleService::class);

        $method = (new ReflectionClass($service))->getMethod('depositRows');
        $method->setAccessible(true);

        $rows = $method->invoke($service, [
            'deposits' => [[
                'amount' => '1500.00',
                'account_id' => $this->cash()->id,
            ] + $extra],
        ]);

        return $rows[0] ?? [];
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    /** ⓘ নাম টাইপ করা হয় না — ধরন ধরে প্রথম টাকার খাত। */
    private function cash(): Account
    {
        return Account::query()
            ->whereIn('money_kind', Account::MONEY_KINDS)
            ->where('is_group', false)
            ->orderBy('code')
            ->firstOrFail();
    }
}
