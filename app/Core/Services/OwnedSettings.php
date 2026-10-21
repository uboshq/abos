<?php

declare(strict_types=1);

namespace App\Core\Services;

/**
 * ⭐ নিজের পর্দায় বদলানো সেটিং (`owned_by`) — অন্য পর্দা থেকে এলে স্পষ্ট "না", নীরবে উপেক্ষা নয় (fe, ২১ অক্টোবর ২০২৬; a6)।
 *
 * ⓘ সেটিংসের পাতা আর কন্ট্রোল প্যানেল এই ঘরগুলো আঁকে না; তবু হাতে বানানো ফর্ম চাবিটা পাঠালে আগে চুপচাপ বাদ পড়ত, আর পাঠানো মানুষ
 * ভাবতেন বদলেছে। ⛔ এখন পুরো অনুরোধটাই ফেরে, ঘরের নাম আর কোথায় বদলায় তা লিখে — সেই অনুরোধ থেকে কিছুই বসে না। ঘোষণায়
 * `owned_where` থাকলে সেটাই বার্তা (যেমন "এটা বদলায় ছুটির পাতার সাপ্তাহিক ছুটির কার্ডে"), নইলে সাধারণ বার্তা।
 * ⓘ `owned_by` ছাড়া চাবি আগের মতোই বসে।
 */
final class OwnedSettings
{
    /**
     * @param  array<string, array<string, mixed>>  $definitions
     * @param  iterable<int|string, mixed>  $keys  অনুরোধে আসা চাবি
     * @return string|null ফেরানোর বার্তা, বা null (সব ঠিক)
     */
    public static function refusal(array $definitions, iterable $keys): ?string
    {
        $messages = [];

        foreach ($keys as $key) {
            $definition = $definitions[(string) $key] ?? null;

            if ($definition === null || ! isset($definition['owned_by'])) {
                continue;
            }

            $messages[] = __((string) ($definition['label'] ?? $key)).' — '.(isset($definition['owned_where'])
                ? __((string) $definition['owned_where'])
                : __('system_admin::settings.owned_elsewhere'));
        }

        return $messages === [] ? null : implode(' · ', array_unique($messages));
    }
}
