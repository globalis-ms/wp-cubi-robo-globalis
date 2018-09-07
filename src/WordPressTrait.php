<?php

namespace Globalis\WP\Cubi\Robo;

use Globalis\Robo\Core\Command;

trait WordPressTrait
{

    private $saltKeysUrl     = 'https://api.wordpress.org/secret-key/1.1/salt/';
    private $wp_default_lang = 'en_US';

    protected static function wpCli()
    {
        return new Command(\RoboFile::PATH_FILE_WP_CLI_EXECUTABLE);
    }

    protected function wpInit()
    {
        $this->io()->section('WORDPRESS INSTALLATION');
        $this->wpGenerateSaltKeys();
        $this->io()->newLine();
        $this->wpInitConfigFile();
        $this->io()->newLine();
        $this->wpDbCreate();
        $this->wpCoreInstall();
        $this->wpLanguageInstall(null, ['activate' => true]);
        $this->wpUpdateTimezone();
        $this->wpClean();
        $this->wpActivatePlugins();

        $this->io()->success('WordPress is ready.');
    }

    protected function wpUrl()
    {
        $scheme = $this->getConfig('development', 'WEB_SCHEME');
        $domain = $this->getConfig('development', 'WEB_DOMAIN');
        $path   = $this->getConfig('development', 'WEB_PATH');
        return $scheme . '://' . $domain . $path . '/wp';
    }

    protected function wpGenerateSaltKeys($root = \RoboFile::ROOT)
    {
        $target = self::trailingslashit($root) . \RoboFile::PATH_FILE_CONFIG_SALT_KEYS;

        if (file_exists($target)) {
            if ($this->io()->confirm(sprintf('%s already exists. Do you want to override it ?', $target), false)) {
                $this->taskFilesystemStack()
                 ->remove($target)
                 ->run();
            } else {
                return;
            }
        }

        $response = \Requests::request($this->saltKeysUrl, [], [], 'GET', ['timeout' => 10]);

        if (200 === $response->status_code) {
            $salt_keys = $response->body;
        } else {
            throw new Exception(sprintf('Couldn\'t fetch response from %s (HTTP code %s)', $this->saltKeysUrl, $response->status_code));
        }

        $this->taskWriteToFile($target)
             ->line('<?php')
             ->line('')
             ->line('// WORDPRESS SALT KEYS generated from: ' . $this->saltKeysUrl)
             ->line($salt_keys)
             ->run();
    }

    protected function wpInitConfigFile($startPlaceholder = '<##', $endPlaceholder = '##>')
    {
        $settings                     = [];
        $settings['DB_PREFIX']        = $this->io()->ask('Database prefix', 'cubi_');
        $settings['WP_DEFAULT_THEME'] = $this->io()->ask('Default theme slug (you can change it later in ./config/application.php)', 'my-theme');

        $this->taskReplacePlaceholders(self::trailingslashit(\RoboFile::ROOT) . \RoboFile::PATH_FILE_CONFIG_APPLICATION)
         ->from(array_keys($settings))
         ->to($settings)
         ->startDelimiter($startPlaceholder)
         ->endDelimiter($endPlaceholder)
         ->run();
    }

    protected function wpDbCreate()
    {
        $db_name = $this->getConfig('development', 'DB_NAME');

        if ($this->mysqlAvailable()) {
            self::wpCli()
                ->arg('db')
                ->arg('create')
                ->execute();
            $this->io()->success(sprintf('Database `%s` was successfully created.', $db_name));
        } else {
            $this->io()->confirm(sprintf('Could not find `mysql` binary. Please create database `%s` manually then press ENTER', $db_name));
        }
    }

    protected function mysqlAvailable()
    {
        // Check that mysql binary path used by https://github.com/wp-cli/db-command/blob/master/src/DB_Command.php is valid
        $cmd = new Command('/usr/bin/env mysql');
        return $cmd->option('--version')
        ->executeWithoutException()
        ->isSuccessful();
    }

    protected function wpCoreInstall()
    {
        $title    = $this->io()->ask('Site title');
        $username = $this->io()->ask('Admin username');
        $password = $this->io()->askHidden('Admin password');
        $email    = $this->io()->ask('Admin email', $this->getConfig('development', 'DEV_MAIL'));

        self::wpCli()
            ->arg('core')
            ->arg('install')
            ->option('title', $title, '=')
            ->option('admin_user', $username, '=')
            ->option('admin_password', $password, '=')
            ->option('admin_email', $email, '=')
            ->option('url', $this->wpUrl(), '=')
            ->option('skip-email')
            ->execute();

        $this->io()->success('WordPress core was successfully installed.');
    }

    public function wpLanguageInstall($language = null, $options = ['activate' => false])
    {
        if (!isset($language)) {
            $language = $this->io()->ask('WordPress language', $this->wp_default_lang);
        }
        $this->wpLanguageUpdate($language, ['activate' => $options['activate']]);
    }

    public function wpLanguageUpdate($language = null, $options = ['activate' => false])
    {
        if (!isset($language)) {
            $language = $this->wp_default_lang;

            $cmd = self::wpCli()
                ->arg('option')
                ->arg('get')
                ->arg('WPLANG');

            $process = $cmd->executeWithoutException();

            if ($process->isSuccessful()) {
                $language = rtrim($process->getOutput());
            }
        }

        $this->wpLanguageUpdateCore($language, $options['activate']);
        $this->wpLanguageUpdatePlugins($language);
        $this->wpLanguageUpdateThemes($language);
    }

    protected function wpLanguageUpdateCore($language, $activate)
    {
        $cmd = self::wpCli()
            ->arg('language')
            ->arg('core')
            ->arg('install')
            ->arg($language)
            ->execute();

        if ($activate) {
            $cmd = self::wpCli()
                ->arg('language')
                ->arg('core')
                ->arg('activate')
                ->arg($language)
                ->execute();
        }

        $cmd = self::wpCli()
            ->arg('language')
            ->arg('core')
            ->arg('update')
            ->execute();
    }

    protected function wpLanguageUpdatePlugins($language)
    {
        $cmd = self::wpCli()
            ->arg('plugin')
            ->arg('list')
            ->option('field', 'name', '=')
            ->option('status', 'active', '=');

        $process    = $cmd->execute();
        $pluginList = rtrim($process->getOutput());
        $plugins    = explode(PHP_EOL, $pluginList);

        $cmd = self::wpCli()
            ->arg('plugin')
            ->arg('list')
            ->option('field', 'name', '=')
            ->option('status', 'inactive', '=');

        $process    = $cmd->execute();
        $pluginList = rtrim($process->getOutput());
        $plugins    = array_merge($plugins, explode(PHP_EOL, $pluginList));

        foreach ($plugins as $plugin) {
            if (!empty($plugin)) {
                $cmd = self::wpCli()
                    ->arg('language')
                    ->arg('plugin')
                    ->arg('install')
                    ->arg($plugin)
                    ->arg($language)
                    ->executeWithoutException();
            }
        }

        $cmd = self::wpCli()
            ->arg('language')
            ->arg('plugin')
            ->arg('update')
            ->option('all')
            ->execute();
    }

    protected function wpLanguageUpdateThemes($language)
    {
        $cmd = self::wpCli()
            ->arg('theme')
            ->arg('list')
            ->option('field', 'name', '=');

        $process   = $cmd->execute();
        $themeList = rtrim($process->getOutput());
        $themes    = explode(PHP_EOL, $themeList);

        foreach ($themes as $theme) {
            if (!empty($theme)) {
                $cmd = self::wpCli()
                    ->arg('language')
                    ->arg('theme')
                    ->arg('install')
                    ->arg($theme)
                    ->arg($language)
                    ->executeWithoutException();
            }
        }

        $cmd = self::wpCli()
            ->arg('language')
            ->arg('theme')
            ->arg('update')
            ->option('all')
            ->execute();
    }

    public function wpUpdateTimezone()
    {
        $timezones = self::getTimeZones();

        $group     = $this->io()->choice('Wordpress Timezone (1/2)', array_keys($timezones));

        $timezone  = $this->io()->choice('Wordpress Timezone (2/2)', array_keys($timezones[$group]));

        $value     = $timezones[$group][$timezone];

        self::wpCli()
            ->arg('option')
            ->arg('update')
            ->arg('timezone_string')
            ->arg($value)
            ->execute();
    }

    private static function getTimeZones()
    {
        $groups = [];

        foreach (timezone_identifiers_list() as $timezone) {
            $parts   = explode('/', $timezone);
            $group   = $parts[0];
            $zone    = isset($parts[1]) ? $parts[1] : $parts[0];

            if (!isset($groups[$group])) {
                $groups[$group] = [];
            }

            $groups[$group][$zone] = $timezone;
        }

        return $groups;
    }

    protected function wpClean()
    {
        self::wpCli()
            ->arg('option')
            ->arg('update')
            ->arg('blogdescription')
            ->execute();

        self::wpCli()
            ->arg('post')
            ->arg('delete')
            ->arg('1')
            ->option('force')
            ->execute();

        self::wpCli()
            ->arg('post')
            ->arg('delete')
            ->arg('2')
            ->option('force')
            ->execute();
    }

    protected function wpActivatePlugins()
    {
        self::wpCli()
            ->arg('plugin')
            ->arg('activate')
            ->option('all')
            ->execute();

        self::wpCli()
            ->arg('cap')
            ->arg('add')
            ->arg('administrator')
            ->arg('view_query_monitor')
            ->execute();
    }
}
