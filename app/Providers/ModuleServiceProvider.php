<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Contracts\CreditHolds;
use App\Core\Contracts\CustomerSalesFilters;
use App\Core\Contracts\CustomerTrade;
use App\Core\Contracts\FreeGoodsOffers;
use App\Core\Contracts\HomeSalesFilters;
use App\Core\Contracts\LinkedDocuments;
use App\Core\Contracts\ParkedStock;
use App\Core\Contracts\PartyOpenBills;
use App\Core\Contracts\RecipeBook;
use App\Core\Contracts\SalesOffers;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Events\EventRegistry;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\NoCreditHolds;
use App\Core\Services\NoCustomerSalesFilters;
use App\Core\Services\NoCustomerTrade;
use App\Core\Services\NoFreeGoodsOffers;
use App\Core\Services\NoHomeSalesFilters;
use App\Core\Services\NoLinkedDocuments;
use App\Core\Services\NoParkedStock;
use App\Core\Services\NoPartyOpenBills;
use App\Core\Services\NoRecipeBook;
use App\Core\Services\NoSalesOffers;
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

        // ⓘ অফারের মডিউল বন্ধ থাকলে অর্ডারের লাইনে ফ্রি নেই ([[FreeGoodsOffers]])
        $this->app->bind(FreeGoodsOffers::class, NoFreeGoodsOffers::class);

        // ⓘ বিক্রয় বন্ধ থাকলে ডিও বা খসড়া বিল নেই — আটকে থাকা টাকা শূন্য
        $this->app->bind(CreditHolds::class, NoCreditHolds::class);

        // ⓘ বিক্রয় বন্ধ থাকলে বিল বা আদায় নেই — গ্রাহকের সারাংশ কেবল খাতা আর সীমা দেখায়
        $this->app->bind(CustomerTrade::class, NoCustomerTrade::class);

        // ⓘ বিক্রয় বন্ধ থাকলে গ্রাহকের তালিকায় টপ/বটম বিক্রি আর "ভালো কাস্টমার" কাউকে পায় না ([[CustomerSalesFilters]])
        $this->app->bind(CustomerSalesFilters::class, NoCustomerSalesFilters::class);

        // ⓘ বিক্রয় বন্ধ থাকলে রসিদে বাছার মতো খোলা বিল নেই ([[PartyOpenBills]], অডিট ম১)
        $this->app->bind(PartyOpenBills::class, NoPartyOpenBills::class);

        // ⓘ বিক্রয় বন্ধ থাকলে হোমের ছাঁকনিতে বাছার কিছু নেই ([[HomeSalesFilters]])
        $this->app->bind(HomeSalesFilters::class, NoHomeSalesFilters::class);

        // ⓘ অফারের মডিউল বন্ধ থাকলে বিক্রয়ের কাগজে অফারের প্যানেল আঁকা হয় না ([[SalesOffers]])
        $this->app->bind(SalesOffers::class, NoSalesOffers::class);

        // ⓘ জোড়া কাগজ — ডকুমেন্ট মডিউল বন্ধ থাকলে খালি ([[NoLinkedDocuments]])
        $this->app->bind(LinkedDocuments::class, NoLinkedDocuments::class);

        // ⓘ কাউন্টার না থাকলে ধরে রাখা বিল নেই, তাই ছাড়ার মতো আটকানো মালও নেই ([[NoParkedStock]])
        $this->app->bind(ParkedStock::class, NoParkedStock::class);
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
            $this->registerBorrowedRouteNames($module->dir('Routes'), $module->code, $module->namespace, $module->dependsOn);

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

    /**
     * ধার করা রুটের নাম — `Routes/web.<আশ্রয়দাতা>.php` (পিওএস ধাপ ২, ১১ অক্টোবর ২০২৬)।
     *
     * ── ⭐ কেন ─────────────────────────────────────────────────────────
     * একটা পর্দা এক মডিউল থেকে আরেক মডিউলে সরলে তার কোড সরে, কিন্তু **রুটের নাম
     * আর ঠিকানা সরতে পারে না** — ফোনের অ্যাপ, বুকমার্ক আর ছাপা কাগজের লিংক ঐ
     * নামের উপর দাঁড়িয়ে। ⓘ সাধারণ `web.php`-র নাম মডিউলের কোড দিয়ে শুরু হয়, তাই
     * নতুন ঘরে বসালে `sales.pos.index` হয়ে যেত `pos.sales.pos.index`।
     *
     * ⓘ তাই এই ফাইলের নামগুলো আশ্রয়দাতার কোড দিয়ে শুরু হয়, আর controller খোঁজা
     * হয় **নিজের** নামস্থানে। ⚠️ কোনো মডিউলের নাম এখানে লেখা নেই — ফাইলের নামটাই বলে।
     *
     * ── ⛔ কেবল ঘোষিত নির্ভরতার নাম ──────────────────────────────────
     * যার উপর মডিউলটা দাঁড়ায় না তার নামে রুট বসালে দুইটা মডিউল একই নাম দাবি করতে
     * পারত, আর পরেরটা নীরবে আগেরটাকে ঢেকে দিত। ⓘ তাই অঘোষিত নাম এখানেই থামে।
     *
     * @param  list<string>  $dependsOn
     */
    private function registerBorrowedRouteNames(string $path, string $code, string $namespace, array $dependsOn): void
    {
        foreach (glob($path.DIRECTORY_SEPARATOR.'web.*.php') ?: [] as $file) {
            $host = substr(basename($file, '.php'), strlen('web.'));

            if (! in_array($host, $dependsOn, true)) {
                throw new \InvalidArgumentException(
                    "{$file}: module '{$code}' registers routes under '{$host}.' names, but '{$host}' is not in "
                    .'its depends_on. A route may only keep the name of a module this one stands on, or two '
                    .'modules could claim the same name and the later one would silently win.'
                );
            }

            Route::middleware('web')
                ->namespace($namespace.'\\Http\\Controllers')
                ->name($host.'.')
                ->group($file);
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
