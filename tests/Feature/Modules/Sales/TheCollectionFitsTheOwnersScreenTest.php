<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ টাকা নেওয়ার পর্দা মালিকের ১৩৬৬-এর পর্দায় নকশার মতো দুই কলামে (মালিক আর fe, ২৪ অক্টোবর ২০২৬; ছবি ১২, ১৩): মালিক নকশার পুরো
 * ডান কলাম দাগিয়ে বলেছিলেন "দাগানো পুরো অংশটাই নেই" — ১৩৬৬-এ সেটা নিচে নামত।
 *
 * এখন: পাতা নিজেকে দুই-কলামের টাকার ফর্ম বলে (`<html data-two-column-form>`), তাই ১৫৩৬-এর নিচে মেনু রেলে বা ভাসমান
 * ([[sidebar-state.js]], vitest-এ দাবি); দুই কলাম ১৩৬৬ থেকে (রেলের পাশে বিলের টেবিল ৬৮৫/৬৮৫px, আসল ব্রাউজারে মাপা); ডান কলাম
 * আটকানো; ১৩৬৬-এর নিচে এক কলামে উপরে সারাংশ-পট্টি; গ্রাহকের নাম দুই লাইনে, কিছু কাটে না। ⓘ শ্রেণিগুলো বিল্ড করা CSS-এও —
 * বান্ডিলে না থাকলে শ্রেণি নীরবে কিছুই করে না।
 */
class TheCollectionFitsTheOwnersScreenTest extends TestCase
{
    use RefreshDatabase;

    private const GRID = 'min-[1366px]:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)]';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_the_collection_screen_is_two_columns_from_1366_with_the_menu_on_its_rail(): void
    {
        $page = (string) $this->get(route('sales.collection.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<html\b[^>]*\bdata-two-column-form\b/', $page,
            '⛔ আদায়ের পাতা নিজেকে দুই-কলামের টাকার ফর্ম বলে না — ১৩৬৬-এ মেনু খোলা থেকে বিলের টেবিল ৫৪px কাটে');
        $this->assertStringContainsString('class="grid gap-4 '.self::GRID.'"', $page,
            '⛔ দুই কলাম ১৩৬৬ থেকে নয় — মালিকের ল্যাপটপে ডান কলাম (সারাংশ, খাতা, নিয়ন্ত্রণ) নিচে নামে');
        $this->assertMatchesRegularExpression('/data-right-column[^>]*min-\[1366px\]:sticky/s', $page,
            '⛔ ডান কলাম আটকানো নয় — বিল টিক দেওয়ার সময় সারাংশ চোখের বাইরে যায়');
        $this->assertMatchesRegularExpression('/data-summary-strip[^>]*min-\[1366px\]:hidden/s', $page,
            '⛔ ১৩৬৬-এর নিচে সারাংশ-পট্টি নেই (বা দুই কলামেও দেখায়)');
        $this->assertStringContainsString('data-party-stacked', $page, '⛔ গ্রাহকের নাম দুই লাইনে নয় — দুই কলামে তীরের নিচে কাটে');

        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $css = (string) file_get_contents(public_path('build/'.$manifest['resources/css/app.css']['file']));
        foreach ([':grid-cols-\[minmax\(0\,1\.45fr\)_minmax\(0\,1fr\)\]', ':sticky', ':hidden'] as $rule) {
            $this->assertStringContainsString('.min-\[1366px\]\\'.$rule, $css, "⛔ বিল্ড করা CSS-এ min-[1366px]{$rule} নেই — শ্রেণি নীরবে কিছুই করে না");
        }
    }

    public function test_other_screens_keep_their_menu_as_it_was(): void
    {
        $page = (string) $this->get(route('customer.index'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/<html\b[^>]*\bdata-two-column-form\b/', $page,
            '⛔ গ্রাহকের তালিকাও নিজেকে টাকার ফর্ম বলল — অন্য পাতায় মেনু রেলে যাওয়ার কথা নয়');
    }
}
