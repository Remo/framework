<?php
/**
 * This file is part of the PPI Framework.
 *
 * @copyright   Copyright (c) 2012 Paul Dragoonis <paul@ppi.io>
 * @license     http://opensource.org/licenses/mit-license.php MIT
 * @link        http://www.ppi.io
 */
namespace PPI\ServiceManager\Config;

use PPI\View\FileLocator;
use PPI\View\GlobalVariables;
use PPI\View\TemplateLocator;
use PPI\View\DelegatingEngine;
use PPI\View\TemplateNameParser;
use PPI\View\Php\FileSystemLoader;
use Symfony\Component\Templating\PhpEngine;

// Helpers
use PPI\View\Helper\RouterHelper;
use PPI\View\Helper\SessionHelper;
use Symfony\Component\Templating\Helper\SlotsHelper;
use Symfony\Component\Templating\Helper\AssetsHelper;

// Twig
use PPI\View\Twig\TwigEngine;
use PPI\View\Twig\Loader\FileSystemLoader as TwigFileSystemLoader;
use PPI\View\Twig\Extension\AssetsExtension as TwigAssetsExtension;
use PPI\View\Twig\Extension\RouterExtension as TwigRouterExtension;

// Mustache
use PPI\View\Mustache\MustacheEngine;
use PPI\View\Mustache\Loader\FileSystemLoader as MustacheFileSystemLoader;

// Smarty
use PPI\View\Smarty\SmartyEngine;
use PPI\View\Smarty\Extension\AssetsExtension as SmartyAssetsExtension;
use PPI\View\Smarty\Extension\RouterExtension as SmartyRouterExtension;

// Service Manager
use Zend\ServiceManager\ServiceManager;

/**
 * ServiceManager configuration for the Templating component.
 *
 * @author     Vítor Brandão <vitor@ppi.io>
 * @package    PPI
 * @subpackage ServiceManager
 */
class ViewConfig extends AbstractConfig
{
    /**
     * Templating engines currently supported:
     * - PHP
     * - Twig
     * - Smarty
     * - Mustache
     *
     * @param ServiceManager $serviceManager
     *
     * @return type
     */
    public function configureServiceManager(ServiceManager $serviceManager)
    {
        $config = $serviceManager->get('Config');
        $appRootDir = $config['parameters']['app.root_dir'];
        $appCacheDir = $config['parameters']['app.cache_dir'];

        $moduleManager = $serviceManager->get('ModuleManager');
        $modulePaths = array();
        foreach ($moduleManager->getLoadedModules() as $module) {
            $modulePaths[] = $module->getPath();
        }

        // The "framework.templating" option is deprecated. Please replace it with "framework.view"
        $config = $this->processConfiguration($config);

        // these are the templating engines currently supported
        $knownEngineIds = array('php', 'smarty', 'twig', 'mustache');

        // these are the engines selected by the user
        $engineIds = isset($config['engines']) ? $config['engines'] : array('php');

        // filter templating engines
        $engineIds = array_intersect($engineIds, $knownEngineIds);
        if (empty($engineIds)) {
            throw new \RuntimeException(sprintf('At least one templating engine should be defined in your app config (in $config[\'view.engines\']). These are the available ones: "%s". Example: "$config[\'templating.engines\'] = array(\'%s\');"', implode('", ', $knownEngineIds), implode("', ", $knownEngineIds)));
        }

        // File locator
        $serviceManager->setFactory('filelocator', function($serviceManager) use ($appRootDir, $modulePaths) {
            return new FileLocator(array(
                'modules'     => $serviceManager->get('ModuleManager')->getModules(),
                'modulesPath' => realpath($modulePaths[0]),
                'appPath'     => $appRootDir
            ));
        });

        // Templating locator
        $serviceManager->setFactory('templating.locator', function($serviceManager) {
            return new TemplateLocator($serviceManager->get('filelocator'));
        });

        // Templating Name Parser
        $serviceManager->setFactory('templating.name.parser', function($serviceManager) {
            return new TemplateNameParser();
        });

        // Templating assets helper
        $serviceManager->setFactory('templating.helper.assets', function($serviceManager) {
            return new AssetsHelper($serviceManager->get('request')->getBasePath());
        });

        // Templating globals
        $serviceManager->setFactory('templating.globals', function($serviceManager) {
            return new GlobalVariables($serviceManager->get('servicemanager'));
        });

        // PHP Engine
        $serviceManager->setFactory('templating.engine.php', function($serviceManager) {
            return new PhpEngine(
                $serviceManager->get('templating.name.parser'),
                new FileSystemLoader($serviceManager->get('templating.locator')),
                array(
                    new SlotsHelper(),
                    $serviceManager->get('templating.helper.assets'),
                    new RouterHelper($serviceManager->get('router')),
                    new SessionHelper($serviceManager->get('session'))
                 )
            );
        });

        // Twig Engine
        $serviceManager->setFactory('templating.engine.twig', function($serviceManager) {

            $templatingLocator = $serviceManager->get('templating.locator');

            $twigEnvironment = new \Twig_Environment(
                new TwigFileSystemLoader($templatingLocator, new TemplateNameParser())
            );

            // Add some twig extension
            $twigEnvironment->addExtension(new TwigAssetsExtension($serviceManager->get('templating.helper.assets')));
            $twigEnvironment->addExtension(new TwigRouterExtension($serviceManager->get('router')));

            return new TwigEngine($twigEnvironment, new TemplateNameParser(), $templatingLocator, $serviceManager->get('templating.globals'));
        });

        // Smarty Engine
        $serviceManager->setFactory('templating.engine.smarty', function($serviceManager) use ($appCacheDir) {
            $cacheDir = $appCacheDir . DIRECTORY_SEPARATOR . 'smarty';
            $templateLocator = $serviceManager->get('templating.locator');

            $smartyEngine = new SmartyEngine(
                new \Smarty(),
                $templateLocator,
                new TemplateNameParser(),
                new FileSystemLoader($templateLocator),
                array(
                    'cache_dir'     => $cacheDir . DIRECTORY_SEPARATOR . 'cache',
                    'compile_dir'   => $cacheDir . DIRECTORY_SEPARATOR . 'templates_c',
                ),
                $serviceManager->get('templating.globals')
            );

            // Add some SmartyBundle extensions
            $smartyEngine->addExtension(new SmartyAssetsExtension($serviceManager->get('templating.helper.assets')));
            $smartyEngine->addExtension(new SmartyRouterExtension($serviceManager->get('router')));

            return $smartyEngine;
        });

        // Mustache Engine
        $serviceManager->setFactory('templating.engine.mustache', function($serviceManager, $appCacheDir) {

            $rawMustacheEngine = new \Mustache_Engine(array(
                'loader' => new MustacheFileSystemLoader($serviceManager->get('templating.locator'), new TemplateNameParser()),
                'cache'  => $appCacheDir . DIRECTORY_SEPARATOR . 'mustache'
            ));

            return new MustacheEngine($rawMustacheEngine, new TemplateNameParser());
        });

        // Delegating Engine
        $serviceManager->setFactory('templating', function($serviceManager) use ($engineIds) {
            $delegatingEngine = new DelegatingEngine();
            foreach ($engineIds as $id) {
                $delegatingEngine->addEngine($serviceManager->get('templating.engine.'.$id));
            }

            return $delegatingEngine;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getConfigurationDefaults()
    {
        return array('framework' => array(
            'view'  => array(
                'engines' => array('php')
            )
        ));
    }

    /**
     * {@inheritDoc}
     */
    protected function processConfiguration(array $config, ServiceManager $serviceManager = null)
    {
        $config = $config['framework'];
        if (!isset($config['view'])) {
            $config['view'] = array();
        }

        if (isset($config['templating'])) {
            $config['view'] = $this->mergeConfiguration($config['templating'], $config['view']);
        }

        return $config['view'];
    }
}