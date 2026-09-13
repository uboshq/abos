{{--
    পাসওয়ার্ড রিসেটের চিঠি।

    ── কেন নিজের ভিউ, Laravel-এর markdown ছাঁচ নয় ───────────────────────
    ফ্রেমওয়ার্কের `mail::message` ছাঁচে **তার নিজের ইংরেজি বাক্য মেশানো
    থাকে** — "If you're having trouble clicking the button, copy and paste
    the URL below", আর নিচে "Regards"। ওগুলো অনুবাদযোগ্য নয়, তাই একটা
    বাংলা চিঠির পা আর মাথা ইংরেজি হয়ে থাকত।

    ⚠️ এই ব্যবস্থার নিয়ম ৯ বলে ব্যাকএন্ডের বাংলা লেখা সরাসরি মানুষের
    পর্দায় যায় — ওগুলো লগ নয়, UI। একটা ইমেইলও তাই।

    ── কেন ইনলাইন CSS ───────────────────────────────────────────────────
    ইমেইল ক্লায়েন্টরা `<style>` ব্লক আর ক্লাস প্রায়ই ফেলে দেয় (Gmail-এর
    ওয়েব ক্লায়েন্ট `<head>`-টাই কেটে দেয়)। তাই এখানে Tailwind নেই, আর
    সেটা অভাব নয় — এটা অ্যাপের পাতা নয়, চিঠি।

    ── কেন টেবিল নয় ────────────────────────────────────────────────────
    পুরনো Outlook-এর জন্য টেবিল-লেআউট লাগত। এই চিঠিতে একটাই কলাম, তাই
    সাধারণ ব্লকেই সব ক্লায়েন্টে ঠিক দেখায় — জটিলতাটা দাম দিত, কিছু
    ফেরত দিত না।
--}}
{{-- বাংলা ও ইংরেজি দুইটাই বাঁ-থেকে-ডানে, তাই `dir` স্থির। --}}
<div dir="ltr"
     style="margin:0;padding:24px;background:#f6f7f9;
            font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Noto Sans Bengali',sans-serif;
            color:#1f2328;line-height:1.7;">

    <div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e3e6ea;
                border-radius:10px;padding:28px;">

        <p style="margin:0 0 4px;font-size:18px;font-weight:600;color:#1f2328;">
            {{ __('auth.reset_mail_heading', [], $locale) }}
        </p>

        <p style="margin:0 0 18px;font-size:14px;color:#5b6670;">
            {{ __('auth.reset_mail_greeting', ['name' => $name], $locale) }}
        </p>

        <p style="margin:0 0 20px;font-size:14px;">
            {{ __('auth.reset_mail_body', ['minutes' => $minutes], $locale) }}
        </p>

        <p style="margin:0 0 22px;">
            <a href="{{ $url }}"
               style="display:inline-block;background:#1f6feb;color:#ffffff;text-decoration:none;
                      padding:11px 20px;border-radius:8px;font-size:14px;font-weight:600;">
                {{ __('auth.reset_mail_button', [], $locale) }}
            </a>
        </p>

        {{--
            বোতাম না খুললে ঠিকানাটা নিজেই।

            ⚠️ অনেক ইমেইল ক্লায়েন্ট বোতামের লিংক আটকে দেয় বা ছবি বন্ধ
            রাখে। ঠিকানাটা লেখা না থাকলে ঐ ব্যবহারকারীর আর কোনো পথ
            থাকত না — আর তিনি ভাবতেন চিঠিটাই ভাঙা।
        --}}
        <p style="margin:0 0 6px;font-size:12px;color:#5b6670;">
            {{ __('auth.reset_mail_fallback', [], $locale) }}
        </p>
        <p style="margin:0 0 22px;font-size:12px;word-break:break-all;">
            <a href="{{ $url }}" style="color:#1f6feb;">{{ $url }}</a>
        </p>

        <hr style="border:0;border-top:1px solid #e3e6ea;margin:0 0 16px;">

        {{--
            ⭐ "আপনি না চেয়ে থাকলে কিছুই করতে হবে না" — এই লাইনটা বাদ
            দেওয়া যায় না।

            ⓘ কেউ অন্যের ইমেইল দিয়ে রিসেট চাইতে পারেন, আর তখন নির্দোষ
            মানুষটার ইনবক্সে হঠাৎ একটা চিঠি আসে। ⚠️ ব্যাখ্যা না থাকলে
            তিনি ধরে নিতেন তাঁর অ্যাকাউন্ট ভাঙা হয়েছে — অথচ আসলে কিছুই
            ঘটেনি, আর লিংকটা না ছুঁলে পুরনো পাসওয়ার্ডই চলতে থাকে।
        --}}
        <p style="margin:0;font-size:12px;color:#5b6670;">
            {{ __('auth.reset_mail_ignore', [], $locale) }}
        </p>
    </div>

    {{--
        ⚠️ `config('app.name')` নয় — মেপে দেখা, ১৩ সেপ্টেম্বর ২০২৬।

        `.env`-এ `APP_NAME` **সেট করাই নেই**, তাই ওটা Laravel-এর নিজের
        ডিফল্টে নামে আর চিঠির পায়ে **"Laravel"** লেখা যেত। ⓘ ব্র্যান্ডের
        নামটা এই ব্যবস্থার নিজের চাবিতে আছে, আর সেটা দুই ভাষাতেই।
    --}}
    <p style="max-width:560px;margin:14px auto 0;font-size:11px;color:#8b949e;text-align:center;">
        {{ __('core.brand.name', [], $locale) }}
    </p>
</div>
