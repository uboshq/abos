<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Models\Notification;
use App\Models\NotificationChannel;
use App\Models\User;

/**
 * ⭐ একটা পৌঁছানোর মাধ্যম — প্রতিটা প্রোভাইডার আলাদা অ্যাডাপ্টার, আলাদা পরীক্ষিত (মালিকের স্পেক §৭, §১৮; ধাপ ২)।
 *
 * ⛔ অ্যাডাপ্টার কখনো ব্যতিক্রম ছুড়ে উপরে পাঠায় না — সব ভুল [[DeliveryResult]] হয়ে ফেরে, তাই একটা প্রোভাইডার বন্ধ থাকলেও
 * ঘণ্টা আর ERP-র কাজ চলে (graceful degradation, স্পেক §১০)।
 */
interface DeliveryChannel
{
    /** email · web_push · mobile_push · sms */
    public function key(): string;

    /** প্রোভাইডারের নাম — চেষ্টার লগে */
    public function provider(?NotificationChannel $config): string;

    /**
     * ⛔ সংযুক্ত কি — প্রোভাইডার আর চাবি বসানো আছে কি না। অনুমোদিত প্রোভাইডার ছাড়া কখনো "সংযুক্ত" নয় (স্পেক §৭)।
     */
    public function connected(?NotificationChannel $config): bool;

    /** এই মানুষটার কাছে এই মাধ্যমে পৌঁছানোর মতো ঠিকানা আছে কি (ইমেইল, সাবস্ক্রিপশন, ফোনের টোকেন) */
    public function reaches(User $user): bool;

    public function send(Notification $notification, User $user, ?NotificationChannel $config): DeliveryResult;

    /** ⭐ সংযোগ পরীক্ষা — পরীক্ষাকারীর নিজের কাছে একটা পরীক্ষার খবর (স্পেক §৪ "Connection Test") */
    public function test(User $user, ?NotificationChannel $config): DeliveryResult;
}
