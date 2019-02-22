<?php

namespace Liz\Bundle\EasyDocBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Symfony\Bundle\MakerBundle\Exception\RuntimeCommandException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

class DocCommand extends Command
{
    protected static $defaultName = 'doc';
    /**
     * @var KernelInterface
     */
    private $kernel;
    /**
     * @var Environment
     */
    private $twig;
    /**
     * @var RouterInterface
     */
    private $router;
    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var \phpDocumentor\Reflection\DocBlockFactory
     */
    private $docBlockReader;

    public function __construct(KernelInterface $kernel, Environment $twig,
                                RouterInterface $router, ContainerInterface $container, EntityManagerInterface $em)
    {
        parent::__construct();
        $this->kernel = $kernel;
        $this->twig = $twig;
        $this->router = $router;
        $this->container = $container;
        $this->docBlockReader = \phpDocumentor\Reflection\DocBlockFactory::createInstance();
        $this->em = $em;
    }

    protected function configure()
    {
        $this
            ->setDescription('Generates the entire documentation of your Symfony application')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $params['project_name'] = $this->getProjectName();
        $params['easydoc_version'] = $this->getEasyDocVersion();
        $params['routes'] = $this->getRoutes();
        $params['services'] = $this->getServices();
        $params['packages'] = $this->getPackages();
        $params['bundles'] = $this->getBundles();
        $params['project_score'] = $this->getProjectScore($params);
        $params['project_docs'] = $this->getProjectDoc();
        $params['doctrine_entities'] = $this->getDoctrineEntities();
        $params['last_build_date'] = new \DateTime();

        $docPath = $this->kernel->getCacheDir().'/doc.html';
        file_put_contents($docPath, $this->twig->render('@EasyDoc/doc.html.twig', $params));
        $output->writeln(sprintf('[OK] The documentation was generated in %s', realpath($docPath)));
    }

    private function getComposerJson(){
        $composerJsonPath = $this->kernel->getProjectDir().'/composer.json';
        if (!file_exists($composerJsonPath)) {
            throw new RuntimeCommandException('There is no "composer.json" file in your application');
        }
        return json_decode(file_get_contents($composerJsonPath), true);
    }

    private function getProjectName()
    {
        $composerJsonContents = $this->getComposerJson();

        if (!isset($composerJsonContents['name'])){
            throw new RuntimeCommandException('You application "composer.json" MISS a name ');
        }
        if (2 !==  substr_count($composerJsonContents['name'], '/') + 1){
            throw new RuntimeCommandException('You application "composer.json" name format error read https://getcomposer.org/doc/02-libraries.md ');
        }
        return ucwords(strtr($composerJsonContents['name'], '_-', '  '));
    }

    private function getEasyDocVersion()
    {
        foreach ($this->getPackages() as $package) {
            if ('easycorp/easy-doc-bundle' === $package['name']) {
                return $package['version'];
            }
        }

        return 'v1.0.0';
    }

    private function getRoutes()
    {
        $allRoutes = $this->router->getRouteCollection();
        $routes = array();
        foreach ($allRoutes->all() as $name => $routeObject) {
            $route['name'] = $name;
            $route['path'] = $routeObject->getPath();
            $route['path_regex'] = $routeObject->compile()->getRegex();
            $route['host'] = '' !== $routeObject->getHost() ? $routeObject->getHost() : '(any)';
            $route['host_regex'] = '' !== $routeObject->getHost() ? $routeObject->compile()->getHostRegex() : '';
            $route['http_methods'] = $routeObject->getMethods() ?: '(any)';
            $route['http_schemes'] = $routeObject->getSchemes() ?: '(any)';
            $route['php_class'] = get_class($routeObject);
            $route['defaults'] = $routeObject->getDefaults();
            //$route['requirements'] = $routeObject->getRequirements() ?: '(none)',
            //$route['options'] = $this->formatRouterConfig($route->getOptions();

            if (in_array($name, $this->getSymfonyBuiltInRouteNames())) {
                $routes['symfony'][] = $route;
            } else {
                $routes['application'][] = $route;
            }
        }

        return $routes;
    }

    private function getServices()
    {
        $cachedFile = $this->container->getParameter('debug.container.dump');
        $container = new ContainerBuilder();
        $loader = new XmlFileLoader($container, new FileLocator());
        $loader->load($cachedFile);

        $services = array();
        foreach ($this->container->getServiceIds() as $serviceId) {
            try{
                $definition = $container->getDefinition($serviceId);
            }catch (\Exception $exception){
                continue;
            }
            $isShared = method_exists($definition, 'isShared') ? $definition->isShared() : 'prototype' !== $definition->getScope();
            $service['id'] = $serviceId;
            $service['class'] = $definition->getClass() ?: '-';
            $service['public'] = $definition->isPublic() ? 'yes' : 'no';
            $service['synthetic'] = $definition->isSynthetic() ? 'yes' : 'no';
            $service['lazy'] = $definition->isLazy() ? 'yes' : 'no';
            $service['shared'] = $isShared ? 'yes' : 'no';
            $service['abstract'] = $definition->isAbstract() ? 'yes' : 'no';
            $service['tags'] = $definition->getTags();
            $service['method_calls'] = $definition->getMethodCalls();
            $service['factory'] = $definition->getFactory();

            $services[] = $service;
        }

        return $services;
    }

    private function getPackages()
    {
        $packages = array();

        $composerLockPath = $this->kernel->getProjectDir().'/../composer.lock';
        if (!file_exists($composerLockPath)) {
            return $packages;
        }

        $composerLockContents = json_decode(file_get_contents($composerLockPath), true);
        $prodPackages = $this->processComposerPackagesInformation($composerLockContents['packages']);
        $devPackages = $this->processComposerPackagesInformation($composerLockContents['packages-dev'], true);
        $allPackages = array_merge($prodPackages, $devPackages);
        ksort($allPackages);

        return $allPackages;
    }

    private function getBundles()
    {
        $bundles = array();
        $rootDir = realpath($this->kernel->getProjectDir().'/..').DIRECTORY_SEPARATOR;

        foreach ($this->kernel->getBundles() as $bundleName => $bundleObject) {
            $bundle = array(
                'name' => $bundleObject->getName(),
                'namespace' => $bundleObject->getNamespace(),
                'path' => str_replace($rootDir, '', $bundleObject->getPath()),
            );
            if (method_exists($bundleObject, 'getParent')){
                $bundle['parent'] = $bundleObject->getParent();
            }
            $stats = $this->getBundleDirSize($bundleObject);
            $bundle['num_files'] = $stats['num_files'];
            $bundle['size'] = $stats['size'];

            $bundles[$bundleObject->getName()] = $bundle;
        }

        ksort($bundles);

        return $bundles;
    }

    private function processComposerPackagesInformation($composerPackages, $isDev = false)
    {
        $packages = array();
        foreach ($composerPackages as $packageConfig) {
            $package = array();
            $package['is_dev'] = $isDev;
            foreach (array('name', 'description', 'keywords', 'authors', 'version', 'license', 'homepage', 'type', 'source', 'bin', 'autoload', 'time') as $key) {
                $package[$key] = isset($packageConfig[$key]) ? $packageConfig[$key] : '';
            }

            $packages[$package['name']] = $package;
        }

        return $packages;
    }

    private function getSymfonyBuiltInRouteNames()
    {
        return array(
            '_profiler',
            '_profiler_exception',
            '_profiler_exception_css',
            '_profiler_home',
            '_profiler_info',
            '_profiler_open_file',
            '_profiler_phpinfo',
            '_profiler_router',
            '_profiler_search',
            '_profiler_search_bar',
            '_profiler_search_results',
            '_twig_error_test',
            '_wdt',
        );
    }

    private function getBundleDirSize(BundleInterface $bundle)
    {
        $dirSize = 0;
        $numFiles = 0;
        $dirItems = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($bundle->getPath()));
        foreach ($dirItems as $item) {
            if ($item->isFile()) {
                $dirSize += $item->getSize();
                $numFiles++;
            }
        }

        return array(
            'num_files' => $numFiles,
            'size' => $dirSize,
        );
    }

    private function getProjectScore($params)
    {
        $score = 0;

        $score += 1 * count($params['routes']['symfony']);
        $score += 5 * count($params['routes']['application']);
        $score += 10 * count($params['services']);
        foreach ($params['packages'] as $package) {
            $score += $package['is_dev'] ? 25 : 50;
        }
        foreach ($params['bundles'] as $bundle) {
            $score += 'vendor/' === substr($bundle['path'], 0, 7) ? 25 : 100;
        }

        return $score;
    }

    private function getAcmeNameSpace(){
        $composerJson = $this->getComposerJson();
        if( !isset($composerJson['autoload']['psr-4']) ){
            throw new RuntimeCommandException("composer.json have no key ['psr-4']");
        }
        $flipPsr4 = array_flip($composerJson['autoload']['psr-4']);
        return $flipPsr4['src/'];
    }

    private function getProjectDoc(){

        $dir = $this->kernel->getProjectDir().'/src';
        $finder = new Finder();
        $finder->files()->in($dir)->exclude(['Migrations', 'Resources'])->name('*.php');

        $folders = [];
        foreach ($finder as $file){
            $folder = $file->getRelativePath();
            if (!array_key_exists($folder, $folders)){
                $folders[$folder] = [];
            }
            /**
             * @var $reflection \ReflectionClass
             */
            list($reflection, $folders) = $this->appendClassDocument($file, $folders, $folder);
            $reflectionMethods = $reflection->getMethods();
            foreach ($reflectionMethods as $reflectionMethod){
                if($reflectionMethod->class == $reflection->getName()){
                    $folders[$folder][$reflection->getShortName()]['methods'][$reflectionMethod->getName()] =
                        $this->appendMethodDocument($reflectionMethod);
                }
            }
        }
        ksort($folders);
        return $folders;
    }

    /**
     * @param $acme
     * @param $file
     * @param $folders
     * @param $folder
     * @return array
     * @throws \ReflectionException
     */
    private function appendClassDocument(SplFileInfo $file,array $folders, $folder): array
    {
        $acme = $this->getAcmeNameSpace();
        $class = $acme . strtr(rtrim($file->getRelativePathname(), '.php'), ['/' => '\\']);
        $reflection = new \ReflectionClass($class);
        $classShortName = $reflection->getShortName();
        $folders[$folder][$classShortName] = [
            'className' => $reflection->getName(),
            'summary' => '',
            'description' => '',
        ];
        if ($reflection->getDocComment()) {
            $classDoc = $this->docBlockReader->create($reflection->getDocComment());
            $folders[$folder][$classShortName]['summary'] = $classDoc->getSummary();
            $folders[$folder][$classShortName]['description'] = $classDoc->getDescription()->render();
            foreach ($classDoc->getTags() as $tag){
                $folders[$folder][$classShortName][$tag->getName()][] = $tag->render();
            }
        }
        return array($reflection, $folders);
    }

    private function appendMethodDocument(\ReflectionMethod $reflectionMethod)
    {

        $methodDoc = [
            'methodName' => $reflectionMethod->getName(),
            'summary' => '',
            'description' => '',
        ];

        if ($reflectionMethod->getDocComment()){
            $methodDocBlock = $this->docBlockReader->create($reflectionMethod->getDocComment());
            $methodDoc['summary'] = $methodDocBlock->getSummary();
            $methodDoc['description'] = $methodDocBlock->getDescription()->render();
            foreach ($methodDocBlock->getTags() as $tag){
                $methodDoc[$tag->getName()][] = $tag->render();
            }
        }
        return $methodDoc;
    }

    private function getDoctrineEntities()
    {
        /**
         * @var $meta ClassMetadataInfo[]
         */
        # todo 获取Entity 内容
        $meta = $this->em->getMetadataFactory()->getAllMetadata();
        foreach ($meta as $m){
            dump($m->getName());
            dump($m->table);
            dump($m->customRepositoryClassName);
            dump($m->fieldMappings);
            dump($m->associationMappings);
        }
    }
}
