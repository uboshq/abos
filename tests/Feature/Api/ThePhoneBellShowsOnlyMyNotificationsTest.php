<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ফোনের ঘণ্টা — মালিক, ৬ অক্টোবর ২০২৬: *"User photo pase Notification icon dibe ekta, zate notification gulo dekha zay"*
 * ([[NotificationApiController]])।
 *
 * ⭐ দাবি: কেবল নিজের, এই কোম্পানির খবর, নতুনটা আগে, না-পড়ার গুনতিসহ; নিজেরটায় পড়ার দাগ বসে, অন্যেরটায় ৪০৪ আর তাঁর
 * ঘণ্টা অক্ষত; "সব পড়া" কেবল নিজের ঘণ্টা খালি করে।
 */
final class ThePhoneBellShowsOnlyMyNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $me;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        foreach (['me', 'other'] as $who) {
            $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
            $user->companies()->attach($this->company->id, ['is_active' => true]);
            $this->{$who} = $user;
        }
    }

    public function test_the_bell_lists_only_my_own_newest_first_with_the_unread_count(): void
    {
        $old = $this->tell($this->me, 'Old one');
        $new = $this->tell($this->me, 'New one');
        $this->tell($this->other, 'Not mine');
        $old->forceFill(['read_at' => now()])->save();

        $body = $this->asMe()->getJson('/api/v1/notifications')->assertOk()->json();

        $this->assertSame(['New one', 'Old one'], array_column($body['data'], 'title'), '⛔ অন্যের খবর এল, বা ক্রম উল্টো।');
        $this->assertSame((string) $new->public_id, $body['data'][0]['id']);
        $this->assertFalse($body['data'][0]['read']);
        $this->assertTrue($body['data'][1]['read']);
        $this->assertSame(1, $body['meta']['unread']);
        $this->assertArrayNotHasKey('user_id', $body['data'][0], 'ভেতরের নম্বর বাইরে গেল।');
    }

    public function test_reading_marks_mine_and_another_persons_is_a_404(): void
    {
        $mine = $this->tell($this->me, 'Mine');
        $theirs = $this->tell($this->other, 'Theirs');

        $this->asMe()->postJson("/api/v1/notifications/{$mine->public_id}/read")->assertOk();
        $this->assertNotNull($mine->fresh()->read_at);

        $this->asMe()->postJson("/api/v1/notifications/{$theirs->public_id}/read")->assertNotFound();
        $this->assertNull($theirs->fresh()->read_at, '⛔ অন্যের ঘণ্টা খালি করা গেল।');
    }

    public function test_read_all_empties_only_my_bell(): void
    {
        $this->tell($this->me, 'A');
        $this->tell($this->me, 'B');
        $theirs = $this->tell($this->other, 'C');

        $this->asMe()->postJson('/api/v1/notifications/read-all')->assertOk();

        $this->assertSame(0, $this->asMe()->getJson('/api/v1/notifications')->json('meta.unread'));
        $this->assertNull($theirs->fresh()->read_at, '⛔ "সব পড়া" অন্যের ঘণ্টাও খালি করল।');
    }

    private function tell(User $user, string $title): Notification
    {
        return Notification::query()->create([
            'company_id' => $this->company->id, 'user_id' => $user->id, 'type' => 'test.bell',
            'title' => $title, 'body' => $title.' body', 'url' => null,
        ]);
    }

    private function asMe(): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->me->fresh(), [AuthController::APP]);

        return $this;
    }
}
