<?php

declare(strict_types=1);

/*
 * ছাপার পাতা মাপার প্রথম ধাপ — পর্দাটা একটা ফাইলে নামানো।
 *
 * ── ⛔ কেন এটা রিপোতে, কারও scratchpad-এ নয় ─────────────────────────
 * ২২ সেপ্টেম্বর ২০২৬-এ **দুইজন আলাদা সেশন, আলাদা যন্ত্রে, একই ফাঁদে**
 * পড়েছে: একজন Chrome-কে PDF বানাতে বলেছে, আরেকজন CDP দিয়ে পিক্সেল
 * মেপেছে, আর **দুইজনেরই প্রথম মাপে ছাপার CSS চলেনি**।
 *
 * ⚠️ ফাঁদটা আকস্মিক নয়, **কাঠামোগত**: রেন্ডার করা HTML সবসময়
 * `APP_URL` ধরে ঠিকানা লেখে (`http://abos.test/build/…`), আর মাপার
 * সার্ভার সবসময় অন্য origin-এ (`127.0.0.1:8123`)। ⛔ তাই স্টাইলশিট
 * কখনোই আসে না, পাতাটা সাজসজ্জাহীন ছাপে, আর সংখ্যাগুলো দেখতে
 * **সম্পূর্ণ যুক্তিসঙ্গত** লাগে — দশ পাতা, মেনু ছাপা হচ্ছে, সব
 * ব্যাখ্যাযোগ্য। ⓘ একজন প্রায় "মেনু ছাপা হচ্ছে" বলে রিপোর্ট করে
 * ফেলেছিল; ওটা অ্যাপের দোষ ছিল না, যন্ত্রের।
 *
 * ⭐ তাই ঠিকানাগুলো এখানে আপেক্ষিক করে দেওয়া হয়, আর পাশের
 * [[print-check.py]] মাপার আগে **জানা-উত্তরের ঘরটা** দেখে নেয়।
 *
 * ── ব্যবহার ─────────────────────────────────────────────────────────
 *   php tools/print-check.php ledger=/suppliers/1?ledger=asc&print=1
 *   php -S 127.0.0.1:8123 -t public
 *   python tools/print-check.py ledger
 */

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

if ($argc < 2) {
    fwrite(STDERR, "ব্যবহার: php tools/print-check.php <নাম>=<ঠিকানা> [...]\n");
    exit(1);
}

$company = Company::query()->orderBy('id')->firstOrFail();
CompanyContext::set($company->id, $company->defaultBranch()?->id);

$owner = User::query()->orderBy('id')->firstOrFail();
Auth::login($owner);

$out = base_path('public/__printcheck');

if (! is_dir($out)) {
    mkdir($out, 0777, true);
}

$kernel = $app->make(HttpKernel::class);

foreach (array_slice($argv, 1) as $spec) {
    [$name, $url] = explode('=', $spec, 2);

    $request = Request::create($url, 'GET');
    $request->setUserResolver(fn () => $owner);

    $response = $kernel->handle($request);
    $html = (string) $response->getContent();

    /*
     * ⛔ হোস্টটা কেটে দেওয়া — এই ফাইলের গোটা কারণ।
     *
     * ⓘ `Request::create()` হোস্ট বসায় `localhost`, আর কনফিগে
     * `app.url` অন্য কিছু। ⚠️ দুইটাই ধরা হয়, কারণ কোনটা পাতায় বসবে
     * সেটা নির্ভর করে `asset()` না `url()` ডাকা হয়েছে তার উপর।
     */
    foreach ([rtrim((string) config('app.url'), '/'), 'http://localhost', 'https://localhost'] as $base) {
        $html = str_replace($base.'/', '/', $html);
    }

    file_put_contents($out.'/'.$name.'.html', $html);

    printf("%-24s %s  %d bytes\n", $name, $response->getStatusCode(), strlen($html));
}

echo "\nএখন: php -S 127.0.0.1:8123 -t public   তারপর: python tools/print-check.py <নাম>\n";
