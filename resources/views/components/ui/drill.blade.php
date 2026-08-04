@props(['source' => null, 'id' => null, 'entry' => null])

{{--
    "এই সংখ্যাটা কোথা থেকে এল" — ক্রস-কাটিং নিয়ম ১, সেকশন ১৫.১১।

    একটাই কম্পোনেন্ট, সব রিপোর্টে। প্রতিটা রিপোর্টে আলাদা করে "sales_invoice
    হলে এই রুট" লিখলে নতুন ডকুমেন্ট টাইপ যোগ হওয়ার পর পুরনো রিপোর্টগুলোতে
    সেটা ক্লিকযোগ্য হত না, আর কেউ খেয়ালও করত না।

    উৎস খুঁজে না পেলে (ডকুমেন্ট বাতিল বা মুছে ফেলা) লিংকটা নিষ্ক্রিয় দেখায়
    কিন্তু কী ছিল তা বলে — রিপোর্ট ভেঙে পড়ে না।
--}}
@php
    $resolver = app(\App\Core\Engines\Drill\DrillResolver::class);

    $sourceType = $source ?? $entry?->source_type;
    $sourceId = $id ?? $entry?->source_id;

    $target = ($sourceType && $sourceId)
        ? $resolver->describe($sourceType, $sourceId)
        : null;
@endphp

@if ($target === null)
    {{ $slot }}
@elseif ($target['resolved'] && $target['route'])
    <a href="{{ route($target['route'][0], $target['route'][1] ?? []) }}"
       {{ $attributes->merge(['class' => 'text-(--color-brand-500) underline-offset-2 hover:underline']) }}
       title="{{ $target['label'] }}">
        {{ $slot->isEmpty() ? $target['document_no'] : $slot }}
    </a>
@else
    <span {{ $attributes->merge(['class' => 'text-(--color-ink-muted)']) }}
          title="{{ $target['label'] }}">
        {{ $slot->isEmpty() ? ($target['document_no'] ?? '—') : $slot }}
    </span>
@endif
