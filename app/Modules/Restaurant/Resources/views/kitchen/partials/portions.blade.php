{{-- সংখ্যাটা, আর শূন্য হলে সেটা চোখে পড়ার মতো।

     শূন্য মানে পদটা বন্ধ, আর ওটা তালিকার আর সব সংখ্যার মতো দেখালে
     এক নজরে ধরা পড়ত না — অথচ ওটাই একমাত্র সারি যেটা এখনই কিছু
     করতে বলে। --}}
<span data-dish="{{ $dish['recipe']->id }}"
      @class([
          'inline-flex min-w-10 justify-center rounded-(--radius-field) px-2 py-0.5 num font-semibold',
          'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)'
              => (int) $dish['portions'] === 0,
      ])>
    <span data-portions="{{ $dish['recipe']->id }}">{{ (int) $dish['portions'] }}</span>
</span>
