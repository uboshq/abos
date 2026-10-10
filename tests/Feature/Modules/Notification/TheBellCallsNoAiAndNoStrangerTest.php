<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Models\NotificationSubscription;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ⭐ বিজ্ঞপ্তির কোড কোনো AI ডাকে না, আর কোনো অচেনা বাইরের ঠিকানায় যায় না (মালিকের নিয়ম; cloud task "Tests")।
 *
 * ⭐ দাবি:
 *   · বিজ্ঞপ্তির কোনো ফাইলে কোনো AI সেবা বা লাইব্রেরির নাম নেই, আর composer-এ কোনো AI লাইব্রেরি নেই
 *   · কোডে লেখা প্রতিটা বাইরের ঠিকানা চেনা তালিকার (ব্রাউজারের পুশ-সেবা, SVG-র namespace)
 *   · ব্রাউজার থেকে আসা পুশ-ঠিকানাও কেবল চেনা পুশ-সেবার — অচেনা, http, পোর্ট বা লগইনসহ ঠিকানা নয় (SSRF)
 */
final class TheBellCallsNoAiAndNoStrangerTest extends TestCase
{
    /** বিজ্ঞপ্তির সব কোড — কোর, মডিউল, পর্দা, কাজ, কমান্ড আর ব্রাউজারের service worker */
    private const PLACES = [
        'app/Core/Notifications',
        'app/Modules/Notification',
        'app/Core/Services/NotificationService.php',
        'app/Core/Services/NotificationAudit.php',
        'app/Core/Support/NotificationKinds.php',
        'app/Http/Controllers/NotificationController.php',
        'app/Http/Controllers/NotificationPushController.php',
        'app/Http/Controllers/Api/NotificationApiController.php',
        'app/Jobs/DeliverNotification.php',
        'app/Console/Commands/NotificationsDeliver.php',
        'app/Console/Commands/NotificationsHealth.php',
        'app/Models/Notification.php',
        'app/Models/NotificationEvent.php',
        'app/Models/NotificationChannel.php',
        'app/Models/NotificationJob.php',
        'app/Models/NotificationDeliveryAttempt.php',
        'app/Models/NotificationSubscription.php',
        'app/Models/NotificationAuditLog.php',
        'resources/views/notifications',
        'resources/views/components/shell/notification-bell.blade.php',
        'public/notification-sw.js',
    ];

    /** ⛔ AI সেবা আর লাইব্রেরির নাম */
    private const AI = '/\b(openai|anthropic|claude|gemini|chatgpt|gpt-?[0-9]|llm|huggingface|cohere|mistral|ollama|bedrock|vertex ?ai|langchain|copilot)\b/i';

    /** কোডে লেখা যে বাইরের ঠিকানাগুলো চলে */
    private const KNOWN_HOSTS = ['www.w3.org'];

    public function test_no_notification_file_names_an_ai_or_a_stranger_host(): void
    {
        $ai = [];
        $strangers = [];

        foreach ($this->files() as $file) {
            $text = (string) file_get_contents($file);
            $short = str_replace(base_path().'/', '', $file);

            if (preg_match(self::AI, $text, $m)) {
                $ai[] = $short.' — '.$m[0];
            }

            preg_match_all('#\bhttps?://([a-z0-9.\-]+)#i', $text, $hosts);

            foreach (array_unique(array_map('strtolower', $hosts[1])) as $host) {
                if (! in_array($host, self::KNOWN_HOSTS, true) && ! NotificationSubscription::knownEndpoint('https://'.$host.'/')) {
                    $strangers[] = $short.' — '.$host;
                }
            }
        }

        $this->assertGreaterThan(30, count($this->files()), 'ফাইলগুলো খুঁজে পাওয়া গেল না — পরীক্ষাটা কিছুই মাপছে না');
        $this->assertSame([], $ai, "⛔ বিজ্ঞপ্তির কোডে AI:\n".implode("\n", $ai));
        $this->assertSame([], $strangers, "⛔ বিজ্ঞপ্তির কোডে অচেনা বাইরের ঠিকানা:\n".implode("\n", $strangers));
    }

    public function test_composer_brings_in_no_ai_library(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $packages = array_keys(array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []));

        $this->assertSame([], array_values(preg_grep(self::AI, $packages)), '⛔ composer-এ AI লাইব্রেরি');
        $this->assertContains('minishlink/web-push', $packages);
    }

    public function test_a_push_address_from_the_browser_must_belong_to_a_known_push_service(): void
    {
        foreach ([
            'https://fcm.googleapis.com/fcm/send/abc',
            'https://updates.push.services.mozilla.com/wpush/v2/abc',
            'https://web.push.apple.com/abc',
            'https://db5p.notify.windows.com/w/?token=abc',
        ] as $ok) {
            $this->assertTrue(NotificationSubscription::knownEndpoint($ok), $ok);
        }

        foreach ([
            'https://push.example.com/send/abc',
            'http://fcm.googleapis.com/fcm/send/abc',
            'https://fcm.googleapis.com:8443/fcm/send/abc',
            'https://user:pass@fcm.googleapis.com/fcm/send/abc',
            'https://fcm.googleapis.com.evil.test/abc',
            'https://evilnotify.windows.com.attacker.test/abc',
            'https://169.254.169.254/latest/meta-data',
            'https://localhost/abc',
            'not a url',
        ] as $bad) {
            $this->assertFalse(NotificationSubscription::knownEndpoint($bad), '⛔ অচেনা পুশ-ঠিকানা চলে গেল: '.$bad);
        }
    }

    /** @return list<string> */
    private function files(): array
    {
        $out = [];

        foreach (self::PLACES as $place) {
            $path = base_path($place);

            if (is_dir($path)) {
                foreach (File::allFiles($path) as $file) {
                    $out[] = $file->getPathname();
                }
            } elseif (is_file($path)) {
                $out[] = $path;
            }
        }

        return $out;
    }
}
