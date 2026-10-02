<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Models\LoginAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * ঢোকার জায়গা — ব্রাউজারের দেওয়া স্থানাঙ্ক, আর তার নাম। মালিক, ১ অক্টোবর ২০২৬।
 *
 * ── কেন ব্রাউজার, IP নয় ─────────────────────────────────────────────
 * মালিকের চাওয়া *"bridge more, mymensingh"* — মোড় পর্যন্ত। IP দিয়ে বড়জোর জেলা, আর বাংলাদেশে প্রায়ই
 * ভুল জেলা (ইন্টারনেট কোম্পানির সার্ভার ঢাকায়)। তাই মালিক বেছেছেন: লগইনের পাতা ব্রাউজারের কাছে একবার
 * লোকেশন চায় (`auth/_form`)। ⓘ না দিলে কিছুই বসে না, আর লগইন আগের মতোই চলে — এটা খাতা, পাহারা নয়।
 *
 * ── নাম কোথা থেকে ───────────────────────────────────────────────────
 * OpenStreetMap-এর Nominatim (বিনা পয়সার, মালিকের সম্মতিতে) — উত্তরের পরে ([[fill()]]), যাতে লগইন
 * অপেক্ষা না করে। ⓘ একই এলাকার (প্রায় ১০০ মিটার) নাম একদিন মনে রাখা হয় — রোজ সকালে একই অফিস থেকে
 * দশজন ঢুকলে দশবার জিজ্ঞেস করা হয় না। ⚠️ সেবাটা না পেলে নাম খালি থাকে; স্থানাঙ্ক আর ম্যাপের লিংক
 * তবু থাকে।
 */
class LoginPlace
{
    public const ENDPOINT = 'https://nominatim.openstreetmap.org/reverse';

    /**
     * ফর্ম থেকে আসা স্থানাঙ্ক — পড়া যায় এমন হলে, নাহলে কিছুই নয়।
     *
     * ⛔ ঘরগুলো ব্যবহারকারীর হাতে লেখা যায়, তাই সীমার বাইরের বা অসংখ্যা মান নীরবে বাদ — খাতা ভাঙে না।
     *
     * @return array{latitude: string, longitude: string, accuracy_m: int|null}|array{}
     */
    public function fromRequest(Request $request): array
    {
        $lat = $request->input('geo_lat');
        $lng = $request->input('geo_lng');

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return [];
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
            return [];
        }

        $accuracy = $request->input('geo_acc');

        return [
            'latitude' => number_format($lat, 6, '.', ''),
            'longitude' => number_format($lng, 6, '.', ''),
            'accuracy_m' => is_numeric($accuracy) && (float) $accuracy >= 0 ? (int) min(round((float) $accuracy), 4_000_000) : null,
        ];
    }

    /** সারিটার জায়গার নাম বসানো — উত্তর পাঠানোর পরে চলে ([[LoginJournal]])। */
    public function fill(int $attemptId): void
    {
        $row = LoginAttempt::query()->find($attemptId);

        if ($row === null || $row->latitude === null || $row->longitude === null || filled($row->place)) {
            return;
        }

        $name = $this->nameOf((string) $row->latitude, (string) $row->longitude);

        if ($name !== null) {
            $row->forceFill(['place' => mb_substr($name, 0, 191)])->save();
        }
    }

    /** স্থানাঙ্ক থেকে "মোড়/এলাকা, শহর" — না পেলে `null`। */
    public function nameOf(string $lat, string $lng): ?string
    {
        $key = 'login-place:'.round((float) $lat, 3).','.round((float) $lng, 3);

        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::timeout(5)
                ->withHeaders(['User-Agent' => 'ABOS-ERP/1.0 (login journal; '.config('app.url').')'])
                ->get(self::ENDPOINT, [
                    'format' => 'jsonv2',
                    'lat' => $lat,
                    'lon' => $lng,
                    'zoom' => 17,
                    'addressdetails' => 1,
                    'accept-language' => 'bn,en',
                ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $name = $this->compose((array) ($response->json('address') ?? []));

        if ($name !== null) {
            Cache::put($key, $name, now()->addDay());
        }

        return $name;
    }

    /**
     * ঠিকানার টুকরো থেকে ছোট নাম — কাছের জায়গা আগে, তারপর শহর বা জেলা।
     *
     * @param  array<string, mixed>  $address
     */
    public function compose(array $address): ?string
    {
        $near = $this->first($address, ['amenity', 'road', 'neighbourhood', 'suburb', 'quarter', 'hamlet', 'village']);
        $town = $this->first($address, ['city', 'town', 'municipality', 'county', 'state_district', 'state']);

        $parts = array_values(array_unique(array_filter([$near, $town])));

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $address
     * @param  list<string>  $keys
     */
    private function first(array $address, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($address[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
