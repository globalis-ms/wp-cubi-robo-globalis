<?php

namespace Globalis\WP\Cubi\Robo;

use Globalis\Robo\Core\Command;
use Globalis\Robo\Core\SemanticVersion;

trait GitTrait
{
    /**
     * Start a new feature
     *
     * @param  string $name The feature name
     */
    public function featureStart($name)
    {
        return $this->taskFeatureStart($name)->run();
    }

    /**
     * Finish a feature
     *
     * @param  string $name The feature name
     */
    public function featureFinish($name)
    {
        return $this->taskFeatureFinish($name)->run();
    }

    /**
     * Start a new hotfix
     *
     * @option string $semversion Version number
     * @option string $type    Hotfix type (path, minor)
     */
    public function hotfixStart($options = ['semversion' => null, 'type' => 'patch'])
    {
        if (empty($options['semversion'])) {
            $version = $this->getVersion()
                ->increment($options['type']);
        } else {
            $version = $options['semversion'];
        }
        return $this->taskHotfixStart((string)$version)->run();
    }

    /**
     * Finish a hotfix
     *
     * @option string $semversion Version number
     * @option string $type    Hotfix type (path, minor)
     */
    public function hotfixFinish($options = ['semversion' => null, 'type' => 'patch'])
    {
        if (empty($options['semversion'])) {
            $version = $this->getVersion()
                ->increment($options['type']);
        } else {
            $version = $options['semversion'];
        }
        return $this->taskHotfixFinish((string)$version)->run();
    }

    /**
     * Start a new release
     *
     * @option string $semversion Version number
     * @option string $type    Relase type (minor, major)
     */
    public function releaseStart($options = ['semversion' => null, 'type' => 'minor'])
    {
        if (empty($options['semversion'])) {
            $version = $this->getVersion()
                ->increment($options['type']);
        } else {
            $version = $options['semversion'];
        }
        return $this->taskReleaseStart((string)$version)->run();
    }

    /**
     * Finish a release
     *
     * @option string $semversion Version number
     * @option string $type    Relase type (minor, major)
     */
    public function releaseFinish($options = ['semversion' => null, 'type' => 'minor'])
    {
        if (empty($options['semversion'])) {
            $version = $this->getVersion()
                ->increment($options['type']);
        } else {
            $version = $options['semversion'];
        }
        return $this->taskReleaseFinish((string)$version)->run();
    }

    protected function gitInit()
    {
        $this->io()->section('GIT REPOSITORY');

        if ($this->io()->confirm(sprintf('Initialize a git repository in %s ?', \RoboFile::ROOT), true)) {
            $this->taskGitStack()
             ->dir(\RoboFile::ROOT)
             ->stopOnFail()
             ->exec('init')
             ->run();

             $this->io()->newLine();

            $commitMessage = $this->io()->ask('Initial commit message', 'Initial commit');

            $this->taskGitStack()
             ->dir(\RoboFile::ROOT)
             ->stopOnFail()
             ->add('-A')
             ->commit($commitMessage)
             ->run();
        }

        $this->io()->newLine();
    }

    protected function gitCommit($gitRevision)
    {
        $cmd = new Command('git');
        $cmd = $cmd->arg('rev-parse')
            ->option('--short')
            ->arg($gitRevision);

        $process = $cmd->executeWithoutException();
        return rtrim($process->getOutput());
    }

    protected function gitTag($gitRevision)
    {
        switch ($this->gitRevisionType($gitRevision)) {
            case 'tag':
                return $gitRevision;

            case 'branch':
                if (false !== strpos($gitRevision, 'release_')) {
                    return str_replace('release_', '', $gitRevision);
                } elseif (false !== strpos($gitRevision, 'hotfix_')) {
                    return str_replace('hotfix_', '', $gitRevision);
                }
                return false;

            default:
                return false;
        }
    }

    protected function gitRevisionType($gitRevision)
    {
        $types = [
            'refs/heads/' => 'branch',
            'refs/tags/'  => 'tag'
        ];

        foreach ($types as $ref => $type) {
            $cmd = new Command('git');
            $cmd = $cmd->arg('show-ref')
                ->option('--verify')
                ->option('--quiet')
                ->arg($ref . $gitRevision);

            $process = $cmd->executeWithoutException();

            if ($process->isSuccessful()) {
                return $type;
            }
        }

        return 'commit';
    }

    /**
     * Return current version
     *
     * @return SemanticVersion
     */
    protected function getVersion()
    {
        // Get version from tag
        $cmd = new Command('git');
        $cmd = $cmd->arg('tag')
            ->execute();
        $output = explode(PHP_EOL, trim($cmd->getOutput()));
        $currentVersion = '0.0.0';
        foreach ($output as $tag) {
            if (preg_match(SemanticVersion::REGEX, $tag)) {
                if (version_compare($currentVersion, $tag, '<')) {
                    $currentVersion = $tag;
                }
            }
        }
        return new SemanticVersion($currentVersion);
    }

    protected function gitExtractArchive($gitRevision, $directory)
    {
        $cmd = new Command('git');
        $cmd = $cmd->arg('archive')
            ->option('--format=tar')
            ->option('--prefix=' . basename($directory) . '/')
            ->arg($gitRevision)
            ->pipe('(cd')
            ->arg(dirname($directory))
            ->getCommand();

        $cmd .= ' && tar xf -)';

        $this->taskExec($cmd)
            ->run();
    }
}
