<?php

/*
 * Copyright (c) 2021 Heimrich & Hannot GmbH
 *
 * @license LGPL-3.0-or-later
 */

namespace HeimrichHannot\TwigSupportBundle\Filesystem;

use Contao\CoreBundle\Config\ResourceFinderInterface;
use Contao\CoreBundle\Framework\ContaoFrameworkInterface;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\PageModel;
use Contao\ThemeModel;
use Contao\Validator;
use HeimrichHannot\TwigSupportBundle\Cache\TemplateCache;
use HeimrichHannot\TwigSupportBundle\Exception\TemplateNotFoundException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Webmozart\PathUtil\Path;

class TwigTemplateLocator
{
    /**
     * @var KernelInterface
     */
    protected $kernel;
    /**
     * @var ResourceFinderInterface
     */
    protected $contaoResourceFinder;

    protected $requestStack;

    protected $scopeMatcher;

    /**
     * @var array|null
     */
    protected $templates;
    /** @var array|null */
    protected $templateWithExtension;
    /**
     * @var Stopwatch
     */
    protected $stopwatch;
    /**
     * @var FilesystemAdapter
     */
    protected $templateCache;
    /**
     * @var ContaoFrameworkInterface
     */
    protected $contaoFramework;

    public function __construct(KernelInterface $kernel, ResourceFinderInterface $contaoResourceFinder, RequestStack $requestStack, ScopeMatcher $scopeMatcher, Stopwatch $stopwatch, FilesystemAdapter $templateCache, ContaoFrameworkInterface $contaoFramework)
    {
        $this->kernel = $kernel;
        $this->contaoResourceFinder = $contaoResourceFinder;
        $this->requestStack = $requestStack;
        $this->scopeMatcher = $scopeMatcher;
        $this->stopwatch = $stopwatch;
        $this->templateCache = $templateCache;
        $this->contaoFramework = $contaoFramework;
    }

    /**
     * Return a twig template path by template name (without or with extension).
     *
     * Options:
     * - (bool) disableCache: Set to true to disable cache. Cache is disabled by default in dev environment. Default false.
     *
     * @throws TemplateNotFoundException
     */
    public function getTemplatePath(string $templateName, array $options = []): string
    {
        return $this->getTemplateContext($templateName, $options)->getPath();
    }

    /**
     * Return a twig template path by template name (without or with extension).
     *
     * Options:
     * - (bool) disableCache: Set to true to disable cache. Cache is disabled by default in dev environment. Default false.
     *
     * @throws TemplateNotFoundException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function getTemplateContext(string $templateName, array $options = []): TemplateContext
    {
        $templateName = basename($templateName);
        $themeFolder = '';
        $disableCache = isset($options['disableCache']) && true === $options['disableCache'];

        if ($this->scopeMatcher->isFrontendRequest($this->requestStack->getCurrentRequest())) {
            /* @var PageModel $objPage */
            global $objPage;

            if ('' != $objPage->templateGroup) {
                if (Validator::isInsecurePath($objPage->templateGroup)) {
                    throw new \RuntimeException('Invalid path '.$objPage->templateGroup);
                }

                $themeFolder = $objPage->templateGroup;
            }
        }

        if ((!$templates = $this->getTemplates(false, $disableCache)) || !\array_key_exists($templateName, $templates)) {
            if ((!$templates = $this->getTemplates(true, $disableCache)) || !\array_key_exists($templateName, $templates)) {
                throw new TemplateNotFoundException(sprintf('Unable to find template "%s".', $templateName));
            }
        }

        $template = $templates[$templateName];

        if (!empty($themeFolder)) {
            if ('templates' === substr($themeFolder, 0, 9)) {
                $themeFolder = trim(substr($themeFolder, 9), '/');
            }
            $pathLength = \strlen($themeFolder);

            foreach ($template['paths'] as $path) {
                if ($themeFolder === substr($path, 0, $pathLength)) {
                    return new TemplateContext($templateName, $path, $template['pathInfo'][$path]);
                }
            }
        }

        foreach ($template['paths'] as $path) {
            if ('@' !== substr($path, 0, 1)) {
                return new TemplateContext($templateName, $path, $template['pathInfo'][$path]);
            }
        }

        $path = end($template['paths']);

        return new TemplateContext($templateName, $path, $template['pathInfo'][$path]);
    }

    /**
     * Return all twig template files of a particular group as array.
     *
     * Configuration options:
     * - (bool) extension: Return the file extension in the file names. Default false.
     * - (bool) disableCache: Set to true to disable cache. Cache is disabled by default in dev environment. Default false.
     *
     * , array $arrAdditionalMapper=array(), $strDefaultTemplate='', string $fileExtension
     *
     * @param string|string[] $prefixes
     * @param array           $arrAdditionalMapper
     * @param string          $strDefaultTemplate
     * @param string          $fileExtension
     */
    public function getTemplateGroup($prefixes, array $configuration = []): array
    {
        if (!\is_array($prefixes) && !\is_string($prefixes)) {
            throw new \InvalidArgumentException('Only string or array are allowed!');
        }

        if (\is_string($prefixes)) {
            $prefixes = [$prefixes];
        }

        $templateNames = [];
        $disableCache = isset($configuration['disableCache']) && true === $configuration['disableCache'];

        foreach ($prefixes as $prefix) {
            $templateNames = array_merge($templateNames,
                $this->getPrefixedFiles($prefix, $configuration)
            );
        }

        if (empty($templateNames)) {
            return [];
        }

        try {
            $themes = $this->contaoFramework->getAdapter(ThemeModel::class)->findAll(['order' => 'name']);
        } catch (\Exception $e) {
            $themes = null;
        }

        $options = [];

        $templates = $this->getTemplates(false, $disableCache);

        if (!$templates) {
            return $options;
        }

        foreach ($templateNames as $templateName) {
            if (!\array_key_exists($templateName, $templates)) {
                continue;
            }

            $template = $templates[$templateName];

            $templatePathList = [];

            foreach ($template['paths'] as $path) {
                if ($path && '@' === substr($path, 0, 1)) {
                    $templatePathList['bundles'][] = explode('/', $path)[0];
                } else {
                    if ($themes) {
                        foreach ($themes as $theme) {
                            if (!$theme->templates) {
                                continue;
                            }

                            if (0 === strpos($path, $theme->templates)) {
                                $templatePathList['themefolders'][] = $theme->name;

                                continue 2;
                            }
                        }
                    }
                    $templatePathList['global'] = $path;
                }
            }
            $optionLabel = '';

            if (isset($templatePathList['global'])) {
                $optionLabel .= $GLOBALS['TL_LANG']['MSC']['global'].', ';
            }

            if (isset($templatePathList['themefolders']) && !empty($templatePathList['themefolders'])) {
                $optionLabel .= implode(', ', $templatePathList['themefolders']).', ';
            }

            if (isset($templatePathList['bundles']) && !empty($templatePathList['bundles'])) {
                $optionLabel .= implode(', ', $templatePathList['bundles']);
            }

            $options[$templateName] = $templateName.' ('.trim($optionLabel, ', ').')';
        }

        return $options;
    }

    /**
     * Return the files matching a prefix as array.
     *
     * Configuration options:
     * - (bool) extension: Return the file extension in the file names. Default false.
     * - (bool) disableCache: Set to true to disable cache. Cache is disabled by default in dev environment. Default false.
     *
     * @param string $prefix The prefix (e.g. "moo_")
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *
     * @return array An array of matching files
     */
    public function getPrefixedFiles(string $prefix, array $configuration = []): array
    {
        $disableCache = isset($configuration['disableCache']) && true === $configuration['disableCache'];
        $extension = isset($configuration['extension']) && true === $configuration['extension'];

        if (rtrim($prefix, '_)') === $prefix) {
            $prefix .= '($|_)';
        }

        return array_values(preg_grep('/^'.$prefix.'/', array_keys($this->getTemplates($extension, $disableCache))));
    }

    /**
     * Return a list of all twig templates and their paths.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function getTemplates(bool $extension = false, bool $disableCache = false): array
    {
        if ($extension) {
            $templates = &$this->templateWithExtension;
        } else {
            $templates = &$this->templates;
        }

        if (!$templates) {
            if ('dev' === $this->kernel->getEnvironment() || $disableCache) {
                $templates = $this->generateContaoTwigTemplatePaths($extension);
            } else {
                $cacheKey = TemplateCache::TEMPLATES_WITHOUT_EXTENSION_CACHE_KEY;

                if ($extension) {
                    $cacheKey = TemplateCache::TEMPLATES_WITH_EXTENSION_CACHE_KEY;
                }

                $cacheItem = $this->templateCache->getItem($cacheKey);

                if (!$cacheItem->isHit()) {
                    $cacheItem->set($this->generateContaoTwigTemplatePaths($extension));
                    $this->templateCache->save($cacheItem);
                }
                $cachedTemplates = $this->templateCache->getItem($cacheKey)->get();

                if (!\is_array($cachedTemplates)) {
                    // clean invalid cache entry
                    $cachedTemplates = $this->generateContaoTwigTemplatePaths($extension);
                    $this->templateCache->deleteItem($cacheKey);
                }
                $templates = $cachedTemplates;
            }
        }

        return $templates;
    }

    /**
     * Return twig templates in a given path.
     *
     * @param iterable|string $dir
     *
     * @deprecated Use getTemplatesInPath
     * @codeCoverageIgnore
     */
    public function getTwigTemplatesInPath($dir, ?string $twigKey = null, bool $extension = false): array
    {
        $stopwatchname = 'TwigTemplateLocator::getTwigTemplatesInPath()';
        $this->stopwatch->start($stopwatchname);

        if (is_iterable($dir)) {
            $files = $dir;
        } elseif (\is_string($dir)) {
            $files = (new Finder())->in($dir)->files()->name('*.twig')->getIterator();
        } else {
            throw new \InvalidArgumentException('Template paths entry must be a folder (string) or an iterable');
        }

        $twigFiles = [];

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            $name = $file->getBasename();

            if (!$extension) {
                $name = Path::getFilenameWithoutExtension($name, '.html.twig');
            }

            if (!$twigKey) {
                $twigFiles[$name]['paths'][] = Path::makeRelative($file->getPathname(), $this->kernel->getProjectDir().'/templates');
            } else {
                $twigFiles[$name]['paths'][] = "@$twigKey/".$file->getRelativePathname();
            }
        }
        $this->stopwatch->stop($stopwatchname);

        return $twigFiles;
    }

    /**
     * Return twig templates in a given path.
     *
     * Options:
     * - name: (string) Filename if a specify file should be searched. Example: 'my_template.html.twig'
     * - extension: (bool) Add extension to filename (array key)
     *
     * @param iterable|string $dir
     */
    public function getTemplatesInPath($dir, ?BundleInterface $bundle = null, array $options = []): array
    {
        $stopwatchname = 'TwigTemplateLocator::getTwigTemplatesInPath()';
        $this->stopwatch->start($stopwatchname);

        $name = $options['name'] ?? '*.twig';
        $extension = $options['extension'] ?? false;

        if (is_iterable($dir)) {
            $files = $dir;
        } elseif (\is_string($dir)) {
            $files = (new Finder())->in($dir)->files()->followLinks()->name($name)->getIterator();
        } else {
            throw new \InvalidArgumentException('Template paths entry must be a folder (string) or an iterable');
        }

        $twigKey = null;

        if ($bundle) {
            $twigKey = preg_replace('/Bundle$/', '', $bundle->getName());
        }

        $twigFiles = [];

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            $name = $file->getBasename();

            if (!$extension) {
                $name = Path::getFilenameWithoutExtension($name, '.html.twig');
            }

            if (!$twigKey) {
                $path = Path::makeRelative($file->getPathname(), $this->kernel->getProjectDir().'/templates');
                $twigFiles[$name]['paths'][] = $path;
                $twigFiles[$name]['pathInfo'][$path]['bundle'] = null;
                $twigFiles[$name]['pathInfo'][$path]['pathname'] = $file->getPathname();
            } else {
                $path = "@$twigKey/".$file->getRelativePathname();
                $twigFiles[$name]['paths'][] = $path;
                $twigFiles[$name]['pathInfo'][$path]['bundle'] = $bundle->getName();
                $twigFiles[$name]['pathInfo'][$path]['pathname'] = $file->getPathname();
            }
        }
        $this->stopwatch->stop($stopwatchname);

        return $twigFiles;
    }

    /**
     * Return all twig file paths in the typical folders.
     */
    protected function generateContaoTwigTemplatePaths(bool $extension = false): array
    {
        $bundles = $this->kernel->getBundles();
        $twigFiles = [];

        if (\is_array($bundles)) {
            foreach ($bundles as $key => $bundle) {
                $path = $bundle->getPath();

                if (!is_dir($dir = rtrim($path, '/').'/Resources/views')) {
                    if (!is_dir($dir = rtrim($path, '/').'/templates')) {
                        continue;
                    }
                }

                $twigFiles = array_merge_recursive($twigFiles, $this->getTemplatesInPath($dir, $bundle, ['extension' => $extension]));
            }
        }

        // Bundle template folders
        $twigFiles = array_merge_recursive($twigFiles, $this->getTemplatesInPath(
            $this->contaoResourceFinder->findIn('templates')->name('*.twig')->getIterator(), null, ['extension' => $extension]));

        // Project template folder
        $twigFiles = array_merge_recursive($twigFiles, $this->getTemplatesInPath(
            $this->kernel->getProjectDir().'/templates', null, ['extension' => $extension]));

        return $twigFiles;
    }
}
