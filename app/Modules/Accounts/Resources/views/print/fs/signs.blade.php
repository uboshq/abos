{{--
    ⭐ আনুষ্ঠানিক বিবরণীর সই — কোম্পানি আইনের চার (ব্যবস্থাপনা পরিচালক, পরিচালক, কোম্পানি সচিব, প্রধান অর্থ কর্মকর্তা),
    সেটিংসে বন্ধ করলে রোজকার কাগজের দুই (প্রস্তুতকারী, অনুমোদনকারী); সইয়ের ঘরই বন্ধ থাকলে কিছুই নয় (মালিক, ১৭ নভেম্বর ২০২৬)।
--}}
@if ($board)
    <table width="100%" style="margin-top: 16mm;" data-signs="4"><tr>
        @foreach (['md', 'director', 'secretary', 'cfo'] as $i => $who)
            @if ($i > 0)<td width="4%"></td>@endif
            <td width="22%" class="sign">{{ __('accounts::fs.sign.'.$who) }}</td>
        @endforeach
    </tr></table>
@elseif ($signatures)
    <table width="100%" style="margin-top: 16mm;" data-signs="2"><tr>
        <td width="35%" class="sign">{{ __('core.listing.sign_prepared') }}</td>
        <td width="30%"></td>
        <td width="35%" class="sign">{{ __('core.listing.sign_approved') }}</td>
    </tr></table>
@endif
