<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কোম্পানি আর শাখার ফর্ম: সংরক্ষণ নিচের স্থির পট্টিতে, লোগো তোলার বোতাম বাংলায়।
 *
 * ── ⭐ সিস্টেম পর্দার নকশা §৪, ১০ অক্টোবর ২০২৬ ─────────────────────────────────────────
 * *"কোম্পানি সম্পাদনা এখন: সংরক্ষণ দুই ভাগের মাঝখানে; নিচে শাখা বানানোর ফর্ম জোড়া; 'Choose File'"*। ⓘ fe-র নিয়মে ছোট বদল:
 * গড়ন (শাখাগুলো কোম্পানির পাতাতেই) অক্ষত — কেবল পট্টি আর বোতাম।
 */
final class TheCompanyFormSavedInTheMiddleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($owner->fresh());
    }

    /** ⭐ কোম্পানির সংরক্ষণ নিজের ফর্মের স্থির পট্টিতে — শাখার "তৈরি"-র সাথে গুলিয়ে নয়। */
    public function test_the_company_saves_from_its_own_sticky_bar(): void
    {
        $html = $this->page(route('system_admin.company.edit', $this->company->id));

        $form = $this->between($html, 'action="'.route('system_admin.company.update', $this->company->id).'"', '</form>');

        $this->assertStringContainsString('data-form-actions', $form, '⛔ কোম্পানির ফর্মে স্থির পট্টি নেই।');
        $this->assertStringContainsString('href="'.route('system_admin.company.index').'"', substr($form, (int) strpos($form, 'data-form-actions')),
            '⛔ পট্টিতে বাতিল নেই।');
        $this->assertSame(1, substr_count($form, 'type="submit"'), '⛔ কোম্পানির ফর্মে সংরক্ষণ একটার বেশি।');

        /* ⓘ শাখা বানানোর ফর্ম আলাদা, নিজের বোতামসহ — পট্টি সেখানে নেই */
        $branch = $this->between($html, 'action="'.route('system_admin.company.branch.store', $this->company->id).'"', '</form>');
        $this->assertStringNotContainsString('data-form-actions', $branch, '⛔ শাখার ফর্মেও কোম্পানির পট্টি বসেছে।');
    }

    /** ⭐ লোগোর ঘর বাংলা বোতাম পরে, আর নামটা আগের মতোই `logo` — সার্ভার যা পড়ে। */
    public function test_the_logo_button_speaks_bengali(): void
    {
        foreach ([route('system_admin.company.edit', $this->company->id), route('system_admin.company.create')] as $url) {
            $html = $this->page($url);

            $field = $this->between($html, 'data-file-input', 'x-text="label"');

            $this->assertStringContainsString('name="logo"', $field, "⛔ {$url}: লোগোর ঘরটা বাংলা বোতামের ভিতরে নেই।");
            $this->assertStringContainsString(__('core.file.choose', [], 'bn'), $field, "⛔ {$url}: বোতামে বাংলা লেখা নেই।");
            $this->assertStringContainsString('accept="image/png,image/jpeg,image/webp"', $field, "⛔ {$url}: SVG আটকানোর accept হারিয়েছে।");
        }
    }

    public function test_the_branch_form_saves_from_the_sticky_bar(): void
    {
        $branch = Branch::query()->where('company_id', $this->company->id)->firstOrFail();

        $html = $this->page(route('system_admin.branch.edit', $branch->id));

        $form = $this->between($html, 'action="'.route('system_admin.branch.update', $branch->id).'"', '</form>');

        $this->assertStringContainsString('data-form-actions', $form, '⛔ শাখার ফর্মে স্থির পট্টি নেই।');
        $this->assertStringContainsString('href="'.route('system_admin.branch.index').'"', $form, '⛔ বাতিল শাখার তালিকায় ফেরে না।');
        $this->assertSame(1, substr_count($form, 'type="submit"'), '⛔ শাখার ফর্মে সংরক্ষণ একটার বেশি।');
    }

    private function page(string $url): string
    {
        return (string) $this->get($url)->assertOk()->getContent();
    }

    private function between(string $html, string $from, string $to): string
    {
        $at = strpos($html, $from);
        $this->assertNotFalse($at, "⛔ পাতায় «{$from}» নেই।");
        $end = strpos($html, $to, $at);
        $this->assertNotFalse($end, "⛔ «{$from}»-এর পরে «{$to}» নেই।");

        return substr($html, $at, $end - $at);
    }
}
