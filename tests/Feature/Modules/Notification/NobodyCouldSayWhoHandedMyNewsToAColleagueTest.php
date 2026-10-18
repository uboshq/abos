<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Support\CompanyContext;
use App\Core\Support\NotificationKinds;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\NotificationPreference;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ ছুটির দায়িত্ব কে কাকে দিলেন, তা অডিটে থাকে — ১৭ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল ──────────────────────────────────────────────────
 * NotificationPreference অডিটের বাইরে ছিল ("ব্যক্তির নিজের পছন্দ")।
 * ⚠️ কিন্তু ঐ সারিতেই `delegate_user_id` বসে, আর সেটা একজনের **সব খবরের
 * কপি** — অনুমোদন, প্রত্যাখ্যান, টাকার খবর — আরেকজনের কাছে পাঠায়।
 * ⓘ পরে কেউ জিজ্ঞেস করলে "আমার খবর ও কেন পেল, কবে থেকে, কে দিল" —
 * উত্তর কোথাও ছিল না। AuditTest এই ফাঁকটাই লাল করে দেখাচ্ছিল।
 *
 * ⭐ দাবি: দায়িত্ব দেওয়া আর বদলানো দুইটাই অডিটে বসে, কে দিলেন আর কাকে।
 */
final class NobodyCouldSayWhoHandedMyNewsToAColleagueTest extends TestCase
{
    use RefreshDatabase;

    public function test_handing_ones_news_to_a_colleague_leaves_a_trail(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        $cover = User::factory()->create(['is_active' => true, 'current_company_id' => $company->id]);
        $cover->companies()->attach($company->id, ['is_active' => true]);
        $other = User::factory()->create(['is_active' => true, 'current_company_id' => $company->id]);
        $other->companies()->attach($company->id, ['is_active' => true]);

        $hand = fn (User $to) => $this->actingAs($clerk)->put(route('notifications.settings.update'), [
            'kinds' => array_keys(NotificationKinds::all()),
            'pref' => ['channels' => ['email'], 'delegate_user_id' => $to->id,
                'delegate_from' => now()->toDateString(), 'delegate_until' => now()->addDays(3)->toDateString()],
        ])->assertRedirect();

        $hand($cover);

        $pref = NotificationPreference::query()->where('user_id', $clerk->id)->firstOrFail();
        $created = AuditTrail::query()->forRecord(NotificationPreference::class, $pref->id)
            ->where('action', AuditTrail::CREATED)->first();

        $this->assertNotNull($created, '⛔ ছুটির দায়িত্ব দেওয়া হলো, অথচ অডিটে কোনো সারি নেই');
        $this->assertSame($clerk->id, (int) $created->user_id, '⛔ অডিট বলে না কে দায়িত্বটা দিলেন');

        $hand($other);

        $edit = AuditTrail::query()->forRecord(NotificationPreference::class, $pref->id)
            ->where('action', AuditTrail::UPDATED)->with('changes')->latest('id')->first();

        $this->assertNotNull($edit, '⛔ দায়িত্ব আরেকজনকে দেওয়া হলো, অথচ অডিটে বদলের সারি নেই');

        $field = $edit->changes->keyBy('field')->get('delegate_user_id');
        $this->assertNotNull($field, '⛔ বদলের সারিতে কাকে দেওয়া হলো তা নেই');
        $this->assertSame((string) $cover->id, (string) $field->old_value);
        $this->assertSame((string) $other->id, (string) $field->new_value);
    }
}
