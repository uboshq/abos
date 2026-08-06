@props(['document'])

{{--
    ডকুমেন্টের কাগজপত্র — সরবরাহকারীর বিল, চালানের ছবি, ব্যাংক স্লিপ।

    ── কেন এটা একটা কম্পোনেন্ট ──────────────────────────────────────────
    কাগজ লাগে ক্রয় বিলে, বিক্রয় চালানে, ভাউচারে — সব জায়গায় একই জিনিস।
    প্রতিটা পর্দায় আলাদা করে লিখলে একটাতে মুছে ফেলার বোতাম বসত, অন্যটায়
    বসত না, আর কেউ বুঝত না কেন।

    ডকুমেন্টটা নিজেই বলে দেয় সে কোন ধরনের (Drillable), তাই এখানে কোনো
    মডিউলের নাম নেই।
--}}
@php
    $sourceType = $document::drillSourceType();

    $papers = app(\App\Core\Engines\Attachment\AttachmentEngine::class)
        ->listFor(
            app(\App\Core\Engines\Drill\DrillResolver::class)->moduleFor($sourceType) ?? '',
            $sourceType,
            (int) $document->getKey(),
        );

    /*
     * যিনি এই ধরনের ডকুমেন্ট তৈরি করতে পারেন, তিনিই কাগজ রাখতে পারেন।
     *
     * 'update' নয়: প্রায় সব মডিউলে ওই নিয়মে "খসড়া হলে তবেই" শর্ত আছে,
     * অথচ সরবরাহকারীর আসল বিল হাতে আসে পোস্ট করার পরে।
     */
    $canAttach = auth()->user()?->can('create', $document::class) ?? false;
@endphp

<section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
    <h2 class="border-b border-(--color-border) px-4 py-3 font-semibold">
        {{ __('core.attachment.title') }}
    </h2>

    @if ($papers->isEmpty())
        <p class="px-4 py-4 text-sm text-(--color-ink-muted)">
            {{ __('core.attachment.none') }}
        </p>
    @else
        <ul class="divide-y divide-(--color-border)">
            @foreach ($papers as $paper)
                <li class="flex flex-wrap items-center gap-3 px-4 py-2 text-sm">
                    <a href="{{ route('attachment.download', $paper) }}"
                       class="min-w-0 flex-1 truncate text-(--color-brand-500) underline-offset-2 hover:underline">
                        {{ $paper->original_name }}
                    </a>

                    {{-- মাপটা মানুষের ভাষায় — "2.4 MB", বাইট নয় --}}
                    <span class="tabular text-2xs text-(--color-ink-muted)">{{ $paper->humanSize() }}</span>

                    @if ($paper->version > 1)
                        {{-- পুরনো সংস্করণ মুছে যায় না, তাই কোনটা চলতি তা
                             লেখা থাকা দরকার --}}
                        <span class="rounded-(--radius-field) bg-(--color-surface-hover) px-1.5 text-2xs">
                            v{{ $paper->version }}
                        </span>
                    @endif

                    <span class="text-2xs text-(--color-ink-muted)">
                        {{ $paper->uploader?->name }} · {{ $paper->created_at?->format('d/m/Y') }}
                    </span>

                    @if ($canAttach)
                        <form method="POST" action="{{ route('attachment.destroy', $paper) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="rounded-(--radius-field) px-2 py-1 text-(--color-ink-muted)
                                           transition-colors hover:bg-(--color-surface-hover)
                                           hover:text-(--color-danger)">
                                {{ __('core.attachment.remove') }}
                            </button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($canAttach)
        <form method="POST" action="{{ route('attachment.store') }}" enctype="multipart/form-data"
              class="flex flex-wrap items-center gap-2 border-t border-(--color-border) px-4 py-3">
            @csrf
            <input type="hidden" name="source_type" value="{{ $sourceType }}">
            <input type="hidden" name="source_id" value="{{ $document->getKey() }}">

            <input type="file" name="file" required
                   class="min-w-0 flex-1 text-sm file:me-2 file:rounded-(--radius-field)
                          file:border file:border-(--color-border) file:bg-(--color-surface-app)
                          file:px-3 file:py-1.5 file:text-sm">

            <x-ui.button type="submit" tone="secondary">{{ __('core.attachment.upload') }}</x-ui.button>

            {{-- সীমাটা লেখা থাকে, নাহলে বড় ফাইল বেছে জমা দেওয়ার পর
                 ব্যবহারকারী জানতেন সেটা নেওয়া হয়নি --}}
            <span class="w-full text-2xs text-(--color-ink-muted)">
                {{ __('core.attachment.limit') }}
            </span>
        </form>
    @endif
</section>
