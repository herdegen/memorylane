@if (! empty($thumbs))
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;"><tr>
@foreach ($thumbs as $src)
    <td style="padding:0 6px 0 0;"><img src="{{ $src }}" width="96" height="96" alt="" style="display:block; width:96px; height:96px; object-fit:cover; border-radius:10px;"></td>
@endforeach
</tr></table>
@endif
