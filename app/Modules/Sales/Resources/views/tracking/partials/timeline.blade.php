{{--
    ⭐ চালানের সময়রেখা — তৈরি → গাড়ি → লোডিং → প্যাক → গেট পাস → পথে → পৌঁছেছে ([[SaleTracking::timeline()]], ৪ অক্টোবর ২০২৬)।
    ⓘ প্রতিটা ধাপে সময়, কে করলেন, গাড়ি আর চালক — এক লাইনে এক জিনিস (মালিকের নিয়ম)। চালানের পাতা আর ট্র্যাকিংয়ের পাতা দুটোই এটা আঁকে।
    `$timeline` — সাত সারির তালিকা।
--}}
<section data-boxed data-challan-timeline class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
    <h2 class="mb-2 text-sm font-semibold">{{ __('sales::tracking.timeline.title') }}</h2>
    <ol class="grid gap-2">
        @foreach ($timeline as $row)
            <li data-timeline-step="{{ $row['step'] }}" data-state="{{ $row['state'] }}" class="text-sm">
                <div class="{{ $row['state'] === 'todo' ? 'text-(--color-ink-muted)' : 'font-semibold' }}">
                    @switch($row['state'])
                        @case('done') ✓ @break
                        @case('rejected') ✕ @break
                        @case('hold') ■ @break
                        @default ○
                    @endswitch
                    {{ $row['label'] }}
                    @if ($row['state'] === 'current')
                        · {{ __('sales::tracking.timeline.now') }}
                    @endif
                </div>
                @if ($row['at'])
                    <div class="text-xs text-(--color-ink-muted)">{{ \Illuminate\Support\Carbon::parse($row['at'])->timezone(config('app.timezone'))->format('d/m/Y h:i A') }}</div>
                @elseif ($row['state'] === 'todo')
                    <div class="text-xs text-(--color-ink-muted)">{{ __('sales::tracking.timeline.not_yet') }}</div>
                @endif
                @if ($row['by'])
                    <div class="text-xs">{{ __('sales::tracking.timeline.by') }}: {{ $row['by'] }}</div>
                @endif
                @if ($row['vehicle'])
                    <div class="text-xs">{{ __('sales::tracking.timeline.car') }}: {{ $row['vehicle'] }}</div>
                @endif
                @if ($row['driver'])
                    <div class="text-xs">{{ __('sales::tracking.timeline.driver') }}: {{ $row['driver'] }}</div>
                @endif
                {{-- ⭐ পৌঁছানোর প্রমাণ — কে বুঝে নিলেন, নাম আর ফোন (ধাপ ৭) --}}
                @if ($row['receiver'] ?? null)
                    <div class="text-xs font-medium" data-timeline-receiver>{{ __('sales::tracking.timeline.received_by') }}: {{ $row['receiver'] }}</div>
                @endif
            </li>
        @endforeach
    </ol>
</section>
