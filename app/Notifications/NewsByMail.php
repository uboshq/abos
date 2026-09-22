<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Notification as Bell;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ঘণ্টায় যে খবরটা বাজল, সেটাই চিঠি হয়ে ইনবক্সে — ২২ সেপ্টেম্বর ২০২৬।
 *
 * ── ⓘ কেন প্রতিটা ধরনের জন্য আলাদা ক্লাস নয় ─────────────────────────
 * পাঁচ ধরনের খবরের জন্য পাঁচটা `Notification` ক্লাস লেখা যেত। ⛔ কিন্তু
 * তাতে **ষষ্ঠ ধরনটা যোগ করার দিন চিঠিটা লিখতে ভুল হত** — ঘণ্টা বাজত,
 * চিঠি যেত না, আর কোথাও কিছু লাল হত না। ⚠️ ABOS-এর সবচেয়ে চেনা
 * আকৃতিটাই সেটা: কাজটা হয়েছে, জোড়াটা নয়।
 *
 * ⭐ তাই চিঠিটা ঘণ্টার সারিটাকেই বয়ে নেয় — শিরোনাম, শরীর, ঠিকানা।
 * নতুন ধরনের খবর কোনো কোড না বদলেই চিঠি পায়।
 *
 * ── ⛔ কেন এটা `ShouldQueue` নয় ─────────────────────────────────────
 * **মেপে দেখা, ২২ সেপ্টেম্বর ২০২৬:** লাইভে `QUEUE_CONNECTION=database`,
 * আর `queue:work` চলছে **শূন্যটা**। ⚠️ এই ক্লাসে `ShouldQueue` বসালে
 * প্রতিটা চিঠি `jobs` টেবিলে গিয়ে বসত আর **কোনোদিন যেত না** — অথচ
 * কোড দিব্যি সফল ফেরত দিত।
 *
 * ⓘ ওটা ঠিক ঐ আকৃতি যেটা [[App\Core\Support\MailReach]] ঠেকাতে
 * বানানো — **সফল দেখানো ব্যর্থতা**, যার একমাত্র লক্ষণ মানুষের অপেক্ষা।
 *
 * ⭐ ওয়ার্কার বসানোর দিন এখানে `implements ShouldQueue` যোগ করাই
 * যথেষ্ট — কিন্তু **আগে নয়**, আর সেটা পাহারা দেয়
 * [[Tests\Feature\Core\TheNewsLeavesTheBuildingTest]]।
 */
final class NewsByMail extends Notification
{
    public function __construct(private readonly Bell $bell) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /*
         * ভাষাটা তাঁর নিজের রেকর্ড থেকে, অনুরোধের চলতি ভাষা থেকে নয়।
         *
         * ⚠️ এখানে কারণটা পাসওয়ার্ডের চিঠির চেয়েও কড়া: খবরগুলো প্রায়ই
         * **ক্রন থেকে** যায় (সূচিমতো রিপোর্ট, জমার মেয়াদ, হাতধার), আর
         * ক্রনের কোনো ব্যবহারকারী নেই — অনুরোধের ভাষা বলতে তখন অ্যাপের
         * ডিফল্ট ছাড়া কিছুই নেই। ⛔ তাই যিনি বাংলায় কাজ করেন তিনি
         * ইংরেজি চিঠি পেতেন, আর কেন পেতেন কেউ বলতে পারত না।
         */
        $locale = in_array($notifiable->locale, ['bn', 'en'], true)
            ? $notifiable->locale
            : (string) config('app.locale');

        /*
         * ⓘ শিরোনাম ও শরীর **পাঠানোর মুহূর্তে অনুবাদ করা হয়ে** সারিতে
         * বসেছে (`NotificationService::send()`-এর ডাকা জায়গাগুলো দেখুন),
         * তাই এখানে আর অনুবাদ নেই — কেবল বহন।
         *
         * ⚠️ ফলে একটা সীমা আছে, আর সেটা লিখে রাখা দরকার: খবরটা যে ভাষায়
         * তৈরি হয়েছিল চিঠিও সেই ভাষায় যায়। চিঠির **কাঠামোটা** (অভিবাদন,
         * বোতাম, পাদটীকা) প্রাপকের ভাষায়। ⓘ পুরোটা ঠিক করতে হলে সারিতে
         * অনূদিত লেখার বদলে চাবি+মান রাখতে হত — সেটা আলাদা একটা কাজ,
         * আর আজ ঐটুকুর জন্য গোটা ঘণ্টা ভাঙার মানে নেই।
         */
        return (new MailMessage)
            ->subject($this->bell->title)
            ->view('mail.news', [
                'name' => $notifiable->name,
                'title' => $this->bell->title,
                'body' => $this->bell->body,
                'url' => $this->bell->url,
                'locale' => $locale,
            ]);
    }
}
