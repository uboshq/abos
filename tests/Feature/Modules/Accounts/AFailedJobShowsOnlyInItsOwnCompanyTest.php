<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Jobs\SendPushToUser;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * হিসাবের "পটভূমির কাজ" পাতায় কেবল এই কোম্পানির ব্যর্থ কাজ — অডিট ⛔৯ (৬ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে `failed_jobs` থেকে সব সারি আসত। ঐ টেবিলে কোম্পানির ঘর নেই, আর ভুলের বার্তায় অন্য প্রতিষ্ঠানের নাম আর SQL-এর
 * মান থাকে।
 *
 * দাবি:
 *  - সারিতে পাঠানো প্রতিটা কাজের গায়ে পাঠানোর সময়ের কোম্পানির দাগ (`abos_company`)।
 *  - পাতায় নিজের কোম্পানির ব্যর্থ কাজ দেখা যায়; অন্য কোম্পানির আর দাগহীন পুরনো সারি দেখা যায় না।
 */
final class AFailedJobShowsOnlyInItsOwnCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_jobs_page_shows_only_this_companys_failed_jobs(): void
    {
        $this->seed(DemoSeeder::class);
        $mine = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $other = Company::query()->where('id', '<>', $mine->id)->firstOrFail();
        CompanyContext::set($mine->id, $mine->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        // ── দাগ: সারিতে পাঠানো কাজের গায়ে এই কোম্পানি ──
        Queue::connection('database')->push(new SendPushToUser($owner->id, 'hello'));
        $payload = json_decode((string) DB::table('jobs')->latest('id')->value('payload'), true);
        $this->assertSame($mine->id, $payload['abos_company'] ?? null, '⛔ কাজের গায়ে কোম্পানির দাগ নেই।');

        // ── পাতা: তিনটা ব্যর্থ কাজ — নিজের, অন্যের, দাগহীন ──
        $fail = fn (?int $company, string $marker) => DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(array_filter(['displayName' => 'X', 'abos_company' => $company], fn ($v) => $v !== null)),
            'exception' => "RuntimeException: {$marker}", 'failed_at' => now(),
        ]);
        $fail($mine->id, 'MINE-FAILED-123');
        $fail($other->id, 'OTHER-COMPANY-SECRET');
        $fail(null, 'UNTAGGED-OLD-ROW');

        $html = (string) $this->get(route('accounts.control.jobs'))->assertOk()->getContent();
        $this->assertStringContainsString('MINE-FAILED-123', $html, 'নিজের কোম্পানির ব্যর্থ কাজ দেখা যায় না — দাবি অন্ধ।');
        $this->assertStringNotContainsString('OTHER-COMPANY-SECRET', $html, '⛔ অন্য কোম্পানির ব্যর্থ কাজের বার্তা দেখা গেল।');
        $this->assertStringNotContainsString('UNTAGGED-OLD-ROW', $html, '⛔ কারও নয় এমন পুরনো সারি দেখা গেল।');
    }
}
