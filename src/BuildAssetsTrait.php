<?php

namespace Globalis\WP\Cubi\Robo;

trait BuildAssetsTrait
{
    protected $dirAssetsSrc    = 'assets';
    protected $dirAssetsDest   = 'dist';
    protected $dirStyles       = 'styles';
    protected $dirScripts      = 'scripts';
    protected $dirImages       = 'images';
    protected $dirFonts        = 'fonts';
    protected $scriptsFormat   = ['normal', 'minified'];
    protected $stylesFormat    = [
        'normal'   => \ScssPhp\ScssPhp\OutputStyle::EXPANDED,
        'minified' => \ScssPhp\ScssPhp\OutputStyle::COMPRESSED,
    ];
    protected $assetsVersion = null;

    /**
     * Builds the theme
     *
     * @option bool   $disable-minify Disable minification of js/css or not. Default false (minified)
     * @option bool   $skip-styles    Skip building styles or not. Default false (builds them)
     * @option bool   $skip-scripts   Skip building scripts or not. Default false (builds them)
     * @option bool   $skip-images    Skip optimizing images or not. Default false (optimize them)
     * @option bool   $skip-fonts     Skip moving fonts or not. Default false (moves them)
     * @option bool   $to-website     Moves the theme to the Website. Default false (don't move it)
     * @option string $semversion     Version number. Default to autoincrement
     */
    public function buildAssets($environment = 'development', $root = \RoboFile::ROOT, array $options = ['disable-minify' => false, 'skip-styles' => false, 'skip-scripts' => false, 'skip-images' => false, 'skip-fonts' => false])
    {
        if (true === $options['disable-minify']) {
            $format = 'normal';
        } else {
            $format = 'minified';
        }

        if (!$options['skip-styles']) {
            $this->buildStyles($root, $format, $environment);
        }
        if (!$options['skip-scripts']) {
            $this->buildScripts($root, $format);
        }
        if (!$options['skip-images']) {
            $this->buildImages($root);
        }
        if (!$options['skip-fonts']) {
            $this->buildFonts($root);
        }

        $this->updateAssetsVersion($root);
    }


    protected function getDirTheme($root)
    {
        return $root . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . \RoboFile::THEME_SLUG;
    }

    protected function getDirAssets($from = 'src', $root = false)
    {
        switch ($from) {
            case 'dest':
                $dir = $this->dirAssetsDest;
                break;
            case 'src':
            default:
                $dir = $this->dirAssetsSrc;
                break;
        }
        return $this->getDirTheme($root) . DIRECTORY_SEPARATOR . $dir;
    }

    protected function getDirStyles($from = 'src', $root = false)
    {
        return $this->getDirAssets($from, $root) . DIRECTORY_SEPARATOR . $this->dirStyles;
    }

    protected function getDirScripts($from = 'src', $root = false)
    {
        return $this->getDirAssets($from, $root) . DIRECTORY_SEPARATOR . $this->dirScripts;
    }

    protected function getDirFonts($from = 'src', $root = false)
    {
        return $this->getDirAssets($from, $root) . DIRECTORY_SEPARATOR . $this->dirFonts;
    }

    protected function getDirImages($from = 'src', $root = false)
    {
        return $this->getDirAssets($from, $root) . DIRECTORY_SEPARATOR . $this->dirImages;
    }

    /**
     * Watches file changes to rebuild theme on the fly
     *
     * @option bool   $skip-styles  Skip building styles or not. Default false (builds them)
     * @option bool   $skip-scripts Skip building scripts or not. Default false (builds them)
     * @option bool   $skip-images  Skip optimizing images or not. Default false (optimize them)
     * @option bool   $skip-fonts   Skip moving fonts or not. Default false (moves them)
     */
    public function themeWatch(array $options = ['disable-minify' => false, 'skip-styles' => false, 'skip-scripts' => false, 'skip-images' => false, 'skip-fonts' => false, 'environment' => 'development'])
    {
        $watch = $this->taskWatch();
        $root  = \RoboFile::ROOT;
        $env   = $options['environment'];

        if (true === $options['disable-minify']) {
            $format = 'normal';
        } else {
            $format = 'minified';
        }

        if (!$options['skip-styles']) {
            $this->buildStyles($root);
            $watch->monitor($this->getDirStyles('src', $root), function () use ($root, $format, $env) {
                $this->buildStyles($root, $format, $env);
            });
        }

        if (!$options['skip-scripts']) {
            $this->buildScripts($root);
            $watch->monitor($this->getDirScripts('src', $root), function () use ($root, $format) {
                $this->buildScripts($root, $format);
            });
        }

        if (!$options['skip-images']) {
            $watch->monitor($this->getDirImages('src', $root), function () use ($root) {
                $this->buildImages($root);
            });
        }

        if (!$options['skip-fonts']) {
            $watch->monitor($this->getDirFonts('src', $root), function () use ($root) {
                $this->buildFonts($root);
            });
        }

        $this->updateAssetsVersion($root);
        $watch->run();
    }

    public function scssphpGlobalis($file, $compilerOptions)
    {
        if (!class_exists('\ScssPhp\ScssPhp\Compiler')) {
            return Result::errorMissingPackage($this, 'scssphp', 'scssphp/scssphp');
        }

        $scssCode = file_get_contents($file);
        $scss = new \ScssPhp\ScssPhp\Compiler();

        // set options for the scssphp compiler
        if (isset($compilerOptions['importDirs'])) {
            $scss->setImportPaths($compilerOptions['importDirs']);
        }

        if (isset($compilerOptions['formatter'])) {
            $scss->setOutputStyle($compilerOptions['formatter']);
        }

        if (isset($compilerOptions['sourceMap'])) {
            $scss->setSourceMap($compilerOptions['sourceMap']);
        }

        if (isset($compilerOptions['sourceMapOptions'])) {
            $scss->setSourceMapOptions($compilerOptions['sourceMapOptions']);
        }

        $scssCode = '$assets-version: "' . $this->assetsVersion() . '";' . PHP_EOL . $scssCode;

        return ($scss->compileString($scssCode))->getCss();
    }

    public function minifyJSGlobalis($file)
    {
        if (!class_exists('\JShrink\Minifier')) {
            return Result::errorMissingPackage($this, 'Minifier', 'JShrink');
        }

        $js = file_get_contents($file);

        return \JShrink\Minifier::minify($js, ['flaggedComments' => false]);
    }

    /**
     * Runs the sass compiler on the files given in configuration
     *
     * @param boolean $format
     */
    protected function buildStyles($root = false, $format = false, $env = false)
    {
        $src       = $this->getDirStyles('src', $root);
        $dest      = $this->getDirStyles('dest', $root);
        $maps      = glob($src . DIRECTORY_SEPARATOR . '*.scss');
        $rules     = '#^[^\_]+(.)*.scss$#';
        foreach ($maps as $i => $file) {
            if (!preg_match($rules, basename($file))) {
                unset($maps[$i]);
            }
        }

        if (is_dir($dest)) {
            $this->_cleanDir($dest);
        } else {
            $this->_mkdir($dest);
        }

        if (empty($maps)) {
            return;
        }

        $map_args = [];

        foreach ($maps as $map) {
            $scriptName = basename($map, '.scss') . '.css';
            $destFile   = $dest . DIRECTORY_SEPARATOR . $scriptName;
            $formatter  = $format ? $this->stylesFormat[$format] : $this->stylesFormat['normal'];

            if ($env && 'development' === $env) {
                $mapOptions  = [
                    'sourceMapWriteTo'  => $dest . DIRECTORY_SEPARATOR . str_replace("/", "_", $scriptName) . ".map",
                    'sourceMapURL'      => str_replace("/", "_", $scriptName) . ".map",
                    'sourceMapFilename' => $destFile,
                    'sourceMapBasepath' => $src,
                    'sourceRoot'        => '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . $this->dirAssetsSrc . DIRECTORY_SEPARATOR . $this->dirStyles . DIRECTORY_SEPARATOR,
                ];

                $map_args = ['sourceMap' => \ScssPhp\ScssPhp\Compiler::SOURCE_MAP_INLINE, 'sourceMapOptions'  => $mapOptions];
            }

            $this->taskScss([$map => $destFile])
                 ->setFormatter($formatter)
                 ->setImportPaths($src)
                 ->compiler([$this, 'scssphpGlobalis'], $map_args)
                 ->run();
        }
    }

    /**
     * Runs the minified on the script directory
     *
     * @param boolean $format
     */
    protected function buildScripts($root = false, $format = false)
    {
        $src       = $this->getDirScripts('src', $root);
        $dest      = $this->getDirScripts('dest', $root);
        $maps      = glob($src . DIRECTORY_SEPARATOR . "_*.map");


        if (is_dir($dest)) {
            $this->_cleanDir($dest);
        } else {
            $this->_mkdir($dest);
        }

        if (empty($maps)) {
            return;
        }

        $filesToBuild = [];

        foreach ($maps as $map) {
            $scriptName = substr(basename($map, '.map'), 1);

            if (file_exists($map)) {
                $content = file_get_contents($map);

                preg_match_all('/[\w\-\/.]*.js/', $content, $matches);
                if (isset($matches[0]) && !empty($matches[0])) {
                    foreach ($matches[0] as $match) {
                        if (file_exists($src . DIRECTORY_SEPARATOR . $match)) {
                            $filesToBuild[$scriptName][] = $src . DIRECTORY_SEPARATOR . $match;
                        }
                    }
                }
            }
        }

        if (!empty($filesToBuild)) {
            foreach ($filesToBuild as $scriptName => $files) {
                $destFile = $dest . DIRECTORY_SEPARATOR . $scriptName . '.js';
                $this->taskConcat($files)->to($destFile)->run();

                if ($format && in_array($format, $this->scriptsFormat) && $format !== 'normal') {
                    $this->taskMinify($destFile)
                         ->compiler([$this, 'minifyJSGlobalis'])
                         ->run();

                    $this->_remove($destFile);
                    $minFilename = str_replace('.js', '.min.js', $destFile);
                    $this->taskFilesystemStack()->rename($minFilename, $destFile)->run();
                }
            }
        }
    }

    /**
     * Optimize / minify images
     */
    protected function buildImages($root = false)
    {
        $src    = $this->getDirImages('src', $root);
        $dest   = $this->getDirImages('dest', $root);

        if (is_dir($dest)) {
            $this->_cleanDir($dest);
        } else {
            $this->_mkdir($dest);
        }

        $this->taskCopyDir([$src => $dest])->run();

        // Images at root levelt
        $children = glob($src . DIRECTORY_SEPARATOR . '*.{jpg,png,gif}', GLOB_BRACE);
        foreach ($children as $child) {
            $this->taskImageMinify($child)->to($dest)->run();
        }

        // Images in sub directories
        $this->buildImagesInFolders($src, $dest, $folder = '');
    }
    /**
     * Sub function used in buildImages() to do it recursively
     *
     * @param string $src    Source folder of the images
     * @param string $dest   Destination folder for the minified images
     * @param string $folder Folder to start from (recursivity)
     */
    protected function buildImagesInFolders($src, $dest, $folder = '')
    {
        $folders = array_filter(glob($src . $folder . DIRECTORY_SEPARATOR . '*'), 'is_dir');

        foreach ($folders as $dir) {
            $images = glob($dir . DIRECTORY_SEPARATOR . '*.{jpg,png,gif}', GLOB_BRACE);
            $next_sublevel = DIRECTORY_SEPARATOR . basename($dir);
            foreach ($images as $image) {
                $this->taskImageMinify($image)->to($dest . $folder . $next_sublevel)->run();
            }

            $this->buildImagesInFolders($src, $dest, $next_sublevel);
        }
    }

    /**
     * Moves the fonts
     */
    protected function buildFonts($root = false)
    {
        $src    = $this->getDirFonts('src', $root);
        $dest   = $this->getDirFonts('dest', $root);

        if (is_dir($dest)) {
            $this->_cleanDir($dest);
        } else {
            $this->_mkdir($dest);
        }

        $this->taskCopyDir([$src => $dest])->run();
    }

    protected function assetsVersion()
    {
        if (empty($this->assetsVersion)) {
            $this->assetsVersion = date('YmdHis');
        }
        return $this->assetsVersion;
    }

    /**
     * Updates the theme version automatically to disable cache on new modifications
     * Currently made for WordPress using the theme version in its style.css
     *
     * @param string $version
     */
    protected function updateAssetsVersion($root)
    {
        file_put_contents($this->getDirAssets('dest', $root) . DIRECTORY_SEPARATOR . 'version', $this->assetsVersion());
    }
}
