<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\NoticeBoard;
use App\Core\Services\NoticeLifecycle;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\NoticePriority;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\Notice;
use App\Models\NoticeAck;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ফোনের নোটিশ বোর্ড — মালিক, ১৩ অক্টোবর ২০২৬: "app e baki module & menu"।
 *
 * দাবি ([[NoticeApiController]]):
 *  - তালিকা বলে কে লিখলেন, কবে থেকে, সইয়ের শেষ দিন, আর কয়টা না-পড়া — ওয়েবের নোটিশ পাতার একই গোনা ([[NoticeBoard::unreadCount()]])।
 *  - খুললে (`read`) না-পড়া কমে; সই দিলে অবস্থা "acknowledged", আর সইয়ের সারিতে যন্ত্রের নাম বসে।
 *  - সই না চাওয়া নোটিশে সই ৪২২, বাংলা কারণসহ; অন্য কোম্পানির নোটিশ ৪০৪।
 */
final class ThePhoneReadsAndSignsTheNoticeBoardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(SettingsService::class)->set('notice.creator_cannot_approve', false);
        app(SettingsService::class)->flush();
    }

    public function test_the_list_says_who_wrote_it_the_signing_deadline_and_how_many_are_unread(): void
    {
        $deadline = now()->addDays(3)->startOfMinute();
        $signed = $this->published('নতুন নিয়ম', ['ack_required' => true, 'ack_deadline' => $deadline]);
        $this->published('সাধারণ খবর');

        $body = $this->phone('GET', '/api/v1/notices')->assertOk()->json();
        $row = collect($body['data'])->firstWhere('id', $signed->public_id);

        $this->assertNotNull($row, '⛔ প্রকাশিত নোটিশ ফোনের তালিকায় নেই।');
        $this->assertSame($this->owner->name, $row['author'], '⛔ কে লিখলেন — ফোনে নেই।');
        $this->assertTrue($row['ack_required']);
        $this->assertSame($deadline->toIso8601String(), $row['ack_deadline'], '⛔ সইয়ের শেষ দিন ফোনে নেই।');
        $this->assertSame(app(NoticeBoard::class)->unreadCount($this->owner->fresh()), $body['meta']['unread'],
            '⛔ ফোনের না-পড়া গোনা ওয়েবের গোনার সাথে মেলে না।');
        $this->assertGreaterThanOrEqual(2, $body['meta']['unread'], 'দাবির ভিত্তি নেই — দুইটা নতুন নোটিশও না-পড়া নয়।');
    }

    public function test_opening_reads_it_and_signing_is_recorded_with_the_device(): void
    {
        $notice = $this->published('সই দিন', ['ack_required' => true]);
        $before = $this->phone('GET', '/api/v1/notices')->json('meta.unread');

        $this->phone('POST', '/api/v1/notices/'.$notice->public_id.'/read')->assertOk();
        $this->assertSame($before - 1, $this->phone('GET', '/api/v1/notices')->json('meta.unread'), '⛔ খোলার পরেও না-পড়া কমেনি।');

        $this->phone('POST', '/api/v1/notices/'.$notice->public_id.'/acknowledge')->assertOk()->assertJsonPath('data.standing', 'acknowledged');

        $ack = NoticeAck::query()->where('notice_id', $notice->id)->where('user_id', $this->owner->id)->sole();
        $this->assertNotNull($ack->acknowledged_at);
        $row = collect($this->phone('GET', '/api/v1/notices')->json('data'))->firstWhere('id', $notice->public_id);
        $this->assertSame('acknowledged', $row['standing'], '⛔ সই দেওয়ার পরেও তালিকা "সই বাকি" বলছে।');
    }

    public function test_a_notice_that_wants_no_signature_refuses_one_and_another_company_is_not_found(): void
    {
        $plain = $this->published('শুধু পড়ুন');
        $this->phone('POST', '/api/v1/notices/'.$plain->public_id.'/acknowledge')->assertStatus(422);
        $this->assertSame(0, NoticeAck::query()->where('notice_id', $plain->id)->count(), '⛔ সই না চাওয়া নোটিশে সই বসল।');

        $other = Company::query()->where('code', '!=', 'TDEPOT')->firstOrFail();
        $theirs = CompanyContext::forCompany((int) $other->id, function (): Notice {
            app(SettingsService::class)->set('notice.creator_cannot_approve', false);
            app(SettingsService::class)->flush();

            return $this->published('অন্য কোম্পানির নিয়ম', ['ack_required' => true]);
        });

        $this->phone('POST', '/api/v1/notices/'.$theirs->public_id.'/acknowledge')->assertNotFound();
        $this->assertNull(collect($this->phone('GET', '/api/v1/notices')->json('data'))->firstWhere('id', $theirs->public_id),
            '⛔ অন্য কোম্পানির নোটিশ তালিকায় এল।');
    }

    private function phone(string $method, string $url): TestResponse
    {
        app(SettingsService::class)->flush();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);

        return $this->json($method, $url);
    }

    /** @param array<string, mixed> $extra */
    private function published(string $title, array $extra = []): Notice
    {
        $life = app(NoticeLifecycle::class);
        $notice = $life->draft(['title' => $title, 'body' => $title.' — বিস্তারিত', 'priority' => NoticePriority::IMPORTANT->value] + $extra);

        return $life->publish($life->approve($life->submit($notice)));
    }
}
