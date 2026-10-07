<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⭐ ফোনের একটা লেখা একবারই — `Idempotency-Key` হেডার ধরে (পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⚠️১২: নেট ধীর হলে বা দুবার
 * চাপলে জমার বিজ্ঞপ্তি, আদেশ, DO আর ফেরত দুটো করে লেখা হত — হিসাবরক্ষকের তালিকায় নকল, আদেশে দুবার বাকির যাচাই)।
 *
 * <p>ফোন প্রতিটা কাজে নিজে একটা চাবি বানায় আর একই কাজ আবার পাঠালে একই চাবি দেয়। প্রথমবার কাজটা চলে আর সফল উত্তরটা মনে থাকে
 * (২৪ ঘণ্টা); একই চাবি আবার এলে কাজ না চালিয়ে সেই উত্তর ফেরে, `Idempotent-Replay: true` সহ। একই মুহূর্তে দুটো এলে তালা — একটা
 * চলে, অন্যটা তার উত্তর পায়। ⓘ কেবল সফল উত্তর মনে রাখা হয়: ফেরানো কাজ ঠিক করে পাঠালে নতুন চাবি (ফোনের নিয়ম)।
 * ⓘ হেডার না থাকলে কিছুই বদলায় না — পুরনো অ্যাপ আগের মতো চলে।
 *
 * <p>চাবির পরিসর কোম্পানি + মানুষ + পথ — অন্যের চাবি জেনে তার উত্তর পাওয়া যায় না।
 */
final class RemembersAPhoneWrite
{
    private const TTL_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('Idempotency-Key', '');

        if (! $request->isMethod('POST') || $key === '') {
            return $next($request);
        }

        abort_unless(preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key) === 1, 422, __('validation.regex', ['attribute' => 'Idempotency-Key']));

        $slot = 'phone-write:'.sha1(implode('|', [CompanyContext::id(), $request->user()?->getAuthIdentifier(), $request->path(), $key]));

        if (($seen = Cache::get($slot)) !== null) {
            return $this->replay($seen);
        }

        return Cache::lock($slot.':lock', 30)->block(15, function () use ($slot, $request, $next): Response {
            // ⓘ তালার অপেক্ষায় থাকতে অন্যজন শেষ করে থাকলে — তার উত্তর
            if (($seen = Cache::get($slot)) !== null) {
                return $this->replay($seen);
            }

            $response = $next($request);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                Cache::put($slot, [
                    'status' => $response->getStatusCode(),
                    'body' => (string) $response->getContent(),
                    'type' => (string) $response->headers->get('Content-Type', 'application/json'),
                ], self::TTL_SECONDS);
            }

            return $response;
        });
    }

    /** @param  array{status: int, body: string, type: string}  $seen */
    private function replay(array $seen): Response
    {
        return response($seen['body'], $seen['status'], [
            'Content-Type' => $seen['type'],
            'Idempotent-Replay' => 'true',
        ]);
    }
}
