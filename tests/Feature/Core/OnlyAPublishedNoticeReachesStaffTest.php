<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\NoticeAcknowledgement;
use App\Core\Services\NoticeBoard;
use App\Core\Services\NoticeLifecycle;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notice;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ কর্মীর তালিকায়, গোনায় আর সইয়ে কেবল প্রকাশিত নোটিশ — Notice লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬ ([[NoticeBoard::queryFor()]])।
 *
 * ⓘ তালিকা দেখত কেবল `is_active` আর তারিখ। খসড়াও `is_active` হয়ে জন্মায়, আর ফেরানো বা স্থগিত নোটিশ কেবল বার থেকে নামে — তাই
 * আধা-লেখা খসড়া সবার তালিকায়, অপঠিতের গোনায় আর ফোনে উঠত, ফেরানো নোটিশ থেকে যেত, আর দুটোতেই সই দেওয়া যেত।
 */
final class OnlyAPublishedNoticeReachesStaffTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_and_a_recalled_notice_are_neither_listed_nor_signed(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $staff = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true]);
        $staff->companies()->attach($company->id, ['is_active' => true]);
        $staff = $staff->fresh();

        $life = app(NoticeLifecycle::class);
        $board = app(NoticeBoard::class);
        $notice = $life->draft(['title' => 'আধা-লেখা', 'body' => 'এখনো লেখা চলছে', 'ack_required' => true]);

        $this->assertFalse($board->forUser($staff)->contains('id', $notice->id), '⛔ খসড়া কর্মীর তালিকায়');
        $this->assertNotContains($notice->id, $board->unreadIds($staff), '⛔ খসড়া অপঠিতের গোনায়');
        $this->assertRefusedToSign($notice, $staff);

        // ⓘ প্রকাশিত — আগের মতোই সবার তালিকায়
        $notice = $life->publish($life->approve($life->submit($notice)));
        $this->assertTrue($board->forUser($staff)->contains('id', $notice->id), 'প্রকাশিত নোটিশও তালিকায় নেই — নিয়মটা সব বন্ধ করেছে');

        $notice = $life->recall($notice, 'ভুল তথ্য');
        $this->assertFalse($board->forUser($staff)->contains('id', $notice->id), '⛔ ফেরানো নোটিশ কর্মীর তালিকায় থেকে গেল');
        $this->assertRefusedToSign($notice->fresh(), $staff);
    }

    private function assertRefusedToSign(Notice $notice, User $staff): void
    {
        try {
            app(NoticeAcknowledgement::class)->sign($notice, $staff);
            $this->fail('⛔ প্রকাশিত নয় এমন নোটিশে সই হল');
        } catch (ValidationException $e) {
            $this->assertSame(__('core.notice.not_live_to_sign'), $e->errors()['notice'][0] ?? null);
        }
    }
}
