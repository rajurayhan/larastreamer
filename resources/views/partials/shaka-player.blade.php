@once
    <script>
        window.__larastreamerShaka = window.__larastreamerShaka || new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = {{ \Illuminate\Support\Js::from($shakaSrc) }};
            script.onload = function () { resolve(window.shaka); };
            script.onerror = function () { reject(new Error('shaka_load_failed')); };
            document.head.appendChild(script);
        });
    </script>
@endonce

<script>
    (function (video, status, manifestUrl, drm) {
        var stage = 'script';
        var failed = false;

        var fail = function (error) {
            if (failed) {
                return;
            }

            failed = true;
            var shakaCode = error && typeof error.code === 'number' ? error.code : null;
            var code = stage === 'script' ? 'shaka_load_failed' : 'drm_playback_failed';

            status.hidden = false;
            video.dispatchEvent(new CustomEvent('larastreamer:drm-error', {
                detail: {code: code, stage: stage, shakaCode: shakaCode}
            }));
        };

        window.__larastreamerShaka.then(async function (shaka) {
            if (!shaka || !shaka.Player) {
                throw new Error('shaka_unavailable');
            }

            stage = 'support';
            shaka.polyfill.installAll();

            if (!shaka.Player.isBrowserSupported()) {
                throw new Error('drm_unsupported');
            }

            stage = 'attach';
            var player = new shaka.Player();
            await player.attach(video);

            var networking = player.getNetworkingEngine();

            networking.registerRequestFilter(function (type, request) {
                var requestType = shaka.net.NetworkingEngine.RequestType;
                var headers = {};

                if (type === requestType.LICENSE) {
                    headers = drm.license_headers;
                } else if (type === requestType.MANIFEST || type === requestType.SEGMENT) {
                    headers = drm.content_headers;
                }

                Object.keys(headers || {}).forEach(function (name) {
                    request.headers[name] = headers[name];
                });
            });

            var advanced = Object.assign({}, drm.advanced || {});

            if (drm.certificate_url) {
                stage = 'certificate';
                var certificateResponse = await fetch(drm.certificate_url, {
                    headers: drm.certificate_headers || {}
                });

                if (!certificateResponse.ok) {
                    throw new Error('certificate_failed');
                }

                var certificate = new Uint8Array(await certificateResponse.arrayBuffer());
                advanced['com.apple.fps'] = Object.assign({}, advanced['com.apple.fps'] || {}, {
                    serverCertificate: certificate
                });
            }

            stage = 'configure';
            player.configure({
                drm: {
                    servers: drm.servers,
                    advanced: advanced
                }
            });

            stage = 'load';
            await player.load(manifestUrl);
            player.addEventListener('error', function (event) {
                stage = 'runtime';
                fail(event.detail);
            });
            video.dispatchEvent(new CustomEvent('larastreamer:drm-ready'));
            stage = 'runtime';
        }).catch(fail);
    })(
        document.getElementById({{ \Illuminate\Support\Js::from($videoId) }}),
        document.getElementById({{ \Illuminate\Support\Js::from($fallbackId) }}),
        {{ \Illuminate\Support\Js::from($publicUrl) }},
        {{ \Illuminate\Support\Js::from($drmPayload) }}
    );
</script>
