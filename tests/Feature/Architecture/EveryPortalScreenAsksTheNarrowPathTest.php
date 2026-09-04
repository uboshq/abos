<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * ডিলারের পর্দা নিজে কোয়েরি লেখে না — সরু পথে জিজ্ঞেস করে।
 *
 * ── কেন এই পাহারাটা লাগল ─────────────────────────────────────────────
 * পোর্টালের প্রতিটা পাতা আগে **নিজে** মডেল ডাকত, **নিজে** শাখার ছাঁকনি
 * সরাত, আর **নিজে** পার্টি মেলাত। আজ ঠিক ছিল। কিন্তু ভুলটা দুই দিকে
 * যায়, আর **অসমান**:
 *
 *   ছাঁকনি সরাতে ভুলে গেলে   ৫০০ — জোরে ভাঙে, সাথে সাথে ধরা পড়ে
 *   বেশি সরিয়ে ফেললে        ডিলার **অন্যের কাগজ** দেখেন — নীরব, আর
 *                            একবার দেখে ফেললে ফেরানো যায় না
 *
 * দ্বিতীয়টা কোনো টেস্ট ভাঙায় না, কোনো লগে ওঠে না। তাই পাহারাটা
 * "ভুল ফলাফল" ধরে না — **ভুল লেখার সুযোগটাই** ধরে।
 *
 * ── কী বসানো হলো ─────────────────────────────────────────────────────
 * সব কোয়েরি এখন [[App\Modules\Sales\Services\DealerPapers]]-এ, আর তার
 * একটাও পদ্ধতি গ্রাহকের আইডি **প্যারামিটার হিসেবে নেয় না** — "কে",
 * সেটা সবসময় গার্ড থেকে। অর্থাৎ ডাকার জায়গা থেকে ভুল আইডি পাঠানোর
 * কোনো উপায়ই নেই।
 *
 * এই টেস্টটা তার পাহারাদার: কেউ কাল একটা নতুন পোর্টাল-পাতা লিখে আবার
 * হাতে কোয়েরি লিখলে সুইট লাল হবে।
 *
 * ── ⚠️ তালিকাটা নিষেধ নয়, ঘোষণা ──────────────────────────────────────
 * নিচের ছাড়গুলো "পাহারা তুলে নেওয়া" নয় — "এটা ইচ্ছাকৃত, দুর্ঘটনা নয়",
 * আর পাশে **কেন**। মালিকের নিয়ম অনুযায়ী তালিকাটা **কেবল কমবে**।
 *
 * ── কোন ফাইলগুলো এই পাহারার আওতায় ────────────────────────────────────
 * নামের উপর ভরসা করা হয় না (`*Portal*.php` খুঁজলে
 * `CustomerPortalController` ধরা পড়ত — অথচ ওটা **কর্মীর** পর্দা,
 * ডিলারের নয়)। আওতাটা আসে **দরজা ধরে**: যে রুটগুলোয় `auth:portal`
 * বসানো, তাদের কন্ট্রোলার।
 */
class EveryPortalScreenAsksTheNarrowPathTest extends TestCase
{
    /**
     * ডিলারের দরজার পিছনে যে কয়টা সরাসরি মডেল-ডাক টিকে আছে — আর কেন।
     *
     * চাবি: `ফাইল → টোকেন`, মান: `[কয়টা, কেন]`।
     *
     * ⚠️ সংখ্যাটা আছে বলেই একই ফাইলে **দ্বিতীয়** একটা ডাক যোগ করলে
     * টেস্ট লাল হয়। সংখ্যা ছাড়া চাবিটা মিলে যেত আর পাহারাটা অন্ধ হত।
     */
    private const HANDLED = [
        /*
         * লগইনের খোঁজ — আর এটা সেবায় সরানো **যায় না**।
         *
         * [[App\Modules\Sales\Services\DealerPapers]]-এর ভিত্তি-নিয়ম:
         * কোনো পদ্ধতি "কার" জিজ্ঞেস করে না, উত্তরটা গার্ড থেকে আসে।
         * কিন্তু এই লাইনটা চলে **লগইনের আগে** — তখন গার্ডে কেউ নেই,
         * "কে" প্রশ্নের কোনো উত্তরই নেই। সেবায় নিতে হলে তাকে একটা
         * `code` প্যারামিটার নিতে হত, আর তাতে ঠিক সেই দরজাটাই খুলে
         * যেত যেটা বন্ধ করার জন্য সেবাটা বানানো।
         */
        'PortalController.php → Customer::query()' => [1, 'লগইনের খোঁজ — চলে যাচাইয়ের আগে, তখন গার্ডে কেউ নেই'],
        'PortalController.php → withoutGlobalScopes()' => [1, 'ওই একই লগইনের খোঁজ — কোন কোম্পানির ডিলার, সেটাই তো এখনো অজানা'],

        /*
         * ব্যাংকের তালিকা — এটা কারো কাগজ নয়।
         *
         * জমার দাবি তুলতে ডিলারকে বলতে হয় "কোন ব্যাংকে দিয়েছি", আর
         * তালিকাটা **আমাদের নিজের** হিসাব — প্রতিটা ডিলারের জন্য এক।
         * এখানে "নিজের কাগজ বনাম অন্যের কাগজ" বলে কিছু নেই, তাই সরু
         * পথের প্রশ্নটাই ওঠে না।
         *
         * ⓘ কোম্পানির ছাঁকনি এখানে **সরানো হয়নি** — সেটাই তফাত।
         */
        'PortalController.php → Account::query()' => [1, 'আমাদের নিজের ব্যাংক তালিকা — কারো ব্যক্তিগত কাগজ নয়, আর টেন্যান্ট ছাঁকনি বহাল'],
    ];

    /**
     * বাজেট — মালিকের ratchet নিয়ম: **কেবল কমবে**।
     *
     * সংখ্যাটা আজকের বাস্তবতা, লক্ষ্য নয়। বাড়াতে হলে সেটা একটা
     * সিদ্ধান্ত, আর সিদ্ধান্তটা এই লাইনে চোখে পড়বে।
     */
    private const BUDGET = 3;

    /**
     * Eloquent-এ ঢোকার যে দরজাগুলো ধরা হয়।
     *
     * তালিকাটা "সব পদ্ধতি" নয়, **শুরুর পদ্ধতি** — যেখান থেকে একটা
     * কোয়েরি জন্ম নেয়। মাঝপথের `->where()` ধরার দরকার নেই: শুরুটা
     * সেবার ভিতরে থাকলে মাঝপথও সেখানেই।
     */
    private const ENTRY_POINTS = [
        'query', 'where', 'whereIn', 'find', 'findOrFail', 'findOr',
        'first', 'firstWhere', 'firstOrFail', 'all', 'create',
        'firstOrCreate', 'updateOrCreate', 'with', 'withoutGlobalScope',
        'withoutGlobalScopes', 'select', 'count', 'pluck',
    ];

    public function test_a_portal_screen_does_not_write_its_own_query(): void
    {
        $found = [];

        foreach ($this->portalFiles() as $file) {
            foreach ($this->directCallsIn($file) as $token) {
                $key = basename($file).' → '.$token;
                $found[$key] = ($found[$key] ?? 0) + 1;
            }
        }

        foreach ($found as $key => $times) {
            $this->assertArrayHasKey($key, self::HANDLED,
                "\n\nডিলারের পর্দা নিজে কোয়েরি লিখছে: {$key}\n\n"
                ."কোয়েরিটা DealerPapers-এ নিন। ওখানে কোনো পদ্ধতি \"কার\" জিজ্ঞেস করে না —\n"
                ."ডিলার আসে গার্ড থেকে, তাই ভুল আইডি পাঠানোর উপায় থাকে না।\n\n"
                .'সত্যিই এখানেই থাকতে হলে HANDLED-এ **কারণসহ** ঘোষণা করুন।');

            [$allowed, $why] = self::HANDLED[$key];

            $this->assertLessThanOrEqual($allowed, $times,
                "\n\n`{$key}` আগে {$allowed} বার ছিল, এখন {$times} বার।\n\n"
                ."ছাড়টা এই কারণে দেওয়া: {$why}\n"
                .'নতুন ডাকটা কি একই কারণে? না হলে সেবায় নিন।');
        }

        $this->assertLessThanOrEqual(self::BUDGET, count(self::HANDLED),
            'ছাড়ের তালিকা কেবল কমে — বাড়াতে হলে বাজেটের লাইনটাও বদলাতে হবে, আর সেটা একটা সিদ্ধান্ত।');
    }

    /**
     * URL থেকে আসা মডেল — মালিকানা হাতে দেখা হয়েছে কি না।
     *
     * ── কেন এটা আলাদা প্রশ্ন ─────────────────────────────────────────
     * রুট-বাঁধাই মডেলটা **আইডি ধরে** তুলে আনে, আর আইডিটা আসে ঠিকানা
     * থেকে। উপরের পাহারা এখানে কিছুই দেখে না — কোনো `::` নেই, কোনো
     * ছাঁকনি সরানো নেই, দেখতে নিখুঁত। শুধু সংখ্যাটা এক বাড়িয়ে দিলে
     * ডিলার অন্যের দাবিটা পড়তে পারতেন।
     */
    public function test_a_model_that_came_from_the_url_is_checked_against_its_owner(): void
    {
        $bound = 0;

        foreach ($this->portalActions() as [$class, $method]) {
            $reflection = new ReflectionMethod($class, $method);
            $body = $this->bodyOf($reflection);

            foreach ($reflection->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                if (! is_subclass_of($type->getName(), Model::class)) {
                    continue;
                }

                $bound++;
                $var = '$'.$parameter->getName();

                $this->assertMatchesRegularExpression(
                    '/abort_(if|unless)\s*\([^;]*'.preg_quote($var, '/').'/s',
                    $body,
                    "\n\n{$class}::{$method}() ঠিকানা থেকে একটা মডেল নিচ্ছে ({$var}) অথচ\n"
                    ."কোথাও দেখা হচ্ছে না ওটা এই ডিলারেরই কি না।\n\n"
                    .'আইডিটা URL-এ, তাই সংখ্যা বদলে অন্যের কাগজ খোলা যায় — আর সেটা নীরবে ঘটে।'
                );
            }
        }

        /*
         * অন্ধত্বের পাহারা: একটাও বাঁধাই করা মডেল না থাকলে উপরের লুপটা
         * শূন্যবার চলত আর টেস্টটা **কিছু না দেখেই** সবুজ হত।
         */
        $this->assertGreaterThan(0, $bound,
            'ডিলারের কোনো পর্দাই ঠিকানা থেকে মডেল নিচ্ছে না — তাহলে এই পাহারাটা কিছুই দেখছে না।');
    }

    // ── খুঁটিনাটি ────────────────────────────────────────────────────

    /**
     * ডিলারের দরজার পিছনের প্রতিটা কাজ।
     *
     * @return list<array{0: class-string, 1: string}>
     */
    private function portalActions(): array
    {
        $actions = [];

        foreach (Router::getRoutes() as $route) {
            if (! $this->isBehindThePortalDoor($route)) {
                continue;
            }

            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            if (class_exists($class) && method_exists($class, $method)) {
                $actions[] = [$class, $method];
            }
        }

        /*
         * ⚠️ আওতাটা খালি হলে দুইটা টেস্টই "খারাপ কিছু পাওয়া গেল না"
         * বলে পাশ করত। রুট লোড না হওয়া, মিডলওয়্যারের নাম বদলে যাওয়া —
         * দুইটাই এই পাহারাকে নীরবে অলংকারে পরিণত করে।
         */
        $this->assertNotEmpty($actions,
            '`auth:portal`-এর পিছনে একটাও কাজ পাওয়া গেল না — পাহারাটা তখন কিছুই দেখছে না।');

        return $actions;
    }

    /** @return list<string> */
    private function portalFiles(): array
    {
        $files = [];

        foreach ($this->portalActions() as [$class]) {
            $file = (new ReflectionClass($class))->getFileName();

            if (is_string($file)) {
                $files[$file] = true;
            }
        }

        return array_keys($files);
    }

    private function isBehindThePortalDoor(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && $middleware === 'auth:portal') {
                return true;
            }
        }

        return false;
    }

    /**
     * একটা ফাইলে সরাসরি Eloquent-ডাকগুলো।
     *
     * ⓘ `use` তালিকা দেখে ঠিক করা হয় নামটা সত্যিই একটা মডেল কি না —
     * নাহলে `Auth::guard()` বা `CompanyContext::set()`-ও ধরা পড়ত, আর
     * তখন ছাড়ের তালিকাটা এত লম্বা হত যে কেউ পড়ত না।
     *
     * @return list<string>
     */
    private function directCallsIn(string $file): array
    {
        $whole = (string) file_get_contents($file);

        /*
         * ⚠️ মন্তব্য বাদ, নাহলে পাহারাটা **লেখাকে** কোড ভাবে।
         *
         * এই ফাইলগুলোর মন্তব্যেই সবচেয়ে বেশি ব্যাখ্যা থাকে — আর
         * ব্যাখ্যায় স্বভাবতই কোড উদ্ধৃত হয় (`Customer::query()`)।
         * প্রথম রানে ঠিক এটাই ঘটেছিল: একটা ডাক, অথচ গোনা হলো দুইটা।
         *
         * ⓘ regex দিয়ে মন্তব্য ছাঁটা হয় না — PHP-র নিজের tokenizer
         * দিয়ে, কারণ একটা স্ট্রিংয়ের ভিতরে `//` থাকলে regex সেখান
         * থেকে বাকি লাইনটা মুছে ফেলত।
         */
        $source = $this->withoutComments($whole);
        $imports = $this->importsOf($whole);
        $entry = implode('|', self::ENTRY_POINTS);

        $tokens = [];

        /*
         * ⚠️ পুরো নামটাও ধরা হয় — `\App\Modules\...\SalesInvoice::query()`।
         *
         * এটা এই রিপোর একটা পুরনো ফাঁদ: `use` তালিকা দেখে গোনা হলে
         * যিনি পুরো নাম লিখে ডাকেন তিনি **অদৃশ্য** থাকেন, আর পাহারাটা
         * সবুজ থেকেই যায়। ভেঙে দেখতে গিয়ে ঠিক এটাই ধরা পড়েছে
         * (৪ সেপ্টেম্বর ২০২৬) — প্রথম সংস্করণটা এই ডাকটা দেখতেই পেত না।
         */
        preg_match_all(
            '/\\\\?\b([A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*)::('.$entry.')\s*\(/',
            $source, $matches, PREG_SET_ORDER
        );

        foreach ($matches as [, $name, $call]) {
            /*
             * নামটা পুরো হলে সেটাই শ্রেণি; ছোট হলে `use` তালিকা থেকে।
             * টোকেনে সবসময় **শেষ অংশটা** বসে, তাই ছাড়ের তালিকার চাবি
             * বদলায় না — কেউ পুরো নাম লিখলেও ঘোষণাটা একই থাকে।
             */
            $fqcn = str_contains($name, '\\')
                ? ltrim($name, '\\')
                : ($imports[$name] ?? null);

            if ($fqcn !== null && is_subclass_of($fqcn, Model::class)) {
                $short = substr((string) strrchr('\\'.$fqcn, '\\'), 1);

                $tokens[] = $short.'::'.$call.'()';
            }
        }

        /*
         * ছাঁকনি সরানোটা আলাদা করে ধরা হয়, শ্রেণির নাম ছাড়াও।
         *
         * ⚠️ বাস্তবে লাইনটা প্রায় সবসময় চেইনের **মাঝে** থাকে
         * (`Customer::query()->withoutGlobalScopes()`), তাই উপরের
         * প্যাটার্নে ধরা পড়ত না — অথচ এই একটা লাইনই সবচেয়ে বিপজ্জনক।
         */
        $removals = preg_match_all('/->\s*withoutGlobalScopes?\s*\(/', $source);

        for ($i = 0; $i < $removals; $i++) {
            $tokens[] = 'withoutGlobalScopes()';
        }

        return $tokens;
    }

    /**
     * কোডটুকু — মন্তব্য ছাড়া।
     *
     * doc-block, `//` আর `#` তিনটাই ফাঁকা জায়গা দিয়ে বদলানো হয়, মুছে
     * ফেলা হয় না — তাতে বাকি লেখার অবস্থান অটুট থাকে।
     */
    private function withoutComments(string $source): string
    {
        $kept = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                /*
                 * ⚠️ নতুন লাইনগুলো রাখা হয়, শুধু অক্ষরগুলো ফাঁকা হয়।
                 *
                 * পুরো মন্তব্যটা সমান দৈর্ঘ্যের স্পেস দিয়ে বদলালে
                 * একটা বহু-লাইনের doc-block **এক লাইনে** নেমে আসত, আর
                 * তখন লাইন-নম্বর ধরে পদ্ধতির শরীর কাটাটা ভুল জায়গা
                 * থেকে কাটত ([[bodyOf]])।
                 */
                $kept .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? (string) preg_replace('/[^\n]/', ' ', $token[1])
                    : $token[1];

                continue;
            }

            $kept .= $token;
        }

        return $kept;
    }

    /**
     * ফাইলের `use` তালিকা — ছোট নাম থেকে পুরো নাম।
     *
     * @return array<string, string>
     */
    private function importsOf(string $source): array
    {
        preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m', $source, $matches, PREG_SET_ORDER);

        $imports = [];

        foreach ($matches as $match) {
            $fqcn = $match[1];
            $short = ($match[2] ?? '') !== ''
                ? $match[2]
                : substr((string) strrchr('\\'.$fqcn, '\\'), 1);

            $imports[$short] = $fqcn;
        }

        return $imports;
    }

    /**
     * পদ্ধতির শরীর — **মন্তব্য ছাড়া**।
     *
     * ⚠️ মন্তব্য রেখে দিলে পাহারাটা অন্ধ, আর সেটা অনুমান নয় — ভেঙে
     * দেখা হয়েছে (৪ সেপ্টেম্বর ২০২৬)। মালিকানার যাচাইটা মুছে না দিয়ে
     * **মন্তব্য করে** দিলে টেস্টটা দিব্যি সবুজ থাকত: লাইনটা তো ফাইলে
     * আছেই, শুধু আর চলে না। অথচ ঠিক এভাবেই একটা যাচাই হারায় —
     * "একটু পরীক্ষা করে দেখি" বলে মন্তব্য করা হয়, তারপর থেকে যায়।
     */
    private function bodyOf(ReflectionMethod $method): string
    {
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();

        if (! is_string($file) || $start === false || $end === false) {
            return '';
        }

        $code = $this->withoutComments((string) file_get_contents($file));
        $lines = explode("\n", $code);

        return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
    }
}
