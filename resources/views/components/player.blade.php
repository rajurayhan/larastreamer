@props([
    'src' => null,
    'url' => null,
    'poster' => null,
    'autoplay' => false,
    'controls' => true,
    'mime' => null,
    'captions' => [],
])

@php
    $embed = ['url' => null, 'mime' => $mime, 'kind' => 'progressive', 'captions' => []];

    if (is_string($url) && $url !== '') {
        $embed['url'] = $url;
        $embed['mime'] = $mime;
        $embed['kind'] = is_string($src) && str_ends_with(strtolower($src), '.m3u8')
            ? 'hls'
            : (is_string($src) && str_ends_with(strtolower($src), '.mpd') ? 'dash' : 'progressive');

        if (is_string($url) && str_contains(strtolower($url), '.m3u8')) {
            $embed['kind'] = 'hls';
        }
    } elseif (is_string($src) && $src !== '') {
        try {
            $embed = \Raju\Streamer\Facades\Streamer::file($src)->embedData();
        } catch (\Raju\Streamer\Exceptions\StreamException) {
            $embed = ['url' => null, 'mime' => $mime, 'kind' => 'progressive', 'captions' => []];
        }
    }

    $publicUrl = is_string($embed['url'] ?? null) ? $embed['url'] : null;

    if (is_string($publicUrl) && $publicUrl !== '' && ! str_contains($publicUrl, '://') && ! str_starts_with($publicUrl, '/')) {
        $publicUrl = null;
    }

    $isEmpty = $publicUrl === null || $publicUrl === '';
    $kind = is_string($embed['kind'] ?? null) ? $embed['kind'] : 'progressive';
    $useHlsJs = ! $isEmpty && $kind === 'hls' && config('larastreamer.hls.player') === 'hlsjs';
    $hlsjsSrc = (string) config('larastreamer.hls.hlsjs_src');
    $videoId = 'larastreamer-player-'.bin2hex(random_bytes(4));
    $tracks = is_array($captions) && $captions !== [] ? $captions : ($embed['captions'] ?? []);
@endphp

<video
    id="{{ $videoId }}"
    @if ($isEmpty) data-empty="true" @endif
    @if ($useHlsJs) data-hls="true" data-src="{{ $publicUrl }}" @endif
    {{ $attributes->merge([
        'controls' => $controls,
        'autoplay' => $autoplay,
        'poster' => $poster,
    ]) }}
>
    @if (! $isEmpty)
        <source src="{{ $publicUrl }}" @if (! empty($embed['mime'])) type="{{ $embed['mime'] }}" @endif>
    @endif

    @foreach ($tracks as $track)
        @php
            $trackSrc = is_array($track) ? ($track['src'] ?? '') : '';
            if (is_string($trackSrc) && $trackSrc !== '' && ! str_contains($trackSrc, '://') && ! str_starts_with($trackSrc, '/')) {
                try {
                    $trackSrc = \Raju\Streamer\Facades\Streamer::file($trackSrc)->embedData()['url'];
                } catch (\Raju\Streamer\Exceptions\StreamException) {
                    $trackSrc = '';
                }
            }
        @endphp
        @if (is_string($trackSrc) && $trackSrc !== '' && (str_contains($trackSrc, '://') || str_starts_with($trackSrc, '/')))
            <track
                kind="subtitles"
                src="{{ $trackSrc }}"
                @if (! empty($track['srclang'])) srclang="{{ $track['srclang'] }}" @endif
                @if (! empty($track['label'])) label="{{ $track['label'] }}" @endif
                @if (! empty($track['default'])) default @endif
            >
        @endif
    @endforeach
</video>

@if ($useHlsJs && $hlsjsSrc !== '')
    <script>
        (function (video, src, cdn) {
            var boot = function () {
                if (window.Hls && Hls.isSupported()) {
                    var hls = new Hls();
                    hls.loadSource(src);
                    hls.attachMedia(video);
                    return;
                }
                if (video.canPlayType('application/vnd.apple.mpegurl')) {
                    video.src = src;
                }
            };
            var script = document.createElement('script');
            script.src = cdn;
            script.onload = boot;
            document.head.appendChild(script);
        })(document.getElementById(@json($videoId)), @json($publicUrl, JSON_UNESCAPED_SLASHES), @json($hlsjsSrc, JSON_UNESCAPED_SLASHES));
    </script>
@endif
