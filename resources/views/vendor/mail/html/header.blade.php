@props(['url'])
{{-- Lamma header: the app icon (a PNG, because mail clients block SVG) and the wordmark in the mail's language. The slot is the fallback name. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('brand/lamma-app-icon.png') }}" class="logo" width="56" height="56" alt="">
<span class="wordmark">{{ app()->getLocale() === 'ar' ? 'لمّة' : (trim($slot) ?: 'Lamma') }}</span>
</a>
</td>
</tr>
