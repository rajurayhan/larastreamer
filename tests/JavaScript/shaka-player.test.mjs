import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const template = readFileSync(
    new URL('../../resources/views/partials/shaka-player.blade.php', import.meta.url),
    'utf8',
);

const plain = (value) => JSON.parse(JSON.stringify(value));

function renderedPlayerScript(drm) {
    const match = template.match(/<script>\s*(\(function \(video[\s\S]*?)\s*<\/script>/);

    assert.ok(match, 'The Shaka player initialization script was not found.');

    return match[1]
        .replace('{{ \\Illuminate\\Support\\Js::from($videoId) }}', JSON.stringify('video'))
        .replace('{{ \\Illuminate\\Support\\Js::from($fallbackId) }}', JSON.stringify('status'))
        .replace('{{ \\Illuminate\\Support\\Js::from($publicUrl) }}', JSON.stringify('https://media.example.test/manifest.mpd'))
        .replace('{{ \\Illuminate\\Support\\Js::from($drmPayload) }}', JSON.stringify(drm));
}

async function executePlayer(drm) {
    const state = {
        configured: null,
        events: [],
        fetches: [],
        filter: null,
        listener: null,
        loaded: null,
    };
    const video = {
        dispatchEvent(event) {
            state.events.push(event);
        },
    };
    const status = { hidden: true };

    class Player {
        static isBrowserSupported() {
            return true;
        }

        async attach(element) {
            assert.equal(element, video);
        }

        getNetworkingEngine() {
            return {
                registerRequestFilter(filter) {
                    state.filter = filter;
                },
            };
        }

        configure(configuration) {
            state.configured = configuration;
        }

        async load(manifest) {
            state.loaded = manifest;
        }

        addEventListener(name, listener) {
            assert.equal(name, 'error');
            state.listener = listener;
        }
    }

    const shaka = {
        Player,
        net: {
            NetworkingEngine: {
                RequestType: {
                    LICENSE: 1,
                    MANIFEST: 2,
                    SEGMENT: 3,
                },
            },
        },
        polyfill: { installAll() {} },
    };
    const context = {
        CustomEvent: class CustomEvent {
            constructor(type, options = {}) {
                this.type = type;
                this.detail = options.detail;
            }
        },
        Uint8Array,
        document: {
            getElementById(id) {
                return id === 'video' ? video : status;
            },
        },
        fetch: async (url, options) => {
            state.fetches.push({ options, url });

            return {
                ok: true,
                async arrayBuffer() {
                    return Uint8Array.from([1, 2, 3]).buffer;
                },
            };
        },
        window: {
            __larastreamerShaka: Promise.resolve(shaka),
        },
    };

    vm.runInNewContext(renderedPlayerScript(drm), context);

    await new Promise((resolve) => setImmediate(resolve));

    return { state, status };
}

test('isolates request headers, configures FairPlay, and emits lifecycle events', async () => {
    const drm = {
        advanced: {
            'com.widevine.alpha': { videoRobustness: ['SW_SECURE_CRYPTO'] },
        },
        certificate_headers: { 'X-Certificate': 'certificate-token' },
        certificate_url: 'https://license.example.test/fairplay.cer',
        content_headers: { 'X-Content': 'content-token' },
        license_headers: { 'X-License': 'license-token' },
        servers: { 'com.apple.fps': 'https://license.example.test/fairplay' },
    };
    const { state, status } = await executePlayer(drm);

    assert.equal(state.loaded, 'https://media.example.test/manifest.mpd');
    assert.equal(state.fetches.length, 1);
    assert.equal(state.fetches[0].url, drm.certificate_url);
    assert.deepEqual(plain(state.fetches[0].options.headers), drm.certificate_headers);
    assert.deepEqual(
        Array.from(state.configured.drm.advanced['com.apple.fps'].serverCertificate),
        [1, 2, 3],
    );
    assert.deepEqual(
        Array.from(state.configured.drm.advanced['com.widevine.alpha'].videoRobustness),
        ['SW_SECURE_CRYPTO'],
    );

    const licenseRequest = { headers: {} };
    const manifestRequest = { headers: {} };
    const segmentRequest = { headers: {} };
    const unrelatedRequest = { headers: {} };
    state.filter(1, licenseRequest);
    state.filter(2, manifestRequest);
    state.filter(3, segmentRequest);
    state.filter(99, unrelatedRequest);

    assert.deepEqual(plain(licenseRequest.headers), drm.license_headers);
    assert.deepEqual(plain(manifestRequest.headers), drm.content_headers);
    assert.deepEqual(plain(segmentRequest.headers), drm.content_headers);
    assert.deepEqual(plain(unrelatedRequest.headers), {});
    assert.equal(state.events[0].type, 'larastreamer:drm-ready');

    state.listener({ detail: { code: 7001 } });

    assert.equal(status.hidden, false);
    assert.equal(state.events[1].type, 'larastreamer:drm-error');
    assert.deepEqual(plain(state.events[1].detail), {
        code: 'drm_playback_failed',
        shakaCode: 7001,
        stage: 'runtime',
    });
});
