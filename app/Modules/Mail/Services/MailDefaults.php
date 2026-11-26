<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

use App\Modules\Mail\Models\MailFestival;
use App\Modules\Mail\Models\MailTemplate;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ প্রথমবার মেইল খুললে কোম্পানির জন্য শুরুর তালিকা — উৎসব ও দিবস (19টা) আর কাজের টেমপ্লেট (নমুনা v4 পর্দা ৫–৬)।
 * ⓘ একবারই বসে; তারপর কোম্পানি নিজে বদলায়, লুকায়, যোগ করে। চাঁদের তারিখ প্রতি বছর প্রশাসক বসান (`on_date`)।
 */
final class MailDefaults
{
    /** [key, name, greeting, line, when, [from, to, accent], mark, quiet, fixed date m-d] */
    public const FESTIVALS = [
        ['eid_ul_fitr', 'ঈদুল ফিতর', 'ঈদ মোবারক', 'আপনার ও পরিবারের সবার জন্য ঈদের অনেক শুভেচ্ছা', 'শাওয়াল 1', ['#0b4d2c', '#00ab3f', '#f8cb46'], '🌙', false, null],
        ['eid_ul_adha', 'ঈদুল আযহা', 'ঈদুল আযহার শুভেচ্ছা', 'ত্যাগের মহিমায় আলোকিত হোক সবার জীবন', 'জিলহজ 10', ['#0e3b2e', '#127a55', '#f8cb46'], '🕌', false, null],
        ['ramadan', 'রমজান', 'রমজান মোবারক', 'সংযম ও রহমতের মাসে আন্তরিক শুভেচ্ছা', 'রমজান 1', ['#1b1f4a', '#3b3f9a', '#f8cb46'], '🌙', false, null],
        ['holy_nights', 'শবে কদর ও শবে বরাত', 'পবিত্র রজনীর শুভেচ্ছা', 'দোয়া ও মাগফিরাতের রাত', 'চাঁদ অনুযায়ী', ['#0f1d3d', '#24407a', '#e8d8a0'], '✨', false, null],
        ['milad', 'ঈদে মিলাদুন্নবী', 'ঈদে মিলাদুন্নবী (সা.)', 'শান্তি ও কল্যাণের বার্তা সবার জন্য', 'রবিউল আউয়াল 12', ['#0b4d2c', '#1d8a5a', '#ffffff'], '🕌', false, null],
        ['durga_puja', 'দুর্গাপূজা', 'শারদীয় শুভেচ্ছা', 'দুর্গাপূজার আনন্দে ভরে উঠুক সবার ঘর', 'আশ্বিন–কার্তিক', ['#8a0e12', '#f5121d', '#f8cb46'], '🪷', false, null],
        ['diwali', 'কালীপূজা ও দীপাবলি', 'শুভ দীপাবলি', 'আলোয় আলোয় দূর হোক সব অন্ধকার', 'কার্তিক অমাবস্যা', ['#2a0d3e', '#7a1e8f', '#f8a500'], '🪔', false, null],
        ['saraswati', 'সরস্বতী পূজা', 'শুভ বসন্ত পঞ্চমী', 'জ্ঞান ও বিদ্যার আলো ছড়িয়ে পড়ুক', 'মাঘ শুক্লা পঞ্চমী', ['#c98a00', '#f8cb46', '#ffffff'], '🪷', false, null],
        ['janmashtami', 'জন্মাষ্টমী', 'শুভ জন্মাষ্টমী', 'প্রেম ও শান্তির বার্তা সবার মাঝে', 'ভাদ্র কৃষ্ণা অষ্টমী', ['#0d2a6b', '#1a3fd0', '#f8cb46'], '🪈', false, null],
        ['buddha_purnima', 'বুদ্ধ পূর্ণিমা', 'শুভ বুদ্ধ পূর্ণিমা', 'জগতের সকল প্রাণী সুখী হোক', 'বৈশাখী পূর্ণিমা', ['#7a4a00', '#e0a100', '#ffffff'], '☸️', false, null],
        ['christmas', 'বড়দিন', 'শুভ বড়দিন', 'Merry Christmas — আনন্দ ও শান্তির শুভেচ্ছা', '25 ডিসেম্বর', ['#7a0d12', '#c40d16', '#e3f7ea'], '🎄', false, '12-25'],
        ['new_year', 'ইংরেজি নববর্ষ', 'শুভ নববর্ষ', 'Happy New Year — নতুন বছর হোক সাফল্যের', '1 জানুয়ারি', ['#0b1020', '#1a3fd0', '#f8cb46'], '🎆', false, '01-01'],
        ['pohela_boishakh', 'পহেলা বৈশাখ', 'শুভ নববর্ষ', 'এসো হে বৈশাখ — নতুন বছরের শুভেচ্ছা', '14 এপ্রিল', ['#c40d16', '#f5121d', '#ffffff'], '🌺', false, '04-14'],
        ['pohela_falgun', 'পহেলা ফাল্গুন', 'বসন্তের শুভেচ্ছা', 'ফুলে ফুলে রঙিন হোক নতুন দিন', '14 ফেব্রুয়ারি', ['#d9480f', '#f8a500', '#ffffff'], '🌼', false, '02-14'],
        ['nobanno', 'নবান্ন', 'নবান্নের শুভেচ্ছা', 'নতুন ধানের উৎসবে আন্তরিক শুভেচ্ছা', '1 অগ্রহায়ণ', ['#7a5a00', '#c9a227', '#ffffff'], '🌾', false, '11-16'],
        ['ekushe', 'অমর একুশে', 'অমর একুশে — শ্রদ্ধাঞ্জলি', 'ভাষা শহীদদের প্রতি গভীর শ্রদ্ধা', '21 ফেব্রুয়ারি', ['#111111', '#3a3a3a', '#ffffff'], '🕯️', true, '02-21'],
        ['independence', 'স্বাধীনতা দিবস', 'মহান স্বাধীনতা দিবস', 'লাখো শহীদের প্রতি বিনম্র শ্রদ্ধা', '26 মার্চ', ['#006a4e', '#00ab3f', '#ffffff'], '🇧🇩', true, '03-26'],
        ['victory', 'বিজয় দিবস', 'মহান বিজয় দিবস', 'বিজয়ের গৌরবে আমরা গর্বিত', '16 ডিসেম্বর', ['#006a4e', '#00ab3f', '#ffffff'], '🇧🇩', true, '12-16'],
        ['may_day', 'মে দিবস', 'মহান মে দিবস', 'শ্রমজীবী মানুষের প্রতি শ্রদ্ধা', '1 মে', ['#8a0e12', '#c40d16', '#ffffff'], '✊', true, '05-01'],
    ];

    /** [category, key, name, colour, subject, body, button, attach_paper] */
    public const TEMPLATES = [
        ['collect', 'due_reminder', 'বকেয়ার বিনীত স্মরণ', '#1a3fd0', 'আপনার হিসাবে বাকি {বাকি টাকা}',
            "প্রিয় {গ্রাহকের নাম},\nআপনার হিসাবে {বাকি টাকা} বাকি আছে, সবচেয়ে পুরনো বিল {সবচেয়ে পুরনো বিলের তারিখ}-এর।\nসুবিধামতো সময়ে টাকা জমা দিলে কৃতজ্ঞ থাকব।", 'খতিয়ান দেখুন', false],
        ['collect', 'due_final', 'বকেয়া — শেষ সতর্কতা', '#c40d16', 'জরুরি: বাকি {বাকি টাকা}',
            "প্রিয় {গ্রাহকের নাম},\nআপনার হিসাবে অনেক দিন ধরে {বাকি টাকা} বাকি আছে। 7 দিনের মধ্যে না পেলে নতুন অর্ডার বন্ধ থাকবে।\nকোনো প্রশ্ন থাকলে জানাবেন।", null, false],
        ['collect', 'receipt', 'টাকা পেয়েছি — ধন্যবাদ', '#00ab3f', 'টাকা পেয়েছি — ধন্যবাদ',
            "প্রিয় {গ্রাহকের নাম},\nআপনার {টাকা} পেয়েছি, রসিদ সংযুক্ত। এখন আপনার হিসাবে বাকি {বাকি টাকা}।\nধন্যবাদ।", null, true],
        ['collect', 'statement', 'মাস শেষের হিসাব মিলান', '#0b7a70', 'আপনার হিসাবের বিবরণী',
            "প্রিয় {গ্রাহকের নাম},\nআপনার হিসাবের বিবরণী সংযুক্ত — মিলিয়ে 3 দিনের মধ্যে জানালে খুশি হব।", null, true],
        ['sales', 'sales_bill', 'বিক্রয় বিল পাঠানো', '#1a3fd0', 'বিল {বিল নম্বর}',
            "প্রিয় {গ্রাহকের নাম},\nআপনার বিল {বিল নম্বর} সংযুক্ত — মোট {বিলের টাকা}।\nধন্যবাদ।", null, true],
        ['sales', 'price_list', 'নতুন মূল্যতালিকা', '#f5121d', 'নতুন দামের তালিকা',
            "প্রিয় {গ্রাহকের নাম},\nকয়েকটা পণ্যের দাম বদলেছে — নতুন তালিকা সংযুক্ত।", 'অর্ডার করুন', false],
        ['delivery', 'dispatched', 'মাল রওনা হয়েছে', '#0b7a70', 'আপনার মাল রওনা হয়েছে',
            "প্রিয় {গ্রাহকের নাম},\nআপনার অর্ডারের মাল আজ রওনা হয়েছে; চালান সংযুক্ত।", null, true],
        ['purchase', 'purchase_order', 'ক্রয়াদেশ (PO)', '#0b7a70', 'ক্রয়াদেশ {বিল নম্বর}',
            "প্রিয় {সরবরাহকারী},\nসংযুক্ত তালিকা মতো মাল পাঠানোর অনুরোধ করছি।\nধন্যবাদ।", null, true],
        ['hr', 'hr_notice', 'কর্মীর জন্য নোটিশ', '#6a3fd0', 'অফিসের নোটিশ',
            "প্রিয় সহকর্মী,\n…\nধন্যবাদ,\n{আমার নাম}", null, false],
        ['offer', 'festival_offer', 'উৎসবের অফার', '#00ab3f', 'উৎসবের বিশেষ অফার',
            "প্রিয় {গ্রাহকের নাম},\nউৎসব উপলক্ষে বিশেষ ছাড় — আপনার জন্য বাড়তি {উপহার পয়েন্ট} পয়েন্ট।", 'অফার দেখুন', false],
        ['reply', 'reply_thanks', 'ধন্যবাদ, পেয়েছি', null, '', 'ধন্যবাদ, পেয়েছি।', null, false],
        ['reply', 'reply_friday', 'শুক্রবারের মধ্যে পাঠাচ্ছি', null, '', 'শুক্রবারের মধ্যে পাঠিয়ে দিচ্ছি।', null, false],
        ['reply', 'reply_checking', 'হিসাব মিলিয়ে জানাচ্ছি', null, '', 'হিসাবটা মিলিয়ে দেখে জানাচ্ছি।', null, false],
    ];

    public function ensure(): void
    {
        DB::transaction(function () {
            if (! MailFestival::query()->exists()) {
                $year = (int) now()->format('Y');

                foreach (self::FESTIVALS as [$key, $name, $greeting, $line, $when, $colours, $mark, $quiet, $md]) {
                    $date = $md !== null ? \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $year.'-'.$md)->startOfDay() : null;

                    if ($date !== null && $date->lt(now()->startOfDay())) {
                        $date->addYear();
                    }

                    MailFestival::query()->create(compact('key', 'name', 'greeting', 'line', 'when', 'colours', 'mark', 'quiet') + ['on_date' => $date]);
                }
            }

            if (! MailTemplate::query()->whereNull('user_id')->exists()) {
                foreach (self::TEMPLATES as [$category, $key, $name, $colour, $subject, $body, $button, $attach]) {
                    MailTemplate::query()->create([
                        'category' => $category, 'key' => $key, 'name' => $name, 'colour' => $colour, 'subject' => $subject,
                        'body' => $body, 'button' => $button, 'attach_paper' => $attach,
                        'format' => $category === 'reply' ? 'plain' : 'designed', 'locale' => 'bn',
                        'auto' => in_array($key, ['due_reminder'], true) ? ['on' => false, 'rule' => 'due_30', 'day' => 'mon', 'at' => '10:00'] : null,
                    ]);
                }

                foreach (MailFestival::query()->get() as $f) {
                    MailTemplate::query()->create([
                        'category' => 'festival', 'key' => 'fest_'.$f->key, 'name' => $f->name, 'colour' => $f->colours[1] ?? null,
                        'subject' => $f->greeting, 'format' => 'designed', 'locale' => 'bn',
                        'body' => $f->quiet
                            ? "প্রিয় {গ্রাহকের নাম},\nএই দিনে আমরা গভীর শ্রদ্ধায় স্মরণ করি।"
                            : "প্রিয় {গ্রাহকের নাম},\n".$f->line.'।',
                        'auto' => ['on' => false, 'rule' => 'festival', 'at' => '09:00'],
                    ]);
                }
            }
        });
    }
}
