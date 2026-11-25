{{-- ⭐ চালু/বন্ধের সুইচ — চেকবক্সটাই (role="switch"), পাঠায় আগের মতো; কন্ট্রোল প্যানেলের কার্ডের একই গড়ন (মালিক, ২৪ নভেম্বর ২০২৬) --}}
<label class="relative inline-flex shrink-0 items-center {{ ($disabled ?? false) ? 'cursor-not-allowed opacity-50' : 'cursor-pointer' }}">
    <input type="checkbox" role="switch" name="{{ $name }}" value="{{ $value ?? '1' }}" @if ($form ?? null) form="{{ $form }}" @endif
           aria-label="{{ $label }}" class="peer sr-only" @if ($dataKey ?? null) data-key="{{ $dataKey }}" data-was="{{ ($checked ?? false) ? '1' : '' }}" @endif @checked($checked ?? false) @disabled($disabled ?? false)>
    <span aria-hidden="true"
          class="h-6 w-11 rounded-full bg-(--color-border-strong) transition-colors peer-checked:bg-(--color-brand-600)
                 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-(--color-brand-500)"></span>
    <span aria-hidden="true"
          class="absolute start-0.5 size-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5 rtl:peer-checked:-translate-x-5"></span>
</label>
