<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\AuthController;
use App\Models\ErrorEvent;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⛔ মাঠের ফোনে অ্যাপ বন্ধ হলে অফিস জানতেই পারত না — সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬।
 *
 * এখন `POST /api/v1/app/crash` ভুলের খাতায় লেখে ([[AppCrashController]], [[ErrorJournal::recordFromPhone()]]) — নিজের সার্ভারে,
 * বাইরে কিছু নয়। দাবি: টোকেন ছাড়াও লেখে (লগইনের পর্দাও ভাঙে), টোকেনে মানুষ আর কোম্পানি জোড়া হয়, একই ভুল আবার এলে গোনা
 * বাড়ে, বড় বা অচেনা ঘর ফেরে, আর দরজায় সীমা আছে।
 */
final class ThePhoneCrashedAndNobodyKnewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        RateLimiter::clear('app-crash');
    }

    public function test_a_crash_before_sign_in_is_written_and_nothing_comes_back(): void
    {
        $response = $this->postJson('/api/v1/app/crash', $this->crash())->assertStatus(202);
        $this->assertSame([], $response->json());

        $row = ErrorEvent::query()->where('route', 'api.app.crash')->firstOrFail();
        $this->assertSame('phone:StateError', $row->class);
        $this->assertSame('Bad state: No element', $row->message);
        $this->assertSame('/home/collections', $row->path);
        $this->assertStringContainsString('0.4.24', (string) $row->file);
        $this->assertNull($row->user_id);
        $this->assertNull($row->company_id);
    }

    public function test_with_a_token_it_names_the_person_and_company_and_the_same_crash_is_counted(): void
    {
        $sr = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        Sanctum::actingAs($sr, [AuthController::APP]);

        $this->postJson('/api/v1/app/crash', $this->crash())->assertStatus(202);
        $this->postJson('/api/v1/app/crash', $this->crash())->assertStatus(202);

        $rows = ErrorEvent::query()->where('route', 'api.app.crash')->get();
        $this->assertCount(1, $rows, '⛔ একই ক্র্যাশ দুটো সারি হলো।');
        $this->assertSame(2, (int) $rows->first()->times);
        $this->assertSame((int) $sr->id, (int) $rows->first()->user_id);
        $this->assertSame((int) $sr->current_company_id, (int) $rows->first()->company_id);

        // ⓘ অন্য সংস্করণে একই ভুল — আলাদা সারি (কোন সংস্করণে ভাঙে, সেটাই প্রশ্ন)
        $this->postJson('/api/v1/app/crash', [...$this->crash(), 'version' => '0.4.25'])->assertStatus(202);
        $this->assertSame(2, ErrorEvent::query()->where('route', 'api.app.crash')->count());
    }

    public function test_an_oversized_or_empty_report_is_refused_and_the_door_has_a_limit(): void
    {
        $this->postJson('/api/v1/app/crash', [...$this->crash(), 'stack' => str_repeat('x', 8001)])->assertStatus(422);
        $this->postJson('/api/v1/app/crash', [...$this->crash(), 'error' => ''])->assertStatus(422);
        $this->postJson('/api/v1/app/crash', [...$this->crash(), 'version' => str_repeat('9', 33)])->assertStatus(422);
        $this->assertSame(0, ErrorEvent::query()->where('route', 'api.app.crash')->count());

        RateLimiter::clear('app-crash');
        $statuses = [];
        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $this->postJson('/api/v1/app/crash', $this->crash())->status();
        }
        $this->assertContains(429, $statuses, '⛔ খোলা দরজায় সীমা নেই।');
    }

    /** @return array<string, string> */
    private function crash(): array
    {
        return [
            'version' => '0.4.24',
            'screen' => '/home/collections',
            'error' => 'StateError',
            'message' => 'Bad state: No element',
            'stack' => "#0 ListMixin.first (dart:collection)\n#1 MoneyInListScreen.build (package:abos_mobile/features/books/money_in_screens.dart:120)",
        ];
    }
}
