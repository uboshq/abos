<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification\Support;

use App\Core\Notifications\DeliveryChannel;
use App\Core\Notifications\DeliveryResult;
use App\Models\Notification;
use App\Models\NotificationChannel;
use App\Models\User;
use Throwable;

/**
 * ⓘ পরীক্ষার মাধ্যম — আগে থেকে বলা ফলগুলো একে একে ফেরায় (পৌঁছেছে, সাময়িক ভুল, স্থায়ী ভুল, বা ব্যতিক্রম ছোড়া)।
 * সত্যিকারের প্রোভাইডার ছাড়াই আবার-চেষ্টা, ব্যর্থ-তালিকা আর "প্রোভাইডার বন্ধ" মাপা যায়। শেষ ফলটা বারবার চলে।
 */
final class ScriptedChannel implements DeliveryChannel
{
    public int $calls = 0;

    public bool $breaksOnReach = false;

    /** @param  list<DeliveryResult|Throwable>  $script */
    public function __construct(private array $script, private readonly string $key = NotificationChannel::EMAIL) {}

    /** @param  list<DeliveryResult|Throwable>  $script */
    public function then(array $script): void
    {
        $this->script = $script;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function provider(?NotificationChannel $config): string
    {
        return 'scripted';
    }

    public function connected(?NotificationChannel $config): bool
    {
        return true;
    }

    public function reaches(User $user): bool
    {
        if ($this->breaksOnReach) {
            throw new \RuntimeException('the provider\'s lookup is down');
        }

        return true;
    }

    public function send(Notification $notification, User $user, ?NotificationChannel $config): DeliveryResult
    {
        $this->calls++;
        $next = count($this->script) > 1 ? array_shift($this->script) : $this->script[0];

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    public function test(User $user, ?NotificationChannel $config): DeliveryResult
    {
        return $this->send(new Notification, $user, $config);
    }
}
