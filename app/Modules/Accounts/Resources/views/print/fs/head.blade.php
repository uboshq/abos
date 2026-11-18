{{-- ⭐ লেজারের প্যাড — কোম্পানি বাঁয়ে, বিবরণীর নাম ডানে (বাংলা কাগজে নিচে ইংরেজি নাম) --}}
@php $logo = $company->logoData(); @endphp
<table class="pad" width="100%"><tr>
    @if ($logo)
        <td width="17mm"><img src="{{ $logo }}" style="height: 14mm;" alt=""></td>
    @endif
    <td>
        <div class="co-name">{{ $company->name() }}</div>
        @if ($company->address())<div class="co-meta">{{ $company->address() }}@if ($company->phone) · {{ __('core.listing.phone') }} {{ $company->phone }}@endif @if ($company->bin) · BIN {{ $company->bin }}@endif</div>@endif
    </td>
    <td width="85mm" style="text-align: right;">
        <div class="title">{{ $title }}</div>
        @if ($locale === 'bn')<div class="title-en">{{ $titleEn }}</div>@endif
    </td>
</tr></table>
<div class="rule"></div>
