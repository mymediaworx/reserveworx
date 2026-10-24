@props(['name' => '', 'logo' => null, 'size' => 'md'])
@php
    $words = preg_split('/\s+/', trim((string) $name)) ?: [];
    $initials = strtoupper(mb_substr($words[0] ?? '', 0, 1).mb_substr($words[1] ?? '', 0, 1));
@endphp
<span {{ $attributes->merge(['class' => 'avatar avatar-'.$size]) }}>
    @if ($logo)
        <img src="{{ $logo }}" alt="" loading="lazy">
    @else
        <span aria-hidden="true">{{ $initials !== '' ? $initials : 'RW' }}</span>
    @endif
</span>
