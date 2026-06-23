<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use JeffersonGoncalves\LaravelZero\SelfUpdate\PharUpdater;

function makeUpdater(?Client $client = null, string $currentVersion = 'unreleased'): PharUpdater
{
    return new PharUpdater(
        githubRepo: 'jeffersongoncalves/git-worktree-cli',
        assetName: 'git-worktree.phar',
        tempPrefix: 'git_worktree_',
        currentVersion: $currentVersion,
        client: $client,
    );
}

it('returns the current version passed to the constructor', function () {
    expect(makeUpdater(currentVersion: '1.4.2')->getCurrentVersion())->toBe('1.4.2');
});

it('considers an update available when current is unreleased', function () {
    expect(makeUpdater()->isUpdateAvailable('unreleased', '1.0.0'))->toBeTrue();
});

it('considers no update available when versions are equal', function () {
    expect(makeUpdater()->isUpdateAvailable('1.2.3', '1.2.3'))->toBeFalse();
});

it('considers an update available when current is lower', function () {
    expect(makeUpdater()->isUpdateAvailable('1.2.3', '1.3.0'))->toBeTrue();
});

it('considers no update available when current is higher', function () {
    expect(makeUpdater()->isUpdateAvailable('2.0.0', '1.9.9'))->toBeFalse();
});

it('strips the v prefix when comparing versions', function () {
    $updater = makeUpdater();

    expect($updater->isUpdateAvailable('v1.2.3', 'v1.2.3'))->toBeFalse()
        ->and($updater->isUpdateAvailable('v1.2.3', 'v1.4.0'))->toBeTrue();
});

it('treats a small file as an invalid PHAR', function () {
    $path = tempnam(sys_get_temp_dir(), 'phar_test_');
    file_put_contents($path, 'tiny');

    expect(makeUpdater()->isValidPhar($path))->toBeFalse();

    @unlink($path);
});

it('treats a file with a php header as a valid PHAR', function () {
    $path = tempnam(sys_get_temp_dir(), 'phar_test_');
    file_put_contents($path, '<?php '.str_repeat('A', 200));

    expect(makeUpdater()->isValidPhar($path))->toBeTrue();

    @unlink($path);
});

it('parses the latest release JSON from GitHub', function () {
    $body = json_encode([
        'tag_name' => 'v2.1.0',
        'assets' => [
            ['name' => 'other.phar', 'browser_download_url' => 'https://example.com/other.phar'],
            ['name' => 'git-worktree.phar', 'browser_download_url' => 'https://example.com/git-worktree.phar'],
        ],
    ]);

    $mock = new MockHandler([new Response(200, [], $body)]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);

    $release = makeUpdater($client)->getLatestRelease();

    expect($release)->toBe([
        'tag' => 'v2.1.0',
        'url' => 'https://example.com/git-worktree.phar',
    ]);
});

it('throws when the release has no matching asset', function () {
    $body = json_encode([
        'tag_name' => 'v2.1.0',
        'assets' => [
            ['name' => 'unrelated.phar', 'browser_download_url' => 'https://example.com/unrelated.phar'],
        ],
    ]);

    $mock = new MockHandler([new Response(200, [], $body)]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);

    makeUpdater($client)->getLatestRelease();
})->throws(RuntimeException::class, 'PHAR asset not found in the latest release.');

it('throws when the release data has no tag', function () {
    $mock = new MockHandler([new Response(200, [], json_encode(['assets' => []]))]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);

    makeUpdater($client)->getLatestRelease();
})->throws(RuntimeException::class, 'Invalid release data from GitHub.');
