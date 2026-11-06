{{--
    ⭐ "শেষ বদল" — কন্ট্রোল প্যানেলের পরিকল্পনা, মালিকের অনুমোদিত, ২১ অক্টোবর ২০২৬, দফা ১৩: প্রতিটা পাতায় শেষ বদল (কবে, কে), আর পাশে
    "বদলের ইতিহাস" — সেই রেকর্ডের পুরো খাতা, যেখানে আগের-পরের মান খোঁজা যায় (governance.audit.record)।

    ⓘ খাতার দরজা কেবল নিরীক্ষার চাবিতে (`governance.audit.view`) — মানগুলো ওখানে, চাবি ছাড়া কেবল তারিখ আর নাম।
    ⓘ নতুন রেকর্ড (এখনো সংরক্ষিত নয়) বা কোনো খাতা না থাকলে কিছুই আঁকা হয় না — "শেষ বদল: —" কোনো কথা বলে না।
--}}
@props(['subject'])

@php
    $trail = $subject?->exists
        ? \App\Models\AuditTrail::query()->forRecord($subject::class, (int) $subject->getKey())->latest('id')->with('user:id,name')->first()
        : null;
@endphp

@if ($trail)
    <p data-last-change {{ $attributes->merge(['class' => 'mb-3 flex flex-wrap items-baseline gap-x-2 text-xs text-(--color-ink-muted)']) }}>
        <span>{{ __('core.audit.last_change') }}:</span>
        <span class="tabular font-medium text-(--color-ink)">{{ \App\Core\Support\DateFormat::spoken($trail->created_at) }}</span>
        @if ($trail->user)
            <span>· {{ $trail->user->name }}</span>
        @endif
        @if (\Illuminate\Support\Facades\Route::has('governance.audit.record') && auth()->user()?->can('governance.audit.view'))
            <a data-change-history href="{{ route('governance.audit.record', $trail->id) }}" class="font-semibold text-(--color-link) hover:underline">
                {{ __('core.audit.history') }} →
            </a>
        @endif
    </p>
@endif
