{{--
    ঘণ্টার একটা খবর, চিঠি হয়ে।

    ── কেন পাসওয়ার্ডের চিঠির ছাঁচটাই আবার ───────────────────────────────
    ইনলাইন CSS, টেবিল নয়, Laravel-এর markdown ছাঁচ নয় — তিনটা কারণই
    `mail/password_reset.blade.php`-এ লেখা আছে, আর সেগুলো এখানেও হুবহু
    খাটে। ⓘ দুইটা চিঠি দেখতে এক হওয়া দরকার: প্রাপক যেন এক নজরে বোঝেন
    দুইটাই একই ব্যবস্থা থেকে এসেছে।

    ── ⚠️ কেন `$body` ও `$url` থাকতেও পারে, না-ও পারে ───────────────────
    ঘণ্টার সারিতে দুইটাই nullable — কিছু খবরের কেবল শিরোনামই সব কথা।
    ⛔ শর্ত ছাড়া ছাপালে খালি অনুচ্ছেদ আর "কোথাও-যায়-না" বোতাম বসত।
--}}
<div dir="ltr"
     style="margin:0;padding:24px;background:#f6f7f9;
            font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Noto Sans Bengali',sans-serif;
            color:#1f2328;line-height:1.7;">

    <div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e3e6ea;
                border-radius:10px;padding:28px;">

        <p style="margin:0 0 4px;font-size:18px;font-weight:600;color:#1f2328;">
            {{ $title }}
        </p>

        <p style="margin:0 0 18px;font-size:14px;color:#5b6670;">
            {{ __('core.notify.mail_greeting', ['name' => $name], $locale) }}
        </p>

        @if (filled($body))
            <p style="margin:0 0 22px;font-size:14px;">{{ $body }}</p>
        @endif

        @if (filled($url))
            <p style="margin:0 0 22px;">
                <a href="{{ $url }}"
                   style="display:inline-block;background:#1f6feb;color:#ffffff;text-decoration:none;
                          padding:11px 20px;border-radius:8px;font-size:14px;font-weight:600;">
                    {{ __('core.notify.mail_button', [], $locale) }}
                </a>
            </p>

            {{-- বোতাম না খুললে ঠিকানাটা নিজেই — কারণ password_reset-এ লেখা --}}
            <p style="margin:0 0 6px;font-size:12px;color:#5b6670;">
                {{ __('core.notify.mail_fallback', [], $locale) }}
            </p>
            <p style="margin:0 0 22px;font-size:12px;word-break:break-all;">
                <a href="{{ $url }}" style="color:#1f6feb;">{{ $url }}</a>
            </p>
        @endif

        <hr style="border:0;border-top:1px solid #e3e6ea;margin:0 0 16px;">

        {{--
            ⭐ "এই চিঠিগুলো বন্ধ করা যায়, আর কোথায়" — বাদ দেওয়া যায় না।

            ⓘ যে চিঠি বন্ধ করার পথ দেখায় না, মানুষ সেটা স্প্যামে ফেলেন —
            আর একবার ঠিকানাটা স্প্যামে গেলে ⛔ **পাসওয়ার্ড রিসেটের
            চিঠিটাও** সেখানে যায়। ⚠️ অর্থাৎ এই এক লাইনটা না থাকলে এই
            ফিচারটা লগইনের পথটাই নষ্ট করতে পারত।
        --}}
        <p style="margin:0;font-size:12px;color:#5b6670;">
            {{ __('core.notify.mail_optout', [], $locale) }}
        </p>
    </div>

    <p style="max-width:560px;margin:14px auto 0;font-size:11px;color:#8b949e;text-align:center;">
        {{ __('core.brand.name', [], $locale) }}
    </p>
</div>
