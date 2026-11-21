<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Jobs;

use App\Core\Services\FcmSender;
use App\Models\SyncDevice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A silent `call_end` to every phone of one person — closes a ringing screen (contract §5).
 *
 * ⓘ Data only, no title: the phone does not show it, the app hears it. One quick retry at most —
 * a "stop ringing" that comes a minute late is worth nothing.
 */
class SendCallEnd implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [5];

    public function __construct(
        public readonly int $userId,
        public readonly string $callId,
        public readonly string $status,
    ) {}

    /** @return array<string, string> */
    public function data(): array
    {
        return [
            'open' => 'messenger',
            'type' => 'call_end',
            'call_id' => $this->callId,
            'status' => $this->status,
        ];
    }

    public function handle(FcmSender $fcm): void
    {
        $devices = SyncDevice::query()->withoutGlobalScopes()
            ->where('user_id', $this->userId)
            ->whereNotNull('push_token')
            ->get();

        $retry = false;

        foreach ($devices as $device) {
            $result = $fcm->sendData((string) $device->push_token, $this->data());

            if ($result === FcmSender::OFF) {
                return;
            }

            if ($result === FcmSender::GONE) {
                // the app was removed or the token changed — never send there again
                $device->forceFill(['push_token' => null, 'push_token_at' => null])->save();
            }

            $retry = $retry || $result === FcmSender::FAILED;
        }

        if ($retry && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 5);
        }
    }
}
