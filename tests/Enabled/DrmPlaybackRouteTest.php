<?php

declare(strict_types=1);

use Raju\Streamer\Drm\DrmConfiguration;
use Raju\Streamer\Drm\KeySystem;
use Raju\Streamer\Drm\PlaybackTicketManager;
use Raju\Streamer\Facades\Streamer;

it('serves a named-disk manifest and segment with one scoped ticket', function (): void {
    config([
        'larastreamer.route.enabled' => true,
        'larastreamer.dash.enabled' => true,
        'larastreamer.storage.disk' => 'local',
        'larastreamer.storage.path' => '',
    ]);

    $this->writeTextFixture('movie/manifest.mpd', <<<'MPD'
<?xml version="1.0"?><MPD><SegmentTemplate media="chunk-$Number$.m4s" initialization="init.m4s"/></MPD>
MPD);
    $this->writeFixture('movie/init.m4s', 256);
    $this->writeFixture('movie/chunk-1.m4s', 256);

    $configuration = new DrmConfiguration([
        KeySystem::ClearKey->value => 'http://localhost/license',
    ]);

    $data = Streamer::disk('videos')
        ->file('movie/manifest.mpd')
        ->drm($configuration)
        ->embedData(300);

    $manifest = $this->get($data['url'])->assertOk();
    $body = (string) $manifest->getContent();

    expect($body)->toContain('$Number$')->toContain('ticket=');

    preg_match('/initialization="([^"]+)"/', $body, $matches);
    $initializationUrl = html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_XML1);
    $this->get($initializationUrl)->assertOk();
});

it('rejects an out-of-scope file using a valid playback ticket', function (): void {
    $manager = app(PlaybackTicketManager::class);
    $token = $manager->issue('videos', 'movie/manifest.mpd', now()->addMinute(), null);

    $url = route('larastreamer.playback', [
        'file' => 'other/clip.mp4',
        'ticket' => $token,
    ]);

    $this->get($url)->assertNotFound();
});
