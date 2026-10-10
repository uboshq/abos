{{--
    ⭐ সারসংক্ষেপ চিঠি — দিনের বা সপ্তাহের ধরে রাখা খবর (বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৩)।
    ⓘ ছাঁচ `mail/news.blade.php`-এর মতোই (ইনলাইন CSS) — দুইটা চিঠি এক নজরে একই ব্যবস্থার। কেবল শিরোনাম, উৎস আর সময়;
    পুরো লেখা সফটওয়্যারে।
--}}
<div dir="ltr"
     style="margin:0;padding:24px;background:#f6f7f9;
            font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Noto Sans Bengali',sans-serif;
            color:#1f2328;line-height:1.7;">

    <div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e3e6ea;
                border-radius:10px;padding:28px;">

        <p style="margin:0 0 4px;font-size:18px;font-weight:600;color:#1f2328;">
            {{ __('core.notify.digest_subject.'.$period, ['count' => $bells->count()], $locale) }}
        </p>

        <p style="margin:0 0 18px;font-size:14px;color:#5b6670;">
            {{ __('core.notify.mail_greeting', ['name' => $name], $locale) }}
        </p>

        <ul style="margin:0 0 22px;padding:0;list-style:none;">
            @foreach ($bells as $bell)
                <li style="margin:0 0 10px;padding:0 0 10px;border-bottom:1px solid #eef0f2;font-size:14px;">
                    {{ $bell->title }}
                    <span style="display:block;font-size:12px;color:#5b6670;">
                        {{ \App\Core\Support\NotificationKinds::sourceLabel($bell->module) }} · {{ $bell->created_at?->format('d/m/Y H:i') }}
                    </span>
                </li>
            @endforeach
        </ul>

        <p style="margin:0 0 22px;">
            <a href="{{ $url }}"
               style="display:inline-block;background:#1f6feb;color:#ffffff;text-decoration:none;
                      padding:11px 20px;border-radius:8px;font-size:14px;font-weight:600;">
                {{ __('core.notify.mail_button', [], $locale) }}
            </a>
        </p>

        <hr style="border:0;border-top:1px solid #e3e6ea;margin:0 0 16px;">

        <p style="margin:0;font-size:12px;color:#5b6670;">
            {{ __('core.notify.mail_optout', [], $locale) }}
        </p>
    </div>

    <p style="max-width:560px;margin:14px auto 0;font-size:11px;color:#8b949e;text-align:center;">
        {{ __('core.brand.name', [], $locale) }}
    </p>
</div>
