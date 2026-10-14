<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\NoticeAudience;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notice;
use App\Models\NoticeRead;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ লক্ষ্যের বাইরের প্রশাসক নোটিশ খুললে তিনি "পাঠক" নন — Notice লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⓘ প্রকাশের পরে লেখককে সোজা নোটিশের পাতায় পাঠানো হয়, আর পাতা খোলামাত্র "পড়েছেন" বসত। অথচ লক্ষ্যের বাইরে বলে তিনি সই দিতে
 * পারেন না — তাই গুদামের সবাই সই দিলেও নোটিশটা চিরকাল "সইয়ের অপেক্ষায়", আর তাগাদা যেত মালিকের নামে।
 */
final class AManagerOpeningANoticeIsNotItsReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_someone_the_notice_is_for_is_counted_as_its_reader(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $notice = fn (string $title) => Notice::create(['title' => $title, 'body' => $title, 'is_active' => true, 'created_by' => $owner->id]);
        $elsewhere = $notice('গুদামের জন্য');
        app(NoticeAudience::class)->aimAt($elsewhere, ['role:কেউ-নয়']);
        $mine = $notice('মালিকের জন্য');
        app(NoticeAudience::class)->aimAt($mine, ['user:'.$owner->getKey()]);

        // ⓘ প্রশাসক খুলতে পারেন — কিন্তু পাঠক নন
        $this->get(route('system_admin.notice.show', $elsewhere->id))->assertOk();
        $this->assertSame(0, NoticeRead::query()->where('notice_id', $elsewhere->id)->count(), '⛔ লক্ষ্যের বাইরে খুললেও "পড়েছেন" বসল');

        // ⓘ নিজের জন্য নোটিশে আগের মতোই
        $this->get(route('system_admin.notice.show', $mine->id))->assertOk();
        $this->assertSame(1, NoticeRead::query()->where('notice_id', $mine->id)->where('user_id', $owner->id)->count());
    }
}
