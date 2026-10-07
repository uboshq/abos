<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\Backup\BackupFreshness;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `/up` কেবল বলত PHP জেগে আছে — ডাটাবেস, ডিস্ক বা ব্যাকআপ নয়।
 *
 * ── কেন, ২৭ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * নিরীক্ষায় ধরা পড়ল `infra/deploy.sh` ফেরার সিদ্ধান্ত নেয় `/up` দেখে,
 * আর `/up` Laravel-এর নিজের — ডাটাবেস বন্ধ থাকলেও সে ২০০ দেয়। ⛔ অর্থাৎ
 * মাইগ্রেশনের পরে ডাটাবেস সংযোগ ভাঙলে ডিপ্লয় লিখত "উঠেছে", আর কাউন্টারে
 * প্রতিটা পাতা ৫০০।
 *
 * ⭐ `/health` তিনটা প্রশ্ন করে: ডাটাবেস সাড়া দেয় কি না, ডিস্কে লেখা যায়
 * আর জায়গা আছে কি না, শেষ কাজের ব্যাকআপ টাটকা কি না। একটাও না মিললে ৫০৩।
 *
 * ⓘ ডাটাবেস লাগে কেবল `select 1`-এর জন্য, টেবিল নয় — তাই RefreshDatabase
 * নেই, আর পরীক্ষাটা সেকেন্ডে চলে।
 */
final class TheHealthCheckOnlyAskedIfPhpWasAliveTest extends TestCase
{
    private string $backups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backups = sys_get_temp_dir().DIRECTORY_SEPARATOR.'abos-health-'.bin2hex(random_bytes(6));
        mkdir($this->backups, 0775, true);

        config(['abos.backup.path' => $this->backups]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backups.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->backups);

        parent::tearDown();
    }

    public function test_a_healthy_server_answers_200_with_every_check_ok(): void
    {
        $this->aBackupTakenHoursAgo(1);

        $this->health()
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'checks' => ['db' => 'ok', 'disk' => 'ok', 'backup' => 'ok'],
            ]);
    }

    public function test_an_unreachable_database_answers_503(): void
    {
        $this->aBackupTakenHoursAgo(1);
        $this->databaseIsUnreachable();

        $this->health()
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'fail',
                'checks' => ['db' => 'fail', 'disk' => 'ok', 'backup' => 'ok'],
            ]);
    }

    /**
     * ⛔ ডাটাবেস বন্ধ, সেশনও ডাটাবেসে — তবু সৎ ৫০৩, ৫০০ নয়।
     *
     * ⓘ দরজাটা `web` গোষ্ঠীর বাইরে, আর এই দাবিটা সেটার পাহারা: গোষ্ঠীতে
     * থাকলে সেশন, কোম্পানি আর লাইসেন্স ডাটাবেস ছুঁত, আর নিয়ন্ত্রকে পৌঁছানোর
     * আগেই ৫০০ হত — ডিপ্লয় তখন "কেন" না জেনেই ফেরত যেত।
     */
    public function test_a_dead_database_with_database_sessions_still_answers_an_honest_503(): void
    {
        $this->aBackupTakenHoursAgo(1);
        config(['session.driver' => 'database']);
        $this->databaseIsUnreachable();

        $this->health()
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'fail',
                'checks' => ['db' => 'fail', 'disk' => 'ok', 'backup' => 'ok'],
            ]);
    }

    public function test_a_disk_below_the_free_space_floor_answers_503(): void
    {
        $this->aBackupTakenHoursAgo(1);

        // ⓘ এক পেটাবাইট — কোনো মেশিনেই এত খালি জায়গা নেই
        config(['abos.health.min_free_mb' => 1_000_000_000]);

        $this->health()
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'fail',
                'checks' => ['db' => 'ok', 'disk' => 'fail', 'backup' => 'ok'],
            ]);
    }

    public function test_a_backup_older_than_the_limit_answers_503(): void
    {
        $this->aBackupTakenHoursAgo(BackupFreshness::STALE_AFTER_HOURS + 1);

        $this->health()
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'fail',
                'checks' => ['db' => 'ok', 'disk' => 'ok', 'backup' => 'fail'],
            ]);
    }

    public function test_no_backup_at_all_answers_503(): void
    {
        $this->health()
            ->assertStatus(503)
            ->assertJsonPath('status', 'fail')
            ->assertJsonPath('checks.backup', 'fail');
    }

    /**
     * ⛔ দরজাটা খোলা, তাই উত্তরটা বাইরের যে কেউ পড়ে।
     *
     * ⚠️ কারণটা লগে যায়, উত্তরে নয় — ঠিকানা, হোস্ট, সংস্করণ বা ভুলের
     * লেখা বাইরে গেলে আক্রমণকারী জানত কোথায় কী আছে।
     */
    public function test_the_body_never_names_a_path_a_host_a_version_or_an_exception(): void
    {
        $this->aBackupTakenHoursAgo(BackupFreshness::STALE_AFTER_HOURS + 1);
        $this->databaseIsUnreachable();

        $body = $this->health()->assertStatus(503)->getContent();

        foreach ([
            $this->backups,
            base_path(),
            storage_path(),
            str_replace('\\', '/', base_path()),
            str_replace('\\', '\\\\', base_path()),
            '127.0.0.1',
            'health_unreachable',
            (string) gethostname(),
            'SQLSTATE',
            'Exception',
            'PDO',
            PHP_VERSION,
            app()->version(),
        ] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $body, "উত্তরে ফাঁস: {$secret}");
        }

        $this->assertSame(['status', 'checks'], array_keys((array) json_decode((string) $body, true)));
    }

    public function test_it_answers_without_anyone_signing_in(): void
    {
        $this->aBackupTakenHoursAgo(1);

        $this->assertGuest();

        $this->health()->assertOk()->assertHeader('Content-Type', 'application/json');
    }

    /**
     * ⓘ প্রতিটা ডাকে একটা কোয়েরি আর একটা ফাইল লেখা — খোলা দরজায়
     * সীমা না থাকলে ওটাই একটা সস্তা চাপের পথ হত।
     */
    public function test_the_door_is_throttled(): void
    {
        $this->aBackupTakenHoursAgo(1);

        for ($i = 0; $i < HealthController::PER_MINUTE; $i++) {
            $this->health()->assertOk();
        }

        $this->health()->assertStatus(429);
    }

    private function health(): TestResponse
    {
        return $this->getJson('/health');
    }

    private function aBackupTakenHoursAgo(int $hours): void
    {
        $file = $this->backups.DIRECTORY_SEPARATOR.'abos-2026-09-27-010000.sql.gz';

        file_put_contents($file, str_repeat('x', 4096));
        touch($file, time() - $hours * 3600);
        clearstatcache();
    }

    /**
     * ⓘ বন্ধ পোর্টে একটা ভুয়া সংযোগ — আসল পরীক্ষার ডাটাবেস ছোঁয়া হয় না।
     */
    private function databaseIsUnreachable(): void
    {
        $default = (string) config('database.default');

        config([
            'database.connections.health_unreachable' => array_merge(
                (array) config("database.connections.{$default}"),
                ['host' => '127.0.0.1', 'port' => 1, 'options' => [\PDO::ATTR_TIMEOUT => 2]],
            ),
            'database.default' => 'health_unreachable',
        ]);

        DB::purge('health_unreachable');
    }
}
