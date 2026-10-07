<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * `config:cache`-এর পরে `env()` চুপচাপ `null` দেয় — আর সুইচটা হারিয়ে যায়।
 *
 * ── কেন, ২৭ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * [[ContentSecurityPolicy]] সরাসরি `env('ABOS_CSP')` পড়ত। ⛔ ডিপ্লয়
 * `abos:optimise` চালায়, যা কনফিগ ক্যাশ করে — তারপর `.env` আর পড়া হয়
 * না, আর `env()` ফেরত দেয় ডিফল্ট। ⚠️ অর্থাৎ লাইভে কেউ কোনো ভাঙা পর্দার
 * জন্য `ABOS_CSP=off` দিলে কিছুই ঘটত না, আর তিনি ভাবতেন সুইচটা কাজ করে।
 *
 * ⭐ তাই সুইচটা এখন `config('abos.csp')` — ক্যাশে থাকে। পরীক্ষা দেয়
 * কেবল কনফিগ, আর উল্টো মানের env বসিয়ে প্রমাণ করে env আর পড়াই হয় না।
 *
 * ── আর একই রোগের বড় রূপ ─────────────────────────────────────────────
 * কোড যে চাবি পড়ে কিন্তু নমুনা `.env` ফাইলে নেই, সেটা নতুন সার্ভারে কেউ
 * বসায় না — তখন চলে ডিফল্ট, আর ডিফল্ট প্রায়ই উন্নয়নের মান। তাই এখানে
 * দুইটা নমুনা ফাইলের পাহারাও।
 */
final class TheCspSwitchVanishedUnderConfigCacheTest extends TestCase
{
    public function test_the_config_value_off_removes_the_header_without_any_env(): void
    {
        $this->withoutEnv(function (): void {
            config(['abos.csp' => 'off']);

            $this->assertNull(
                $this->get('/login')->assertOk()->headers->get('Content-Security-Policy'),
                '⛔ কনফিগে off, তবু CSP বসেছে — সুইচটা এখনও env() পড়ছে।'
            );
        });
    }

    /**
     * ⭐ উল্টো দিক: env বলছে off, কনফিগ বলছে on — কনফিগই জেতে। এটাই
     * প্রমাণ যে env() আর পড়া হয় না, কেবল "দুইটাই একমত" নয়।
     */
    public function test_an_env_that_says_off_is_ignored_when_the_config_says_on(): void
    {
        $this->withEnv('off', function (): void {
            config(['abos.csp' => 'on']);

            $this->assertNotNull(
                $this->get('/login')->assertOk()->headers->get('Content-Security-Policy'),
                '⛔ কনফিগে on, অথচ env-এর off মানা হয়েছে — ক্যাশের পরে এই সুইচ মরে যায়।'
            );
        });
    }

    public function test_every_env_key_the_code_reads_is_in_both_examples(): void
    {
        $read = $this->keysTheCodeReads();

        $this->assertGreaterThan(100, count($read), 'চাবিই পাওয়া যায়নি — পাহারাটা কিছু দেখছে না।');

        foreach (['.env.example', '.env.production.example'] as $file) {
            $missing = array_values(array_diff($read, $this->keysIn($file)));

            $this->assertSame([], $missing, "{$file}-এ নেই:\n".implode("\n", $missing));
        }
    }

    public function test_the_production_example_is_safe_by_default(): void
    {
        $values = $this->valuesIn('.env.production.example');

        $this->assertSame('production', $values['APP_ENV'] ?? null);
        $this->assertSame('false', $values['APP_DEBUG'] ?? null, '⛔ লাইভে APP_DEBUG=true মানে ভুলের পাতায় পুরো স্ট্যাক আর .env-এর মান।');
        $this->assertSame('warning', $values['LOG_LEVEL'] ?? null);
        $this->assertSame('true', $values['SESSION_SECURE_COOKIE'] ?? null);
        $this->assertSame('lax', $values['SESSION_SAME_SITE'] ?? null);
        $this->assertSame('on', $values['ABOS_CSP'] ?? null);

        foreach ($values as $key => $value) {
            if (preg_match('/(PASSWORD|SECRET|TOKEN|WEBHOOK|_KEY$|_KEYS$|API_KEY)/', $key) === 1) {
                $this->assertContains($value, ['', 'null'], "⛔ {$key}-এ একটা মান বসানো — নমুনা ফাইলে গোপন কিছু থাকবে না।");
            }
        }
    }

    /**
     * @return list<string>
     */
    private function keysTheCodeReads(): array
    {
        $keys = [];

        $files = Finder::create()->files()->name('*.php')
            ->in(array_map(base_path(...), ['app', 'config', 'bootstrap', 'routes']))
            ->exclude('cache');

        foreach ($files as $file) {
            preg_match_all('/env\(\s*[\'"]([A-Z0-9_]+)[\'"]/', $file->getContents(), $m);
            array_push($keys, ...$m[1]);
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * ⓘ মন্তব্য করা লাইনও গোনে (`# KEY=`) — ফ্রেমওয়ার্কের সূক্ষ্ম চাবি
     * খালি বসালে `env()` ফেরত দেয় `''`, ডিফল্ট নয়; তাই ওগুলো মন্তব্যেই।
     *
     * @return list<string>
     */
    private function keysIn(string $file): array
    {
        preg_match_all('/^\s*#?\s*([A-Z][A-Z0-9_]*)=/m', (string) file_get_contents(base_path($file)), $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * @return array<string, string>
     */
    private function valuesIn(string $file): array
    {
        preg_match_all('/^([A-Z][A-Z0-9_]*)=(.*)$/m', (string) file_get_contents(base_path($file)), $m, PREG_SET_ORDER);

        $values = [];

        foreach ($m as [, $key, $value]) {
            $values[$key] = trim(trim($value), '"');
        }

        return $values;
    }

    private function withoutEnv(callable $run): void
    {
        $this->withEnv(null, $run);
    }

    private function withEnv(?string $value, callable $run): void
    {
        $before = [getenv('ABOS_CSP'), $_ENV['ABOS_CSP'] ?? null, $_SERVER['ABOS_CSP'] ?? null];

        if ($value === null) {
            putenv('ABOS_CSP');
            unset($_ENV['ABOS_CSP'], $_SERVER['ABOS_CSP']);
        } else {
            putenv("ABOS_CSP={$value}");
            $_ENV['ABOS_CSP'] = $_SERVER['ABOS_CSP'] = $value;
        }

        try {
            $run();
        } finally {
            $before[0] === false ? putenv('ABOS_CSP') : putenv('ABOS_CSP='.$before[0]);

            unset($_ENV['ABOS_CSP'], $_SERVER['ABOS_CSP']);

            if ($before[1] !== null) {
                $_ENV['ABOS_CSP'] = $before[1];
            }

            if ($before[2] !== null) {
                $_SERVER['ABOS_CSP'] = $before[2];
            }
        }
    }
}
