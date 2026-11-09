{{--
    ⭐ "আজকের খবর" — তহবিলের ঘরের পাশে (মালিকের অনুমোদিত নমুনা, ১০ নভেম্বর ২০২৬)। লাইন [[FundNews]] থেকে; প্রতিটা নিজের
    পাতায় যায়। ⓘ রং নমুনার — জরুরি লাল, ৭ দিনে কমলা, বাজেট হলুদ, ভালো সবুজ; হালকা পটে গাঢ় লেখা।
--}}
@php
    $tones = [
        'urgent' => ['#fef2f2', '#dc2626', '#ffffff'],
        'soon' => ['#fff7ed', '#ea580c', '#ffffff'],
        'budget' => ['#fefce8', '#facc15', '#422006'],
        'good' => ['#f0fdf4', '#16a34a', '#ffffff'],
    ];
@endphp
<section data-fund-news
         style="flex: 1 1 380px; min-width: 0; background: #ffffff; color: #0f172a; border-radius: 18px; padding: 16px 18px; display: flex; flex-direction: column; gap: 8px; box-shadow: 0 1px 2px rgba(15,23,42,0.08)">
    <h2 style="margin: 0; font-size: 16px; font-weight: 700">{{ __('finance::fund.news_title') }}</h2>
    @forelse ($news as $item)
        @php [$bg, $tagBg, $tagFg] = $tones[$item['tone']]; @endphp
        @php $line = "text-decoration: none; color: #0f172a; display: flex; gap: 10px; align-items: flex-start; background: {$bg}; border-radius: 12px; padding: 10px 12px"; @endphp
        @if ($item['href'])
            <a data-fund-news-item="{{ $item['tone'] }}" href="{{ $item['href'] }}" style="{{ $line }}">
                <span style="flex: none; font-size: 12px; font-weight: 700; color: {{ $tagFg }}; background: {{ $tagBg }}; border-radius: 6px; padding: 2px 8px">{{ $item['tag'] }}</span>
                <span style="font-size: 14px">{{ $item['lead'] }} <b>{{ $item['bold'] }}</b> {{ $item['tail'] }}</span>
            </a>
        @else
            <div data-fund-news-item="{{ $item['tone'] }}" style="{{ $line }}">
                <span style="flex: none; font-size: 12px; font-weight: 700; color: {{ $tagFg }}; background: {{ $tagBg }}; border-radius: 6px; padding: 2px 8px">{{ $item['tag'] }}</span>
                <span style="font-size: 14px">{{ $item['lead'] }} <b>{{ $item['bold'] }}</b> {{ $item['tail'] }}</span>
            </div>
        @endif
    @empty
        <p style="margin: 0; font-size: 14px; color: #475569">{{ __('finance::fund.news_none') }}</p>
    @endforelse
</section>
