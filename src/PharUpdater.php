<?php

namespace JeffersonGoncalves\LaravelZero\SelfUpdate;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Phar;
use RuntimeException;

class PharUpdater
{
    private readonly Client $client;

    /**
     * @param  string  $githubRepo  GitHub repository in "owner/name" form (e.g. "jeffersongoncalves/git-worktree-cli").
     * @param  string  $assetName  Release asset file name to download (e.g. "git-worktree.phar").
     * @param  string  $tempPrefix  Prefix used for the temporary download file (e.g. "git_worktree_").
     * @param  string  $currentVersion  Current application version (e.g. "1.2.3" or "unreleased").
     * @param  Client|null  $client  Optional Guzzle client (injectable for testing).
     */
    public function __construct(
        private readonly string $githubRepo,
        private readonly string $assetName,
        private readonly string $tempPrefix,
        private readonly string $currentVersion = 'unreleased',
        ?Client $client = null,
    ) {
        $this->client = $client ?? new Client;
    }

    public function getCurrentVersion(): string
    {
        return $this->currentVersion;
    }

    public function isRunningAsPhar(): bool
    {
        return Phar::running(false) !== '';
    }

    /**
     * @return array{tag: string, url: string}
     *
     * @throws RuntimeException
     */
    public function getLatestRelease(): array
    {
        try {
            $response = $this->client->get(
                'https://api.github.com/repos/'.$this->githubRepo.'/releases/latest',
                ['headers' => ['Accept' => 'application/vnd.github+json']]
            );
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to fetch latest release from GitHub: '.$e->getMessage());
        }

        $data = json_decode($response->getBody()->getContents(), true);
        $tag = $data['tag_name'] ?? null;

        if (! $tag) {
            throw new RuntimeException('Invalid release data from GitHub.');
        }

        $downloadUrl = null;
        foreach ($data['assets'] ?? [] as $asset) {
            if (($asset['name'] ?? null) === $this->assetName) {
                $downloadUrl = $asset['browser_download_url'];
                break;
            }
        }

        if (! $downloadUrl) {
            throw new RuntimeException('PHAR asset not found in the latest release.');
        }

        return ['tag' => $tag, 'url' => $downloadUrl];
    }

    public function isUpdateAvailable(string $currentVersion, string $latestTag): bool
    {
        $current = ltrim($currentVersion, 'v');
        $latest = ltrim($latestTag, 'v');

        if ($current === 'unreleased') {
            return true;
        }

        return version_compare($current, $latest, '<');
    }

    /**
     * @throws RuntimeException
     */
    public function download(string $url): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), $this->tempPrefix);

        if ($tempFile === false) {
            throw new RuntimeException('Failed to create temporary file.');
        }

        try {
            $this->client->get($url, ['sink' => $tempFile]);
        } catch (GuzzleException $e) {
            @unlink($tempFile);

            throw new RuntimeException('Failed to download the PHAR file: '.$e->getMessage());
        }

        if (! $this->isValidPhar($tempFile)) {
            @unlink($tempFile);

            throw new RuntimeException('Downloaded file is not a valid PHAR.');
        }

        return $tempFile;
    }

    /**
     * @throws RuntimeException
     */
    public function replacePhar(string $tempFile): void
    {
        $pharPath = Phar::running(false);

        if ($pharPath === '') {
            @unlink($tempFile);

            throw new RuntimeException('Cannot determine current PHAR path.');
        }

        $backupPath = $pharPath.'.backup';

        // Create backup
        if (! @copy($pharPath, $backupPath)) {
            @unlink($tempFile);

            throw new RuntimeException('Failed to create backup of current PHAR.');
        }

        // Try rename first (atomic on same filesystem), fall back to copy (Windows cross-drive)
        $replaced = @rename($tempFile, $pharPath) || @copy($tempFile, $pharPath);

        if (! $replaced) {
            @rename($backupPath, $pharPath);
            @unlink($tempFile);

            throw new RuntimeException('Failed to replace PHAR file.');
        }

        @chmod($pharPath, 0755);
        @unlink($backupPath);
        @unlink($tempFile);
    }

    public function isValidPhar(string $path): bool
    {
        $fileSize = @filesize($path);
        if ($fileSize === false || $fileSize < 100) {
            return false;
        }

        $header = @file_get_contents($path, false, null, 0, 50);
        if ($header === false) {
            return false;
        }

        // PHAR stubs typically start with #!/usr/bin/env php or <?php
        return str_contains($header, '<?php') || str_contains($header, '#!/');
    }
}
