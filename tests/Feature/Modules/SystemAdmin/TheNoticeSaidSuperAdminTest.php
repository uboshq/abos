<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Models\Notice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নোটিশের পর্দাগুলো রোলের নাম বাংলায় বলে, লেখার ফর্মে "বাতিল" আছে, আর ধরন/ছাঁচের দুই নামের ঘর কোন ভাষা তা বলে।
 *
 * ── ⭐ সিস্টেম পর্দার নকশা §৫, ১০ অক্টোবর ২০২৬ ─────────────────────────────────────────
 * *"কাদের পাবে — রোলের নাম বাংলায় (super_admin নয়); 'বাতিল'"* আর *"দুটো ঘরের নামই 'নাম' — 'বাংলা নাম' আর 'ইংরেজি নাম'"*।
 * ⓘ ভিতরের নাম অক্ষত — `notice_roles`-এ `super_admin`-ই বসে, কেবল পর্দার লেখা বাংলা।
 */
final class TheNoticeSaidSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($owner->fresh());
    }

    /** ⭐ টিকের মান ভিতরের নাম, পাশের লেখা বাংলা। */
    public function test_the_audience_ticks_read_in_bengali_and_send_the_inner_name(): void
    {
        $html = (string) $this->get(route('system_admin.notice.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/value="super_admin"[^>]*>\s*<span>সুপার অ্যাডমিন<\/span>/u', $html,
            '⛔ super_admin-এর টিকের পাশে বাংলা নাম নেই।');
        $this->assertStringNotContainsString('<span>super_admin</span>', $html, '⛔ পর্দায় এখনো কাঁচা super_admin।');
    }

    /** ⭐ পাঠানো নোটিশের তালিকা আর পাতা — "কারা দেখবেন" বাংলায়, সঞ্চয়ে ভিতরের নাম। */
    public function test_the_list_and_the_notice_say_who_in_bengali(): void
    {
        $this->post(route('system_admin.notice.store'), [
            'title' => 'হিসাবের সভা',
            'body' => 'বিকেল চারটায়',
            'is_active' => '1',
            'roles' => ['salesman'],
        ])->assertRedirect();

        $notice = Notice::query()->where('title', 'হিসাবের সভা')->firstOrFail();
        $this->assertSame(['salesman'], $notice->audience()->pluck('role')->all(), '⛔ সঞ্চয়ে ভিতরের নামটা বদলে গেছে।');

        foreach ([route('system_admin.notice.index'), route('system_admin.notice.show', $notice->id)] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('বিক্রয়কর্মী', $html, "⛔ {$url}: কারা দেখবেন — বাংলা নাম নেই।");
            $this->assertDoesNotMatchRegularExpression('/>\s*salesman\s*</', $html, "⛔ {$url}: কাঁচা salesman দেখা যাচ্ছে।");
        }
    }

    public function test_the_writing_form_has_cancel_on_the_sticky_bar(): void
    {
        $html = (string) $this->get(route('system_admin.notice.create'))->assertOk()->getContent();

        $form = substr($html, (int) strpos($html, 'action="'.route('system_admin.notice.store').'"'));
        $form = substr($form, 0, (int) strpos($form, '</form>'));

        $this->assertStringContainsString('data-form-actions', $form, '⛔ স্থির পট্টি নেই।');
        $this->assertStringContainsString('href="'.route('system_admin.notice.index').'"', $form, '⛔ "বাতিল" নেই।');
        $this->assertSame(1, substr_count($form, 'type="submit"'), '⛔ সংরক্ষণ একটার বেশি।');
    }

    /** ⭐ ধরন আর ছাঁচ — দুই নামের ঘর দুই ভাষা বলে, দুটোই "নাম" নয়। */
    public function test_the_two_name_fields_say_which_language(): void
    {
        foreach ([route('system_admin.notice.category.index'), route('system_admin.notice.template.index')] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('নাম (ইংরেজি)', $html, "⛔ {$url}: ইংরেজি নামের ঘর বলে না সেটা ইংরেজি।");
            $this->assertStringContainsString('নাম (বাংলা)', $html, "⛔ {$url}: বাংলা নামের ঘর বলে না সেটা বাংলা।");
        }
    }
}
