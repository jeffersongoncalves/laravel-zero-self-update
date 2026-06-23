<?php

namespace JeffersonGoncalves\LaravelZero\SelfUpdate;

use Illuminate\Console\Command;
use Phar;
use RuntimeException;

abstract class SelfUpdateCommand extends Command
{
    protected $signature = 'self-update
        {--check : Only check for updates without installing}';

    protected $description = 'Update the CLI to the latest version';

    /**
     * GitHub repository in "owner/name" form (e.g. "jeffersongoncalves/git-worktree-cli").
     */
    abstract protected function githubRepo(): string;

    /**
     * Release asset file name to download (e.g. "git-worktree.phar").
     */
    abstract protected function assetName(): string;

    /**
     * Prefix used for the temporary download file (e.g. "git_worktree_").
     */
    abstract protected function tempPrefix(): string;

    /**
     * Current application version. Override to read from config, e.g. config('app.version').
     */
    protected function currentVersion(): string
    {
        return 'unreleased';
    }

    protected function makeUpdater(): PharUpdater
    {
        return new PharUpdater(
            githubRepo: $this->githubRepo(),
            assetName: $this->assetName(),
            tempPrefix: $this->tempPrefix(),
            currentVersion: $this->currentVersion(),
        );
    }

    public function handle(): int
    {
        $updater = $this->makeUpdater();

        if (! $updater->isRunningAsPhar()) {
            $this->components->error('Self-update is only available when running as a PHAR. Use Git or Composer to update instead.');

            return self::FAILURE;
        }

        $currentVersion = $updater->getCurrentVersion();
        $this->components->info("Current version: <comment>{$currentVersion}</comment>");

        try {
            $release = $updater->getLatestRelease();
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $latestTag = $release['tag'];

        if (! $updater->isUpdateAvailable($currentVersion, $latestTag)) {
            $this->components->info('You are already using the latest version.');

            return self::SUCCESS;
        }

        $this->components->info("A new version is available: <comment>{$latestTag}</comment>");

        if ($this->option('check')) {
            return self::SUCCESS;
        }

        try {
            $tempFile = null;

            $this->components->task('Downloading update', function () use ($updater, $release, &$tempFile) {
                $tempFile = $updater->download($release['url']);
            });

            $this->components->task('Replacing PHAR', function () use ($updater, &$tempFile) {
                $updater->replacePhar($tempFile);
            });
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info("Successfully updated to <comment>{$latestTag}</comment>.");

        if (Phar::running(false) !== '') {
            exit(0);
        }

        return self::SUCCESS;
    }
}
