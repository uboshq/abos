<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Events\EventRegistry;
use App\Core\Module\ModuleRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * প্রতিটা মডিউলের রুট, ভিউ, অনুবাদ ও মাইগ্রেশন নিজে থেকে নিবন্ধন করে।
 *
 * প্ল্যান সেকশন ১৯.৩ — মডিউল নিজের ফোল্ডারে যা রাখবে, কোর সেটা খুঁজে নেবে।
 * এখানে কোনো মডিউলের নাম লেখা নেই এবং কখনো লেখা হবে না; নাম লিখতে হলে
 * "কোর কোড না ছুঁয়ে নতুন মডিউল" কথাটাই মিথ্যা হয়ে যায়।
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class, fn () => new ModuleRegistry(app_path('Modules')));
        $this->app->singleton(ReportEngine::class);
    }

    public function boot(ModuleRegistry $registry, ReportEngine $reports, EventRegistry $events): void
    {
        /*
         * শ্রোতাদের নিবন্ধন — মডিউলের ঘোষণা থেকে, কোরের তালিকা থেকে নয়।
         *
         * লুপের বাইরে, কারণ এক মডিউল অন্য মডিউলের ঘটনা শোনে; দুইটাই
         * পড়া শেষ না হলে ক্রম নিয়ে ভাবতে হত (§১৯.৭)।
         */
        $events->register();

        foreach ($registry->all() as $module) {
            $this->registerTranslations($module->dir('Resources', 'lang'), $module->code);
            $this->registerViews($module->dir('Resources', 'views'), $module->code);
            $this->registerMigrations($module->dir('Database', 'Migrations'));
            $this->registerRoutes($module->dir('Routes'), $module->code, $module->namespace);

            // রিপোর্টও মডিউলের নিজের — module.php-তে ঘোষিত, তাই নতুন
            // মডিউলের রিপোর্ট যোগ করতে কোর ফাইলে নাম লিখতে হয় না।
            foreach ($module->reports as $provider) {
                $provider::registerAll($reports);
            }

            /*
             * মডিউলের নিজের লগইন-প্রোভাইডার।
             *
             * ── কেন কোর ক্লাসের নামটা জানে না ───────────────────────
             * ডিলারের গার্ডের একটা নিজস্ব প্রোভাইডার দরকার (কারণটা
             * `DealerProvider`-এ লেখা)। প্রথমে সেটা
             * `AppServiceProvider`-এ নিবন্ধন করা হয়েছিল, আর সাথে
             * সাথেই `BoundariesTest` ধরল: কোর একটা মডিউলের ক্লাসের
             * নাম জেনে ফেলেছে (§১৯.৭)।
             *
             * এখন নামটা module.php-তে, আর কোর কেবল পড়ে — রিপোর্ট ও
             * শ্রোতাদের মতোই।
             */
            foreach ($module->authProviders as $name => $class) {
                Auth::provider($name, fn ($app, array $config) => new $class(
                    $app['hash'], $config['model'],
                ));
            }
        }
    }

    private function registerTranslations(string $path, string $code): void
    {
        // দুই ভাষার ফাইলই থাকতে হবে — নিয়ম ৯। একটা থাকলে অন্যটায় ফলব্যাক
        // হয়ে যাবে আর কেউ টের পাবে না, তাই দুটোই দাবি করা হয়।
        foreach (['bn', 'en'] as $locale) {
            if (! is_dir($path.DIRECTORY_SEPARATOR.$locale)) {
                return;
            }
        }

        $this->loadTranslationsFrom($path, $code);
    }

    private function registerViews(string $path, string $code): void
    {
        if (is_dir($path)) {
            $this->loadViewsFrom($path, $code);
        }
    }

    private function registerMigrations(string $path): void
    {
        if (is_dir($path)) {
            $this->loadMigrationsFrom($path);
        }
    }

    private function registerRoutes(string $path, string $code, string $namespace): void
    {
        $web = $path.DIRECTORY_SEPARATOR.'web.php';

        if (is_file($web)) {
            Route::middleware('web')
                ->namespace($namespace.'\\Http\\Controllers')
                ->name($code.'.')
                ->group($web);
        }

        $api = $path.DIRECTORY_SEPARATOR.'api.php';

        if (is_file($api)) {
            Route::middleware('api')
                ->prefix('api')
                ->namespace($namespace.'\\Http\\Controllers')
                ->name('api.'.$code.'.')
                ->group($api);
        }
    }
}
