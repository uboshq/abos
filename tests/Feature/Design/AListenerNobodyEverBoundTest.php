<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alpine-এর শোনার ঘর যেন সত্যিই শোনে।
 *
 * ── কী ঘটেছিল, ২৯ আগস্ট ২০২৬ ─────────────────────────────────────────
 * আদায়ের পর্দায় গ্রাহক বদলালে বিলের তালিকা বদলানোর কথা ছিল। কোড লেখা
 * হলো, পরীক্ষা সবুজ হলো (markup-এ `@change` আছে), ডিপ্লয়ও হলো — আর
 * লাইভে গ্রাহক বাছলে **কিছুই হত না**।
 *
 * Alpine কেবল `x-data`-র ভেতরের এলিমেন্ট পড়ে। আগের ধাপে আমি নিজেই
 * ফর্মের মোড়ক `x-data`-টা তুলে দিয়েছিলাম, তাই ওই `select`-এর উপরে আর
 * কোনো `x-data` ছিল না — `@change` কখনো বাঁধাই হয়নি।
 *
 * ── কেন এটা সবচেয়ে বাজে ধরনের ভুল ────────────────────────────────────
 * পর্দায় কোনো ভুল নেই, কনসোলে কোনো ভুল নেই, HTML-এ অ্যাট্রিবিউটটা
 * চোখের সামনেই বসা। কেবল কিছুই ঘটে না।
 *
 * ── কেন দাবিটা আঁকা পাতার উপর, ফাইলের উপর নয় ─────────────────────────
 * প্রথমে ব্লেড ফাইল পড়ে দেখার চেষ্টা হয়েছিল: যে ট্যাগ শোনে তার নিজের
 * `x-data` আছে কি না। ওটা ছয়টা **মিথ্যা লাল** দিল — বোতামগুলোর শোনার
 * ঘর ফাইলের উপরের দিকের একটা `x-data`-র ভেতরেই বসে, আর কম্পোনেন্ট
 * আঁকা হলেও DOM-এ ওই মোড়কের ভেতরেই থাকে।
 *
 * নেস্টিংটা সত্যিই জানা যায় আঁকা HTML-এ। তাই পর্দাগুলো সত্যিই আঁকা হয়,
 * আর গাছ বেয়ে উপরে উঠে দেখা হয় — এটাই আসল প্রশ্ন, আর এটারই উত্তর আছে।
 */
class AListenerNobodyEverBoundTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
    }

    /**
     * যে পর্দাগুলোতে Alpine সবচেয়ে বেশি কাজ করে।
     *
     * সবগুলো পর্দা আঁকলে এই পরীক্ষাটা মিনিটের পর মিনিট চলত। এখানে
     * ফর্ম ও তালিকা দুইটাই আছে, আর যে পর্দায় ভুলটা ঘটেছিল সেটাও।
     *
     * @return array<string, string>
     */
    private function screens(): array
    {
        return [
            'আদায়ের ফর্ম' => route('sales.collection.create'),
            'চালানের ফর্ম' => route('sales.invoice.create'),
            'ক্রয় বিলের ফর্ম' => route('purchase.bill.create'),
            'গ্রাহকের ফর্ম' => route('customer.create'),
            'পণ্যের ফর্ম' => route('inventory.product.create'),
            'চালানের তালিকা' => route('sales.invoice.index'),
            'পণ্যের তালিকা' => route('inventory.product.index'),
            'চেহারার পাতা' => route('appearance'),
        ];
    }

    public function test_no_alpine_listener_sits_outside_every_x_data(): void
    {
        $deaf = [];

        foreach ($this->screens() as $what => $url) {
            $html = (string) $this->actingAs($this->user)->get($url)->getContent();

            /*
             * ── `@change` নামটা DOMDocument পড়তেই পারে না ─────────────
             * প্রথম লেখায় এই পরীক্ষাটা **সবুজ ছিল অথচ অন্ধ**: `x-data`
             * তুলে নিয়ে চালিয়েও সবুজই থাকল। কারণ `@` দিয়ে শুরু হওয়া
             * নাম XML-এ বৈধ নয়, আর পার্সার ওই অ্যাট্রিবিউটগুলো নীরবে
             * ফেলে দেয় — তাই খোঁজার মতো কিছুই থাকত না।
             *
             * ঠিক সেই ফাঁদটাই এই ফাইলের বিষয়: দেখতে কাজ করছে, আসলে
             * কিছুই দেখছে না। তাই নামগুলো আগে পড়ার মতো করে নেওয়া হয়,
             * তারপর গাছটা পড়া হয়।
             */
            $parsable = (string) preg_replace(
                ['/\s@([a-zA-Z][\w.\-]*)=/', '/\sx-on:([a-zA-Z][\w.\-]*)=/'],
                [' data-alpine-on-$1=', ' data-alpine-on-$1='],
                $html,
            );

            $doc = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="utf-8" ?>'.$parsable);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            foreach ((new DOMXPath($doc))->query('//*') as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $listener = null;

                foreach (iterator_to_array($node->attributes) as $attribute) {
                    if (str_starts_with($attribute->nodeName, 'data-alpine-on-')) {
                        $listener = $attribute->nodeName;

                        break;
                    }
                }

                if ($listener === null) {
                    continue;
                }

                /* `.window`/`.document` দেওয়া শোনার ঘরও স্কোপ ছাড়া চলে না। */
                $covered = false;

                for ($up = $node; $up instanceof DOMElement; $up = $up->parentNode) {
                    if ($up->hasAttribute('x-data')) {
                        $covered = true;

                        break;
                    }
                }

                if (! $covered) {
                    $deaf[] = $what.' → <'.$node->tagName.' '.$listener.'> ('
                        .mb_substr(trim((string) $node->getAttribute('name')) ?: $node->tagName, 0, 24).')';
                }
            }
        }

        $this->assertSame([], array_unique($deaf), implode("\n", [
            'এই শোনার ঘরগুলোর উপরে কোনো `x-data` নেই — Alpine ওদের পড়েই না:',
            ...array_unique($deaf),
            '',
            'পর্দায় কোনো ভুল দেখা যাবে না। শুধু ক্লিক বা বাছাইয়ে কিছুই',
            'ঘটবে না, আর কেউ বলতে পারবে না কেন।',
            '',
            'এলিমেন্টটায় একটা খালি `x-data` বসিয়ে দিন।',
        ]));
    }
}
