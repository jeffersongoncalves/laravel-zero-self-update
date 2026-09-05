<div class="filament-hidden">

![laravel-zero-self-update](https://raw.githubusercontent.com/jeffersongoncalves/laravel-zero-self-update/main/art/jeffersongoncalves-laravel-zero-self-update.png)

</div>

# laravel-zero-self-update

[![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20A%20Coffee-support-FFDD00?style=flat-square&logo=buy-me-a-coffee&logoColor=black)](https://buymeacoffee.com/jeffersongoncalves)

Reusable self-update service and base command for [Laravel Zero](https://laravel-zero.com) CLIs that are distributed as a single-file PHAR via GitHub Releases.

Most Laravel Zero PHAR tools end up shipping the exact same `self-update` logic: read the current version, hit the GitHub "latest release" API, compare versions, download the `.phar` asset, back up the running PHAR and atomically swap it in. This package extracts that logic into one well-tested service (`PharUpdater`) plus an abstract `self-update` command base, so each CLI only has to declare its repository, asset name and version.

## Installation

```bash
composer require jeffersongoncalves/laravel-zero-self-update
```

Requires PHP `^8.2`, `guzzlehttp/guzzle ^7.10` and `illuminate/console ^11|^12`.

## Usage

### Base command

In your Laravel Zero app, create a command that extends the base and implements the three abstract methods. Override `currentVersion()` to read your app version from config.

```php
<?php

namespace App\Commands;

use JeffersonGoncalves\LaravelZero\SelfUpdate\SelfUpdateCommand as BaseSelfUpdateCommand;

class SelfUpdateCommand extends BaseSelfUpdateCommand
{
    protected function githubRepo(): string
    {
        return 'jeffersongoncalves/git-worktree-cli';
    }

    protected function assetName(): string
    {
        return 'git-worktree.phar';
    }

    protected function tempPrefix(): string
    {
        return 'git_worktree_';
    }

    protected function currentVersion(): string
    {
        return config('app.version', 'unreleased');
    }
}
```

That gives you a `self-update` command:

```bash
my-cli self-update          # download and install the latest release
my-cli self-update --check  # only report whether an update is available
```

Self-update only runs when the app is executed as a PHAR; otherwise it tells the user to update via Git or Composer.

### Service directly

You can also use `PharUpdater` on its own:

```php
use JeffersonGoncalves\LaravelZero\SelfUpdate\PharUpdater;

$updater = new PharUpdater(
    githubRepo: 'jeffersongoncalves/git-worktree-cli',
    assetName: 'git-worktree.phar',
    tempPrefix: 'git_worktree_',
    currentVersion: config('app.version', 'unreleased'),
);

$release = $updater->getLatestRelease(); // ['tag' => 'v1.2.0', 'url' => '...']

if ($updater->isUpdateAvailable($updater->getCurrentVersion(), $release['tag'])) {
    $tempFile = $updater->download($release['url']);
    $updater->replacePhar($tempFile);
}
```

A custom Guzzle `Client` can be injected as the last constructor argument (handy for testing or proxies).

## Public classes

| Class | Description |
|-------|-------------|
| `JeffersonGoncalves\LaravelZero\SelfUpdate\PharUpdater` | Service that talks to GitHub Releases, compares versions, downloads and swaps the PHAR. |
| `JeffersonGoncalves\LaravelZero\SelfUpdate\SelfUpdateCommand` | Abstract `self-update {--check}` command wiring the service into a Laravel/Laravel Zero command. |

### `PharUpdater` methods

- `getCurrentVersion(): string`
- `isRunningAsPhar(): bool`
- `getLatestRelease(): array{tag: string, url: string}`
- `isUpdateAvailable(string $current, string $latest): bool`
- `download(string $url): string`
- `replacePhar(string $tempFile): void`
- `isValidPhar(string $path): bool`

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE](LICENSE).
