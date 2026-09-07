@props([
    'src' => null,
    'url' => null,
    'poster' => null,
    'autoplay' => false,
    'controls' => true,
    'mime' => null,
])

@php
    $embed = is_string($url) && $url !== ''
        ? ['url' => $url, 'mime' => $mime]
        : (is_string($src) && $src !== ''
            ? \Raju\Streamer\Facades\Streamer::file($src)->embedData()
            : ['url' => null, 'mime' => $mime]);
@endphp

<video
    {{ $attributes->merge([
        'controls' => $controls,
        'autoplay' => $autoplay,
        'poster' => $poster,
    ]) }}
>
    @if (! empty($embed['url']))
        <source src="{{ $embed['url'] }}" @if (! empty($embed['mime'])) type="{{ $embed['mime'] }}" @endif>
    @endif
</video>
