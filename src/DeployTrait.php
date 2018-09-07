<?php

namespace Globalis\WP\Cubi\Robo;

trait DeployTrait
{
    public function deploy($environment, $gitRevision, $options = ['ignore-assets' => false])
    {
        $this->io()->title('Deploy version ' . $gitRevision . ' to ' . $environment);
        $this->io()->text('You must answer a few questions about the remote environment:');

        $this->configure($environment, false);

        $config         = $this->getConfig($environment);
        $collection     = $this->collectionBuilder();
        $buildDirectory = $collection->tmpDir();

        $this->gitExtractArchive($gitRevision, $buildDirectory);

        $this->build($environment, $buildDirectory, $options['ignore-assets']);

        $this->deployWriteState(self::trailingslashit($buildDirectory) . 'deploy', $gitRevision);

        // 1. Dry Run
        $this->rsyncDeploy($buildDirectory, $config['REMOTE_HOSTNAME'], $config['REMOTE_USERNAME'], $config['REMOTE_PATH'], $config['REMOTE_PORT'], $options['ignore-assets'], true);

        if ($this->io()->confirm('Do you want to run ?', false)) {
            // 2. Run
            $this->rsyncDeploy($buildDirectory, $config['REMOTE_HOSTNAME'], $config['REMOTE_USERNAME'], $config['REMOTE_PATH'], $config['REMOTE_PORT'], $options['ignore-assets'], false);
        }

        $this->taskDeleteDir($buildDirectory)->run();
    }

    protected function rsyncDeploy($fromPath, $toHost, $toUser, $toPath, $remotePort, $ignoreAssets, $dryRun)
    {
        $chmod       = 'Du=rwx,Dgo=rx,Fu=rw,Fgo=r';
        $excludeFrom = self::trailingslashit($fromPath) . '.rsyncignore';
        $delete      = true;
        $this->rsync(null, null, $fromPath, $toHost, $toUser, $toPath, $remotePort, $delete, $chmod, $excludeFrom, $ignoreAssets, $dryRun);
    }

    protected function deployWriteState($directory, $gitRevision)
    {
        if ($gitCommit = $this->gitCommit($gitRevision)) {
            $this->taskWriteToFile(self::trailingslashit($directory) . 'git_commit')
                 ->line($gitCommit)
                 ->run();
        }

        if ($gitTag = $this->gitTag($gitRevision)) {
            $this->taskWriteToFile(self::trailingslashit($directory) . 'git_tag')
                 ->line($gitTag)
                 ->run();
        }

        $this->taskWriteToFile(self::trailingslashit($directory) . 'time')
             ->line(date('Y-m-d H:i:s'))
             ->run();
    }

    public function deploySetup($environment)
    {
        $this->io()->title('Setup remote environment: ' . $environment);
        $this->io()->text('You must answer a few questions about the remote environment:');

        $this->configure($environment, false);

        $config         = $this->getConfig($environment);
        $collection     = $this->collectionBuilder();
        $buildDirectory = $collection->tmpDir();

        $this->taskFilesystemStack()
             ->mkdir(self::trailingslashit($buildDirectory) . \RoboFile::PATH_DIRECTORY_CONFIG)
             ->mkdir(self::trailingslashit($buildDirectory) . \RoboFile::PATH_DIRECTORY_WEB)
             ->mkdir(self::trailingslashit($buildDirectory) . \RoboFile::PATH_DIRECTORY_MEDIA)
             ->mkdir(self::trailingslashit($buildDirectory) . \RoboFile::PATH_DIRECTORY_LOG)
             ->run();

        $this->buildConfig($environment, $buildDirectory);
        $this->wpGenerateSaltKeys($buildDirectory);

        $delete       = true;
        $chmod        = 'Du=rwx,Dgo=rx,Fu=rw,Fgo=r';
        $excludeFrom  = false;
        $ignoreAssets = false;

        // 1. Dry Run
        $this->rsync(null, null, $buildDirectory, $config['REMOTE_HOSTNAME'], $config['REMOTE_USERNAME'], $config['REMOTE_PATH'], $config['REMOTE_PORT'], $delete, $chmod, $excludeFrom, $ignoreAssets, true);

        $created = false;

        if ($this->io()->confirm('Do you want to run ?', false)) {
            // 2. Run
            $this->rsync(null, null, $buildDirectory, $config['REMOTE_HOSTNAME'], $config['REMOTE_USERNAME'], $config['REMOTE_PATH'], $config['REMOTE_PORT'], $delete, $chmod, $excludeFrom, $ignoreAssets, false);
            $created = true;
        }

        $this->taskDeleteDir($buildDirectory)->run();

        if ($created) {
            $this->io()->newLine();
            $this->io()->success('Remote environment was created.');
            $this->io()->note('To complete environment installation, following steps are required:');
            $this->io()->text('1. Upload your WordPress database.');
            $this->io()->text('2. Upload your media directory (see command media:push).');
            $this->io()->text('3. Ensure WordPress can write in ' . \RoboFile::PATH_DIRECTORY_MEDIA . ' and ' . \RoboFile::PATH_DIRECTORY_LOG . '.');
            $this->io()->text('4. Deploy application (see command deploy).');
        }
    }

    public function mediaDump($environment, $options = ['delete' => false])
    {
        $this->mediaSync($environment, 'dump', $options['delete']);
    }

    public function mediaPush($environment, $options = ['delete' => false])
    {
        $this->mediaSync($environment, 'push', $options['delete']);
    }

    protected function mediaSync($environment, $action, $delete)
    {
        $config     = $this->getConfig($environment);
        $localPath  = self::trailingslashit(\RoboFile::ROOT) . \RoboFile::PATH_DIRECTORY_MEDIA;
        $remotePath = self::trailingslashit($config['REMOTE_PATH']) . \RoboFile::PATH_DIRECTORY_MEDIA;

        if (!is_dir($localPath)) {
            mkdir($localPath, 0777);
        }

        switch ($action) {
            case 'dump':
                $fromHost = $config['REMOTE_HOSTNAME'];
                $fromUser = $config['REMOTE_USERNAME'];
                $fromPath = $remotePath;
                $toHost   = null;
                $toUser   = null;
                $toPath   = $localPath;
                break;
            case 'push':
                $fromHost = null;
                $fromUser = null;
                $fromPath = $localPath;
                $toHost   = $config['REMOTE_HOSTNAME'];
                $toUser   = $config['REMOTE_USERNAME'];
                $toPath   = $remotePath;
                break;
            default:
                return;
        }

        // 1. Dry Run
        $this->rsyncMedia($fromHost, $fromUser, $fromPath, $toHost, $toUser, $toPath, $config['REMOTE_PORT'], true, $delete);

        if ($this->io()->confirm('Do you want to run ?', false)) {
            // 2. Run
            $this->rsyncMedia($fromHost, $fromUser, $fromPath, $toHost, $toUser, $toPath, $config['REMOTE_PORT'], false, $delete);
        }
    }

    protected function rsyncMedia($fromHost, $fromUser, $fromPath, $toHost, $toUser, $toPath, $remotePort, $dryRun, $delete)
    {
        $chmod        = false;
        $excludeFrom  = false;
        $ignoreAssets = false;
        $this->rsync($fromHost, $fromUser, $fromPath, $toHost, $toUser, $toPath, $remotePort, $delete, $chmod, $excludeFrom, $ignoreAssets, $dryRun);
    }

    protected function rsync($fromHost, $fromUser, $fromPath, $toHost, $toUser, $toPath, $remotePort, $delete, $chmod, $excludeFrom, $ignoreAssets, $dryRun)
    {
        $cmd = $this->taskRsync()
            ->fromHost($fromHost)
            ->fromUser($fromUser)
            ->fromPath(self::trailingslashit($fromPath))
            ->toHost($toHost)
            ->toUser($toUser)
            ->toPath(self::trailingslashit($toPath))
            ->option('rsh', 'ssh -p ' . $remotePort)
            ->verbose()
            ->recursive()
            ->checksum()
            ->compress()
            ->itemizeChanges()
            ->excludeVcs()
            ->progress()
            ->option('copy-links')
            ->stats();

        if ($ignoreAssets) {
            foreach (\RoboFile::PATH_FILES_BUILD_ASSETS as $assetPath) {
                $cmd->exclude($assetPath);
            }
        }

        if (false !== $excludeFrom && file_exists($excludeFrom)) {
            $cmd->excludeFrom($excludeFrom);
        } else {
            $cmd->exclude('.gitkeep');
        }

        if (true === $delete) {
            $cmd->delete();
        }

        if (false !== $chmod) {
            $cmd->option('perms');
            $cmd->option('chmod', $chmod);
        }

        if (true === $dryRun) {
            $cmd->dryRun();
        }

        $cmd->run();
    }
}
