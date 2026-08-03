<?php

use App\Http\Middleware\ResolveCompanyContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // প্রতিটা ওয়েব রিকোয়েস্টে কোম্পানি, শাখা, অর্থবছর ও ভাষা বসে।
        // এখানে না বসালে BelongsToCompany ব্যতিক্রম ছুঁড়বে — সেটাই উদ্দেশ্য,
        // কারণ প্রসঙ্গ ছাড়া টেন্যান্ট ডাটা ছোঁয়া মানে সব কোম্পানির রো দেখা।
        $middleware->web(append: [
            ResolveCompanyContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
