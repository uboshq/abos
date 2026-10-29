<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ⭐ বারের বাঁ কোণে গ্রাহকের নিজের লোগো, রেলের মাথায় ABOS (মালিক, fe-র মাধ্যমে, ২১ অক্টোবর ২০২৬)।
 *
 * দাবি, আসল পাতার দরজা দিয়ে (ABOS রূপ, পুরো চওড়া বার):
 *  - লোগো থাকলে বারের চিহ্নে **এই** কোম্পানির লোগোর `<img>`, alt = কোম্পানির নাম, চাপলে হোম;
 *  - অন্য কোম্পানির লোগো বারের চিহ্নে কখনো নয় — আর কোম্পানি বদলালে চিহ্নও বদলায় (অনুরোধপ্রতি পড়া);
 *  - লোগো না থাকলে নামের আদ্যক্ষর ("আল-আমিন কর্পোরেশন" → "আক") গোল ব্যাজে, `<img>` নয়;
 *  - একই লোগো দুইবার নয়: কোম্পানি-বদলের বোতামে লোগো নেই (চিহ্নেই আছে);
 *  - রেলের প্রথম বোতাম ABOS-এর চিহ্ন, সেটাই হোম; ⌂ ঘরটা নেই — রেলে হোমের ঠিকানা একবারই।
 */
final class TheBarWearsTheCustomersOwnLogoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $other;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['ui' => 'navy', 'locale' => 'bn', 'current_company_id' => $this->company->id])->save();

        // ⓘ নকল ডিস্ক — সিডারের পথগুলো এখানে "নেই", তাই কোন লোগো আছে তা এই পরীক্ষাই ঠিক করে
        Storage::fake('public');
    }

    public function test_the_bar_wears_this_companys_logo_and_never_anothers(): void
    {
        $mine = $this->giveLogo($this->company, 'logos/TDEPOT-mine.png');
        $theirs = $this->giveLogo($this->other, 'logos/FMART-theirs.png');
        $this->assertTrue($this->owner->companies()->whereKey($this->other->id)->exists(), 'প্রস্তুতিটাই ভুল — মালিক দ্বিতীয় কোম্পানিতে নেই।');

        $mark = $this->mark($this->page());
        $this->assertStringContainsString('src="'.$mine.'"', $mark, '⛔ বারের চিহ্নে এই কোম্পানির লোগো নেই।');
        $this->assertStringContainsString('alt="'.e($this->company->name()).'"', $mark, '⛔ লোগোর alt কোম্পানির নাম নয়।');
        $this->assertStringContainsString('href="'.route('dashboard').'"', $mark, '⛔ লোগো চাপলে হোমে যায় না।');
        $this->assertStringNotContainsString($theirs, $mark, '⛔ বারের চিহ্নে অন্য কোম্পানির লোগো।');
        $this->assertStringNotContainsString('data-company-initials', $mark, '⛔ লোগো থাকতেও আদ্যক্ষরের ব্যাজ।');
        $this->assertStringNotContainsString('abos-icon', $this->bar($this->page()), '⛔ বারে এখনো ABOS-এর চিহ্ন — গ্রাহকের ব্র্যান্ডের জায়গায়।');

        // ⭐ কোম্পানি বদলালে চিহ্নও বদলায় — পরের পাতাতেই
        $this->actingAs($this->owner)->post(route('company.switch'), ['company_id' => $this->other->id])->assertRedirect();
        CompanyContext::set($this->other->id, $this->other->defaultBranch()?->id);
        $after = $this->mark($this->page());
        $this->assertStringContainsString('src="'.$theirs.'"', $after, '⛔ কোম্পানি বদলের পরে বারে নতুন কোম্পানির লোগো নেই।');
        $this->assertStringNotContainsString($mine, $after, '⛔ কোম্পানি বদলের পরেও আগের কোম্পানির লোগো।');
    }

    public function test_one_logo_only_the_switcher_beside_the_mark_does_not_repeat_it(): void
    {
        $mine = $this->giveLogo($this->company, 'logos/TDEPOT-once.png');
        $bar = $this->bar($this->page());

        $this->assertSame(1, substr_count($this->mark($bar), '<img'), '⛔ চিহ্নে একটার বেশি ছবি।');
        // ⓘ বদলের বোতাম = data-company-branch থেকে তার তালিকা (role="menu") পর্যন্ত; তালিকায় প্রতিটা কোম্পানির লোগো থাকতেই পারে
        $button = $this->between($bar, 'data-company-branch', 'role="menu"');
        $this->assertStringNotContainsString('<img', $button, '⛔ কোম্পানি-বদলের বোতামেও লোগো — একই লোগো পাশাপাশি দুইবার।');
        $this->assertStringContainsString($this->company->name(), $button, '⛔ বোতামে কোম্পানির নাম নেই।');
        $this->assertStringContainsString($mine, $bar);
    }

    public function test_without_a_logo_the_initials_sit_in_a_round_badge(): void
    {
        $this->company->forceFill(['logo_path' => null, 'name_bn' => 'আল-আমিন কর্পোরেশন'])->save();

        $mark = $this->mark($this->page());

        $this->assertStringNotContainsString('<img', $mark, '⛔ লোগো নেই, তবু চিহ্নে ছবি।');
        $this->assertMatchesRegularExpression('/data-company-initials[^>]*class="[^"]*rounded-full[^"]*"[^>]*>আক</u', $mark,
            '⛔ লোগো না থাকলে "আক" গোল ব্যাজে নেই।');
        $this->assertMatchesRegularExpression('/data-company-initials[^>]*style="background: var\(--color-company-mark-[1-6]\)/', $mark,
            '⛔ ব্যাজ কোম্পানির রঙে নয়।');
        $this->assertStringContainsString('aria-label="আল-আমিন কর্পোরেশন"', $mark, '⛔ ব্যাজের লিংকে কোম্পানির নাম নেই।');
    }

    public function test_the_rail_begins_with_the_abos_mark_and_it_is_the_only_home(): void
    {
        $html = $this->page();
        $rail = $this->between($this->between($html, '<aside', '</aside>'), '<nav', '</nav>');

        preg_match('/<a\b[^>]*>/', $rail, $first);
        $this->assertNotEmpty($first, 'প্রস্তুতিটাই ভুল — রেলে কোনো লিংক নেই।');
        $this->assertStringContainsString('data-rail-home', $first[0], '⛔ রেলের প্রথম বোতাম হোম নয়।');
        $this->assertStringContainsString('href="'.route('dashboard').'"', $first[0], '⛔ রেলের প্রথম বোতাম হোমে যায় না।');
        $this->assertStringContainsString('title="'.__('core.a11y.home').'"', $first[0], '⛔ চিহ্নের নাম "হোম" নয়।');

        $home = $this->between($rail, 'data-rail-home', '</a>');
        $this->assertStringContainsString('brand/abos-icon-64.png', $home, '⛔ রেলের মাথায় ABOS-এর চিহ্ন নেই।');

        // ⛔ ⌂ ঘরটা গেছে — `home` আঁকার পথ রেলে নেই, আর হোমের ঠিকানা রেলে একবারই
        $this->assertStringNotContainsString('M5.5 9.5V20h5v-5.5h3V20h5V9.5', $rail, '⛔ রেলে এখনো ⌂ আইকন — দুইটা হোম।');
        $this->assertSame(1, substr_count($rail, 'href="'.route('dashboard').'"'), '⛔ রেলে হোমের ঠিকানা একাধিকবার।');
    }

    private function giveLogo(Company $company, string $path): string
    {
        Storage::disk('public')->put($path, 'png');
        $company->forceFill(['logo_path' => $path])->save();

        return (string) $company->fresh()->logoUrl();
    }

    private function page(): string
    {
        $this->app['auth']->forgetGuards();

        return (string) $this->actingAs($this->owner->fresh())->get(route('dashboard'))->assertOk()->getContent();
    }

    private function bar(string $html): string
    {
        return $this->between($html, '<header data-topbar="full"', '</header>');
    }

    /** বারের বাঁ কোণের চিহ্ন — `data-shell-logo`-ওয়ালা `<a …>` থেকে তার `</a>` পর্যন্ত (ঠিকানাসহ) */
    private function mark(string $html): string
    {
        $this->assertSame(1, preg_match('/<a\b[^>]*\sdata-shell-logo\b.*?<\/a>/s', $html, $found), '⛔ বারের বাঁ কোণে চিহ্নই নেই।');

        return $found[0];
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "প্রস্তুতিটাই ভুল — পাতায় {$from} নেই।");
        $end = strpos($html, $to, $start + strlen($from));

        return substr($html, $start, $end === false ? null : $end - $start);
    }
}
