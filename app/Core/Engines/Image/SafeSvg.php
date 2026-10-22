<?php

declare(strict_types=1);

namespace App\Core\Engines\Image;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * ⭐ SVG লোগো — স্ক্রিপ্ট ছাঁটা হয়, তারপরই রাখা (মালিক, ২১ অক্টোবর ২০২৬: লোগো PNG/SVG/JPG, "SVG থেকে স্ক্রিপ্ট সরাও")।
 *
 * ── কেন এতদিন SVG নেওয়াই হত না ─────────────────────────────────────────
 * ⛔ SVG ছবি নয়, একটা XML নথি — তাতে `<script>`, `onload="…"`, `href="javascript:…"`, `<foreignObject>`-এর ভেতরে গোটা
 * HTML বসানো যায়। লোগোটা `/storage/logos/…`-এ সরাসরি পরিবেশিত হয়; কেউ ঠিকানাটা খুললে ব্রাউজার নথিটা চালায় — আমাদের
 * ডোমেইনে, আমাদের কুকিসহ। ⓘ `<img>`-এর ভেতরে স্ক্রিপ্ট চলে না, কিন্তু সরাসরি খোলায় চলে — তাই কেবল "img-এ দেখাই" যথেষ্ট নয়।
 *
 * ── ⭐ এখন: একটা সাদা-তালিকা নয়, একটা ছাঁকনি — আর যা বোঝা যায় না তা প্রত্যাখ্যান ───────────────
 *  · মূল উপাদান `<svg>` না হলে, বা XML পড়াই না গেলে → null (দরজা "লোগো" ঘরে ভুল দেখায়);
 *  · `<!ENTITY` থাকলে → null (XXE আর "বিলিয়ন লাফ" — ভেতরের সত্তা ছড়িয়ে কয়েক GB); বাইরের DTD পড়া হয় না (`LIBXML_NONET`,
 *    `LIBXML_NOENT` নয়);
 *  · `<script>`, `<foreignObject>`, `<iframe>`, `<embed>`, `<object>`, `<handler>`, `<listener>` → মুছে ফেলা, ভেতরসহ;
 *  · প্রতিটা `on…` অ্যাট্রিবিউট (onload, onclick, onbegin…) → মুছে ফেলা;
 *  · যেকোনো অ্যাট্রিবিউটের মানে (ফাঁকা অক্ষর বাদ দিয়ে) `javascript:` বা `vbscript:` → মুছে ফেলা — `href`, `xlink:href`,
 *    `<animate to="…">`, `<set to="…">` সবই এতে ধরা পড়ে; `href`-এ `data:` কেবল ছবির হলে থাকে;
 *  · `<style>`-এ `javascript:` বা `@import` থাকলে → মুছে ফেলা; `<?xml-stylesheet ?>` জাতীয় নির্দেশ → মুছে ফেলা।
 * ⓘ রং, আকার, পথ, গ্রেডিয়েন্ট, `<style>`-এর সাধারণ ক্লাস — যা দিয়ে লোগো আঁকা হয় — সবই থাকে।
 */
final class SafeSvg
{
    /** ভেতরসহ মুছে ফেলার উপাদান (ছোট হাতে, নামের স্থানীয় অংশ) */
    private const DROPPED = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'handler', 'listener'];

    /** পরিষ্কার SVG — বা null, যদি এটা পড়ার মতো SVG-ই না হয় */
    public static function clean(string $bytes): ?string
    {
        if (trim($bytes) === '' || stripos($bytes, '<!ENTITY') !== false) {
            return null;
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($bytes, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->documentElement;

        if (! $loaded || ! $root instanceof DOMElement || strtolower($root->localName) !== 'svg') {
            return null;
        }

        $xpath = new DOMXPath($dom);

        foreach (self::nodes($xpath, '//processing-instruction() | //comment()') as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach (self::nodes($xpath, '//*') as $element) {
            if (! $element instanceof DOMElement || $element->parentNode === null) {
                continue;
            }

            $name = strtolower($element->localName);

            if (in_array($name, self::DROPPED, true)
                || ($name === 'style' && preg_match('/javascript:|@import/i', (string) $element->textContent) === 1)) {
                $element->parentNode->removeChild($element);

                continue;
            }

            /** @var list<DOMAttr> $attributes */
            $attributes = iterator_to_array($element->attributes ?? [], false);

            foreach ($attributes as $attribute) {
                if (self::unsafe($attribute)) {
                    $element->removeAttributeNode($attribute);
                }
            }
        }

        $clean = $dom->saveXML($root);

        return $clean === false ? null : $clean;
    }

    private static function unsafe(DOMAttr $attribute): bool
    {
        $name = strtolower($attribute->localName ?? $attribute->name);
        /* ⓘ "java\tscript:" বা "&#10;javascript:" — ব্রাউজার ফাঁকা অক্ষর গিলে ফেলে, তাই মেলানোর আগে আমরাও */
        $value = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $attribute->value));

        if (str_starts_with($name, 'on')) {
            return true;
        }

        if (str_contains($value, 'javascript:') || str_contains($value, 'vbscript:')) {
            return true;
        }

        return $name === 'href' && str_starts_with($value, 'data:') && ! str_starts_with($value, 'data:image/');
    }

    /** @return list<DOMNode> */
    private static function nodes(DOMXPath $xpath, string $query): array
    {
        $found = $xpath->query($query);

        return $found === false ? [] : iterator_to_array($found, false);
    }
}
