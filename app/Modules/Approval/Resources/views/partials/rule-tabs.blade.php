{{--
    ⭐ অনুমোদনের নিয়মের তিন ট্যাব — নিয়ম · কর্তৃত্বের সীমা · নিয়মের বাইরে (অনুমোদন পর্দা ভাগ ২, দফা ২: মেনু ১৩টা থেকে ৫টা;
    সীমা আর নিয়মের বাইরের কাগজ মেনু থেকে সরে এখানে)। ⓘ তিনটাই একই চাবির (`approval.flow.manage`), তাই আলাদা করে ছাঁকার কিছু নেই।
--}}
<x-ui.list-tabs :label="__('approval::menu.flows')" :tabs="[
    ['key' => 'flows', 'label' => __('approval::menu.flows'), 'url' => route('approval.flow.index'), 'active' => request()->routeIs('approval.flow.*')],
    ['key' => 'limits', 'label' => __('approval::menu.limits'), 'url' => route('approval.limit.index'), 'active' => request()->routeIs('approval.limit.*')],
    ['key' => 'exceptions', 'label' => __('approval::exception.title'), 'url' => route('approval.exception.index'), 'active' => request()->routeIs('approval.exception.*')],
]" />
