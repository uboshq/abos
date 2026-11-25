<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Messaging;

use App\Core\Services\FcmSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ⛔ Firebase refuses the WHOLE push when its data holds a reserved word (`from`, `message_type`, google…, gcm…) — calls and
 * messages reached no phone for days, silently (owner, 25 Nov 2026: "অ্যাপে কল আসে না"). The sender drops the word and
 * sends the rest; a refusal is written once a day, never silently.
 */
final class AReservedWordNeverReachesFirebaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        $file = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($file, json_encode(['client_email' => 'push@abos.test', 'private_key' => $pem]));
        config(['services.firebase.credentials' => $file, 'services.firebase.project_id' => 'abos-test']);
        Cache::flush();
    }

    public function test_the_reserved_words_are_dropped_and_the_rest_goes(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 't']), 'fcm.googleapis.com/*' => Http::response(['name' => 'ok'])]);
        $data = ['open' => 'messenger', 'from' => '7', 'message_type' => 'x', 'google.a' => '1', 'gcm_b' => '2', 'sender' => '7', 'from_name' => 'R'];

        $this->assertSame(FcmSender::SENT, app(FcmSender::class)->send('tok', 'R', null, $data));
        $this->assertSame(FcmSender::SENT, app(FcmSender::class)->sendData('tok', $data));

        $sent = Http::recorded(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com'))->map(fn ($p) => $p[0]['message']['data'])->values()->all();
        $want = ['open' => 'messenger', 'sender' => '7', 'from_name' => 'R'];
        $this->assertSame([$want, $want], $sent, '⛔ a reserved word reached Firebase, or an allowed one was lost.');
    }

    public function test_a_refusal_is_reported_once_and_without_the_token(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 't']),
            'fcm.googleapis.com/*' => Http::response(['error' => ['message' => 'Invalid data payload key: x']], 400)]);
        \Illuminate\Support\Facades\Exceptions::fake();

        $this->assertSame(FcmSender::FAILED, app(FcmSender::class)->send('secret-token', 'R', null, ['a' => 'b']));
        $this->assertSame(FcmSender::FAILED, app(FcmSender::class)->send('secret-token', 'R', null, ['a' => 'b']));

        \Illuminate\Support\Facades\Exceptions::assertReportedCount(1);
        \Illuminate\Support\Facades\Exceptions::assertReported(fn (\RuntimeException $e) => str_contains($e->getMessage(), 'Invalid data payload key')
            && ! str_contains($e->getMessage(), 'secret-token'));
    }
}
