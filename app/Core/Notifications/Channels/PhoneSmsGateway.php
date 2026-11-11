<?php

declare(strict_types=1);

namespace App\Core\Notifications\Channels;

use App\Core\Notifications\DeliveryResult;
use App\Core\Services\PartyMessages;
use App\Core\Services\SmsOutboxService;
use App\Models\SmsOutbox;

/**
 * ⭐ নিজের সিমে SMS — অফিসের Android ফোন (মালিকের "ok", নমুনা U1X687u5MkwVfEL7ci7MGv ধাপ ২)।
 *
 * ⓘ "পাঠানো" মানে এখানে সারিতে বসানো ([[SmsOutboxService::queue()]]) — ফোন এসে নিয়ে যায়। বিলি-খাতায় তাই "sent" লেখা হয়
 *   সারির নম্বরসহ (`phone:<id>`); ফোন পরে "পৌঁছেছে" বা "যায়নি" জানালে একই খাতায় ওঠে ([[ProviderCallbacks::fromPhone()]])।
 * ⛔ কোম্পানির চালু ফোন না থাকলে স্থায়ী "না" — সারিতে এমন SMS জমে না যা কেউ কখনো নেবে না।
 */
final class PhoneSmsGateway implements SmsGateway
{
    public const NAME = 'phone';

    public function __construct(private readonly SmsOutboxService $outbox) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function send(string $phone, string $text, string $senderId, array $credentials): DeliveryResult
    {
        if (! $this->outbox->hasPhone()) {
            return DeliveryResult::permanent('no sms phone is set up for this company');
        }

        $number = PartyMessages::phone($phone);

        if ($number === null) {
            return DeliveryResult::permanent('the mobile number is not a mobile number');
        }

        $row = $this->outbox->queue($number, $text, SmsOutbox::NOTIFICATION);

        return DeliveryResult::sent('phone:'.$row->public_id);
    }
}
