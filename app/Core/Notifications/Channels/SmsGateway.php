<?php

declare(strict_types=1);

namespace App\Core\Notifications\Channels;

use App\Core\Notifications\DeliveryResult;

/**
 * ⭐ SMS-এর একটা প্রোভাইডার — এই চুক্তি মেনে নতুন অ্যাডাপ্টার লেখা হবে (মালিকের স্পেক §৭; ধাপ ২)।
 *
 * ⛔ আজ কোনো প্রোভাইডার বসানো নেই। মালিক প্রোভাইডার আর চাবি দিলে একটা ক্লাস লিখে [[SmsChannel::GATEWAYS]]-এ নাম বসাতে
 * হবে; তার আগে SMS মাধ্যম "সংযুক্ত নয়" দেখায়, আর কোনো খবর SMS-এর কিউয়ে ওঠে না।
 */
interface SmsGateway
{
    /** প্রোভাইডারের নাম — মাধ্যমের পর্দার তালিকায় */
    public function name(): string;

    /**
     * @param  array<string, string>  $credentials  এনক্রিপ্ট থেকে খোলা চাবি — ⛔ কখনো লগে নয়
     */
    public function send(string $phone, string $text, string $senderId, array $credentials): DeliveryResult;
}
