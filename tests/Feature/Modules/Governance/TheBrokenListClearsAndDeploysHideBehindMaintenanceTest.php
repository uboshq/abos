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
 * "কী ভেঙেছে" পরিষ্কার, আর ডিপ্লয়ের সময় "রক্ষণাবেক্ষণ চলছে" — মালিক, ১ অক্টোবর ২০২৬: *"etai koro r poriskar koro"*।
 *
 *   পরিষ্কার    এক চাপে সব খোলা সারি "দেখা হয়েছে" — তালিকা খালি, কিন্তু মোছা নয় ("দেখাগুলোও" টিকে ফেরে, কে দেখলেন লেখা)
 *   ডিপ্লয়     নতুন কোড আসার আগে সাইট বন্ধ, ক্যাশ তৈরির পরে খোলা, ব্যর্থ হয়ে ফিরলেও খোলা ([[infra/deploy.sh]])
 *   পাতা       বন্ধের সময় যা দেখা যায় — "রক্ষণাবেক্ষণ চলছে"
 * ⚠️ পরীক্ষায় আসল `artisan down` নয় — ওটা ভাগ করা `storage/framework/down` ফাইল বসায়, আর একই ট্রি-তে চলা অন্য
 * সব রানকে বন্ধ করে দিত। পাতাটা তাই সরাসরি আঁকা হয়।
 */
final class TheBrokenListClearsAndDeploysHideBehindMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_press_clears_the_list_without_deleting_the_history(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        foreach (['ZqViewException one', 'ZqQueryException two'] as $i => $message) {
            ErrorEvent::query()->create([
                'company_id' => $company->id, 'fingerprint' => 'zq-'.$i, 'class' => 'ErrorException', 'message' => $message,
                'file' => 'x.php', 'line' => 1, 'path' => '/x', 'method' => 'GET', 'times' => 1,
                'first_seen_at' => now(), 'last_seen_at' => now(),
            ]);
        }

        $before = (string) $this->actingAs($owner)->get(route('governance.error.index'))->assertOk()->getContent();
        $this->assertStringContainsString('ZqViewException one', $before);

        $this->actingAs($owner)->post(route('governance.error.acknowledge_all'))->assertRedirect();

        $after = (string) $this->actingAs($owner)->get(route('governance.error.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('ZqViewException one', $after, '⛔ পরিষ্কারের পরেও সারি তালিকায়।');
        $this->assertStringNotContainsString('ZqQueryException two', $after);

        $history = (string) $this->actingAs($owner)->get(route('governance.error.index', ['only' => 'all']))->assertOk()->getContent();
        $this->assertStringContainsString('ZqViewException one', $history, '⛔ পরিষ্কার মানে মোছা হয়ে গেছে — ইতিহাস হারাল।');
        $this->assertSame(2, ErrorEvent::query()->where('acknowledged_by', $owner->id)->where('fingerprint', 'like', 'zq-%')->count());

        // ⓘ যাঁর তালিকা দেখার চাবি নেই, তিনি পরিষ্কারও করতে পারেন না
        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->actingAs($sales)->post(route('governance.error.acknowledge_all'))->assertForbidden();
    }

    public function test_the_deploy_closes_the_site_before_new_code_and_opens_it_before_the_health_check(): void
    {
        $script = (string) file_get_contents(base_path('infra/deploy.sh'));
        $code = implode("\n", array_filter(explode("\n", $script), fn ($l) => ! str_starts_with(ltrim($l), '#')));

        $backup = strpos($code, 'php artisan abos:backup');
        $down = strpos($code, 'php artisan down');
        $pull = strpos($code, 'git pull --ff-only');
        $optimise = strrpos($code, 'php artisan abos:optimise');
        $up = strrpos($code, 'php artisan up');
        $health = strpos($code, 'for i in 1 2 3');

        $this->assertNotFalse($down, '⛔ ডিপ্লয়ে রক্ষণাবেক্ষণের পাতাই নেই।');
        $this->assertTrue($backup < $down && $down < $pull, 'রক্ষণাবেক্ষণ চালু হওয়ার কথা ব্যাকআপের পরে, নতুন কোড আসার আগে।');
        $this->assertTrue($optimise < $up && $up < $health, 'সাইট খোলার কথা ক্যাশ তৈরির পরে, স্বাস্থ্য-পরীক্ষার আগে।');

        $rollback = substr($code, strpos($code, 'rollback() {'), strpos($code, 'trap rollback ERR') - strpos($code, 'rollback() {'));
        $this->assertStringContainsString('php artisan up', $rollback, '⛔ ব্যর্থ ডিপ্লয় সাইটটাকে রক্ষণাবেক্ষণে আটকে রাখত।');
        $this->assertStringContainsString('--render="errors::503"', $code);
    }

    public function test_the_maintenance_page_says_maintenance_in_the_owners_words(): void
    {
        app()->setLocale('bn');
        $html = view('errors.503')->render();

        $this->assertStringContainsString('রক্ষণাবেক্ষণ চলছে', $html);
    }
}
