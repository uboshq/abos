<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * ⭐ ফাইলের ঘরে টেনে আনার বাক্স — রসিদ-পরিশোধ মিল-তালিকা #৩৭ (নকশা পাতা ২ আর ৪: "ব্যাংক স্লিপ / স্ক্রিনশট টেনে আনুন বা বাছুন"),
 * ২৫ অক্টোবর ২০২৬। ⓘ একবারই, ভাগ করা [[x-ui.file-input]]-এ — ১৮টা পর্দা নিজে থেকেই পায়।
 *
 * দাবি (আচরণ — ফেলা ফাইল আসল ঘর ভরে, ধরন/একটার ঘর/সীমা, "সরান" — vitest-এ: `resources/js/components/file-drop.test.js`):
 *  ১. গোটা ঘরটাই বাক্স: ফেলা, টেনে আনা আর বেরোনোর তিন শ্রোতা বাক্সে, আর আসল ঘরটা (একই নাম, accept, পাতার নিজের শ্রোতা) তার ভিতরেই।
 *  ২. কীবোর্ডে: বোতাম আসল ঘরের লেবেল, ঘরটা Tab-এ আসে (tabindex বা disabled নেই), "সরান" আসল বোতাম।
 *  ৩. বাংলা আর ইংরেজি লেখা — "বা এখানে টেনে আনুন" / "or drop it here", "সরান" / "Remove", আর ফেরানোর দুই কারণ x-data-য়।
 *  ৪. আসল পাতা — রসিদের পর্দায় সংযুক্তির `attachment` ঘর বাক্সের ভিতরে।
 */
final class AFileCanBeDroppedOnTheBoxTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_whole_box_takes_a_drop_and_holds_the_same_real_input(): void
    {
        app()->setLocale('bn');

        $box = $this->box(Blade::render('<form><x-ui.file-input id="p" name="paper" accept="image/png,application/pdf" x-on:change="$store.scanner.begin($el, \'paper\')" /></form>'));

        foreach (['@drop="drop($event)"', '@dragover="dragOver($event)"', '@dragleave="dragLeave($event)"'] as $wire) {
            $this->assertStringContainsString($wire, $box, "⛔ বাক্সে {$wire} নেই — ফাইল ফেললে ব্রাউজার নিজেই খুলে ফেলত");
        }

        $this->assertMatchesRegularExpression('/<input type="file" id="p" name="paper" class="sr-only"[^>]*accept="image\/png,application\/pdf"[^>]*@change="pick\(\$event\)"[^>]*x-on:change="\$store.scanner.begin/', $box,
            '⛔ বাক্সের ভিতরে বোতামের সেই আসল ঘর নেই — নাম, ধরন বা পাতার শ্রোতা বদলেছে');
        $this->assertSame(1, substr_count($box, 'type="file"'), '⛔ বাক্সে দ্বিতীয় একটা ফাইল-ঘর — ফেলা ফাইল অন্য নামে যেত');
    }

    public function test_the_box_is_reachable_by_keyboard(): void
    {
        $box = $this->box(Blade::render('<x-ui.file-input id="k" name="paper" />'));

        $this->assertMatchesRegularExpression('/<label for="k"/', $box, '⛔ বাছার বোতাম আসল ঘরের লেবেল নয় — কীবোর্ডে বাছা যায় না');
        $this->assertDoesNotMatchRegularExpression('/<input type="file"[^>]*(tabindex="-1"|disabled)/', $box, '⛔ আসল ঘর Tab-এ আসে না');
        $this->assertMatchesRegularExpression('/<button type="button"[^>]*@click="remove\(\$event, 0\)"/', $box, '⛔ "সরান" আসল বোতাম নয় — কীবোর্ডে সরানো যায় না');
        $this->assertStringContainsString('has-[:focus-visible]:outline-2', $box, '⛔ কীবোর্ডে ঘরে এলে বাক্সের রেখা জ্বলে না — কোথায় আছি বোঝা যায় না');

        $many = $this->box(Blade::render('<x-ui.file-input id="m" name="files" :multiple="true" />'));
        $this->assertMatchesRegularExpression('/x-for="\(fileName, index\) in names"[\s\S]*?@click="remove\(\$event, index\)"/', $many,
            '⛔ কয়েকটা ফাইলের ঘরে প্রতিটা নামের পাশে নিজের "সরান" নেই');
    }

    public function test_the_box_speaks_bengali_and_english(): void
    {
        foreach (['bn' => ['বা এখানে টেনে আনুন', 'সরান'], 'en' => ['or drop it here', 'Remove']] as $locale => [$drop, $remove]) {
            app()->setLocale($locale);
            $box = $this->box(Blade::render('<x-ui.file-input id="l" name="paper" />'));

            $this->assertStringContainsString('>'.$drop.'</span>', $box, "⛔ {$locale}: বাক্সে \"{$drop}\" লেখা নেই");
            $this->assertStringContainsString('>'.$remove.' <span', $box, "⛔ {$locale}: \"{$remove}\" বোতামের লেখা নেই");
            $this->assertStringContainsString(e(json_encode(__('core.file.wrong_type'), JSON_UNESCAPED_UNICODE)), $box, "⛔ {$locale}: ভুল ধরনের কারণ বাক্সে পৌঁছায় না");
            $this->assertStringContainsString(e(json_encode(__('core.file.one_only'), JSON_UNESCAPED_UNICODE)), $box, "⛔ {$locale}: একটার ঘরের কারণ বাক্সে পৌঁছায় না");
            $this->assertStringContainsString(':name', __('core.file.wrong_type'), "⛔ {$locale}: ভুল ধরনের কারণে ফাইলের নাম বসার জায়গা নেই");
        }
    }

    public function test_the_receipt_screen_draws_the_box_around_its_attachment(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $html = (string) $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail())
            ->get(route('accounts.voucher.create', ['type' => Voucher::RECEIPT]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-file-drop[^>]*@drop="drop\(\$event\)"[\s\S]*?<input type="file" id="attachment" name="attachment"/', $html,
            '⛔ রসিদের পর্দায় সংযুক্তির ঘরে টেনে আনার বাক্স নেই');
    }

    /** বাক্সটা — `data-file-input` থেকে শেষ পর্যন্ত (ঘরের নিজের অংশ) */
    private function box(string $html): string
    {
        $start = strpos($html, '<span x-data="filePick(');
        $this->assertNotFalse($start, 'প্রস্তুতিটাই ভুল — ফাইলের ঘর আঁকা হয়নি');

        return substr($html, $start);
    }
}
