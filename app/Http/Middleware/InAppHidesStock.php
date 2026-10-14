<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Services\WebInsideApp;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⛔ SR-এর ফোনে মজুদ নয় — অ্যাপের ভিতরে খোলা ওয়েবেও (মালিক, ১ অক্টোবর ২০২৬: *"SR customer dilar er kache mojut dekhabe na"*;
 * ১৩ অক্টোবর ২০২৬: অ্যাপের ভিতরে ওয়েব)।
 *
 * ⓘ কেবল অ্যাপের ভিতরের সেশনে কাজ করে ([[WebInsideApp::SESSION]], [[WebHandoffController]] বসায়) — ব্রাউজারের ওয়েব আগের
 * মতোই। মজুদ দেখার চাবি (`inventory.stock.view`) যাঁর আছে, তাঁর কিছুই বদলায় না; যাঁর নেই, তাঁর জন্য মজুদ-দেখানো পর্দা
 * ([[WebInsideApp::STOCK_SCREENS]]) মেনুতে আসে না, আর ভিতরের লিংক ধরে গেলেও এখানে থামে।
 */
final class InAppHidesStock
{
    public function __construct(private readonly WebInsideApp $inside) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || $request->session()->get(WebInsideApp::SESSION) !== true) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User || ! $this->inside->hidesFrom($user, (string) $request->route()?->getName())) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('mobile.stock_hidden_in_app')], 403);
        }

        return response()->view('errors.layout', [
            'code' => '403',
            'title' => __('core.error.title_403'),
            'body' => __('mobile.stock_hidden_in_app'),
        ], 403);
    }
}
