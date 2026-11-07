<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\VoucherWriter;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ অনুমোদন ভাগ ৩, দফা ২১ — ফোন আর বড় পর্দা (পরিকল্পনার পাতা ৭, সারি ২১; পাতা ৬-এর ছবি):
 * "ওয়েবে ফোনের মাপে কার্ড-তালিকা। … ১০৮০p আর ১৩৬৬-এ কিছু কাটা পড়ে না।"
 *
 * ⛔ আগে (৩০ অক্টোবর ২০২৬, ৩৯০px-এর ছবি): তালিকার নিচে প্রথম কাগজটা পুরো আঁকা; কাগজের লাইনের কার্ডের মাথায় সব শিরোনাম জোড়া
 * ("হিসাবপক্ষবিবরণডেবিটক্রেডিট") আর যোগের সারিতে নামহীন দুইটা সংখ্যা; সিদ্ধান্তের বোতাম পাতার একদম নিচে; যাত্রাপথে
 * "-1.3752777777778 ঘণ্টা"; আর নিয়মের পাতা ৪৪৩px চওড়া — পুরো পাতা পাশে সরত।
 *
 * দাবি:
 *   ২১ক — ফোনে একবারে একটা: কাগজ না বেছে খুললে কেবল তালিকা, বেছে খুললে (`?open=`) কেবল কাগজ আর তার মাথায় তালিকায় ফেরার লিংক
 *          ("২ / ৩"), যেটা `open` ছাড়া; বড় পর্দায় দুইটাই (`max-lg:` কেবল);
 *   ২১খ — ফোনে তিন সিদ্ধান্তের বোতাম পর্দার নিচে স্থির, নিচের মেনুর উপরে; বড় পর্দায় `contents` (আগের মতো, স্থির নয়); শেষ লাইন
 *          ঢাকা না পড়ার ফাঁকা জায়গা; ক্লাসগুলো বান্ডেলে আছে;
 *   ২১গ — ফোনের কার্ডে শিরোনাম সত্যিই লুকায় (`clip-path`, `display: block` — `sticky` utility যাকে হারাতে পারে না) আর টেবিলে ফেরে;
 *          যোগের সারির প্রতিটা সংখ্যার নাম আছে; ফাঁকা ঘর কার্ডে আঁকা হয় না কিন্তু টেবিলে থাকে;
 *   ২১ঘ — যাত্রাপথে ধাপের সময় পুরো ঘণ্টা, কখনো শূন্যের নিচে নয়;
 *   ২১ঙ — নিয়মের পাতা ফোনে পাশে সরে না: উপশিরোনাম ভাঙতে পারে, আর টেবিলের বাক্স নিজের স্ক্রলের ধারক।
 */
final class TheInboxFitsAPhoneTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);
        app()->setLocale('bn');

        $this->signer = $this->signer('দফা ২১-এর অনুমোদনকারী');
        $this->flow([$this->signer]);
    }

    public function test_dofa21a_a_phone_shows_the_list_or_the_paper_not_both(): void
    {
        [, $middle] = $this->threePapers();

        $list = $this->xpath($this->actingAs($this->signer)->get(route('approval.inbox.index'))->assertOk()->getContent());
        $this->assertSame(1, $list->query('//*[@data-inbox-detail]//*[@data-approval-detail]')->length,
            'প্রস্তুতিটাই ভুল — বড় পর্দার জন্য প্রথম কাগজটা ডান দিকে আঁকা হয়নি।');
        $this->assertNotContains('max-lg:hidden', $this->classes($list, '//*[@data-inbox-list]'),
            '⛔ দফা ২১: কাগজ না বেছে খুললে ফোনে তালিকাটাই লুকানো।');
        $this->assertContains('max-lg:hidden', $this->classes($list, '//*[@data-inbox-detail]'),
            '⛔ দফা ২১: কাগজ না বেছে খুললে ফোনে তালিকার নিচে প্রথম কাগজটা পুরো আঁকা — দুইটা একসাথে।');
        $this->assertSame(0, $list->query('//a[@data-inbox-back]')->length, '⛔ দফা ২১: তালিকাতেই "তালিকায় ফিরুন" লিংক।');

        $paper = $this->xpath($this->actingAs($this->signer)->get(route('approval.inbox.index', ['open' => $middle->id]))->assertOk()->getContent());
        $this->assertContains('max-lg:hidden', $this->classes($paper, '//*[@data-inbox-list]'),
            '⛔ দফা ২১: কাগজ বেছে খুললেও ফোনে উপরে পুরো তালিকা — কাগজ খুঁজতে নিচে নামতে হয়।');
        $this->assertNotContains('max-lg:hidden', $this->classes($paper, '//*[@data-inbox-detail]'),
            '⛔ দফা ২১: কাগজ বেছে খুললে ফোনে কাগজটাই লুকানো।');

        $back = $paper->query('//*[@data-inbox-detail]//a[@data-inbox-back]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $back, '⛔ দফা ২১: ফোনে কাগজ থেকে তালিকায় ফেরার লিংক নেই।');
        $this->assertStringNotContainsString('open=', $back->getAttribute('href'), '⛔ দফা ২১: "তালিকায় ফিরুন" আবার একই কাগজ খোলে।');
        $this->assertStringContainsString('lg:hidden', $back->getAttribute('class'), '⛔ দফা ২১: ফেরার লিংক বড় পর্দাতেও — সেখানে তালিকা পাশেই।');
        $this->assertMatchesRegularExpression('/2\s*\/\s*3/u', (string) $back->textContent, '⛔ দফা ২১: ফেরার লিংক বলে না এটা তিনটার কোনটা।');
    }

    public function test_dofa21b_on_a_phone_the_three_decisions_stay_at_the_bottom(): void
    {
        [, $middle] = $this->threePapers();

        $page = $this->xpath($this->actingAs($this->signer)->get(route('approval.inbox.index', ['open' => $middle->id]))->assertOk()->getContent());
        $bar = $page->query('//*[@data-approval-decide]//*[@data-approval-decide-bar]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $bar, '⛔ দফা ২১: সিদ্ধান্তের বোতামের স্থির বার নেই।');

        $classes = preg_split('/\s+/', trim($bar->getAttribute('class'))) ?: [];
        foreach (['contents', 'max-md:fixed', 'max-md:inset-x-0', 'max-md:bottom-(--spacing-bottom-nav)', 'max-md:flex'] as $class) {
            $this->assertContains($class, $classes, "⛔ দফা ২১: ফোনে সিদ্ধান্তের বার নিচে স্থির নয়, বা নিচের মেনুর পেছনে, বা বড় পর্দাতেও স্থির ({$class} নেই)।");
        }
        $this->assertSame([], array_values(array_filter($classes, fn (string $c) => preg_match('/^(md|lg|xl):(fixed|sticky)$|^(fixed|sticky)$/', $c) === 1)),
            '⛔ দফা ২১: বার বড় পর্দাতেও স্থির — ১৩৬৬-এ লাইন আর যাত্রাপথ ঢাকত (২৮ অক্টোবরের ছবি)।');

        foreach (['r' => 'প্রত্যাখ্যান', 's' => 'সংশোধনে ফেরত', 'a' => 'অনুমোদন'] as $key => $what) {
            $this->assertSame(1, $page->query('.//*[@data-key="'.$key.'"]', $bar)->length, "⛔ দফা ২১: \"{$what}\" বোতাম ফোনের স্থির বারে নেই।");
        }
        $this->assertSame(0, $page->query('.//*[@data-ask="forward"]', $bar)->length, '⛔ দফা ২১: ফরোয়ার্ড বারে — চার বোতাম ৩৯০px-এ এক সারিতে ধরে না।');

        $this->assertSame(1, $page->query('//*[@data-approval-decide]//div[@aria-hidden="true" and contains(concat(" ", normalize-space(@class), " "), " md:hidden ")]')->length,
            '⛔ দফা ২১: স্থির বারের নিচে ফাঁকা জায়গা নেই — ফোনে শেষ লাইন বারের নিচে ঢাকা পড়ে।');

        $css = $this->builtCss();
        foreach (['max-md\:fixed', 'max-md\:bottom-\(--spacing-bottom-nav\)', 'max-md\:\*\:flex-1', 'max-lg\:hidden'] as $built) {
            $this->assertStringContainsString($built, $css, "⛔ দফা ২১: বান্ডেলে {$built} নেই — ক্লাসটা লেখা আছে কিন্তু কিছুই করে না।");
        }
    }

    public function test_dofa21c_a_line_card_on_a_phone_names_every_figure_and_hides_the_heading(): void
    {
        [, $middle] = $this->threePapers();

        $page = $this->xpath($this->actingAs($this->signer)->get(route('approval.inbox.index', ['open' => $middle->id]))->assertOk()->getContent());
        $table = $page->query('//*[@data-signing-sheet]//table[contains(@class, "table-cards")]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $table, 'প্রস্তুতিটাই ভুল — কাগজের লাইনের টেবিল নেই।');

        $sums = $page->query('./tfoot/tr[1]/td[position() > 1]', $table);
        $this->assertGreaterThan(0, $sums->length, 'প্রস্তুতিটাই ভুল — যোগের সারি নেই।');
        $named = 0;
        foreach ($sums as $td) {
            $this->assertInstanceOf(DOMElement::class, $td);
            $text = trim((string) $td->textContent);
            if ($text === '') {
                $this->assertTrue($td->hasAttribute('data-empty'), '⛔ দফা ২১: যোগের সারির ফাঁকা ঘর ফোনের কার্ডে নাম নিয়ে খালি লাইন আঁকে।');
            } else {
                $this->assertNotSame('', $td->getAttribute('data-label'), "⛔ দফা ২১: যোগের সারিতে \"{$text}\" — ফোনের কার্ডে নামহীন সংখ্যা, কোনটা ডেবিট বোঝা যায় না।");
                $named++;
            }
        }
        $this->assertSame(2, $named, 'প্রস্তুতিটাই ভুল — জাবেদার যোগে ডেবিট আর ক্রেডিট দুইটা সংখ্যা থাকার কথা।');

        $empties = 0;
        foreach ($page->query('./tbody/tr/td', $table) as $td) {
            $this->assertInstanceOf(DOMElement::class, $td);
            $blank = trim((string) $td->textContent) === '';
            $this->assertSame($blank, $td->hasAttribute('data-empty'), '⛔ দফা ২১: লাইনের ঘরের "ফাঁকা" দাগ ভুল — '.($blank ? 'ফাঁকা ঘর ফোনের কার্ডে নামসহ খালি লাইন।' : 'ভরা ঘর ফোনে লুকায়।'));
            $empties += $blank ? 1 : 0;
        }
        $this->assertGreaterThan(0, $empties, 'প্রস্তুতিটাই ভুল — জাবেদার লাইনে ফাঁকা ঘর (একপাশের ডেবিট/ক্রেডিট) থাকার কথা।');

        $css = (string) file_get_contents(resource_path('css/app.css'));
        $card = $this->rule($css, '    .table-cards thead {');
        $this->assertStringContainsString('clip-path: inset(50%)', $card, '⛔ দফা ২১: ফোনের কার্ডে শিরোনাম লুকানো কেবল clip দিয়ে — thead-এর sticky তাকে হারায়, শিরোনামগুলো জোড়া লেগে দেখায়।');
        $this->assertStringContainsString('display: block', $card, '⛔ দফা ২১: শিরোনামের সারি টেবিলের সারি থেকে যায় — ১px-এ নামে না।');

        $wide = $this->rule($css, '        .table-cards:not(.as-cards) thead {');
        foreach (['display: table-header-group', 'clip-path: none'] as $back) {
            $this->assertStringContainsString($back, $wide, "⛔ দফা ২১: বড় পর্দার টেবিলে শিরোনাম ফেরে না ({$back} নেই)।");
        }

        $hide = strpos($css, '.table-cards td[data-empty] {');
        $table = strpos($css, '        .table-cards:not(.as-cards) td {');
        $this->assertNotFalse($hide, '⛔ দফা ২১: ফাঁকা ঘর ফোনের কার্ডে লুকানোর নিয়ম নেই।');
        $this->assertStringContainsString('display: none', $this->rule($css, '    .table-cards td[data-empty] {'), '⛔ দফা ২১: ফাঁকা ঘরের নিয়ম ঘরটা লুকায় না।');
        $this->assertNotFalse($table);
        $this->assertLessThan($table, $hide,
            '⛔ দফা ২১: ফাঁকা ঘরের নিয়ম টেবিলের নিয়মের পরে — একই নির্দিষ্টতায় পরেরটা জেতে, বড় পর্দার টেবিলে ঘর হারিয়ে কলাম সরে যেত।');
    }

    public function test_dofa21d_a_step_took_whole_hours_never_below_zero(): void
    {
        $second = $this->signer('দফা ২১-এর দ্বিতীয় অনুমোদনকারী');
        $this->flow([$this->signer, $second]);

        foreach (['3' => now()->subMinutes(216), '0' => now()->addHours(2)] as $hours => $askedAt) {
            $paper = $this->ask('700', now()->subMinutes(216));
            $this->actingAs($this->signer)->post(route('approval.inbox.approve', $paper->id), ['remarks' => 'ঠিক আছে'])->assertRedirect();
            $paper->refresh()->forceFill(['requested_at' => $askedAt])->save();
            $this->assertSame(Approval::PENDING, $paper->fresh()?->status, 'প্রস্তুতিটাই ভুল — দুই ধাপের দ্বিতীয়টায় অপেক্ষার কথা।');

            $page = $this->xpath($this->actingAs($second)->get(route('approval.inbox.show', $paper->id))->assertOk()->getContent());
            $journey = $page->query('//*[@data-approval-journey]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $journey, 'প্রস্তুতিটাই ভুল — যাত্রাপথ নেই।');
            $text = preg_replace('/\s+/u', ' ', (string) $journey->textContent);

            $this->assertMatchesRegularExpression('/· '.$hours.' ঘণ্টা/u', (string) $text,
                "⛔ দফা ২১: প্রথম ধাপের সময় {$hours} ঘণ্টা দেখায় না — ভগ্নাংশ বা চিহ্নসহ সংখ্যা ({$text})।");
            $this->assertDoesNotMatchRegularExpression('/-\d|\d\.\d+ ঘণ্টা/u', (string) $text, "⛔ দফা ২১: যাত্রাপথে কাঁচা সংখ্যা ({$text})।");
        }
    }

    public function test_dofa21e_the_rules_page_does_not_slide_sideways_on_a_phone(): void
    {
        $page = $this->xpath($this->actingAs($this->owner)->get(route('approval.flow.index'))->assertOk()->getContent());

        // ⓘ ৭ নভেম্বর ২০২৬ থেকে নিয়মের সংখ্যার বাক্য রঙিন পট্টিতে ([[x-money.page]], মালিকের নমুনা) — নিয়ম একই: ফোনে ভাঙে
        $count = $page->query('//*[@data-money-hero]//*[@data-hero-count]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $count, 'প্রস্তুতিটাই ভুল — নিয়মের পাতার পট্টিতে সংখ্যার বাক্য নেই।');
        $classes = preg_split('/\s+/', trim($count->getAttribute('class'))) ?: [];
        $this->assertNotContains('shrink-0', $classes, '⛔ দফা ২১: উপশিরোনামের বাক্য ভাঙে না — ফোনে পুরো পাতা পাশে সরে।');
        $this->assertContains('min-w-0', $classes, '⛔ দফা ২১: উপশিরোনামের বাক্য তার সারির চেয়ে চওড়া থাকে।');

        $box = $page->query('//*[@data-flow-list]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $box, 'প্রস্তুতিটাই ভুল — নিয়মের তালিকা নেই।');
        $this->assertContains('relative', preg_split('/\s+/', trim($box->getAttribute('class'))) ?: [],
            '⛔ দফা ২১: নিয়মের টেবিলের বাক্স ধারক নয় — শিরোনামের পর্দা-পাঠকের লেখা বাইরে বসে ফোনে পাতা ৪৪৩px চওড়া করে।');
        $this->assertGreaterThan(0, $page->query('//*[@data-flow-list]//th//*[contains(concat(" ", @class, " "), " sr-only ")]')->length,
            'প্রস্তুতিটাই ভুল — টেবিলের শিরোনামে পর্দা-পাঠকের লেখা নেই, দাবিটা কিছুই পাহারা দেয় না।');
    }

    /** @param list<User> $signers এক ধাপে একজন, ক্রমে */
    private function flow(array $signers): void
    {
        ApprovalFlow::query()->where('module', 'accounts')->where('action', 'journal')->delete();
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        foreach ($signers as $i => $user) {
            ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => $i + 1, 'approver_type' => 'user', 'approver_id' => $user->id]);
        }
        ApprovalEngine::flowsChanged();
    }

    /** @return list<string> */
    private function classes(DOMXPath $page, string $query): array
    {
        $node = $page->query($query)->item(0);
        $this->assertInstanceOf(DOMElement::class, $node, "প্রস্তুতিটাই ভুল — {$query} নেই।");

        return preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];
    }

    /** ⓘ একটা CSS নিয়মের ভেতরটা — খোলা বন্ধনী থেকে প্রথম বন্ধ পর্যন্ত */
    private function rule(string $css, string $opening): string
    {
        $at = strpos($css, $opening);
        $this->assertNotFalse($at, "প্রস্তুতিটাই ভুল — app.css-এ \"".trim($opening).'" নিয়ম নেই।');

        return substr($css, $at, (int) strpos($css, '}', $at) - $at);
    }

    private function builtCss(): string
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $file = $manifest['resources/css/app.css']['file'] ?? null;
        $this->assertIsString($file, 'প্রস্তুতিটাই ভুল — বান্ডেলের তালিকায় app.css নেই।');

        return (string) file_get_contents(public_path('build/'.$file));
    }

    /** @return array{0: Approval, 1: Approval, 2: Approval} */
    private function threePapers(): array
    {
        return [$this->ask('500', now()->subHours(3)), $this->ask('700', now()->subHours(2)), $this->ask('900', now()->subHour())];
    }

    private function xpath(string|false $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.(string) $html);

        return new DOMXPath($dom);
    }

    private function signer(string $name): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'bn', 'name' => $name]);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id])->save();
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), ['approval.view', 'approval.decide'])));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function draft(string $amount): Voucher
    {
        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();

        return app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'দফা ২১-এর পরীক্ষা', 'branch_id' => CompanyContext::branchId()],
            [['account_id' => $a->id, 'debit' => $amount, 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => $amount]],
        );
    }

    private function ask(string $amount, \Illuminate\Support\Carbon $at): Approval
    {
        $this->actingAs($this->owner);
        $asked = app(VoucherWriter::class)->post($this->draft($amount));
        $this->assertNotNull($asked, 'প্রস্তুতিটাই ভুল — অনুমোদন চাওয়া হয়নি।');
        $asked->forceFill(['requested_at' => $at])->save();

        return $asked->fresh();
    }
}
