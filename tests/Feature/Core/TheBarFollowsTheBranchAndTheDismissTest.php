<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\NoticeAudience;
use App\Core\Services\NoticeBoard;
use App\Core\Services\NoticeLifecycle;
use App\Core\Services\StatusNotices;
use App\Core\Support\CompanyContext;
use App\Core\Support\NoticePriority;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ নিচের বার হেডারের শাখা বদলালে আর নোটিশ সরালে সাথে সাথে বদলায় — Notice লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬
 * ([[StatusNotices::cacheKey()]])।
 *
 * ⓘ বারের এক মিনিটের ক্যাশের চাবিতে কেবল কোম্পানি আর মানুষ ছিল — অন্য শাখায় গেলে আগের শাখার নোটিশ বারে থাকত, আর সরানো নোটিশও
 * মিনিটখানেক ফিরে ফিরে আসত।
 */
final class TheBarFollowsTheBranchAndTheDismissTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bar_changes_at_once_with_the_header_branch_and_a_dismiss(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $mms = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $ntk = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        CompanyContext::set($company->id, $mms->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $life = app(NoticeLifecycle::class);
        $notice = $life->draft(['title' => 'NTK-BAR-NOTICE', 'body' => 'নেত্রকোনার জন্য', 'priority' => NoticePriority::IMPORTANT->value]);
        app(NoticeAudience::class)->aimAt($notice, ['branch:'.$ntk->id]);
        $notice = $life->publish($life->approve($life->submit($notice)));

        $onBar = fn (): bool => collect(app(StatusNotices::class)->forTicker())->contains(fn (array $n) => $n['text'] === 'NTK-BAR-NOTICE');

        // ⓘ ময়মনসিংহে — নেই (আর ক্যাশে বসল)
        $this->assertFalse($onBar(), 'দৃশ্যটাই বানানো যায়নি — অন্য শাখার নোটিশ বারে');

        // ⛔ নেত্রকোনা বাছলে সাথে সাথে
        CompanyContext::set($company->id, $ntk->id);
        $this->assertTrue($onBar(), '⛔ শাখা বদলানোর পরেও বার আগের শাখার — এক মিনিট পুরনো');

        // ⛔ সরালে সাথে সাথে চলে যায়
        $this->assertTrue(app(NoticeBoard::class)->pushAside($notice->fresh(), $owner));
        $this->assertFalse($onBar(), '⛔ সরানো নোটিশ বারে থেকে গেল');
    }
}
