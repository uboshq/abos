<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Contracts\RecipeBook;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Events\EventRegistry;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\NoRecipeBook;
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

        $this->bindDefaults();
        $this->bindWhatModulesDeclare();
    }

    /**
     * কোরের চুক্তিগুলোর "কিছুই করে না" বাস্তবায়ন।
     *
     * ── ⛔ কেন ডিফল্ট থাকতেই হবে ────────────────────────────────────
     * যে কোম্পানিতে রেস্তোরাঁ মডিউল বন্ধ, সেখানে কেউ [[RecipeBook]]
     * বাঁধে না। ⚠️ বাঁধন ছাড়া কনটেইনার ছুঁড়ত, আর বিক্রয়ের সেবা ওটা
     * ইনজেক্ট করে বলে **প্রতিটা বিল কাটা বন্ধ হয়ে যেত** — যে ডিপো
     * জীবনে একটা রেসিপিও লেখেনি তার ওখানেও।
     *
     * ⓘ পুরো কারণটা [[NoRecipeBook]]-এর ডকে লেখা।
     */
    private function bindDefaults(): void
    {
        $this->app->bind(RecipeBook::class, NoRecipeBook::class);
    }

    /**
     * মডিউলের নিজের ঘোষণা — `module.php`-র `bindings`।
     *
     * ── ⭐ এখানেও কোনো মডিউলের নাম লেখা নেই ─────────────────────────
     * ক্লাসের নামগুলো **ডেটা**, কোড নয় — সেগুলো আসে মডিউলের নিজের
     * ফাইল থেকে। তাই উপরের প্রতিশ্রুতিটা ("এখানে কোনো মডিউলের নাম
     * লেখা নেই এবং কখনো লেখা হবে না") অটুট থাকে।
     *
     * ⚠️ `register()`-এ, `boot()`-এ নয়: বাঁধন বসার আগেই অন্য কোনো সেবা
     * চুক্তিটা চেয়ে বসতে পারে, আর তখন সে ডিফল্ট শূন্য-বাস্তবায়নটা পেত
     * যদিও মডিউলটা আসলটা দিত। ⓘ দুইটার পার্থক্য নীরব — বিল কাটা হত,
     * কেবল উপকরণ কাটা হত না।
     */
    private function bindWhatModulesDeclare(): void
    {
        foreach ($this->app->make(ModuleRegistry::class)->all() as $module) {
            foreach ($module->bindings as $contract => $implementation) {
                $this->app->bind($contract, $implementation);
            }
        }
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
             * `CustomerProvider`-এ লেখা)। প্রথমে সেটা
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
