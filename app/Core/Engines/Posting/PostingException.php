<?php

declare(strict_types=1);

namespace App\Core\Engines\Posting;

use RuntimeException;

/**
 * হিসাবে বসানো যায়নি।
 *
 * আলাদা শ্রেণি, কারণ এটা ধরা পড়লে করণীয় আলাদা: সাধারণ ত্রুটির মতো লগ করে
 * এগিয়ে যাওয়া নয় — পুরো লেনদেনটাই থামাতে হয়। অর্ধেক বসা হিসাব না বসা
 * হিসাবের চেয়ে খারাপ।
 */
class PostingException extends RuntimeException
{
    /** ⓘ মানুষের জন্য বাংলায় (বা চলতি ভাষায়) কারণ — `getMessage()` ইংরেজিই থাকে, লগ আর পরীক্ষার জন্য */
    private ?string $forPeople = null;

    /**
     * ⛔ "না"-টা মানুষের ভাষায় — পুরো-ERP অডিট, হিসাব ⓘ ("পোস্টিংয়ের 'না' ইংরেজিতে"; মালিক কেবল বাংলা পড়েন;
     * [[APostingRefusalSpeaksBanglaTest]])। ⓘ আগে পর্দায় যেত "Financial year 2026-2027 is closed. Reopening it is …" — মালিক পড়তে
     * পারতেন না কেন থামল। এখন পর্দা পায় [[forPeople()]]; খাতার লগে ইংরেজিটাই থাকে, যাতে খোঁজা আর মেলানো সহজ থাকে।
     *
     * @param  array<string, mixed>  $params
     */
    public static function said(string $english, string $key, array $params = []): self
    {
        $exception = new self($english);
        $exception->forPeople = __($key, $params);

        return $exception;
    }

    /**
     * পর্দায় যা দেখাবে — চাবি থাকলে তার বাক্য; নইলে বার্তা নিজে বাংলায় লেখা হলে সেটাই ([[ARefusalToPostIsAnAnswerNotACrashTest]]),
     * আর ইংরেজি হলে বাংলা ভূমিকা আর তারপর কারণটা।
     */
    public function forPeople(): string
    {
        if ($this->forPeople !== null) {
            return $this->forPeople;
        }

        return preg_match('/\p{Bengali}/u', $this->getMessage()) === 1
            ? $this->getMessage()
            : __('core.posting.refused', ['reason' => $this->getMessage()]);
    }
}
