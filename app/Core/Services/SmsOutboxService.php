<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Notifications\ProviderCallbacks;
use App\Core\Support\Actor;
use App\Core\Support\CompanyContext;
use App\Models\SmsGatewayPhone;
use App\Models\SmsOutbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ SMS-এর সারি — বসানো, ফোনের হাতে তুলে দেওয়া, আর ফোনের খবর লেখা (মালিকের "ok", নমুনা ধাপ ২)।
 *
 * ⓘ ফোন কেবল নিজের কোম্পানির সারি দেখে; দিনের সীমা পেরোলে আর দেয় না; নিয়ে খবর না দিলে [[SmsOutbox::TAKEN_MINUTES]] পরে
 *   আবার সারিতে, [[SmsOutbox::MAX_ATTEMPTS]] বার পরে "যায়নি"।
 */
final class SmsOutboxService
{
    /** ⓘ ফোন একবারে এর বেশি নেয় না */
    public const BATCH = 20;

    /** ⭐ সারিতে বসানো — এখনকার কোম্পানির জন্য */
    public function queue(string $phone, string $body, string $source, ?int $sourceId = null): SmsOutbox
    {
        return SmsOutbox::query()->create([
            'company_id' => CompanyContext::id(),
            'phone' => $phone,
            'body' => $body,
            'source' => $source,
            'source_id' => $sourceId,
            'status' => SmsOutbox::WAITING,
            'created_by' => Actor::userId(),
        ]);
    }

    /** ⓘ কোম্পানির চালু ফোন আছে কি না — না থাকলে পাঠানোর বোতাম আসে না, আর SMS-এর মাধ্যম "সংযুক্ত নয়" */
    public function hasPhone(): bool
    {
        return SmsGatewayPhone::query()->where('is_active', true)->exists();
    }

    /**
     * ⭐ ফোনের হাতে — দিনের সীমার মধ্যে, পুরনোটা আগে।
     *
     * ⓘ একই সারি দুই ফোন যেন না নেয়: সারিগুলো লক করে নেওয়া ([[DB::transaction()]] + lockForUpdate)।
     *
     * @return list<array{id: string, phone: string, body: string}>
     */
    public function take(SmsGatewayPhone $gateway, ?string $appVersion = null): array
    {
        return CompanyContext::forCompany((int) $gateway->company_id, function () use ($gateway, $appVersion): array {
            $gateway->forceFill(['last_seen_at' => now()] + ($appVersion === null ? [] : ['app_version' => $appVersion]))->save();
            $this->release();

            $room = max(0, (int) $gateway->daily_limit - $gateway->sentToday());

            if ($room === 0) {
                return [];
            }

            return DB::transaction(function () use ($gateway, $room): array {
                $rows = SmsOutbox::query()->where('status', SmsOutbox::WAITING)
                    ->orderBy('id')->limit(min($room, self::BATCH))->lockForUpdate()->get();

                foreach ($rows as $row) {
                    $row->forceFill([
                        'status' => SmsOutbox::TAKEN, 'gateway_phone_id' => $gateway->id, 'taken_at' => now(), 'attempts' => $row->attempts + 1,
                    ])->save();
                }

                return $rows->map(fn (SmsOutbox $r): array => ['id' => (string) $r->public_id, 'phone' => '+'.$r->phone, 'body' => (string) $r->body])->values()->all();
            });
        });
    }

    /**
     * ⭐ ফোনের খবর — sent (সিম থেকে বেরিয়েছে), delivered (অপারেটর বলছে পৌঁছেছে), failed (যায়নি, কারণসহ)।
     *
     * ⛔ কেবল এই ফোনের নেওয়া সারি — অন্য ফোনের বা অন্য কোম্পানির সারির খবর গোনা হয় না।
     */
    public function report(SmsGatewayPhone $gateway, string $publicId, string $event, ?string $reason = null): bool
    {
        if (! in_array($event, ['sent', 'delivered', 'failed'], true)) {
            return false;
        }

        return CompanyContext::forCompany((int) $gateway->company_id, function () use ($gateway, $publicId, $event, $reason): bool {
            $gateway->forceFill(['last_seen_at' => now()])->save();

            $row = SmsOutbox::query()->where('public_id', $publicId)->where('gateway_phone_id', $gateway->id)->first();

            if ($row === null) {
                return false;
            }

            $clean = $reason === null ? null : mb_substr(trim(strip_tags($reason)), 0, 255);

            match ($event) {
                'sent' => $row->forceFill(['status' => SmsOutbox::SENT, 'sent_at' => $row->sent_at ?? now(), 'error' => null])->save(),
                'delivered' => $row->forceFill(['status' => SmsOutbox::SENT, 'sent_at' => $row->sent_at ?? now(), 'delivered_at' => now()])->save(),
                'failed' => $row->forceFill(['status' => SmsOutbox::FAILED, 'error' => $clean ?: 'failed'])->save(),
            };

            // ⓘ নিজের মানুষের বিজ্ঞপ্তি হলে বিলি-খাতাতেও — পৌঁছানো বা না-যাওয়া ([[ProviderCallbacks]]-এর একই পথ)
            if ($row->source === SmsOutbox::NOTIFICATION && $event !== 'sent') {
                app(ProviderCallbacks::class)->fromPhone((int) $gateway->company_id, [
                    'provider_ref' => 'phone:'.$row->public_id, 'event' => $event, 'reason' => $clean,
                ]);
            }

            return true;
        });
    }

    /** ⓘ নিয়ে চুপ — [[SmsOutbox::TAKEN_MINUTES]] পেরোলে আবার সারিতে, বারবার হলে "যায়নি" */
    public function release(): void
    {
        $stale = SmsOutbox::query()->where('status', SmsOutbox::TAKEN)->where('taken_at', '<', now()->subMinutes(SmsOutbox::TAKEN_MINUTES));

        (clone $stale)->where('attempts', '>=', SmsOutbox::MAX_ATTEMPTS)
            ->update(['status' => SmsOutbox::FAILED, 'error' => 'the phone took it but never answered', 'updated_at' => now()]);
        (clone $stale)->where('attempts', '<', SmsOutbox::MAX_ATTEMPTS)
            ->update(['status' => SmsOutbox::WAITING, 'gateway_phone_id' => null, 'taken_at' => null, 'updated_at' => now()]);
    }

    /**
     * ⭐ অনেকক্ষণ অপেক্ষায় — [[SmsOutbox::WARN_MINUTES]]-এর বেশি পুরনো, এখনো কেউ নেয়নি, আর আগে খবর যায়নি।
     *
     * @return int কয়টা — খবর একবারই, তাই দাগ দেওয়া হয়
     */
    public function markOverdue(): int
    {
        $rows = SmsOutbox::query()->where('status', SmsOutbox::WAITING)->whereNull('warned_at')
            ->where('created_at', '<', now()->subMinutes(SmsOutbox::WARN_MINUTES));
        $count = (clone $rows)->count();

        if ($count > 0) {
            $rows->update(['warned_at' => now()]);
        }

        return $count;
    }

    /** @return array{waiting: int, today_sent: int, today_failed: int, oldest: ?Carbon} পাতার জন্য */
    public function summary(): array
    {
        $today = now()->startOfDay();
        $oldest = SmsOutbox::query()->where('status', SmsOutbox::WAITING)->min('created_at');

        return [
            'waiting' => SmsOutbox::query()->where('status', SmsOutbox::WAITING)->count(),
            'today_sent' => SmsOutbox::query()->where('status', SmsOutbox::SENT)->where('sent_at', '>=', $today)->count(),
            'today_failed' => SmsOutbox::query()->where('status', SmsOutbox::FAILED)->where('updated_at', '>=', $today)->count(),
            'oldest' => $oldest === null ? null : Carbon::parse($oldest),
        ];
    }
}
