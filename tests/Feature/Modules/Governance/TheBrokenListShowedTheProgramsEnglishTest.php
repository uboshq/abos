<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Governance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\ErrorEvent;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "কী ভেঙেছে"-তে প্রোগ্রামের ইংরেজি সরাসরি মালিকের সামনে — পাতা-ঝাড়ু, ধাপ ০ (১০ অক্টোবর ২০২৬)।
 *
 * ⭐ সারির উপরে এক লাইনের বাংলা (কোন পাতায় ভাঙল, বা পেছনের কাজে); শ্রেণি, বার্তা আর কোডের ফাইল "কারিগরি তথ্য" ভাঁজে, শুরুতে বন্ধ।
 */
final class TheBrokenListShowedTheProgramsEnglishTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_program_text_sits_in_a_closed_fold_under_a_bengali_line(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        foreach ([['/customers/reports/due-list', 'GET'], [null, null]] as $i => [$path, $method]) {
            ErrorEvent::query()->create([
                'company_id' => $company->id, 'fingerprint' => 'zf-'.$i, 'class' => 'Illuminate\\Database\\QueryException',
                'message' => 'ZfSQLSTATE[42S22] column '.$i, 'file' => 'vendor/laravel/x/Connection.php', 'line' => 857,
                'path' => $path, 'method' => $method, 'times' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
            ]);
        }

        $html = (string) $this->actingAs($owner)->get(route('governance.error.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(__('governance::message.broke_on_page', ['page' => 'GET /customers/reports/due-list'])), $html);
        $this->assertStringContainsString(e(__('governance::message.broke_in_background')), $html);

        // ⛔ প্রোগ্রামের লেখা কেবল বন্ধ ভাঁজের ভেতরে
        $this->assertSame(2, preg_match_all('#<details(?![^>]*\sopen)[^>]*data-technical>(.*?)</details>#s', $html, $folds), '⛔ কারিগরি তথ্যের বন্ধ ভাঁজ নেই।');
        $outside = preg_replace('#<details[^>]*data-technical>.*?</details>#s', '', $html);
        foreach (['ZfSQLSTATE', 'QueryException', 'Connection.php'] as $raw) {
            $this->assertStringNotContainsString($raw, $outside, '⛔ প্রোগ্রামের ইংরেজি ভাঁজের বাইরে: '.$raw);
            $this->assertStringContainsString($raw, implode('', $folds[1]), 'কারিগরি তথ্য হারিয়ে গেল: '.$raw);
        }
    }
}
