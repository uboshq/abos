{{-- ⓘ চেকবক্সটাই সুইচ — দেখতে সুইচ (role="switch"), পাঠায় সাধারণ ফর্মে (কন্ট্রোল প্যানেলের মডিউল-সুইচের একই ছাঁচ) --}}
<label class="relative inline-flex shrink-0 cursor-pointer items-center">
    <input type="checkbox" role="switch" name="{{ $name }}" value="1" @checked($on)
           aria-label="{{ $label }}" data-road-switch="{{ $name }}" class="peer sr-only">
    <span aria-hidden="true"
          class="h-6 w-11 rounded-full bg-(--color-border-strong) transition-colors peer-checked:bg-(--color-brand-600)
                 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-(--color-brand-500)"></span>
    <span aria-hidden="true"
          class="absolute start-0.5 size-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5 rtl:peer-checked:-translate-x-5"></span>
</label>
