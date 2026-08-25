<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Http\Controllers\MasterListController;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সতেরোটা তালিকার প্রতিটাতেই সম্পাদনা ৫০০ দিত।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * `MasterListController::update()` রুটের প্যারামিটারটা সরাসরি
 * `validated()`-এ পাঠাত, আর সেখানে ঘরটা ঘোষিত ছিল `?int`।
 *
 * HTTP-তে রুটের প্যারামিটার **সবসময় স্ট্রিং**। `declare(strict_types=1)`
 * চালু থাকায় PHP `"6"`-কে `6` বানায় না — সে ছুঁড়ে দেয়:
 *
 *     Argument #3 ($id) must be of type ?int, string given
 *
 * ফলে ইউনিট, কর, শর্ত, ব্র্যান্ড, পেমেন্ট মেথড — **যেকোনো** মাস্টার
 * সারি সম্পাদনা করে সেভ চাপলেই ৫০০।
 *
 * ── কেন এত দিন কেউ টের পায়নি ────────────────────────────────────────
 * ভাঙা ছিল কেবল **সংরক্ষণের পথ**। তালিকা খুলত, ফর্ম খুলত, ঘরগুলো ভরা
 * অবস্থায় দেখাত — সব ঠিক দেখাত। ভাঙত সেভ চাপার পর।
 *
 * আর কোনো পরীক্ষা মাস্টার তালিকার সম্পাদনার পথে HTTP দিয়ে যেত না।
 * তৈরির পথ পরীক্ষা করা ছিল; সম্পাদনার পথ ছিল না। এই ফাইলটা সেই ফাঁকটা।
 *
 * ধরা পড়েছে ২৫ আগস্ট ২০২৬, bKash-এর পেমেন্ট মেথডটা তার খাতের সাথে
 * বাঁধতে গিয়ে — অর্থাৎ সাধারণ একটা কাজ করতে গিয়ে, পরীক্ষা লিখতে গিয়ে নয়।
 *
 * ── কেন প্রতিটা তালিকা ধরে ঘোরা হয় ───────────────────────────────────
 * একটা তালিকা পরীক্ষা করলেই এই বাগটা ধরা পড়ত, কারণ কারণটা সবার এক।
 * তবু সবগুলো ধরে ঘোরা হয়: প্রতিটার নিজের ঘর আলাদা (এককের factor,
 * করের হার, কারণ-কোডের প্রসঙ্গ), আর একটার যাচাই ভাঙলে বাকিগুলো ঠিক
 * থাকলেও সেটা ধরা পড়া দরকার।
 */
class EveryMasterListBrokeOnSaveWhenEditedTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    /**
     * প্রতিটা তালিকার একটা সারি সম্পাদনা করে সেভ — কোথাও ৫০০ নয়।
     *
     * সারিটা **অপরিবর্তিত** রেখেই পাঠানো হয়: নাম যা ছিল, কোড যা ছিল।
     * অর্থাৎ পরীক্ষাটা বলে "কিছু না বদলে সেভ চাপলেও ভাঙে না" — আর
     * বাস্তবে মানুষ ঠিক সেটাই করেন, একটা ঘর ছুঁয়ে সেভ চেপে।
     */
    public function test_saving_an_edited_row_does_not_break_on_any_list(): void
    {
        $broken = [];

        foreach ($this->kinds() as $kind => $spec) {
            $record = $spec['model']::query()->first();

            if ($record === null) {
                continue;   // এই তালিকায় কোনো সারি নেই — পরের বার
            }

            $response = $this->actingAs($this->owner)->put(
                route('master_data.'.$spec['route'].'.update', $record->getKey()),
                $this->payload($record),
            );

            if ($response->status() >= 500) {
                $broken[] = $kind.' ('.$spec['route'].') → '.$response->status();
            }
        }

        $this->assertSame([], $broken, implode("\n", [
            'এই তালিকাগুলোয় সারি সম্পাদনা করে সেভ চাপলে সার্ভার ভাঙে:',
            ...$broken,
            '',
            'তালিকা ও ফর্ম দিব্যি খুলবে — ভাঙবে কেবল সেভের পর, তাই',
            'পর্দা দেখে এটা ধরা যায় না।',
        ]));
    }

    /**
     * খোঁজাটা সত্যিই তালিকাগুলো পেয়েছে।
     *
     * `kinds()` খালি ফিরলে উপরের পরীক্ষাটা চিরকাল সবুজ থাকত, আর
     * সতেরোটা ভাঙা পথ নিয়ে কেউ কিছু জানত না।
     */
    public function test_the_walk_actually_covers_the_lists(): void
    {
        $this->assertGreaterThan(10, count($this->kinds()));
    }

    /**
     * সারিটা যেমন আছে তেমনই — কেবল যা পাঠাতেই হয়।
     *
     * @return array<string, mixed>
     */
    private function payload(object $record): array
    {
        $data = [
            'code' => $record->code,
            'name_en' => $record->name_en,
            'name_bn' => $record->name_bn,
            'is_active' => 1,
        ];

        /*
         * প্রতিটা তালিকার নিজের বাধ্যতামূলক ঘরগুলো।
         *
         * যা সারিতে আছে তাই ফেরত পাঠানো হয়, নতুন কিছু বানানো হয় না —
         * তাই পরীক্ষাটা যাচাইয়ের নিয়ম নিয়ে কোনো অনুমান করে না।
         */
        foreach (['factor', 'rate', 'days', 'context', 'level', 'applies_to', 'account_id'] as $field) {
            if (array_key_exists($field, $record->getAttributes())) {
                $data[$field] = $record->{$field};
            }
        }

        return $data;
    }

    /**
     * @return array<string, array{model: class-string, route: string}>
     */
    private function kinds(): array
    {
        $reflection = new \ReflectionClass(MasterListController::class);

        /** @var array<string, array{model: class-string, route: string, setting?: string}> $kinds */
        $kinds = $reflection->getConstant('KINDS');

        /*
         * সুইচের পেছনের তালিকাগুলো বাদ — ওগুলোর ঠিকানা বন্ধ কোম্পানিতে
         * ৪০৪ দেয়, আর সেটাই ঠিক আচরণ। এখানে প্রশ্নটা ৫০০ নিয়ে।
         */
        return array_filter($kinds, fn (array $spec) => ! isset($spec['setting']));
    }
}
