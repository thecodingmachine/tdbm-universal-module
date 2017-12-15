<?php

namespace TheCodingMachine\TDBM\DI;


use Doctrine\Common\Cache\Cache;
use Doctrine\DBAL\Connection;
use Interop\Container\Factories\Alias;
use Interop\Container\ServiceProviderInterface;
use Mouf\Composer\ClassNameMapper;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use TheCodingMachine\TDBM\Commands\GenerateCommand;
use TheCodingMachine\TDBM\Configuration;
use TheCodingMachine\TDBM\Services\DaoDumper;
use TheCodingMachine\TDBM\TDBMService;
use TheCodingMachine\TDBM\Utils\DefaultNamingStrategy;
use TheCodingMachine\TDBM\Utils\NamingStrategyInterface;

class TdbmServiceProvider implements ServiceProviderInterface
{
    /**
     * @var string
     */
    private $serviceProviderConfigurationFile;

    /**
     * Takes in argument an optional path to the generated configuration file.
     * By default, the configuration file is tdbmServiceProviderConfigStore.php
     *
     * @param null|string $serviceProviderConfigurationFile
     */
    public function __construct(?string $serviceProviderConfigurationFile = null)
    {
        $this->serviceProviderConfigurationFile = $serviceProviderConfigurationFile ?: __DIR__.'/../../../../../tdbmServiceProviderConfigStore.php';
    }

    /**
     * Returns a list of all container entries registered by this service provider.
     *
     * - the key is the entry name
     * - the value is a callable that will return the entry, aka the **factory**
     *
     * Factories have the following signature:
     *        function(\Psr\Container\ContainerInterface $container)
     *
     * @return callable[]
     */
    public function getFactories()
    {
        $services = [
            DefaultNamingStrategy::class => [__CLASS__, 'getNamingStrategy'],
            NamingStrategyInterface::class => new Alias(DefaultNamingStrategy::class),
            DaoDumper::class => [$this, 'getDaoDumper'],
            'tdbm.daoNamespace' => [__CLASS__, 'getDaoNamespace'],
            'tdbm.beanNamespace' => [__CLASS__, 'getBeanNamespace'],
            Configuration::class => [$this, 'getConfiguration'],
            TDBMService::class => [__CLASS__, 'getTdbmService'],
        ];

        $dumpedConfig = $this->getDumpedConfig();
        if ($dumpedConfig !== null) {
            $services += $dumpedConfig->getFactories();
        }

        return $services;
    }

    /**
     * Returns a list of all container entries extended by this service provider.
     *
     * - the key is the entry name
     * - the value is a callable that will return the modified entry
     *
     * Callables have the following signature:
     *        function(Psr\Container\ContainerInterface $container, $previous)
     *     or function(Psr\Container\ContainerInterface $container, $previous = null)
     *
     * About factories parameters:
     *
     * - the container (instance of `Psr\Container\ContainerInterface`)
     * - the entry to be extended. If the entry to be extended does not exist and the parameter is nullable, `null` will be passed.
     *
     * @return callable[]
     */
    public function getExtensions()
    {
        return [
            Application::class => [__CLASS__, 'addCommands']
        ];
    }

    private $config;

    private function getDumpedConfig()
    {
        if ($this->config === false) {
            return null;
        }
        if ($this->config !== null) {
            return $this->config;
        }
        if (file_exists($this->serviceProviderConfigurationFile)) {
            $this->config = require($this->serviceProviderConfigurationFile);
            return $this->config;
        } else {
            $this->config = false;
            return null;
        }
    }

    public static function getNamingStrategy(): DefaultNamingStrategy
    {
        return new DefaultNamingStrategy();
    }

    public function getDaoDumper(): DaoDumper
    {
        return new DaoDumper($this->serviceProviderConfigurationFile);
    }

    private static $rootNamespace;

    private static function getRootNamespace(): string
    {
        if (self::$rootNamespace !== null) {
            return self::$rootNamespace;
        }

        $mapper = ClassNameMapper::createFromComposerFile();
        $namespaces = $mapper->getManagedNamespaces();
        if (empty($namespaces)) {
            throw new TdbmServiceProviderException('You composer.json file does not declare any PSR-0 or PSR-4 namespace.');
        }

        self::$rootNamespace = trim($namespaces[0], '\\');

        return self::$rootNamespace;
    }

    public static function getDaoNamespace(): string
    {
        return self::getRootNamespace().'\\Daos';
    }

    public static function getBeanNamespace(): string
    {
        return self::getRootNamespace().'\\Beans';
    }

    public function getConfiguration(ContainerInterface $container): Configuration
    {
        $connection = $container->get(Connection::class);
        $namingStrategy = $container->get(NamingStrategyInterface::class);
        $cache = $container->get(Cache::class);
        $logger = $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null;
        $daoDumper = $container->get(DaoDumper::class);

        $dumpedConfig = $this->getDumpedConfig();
        if ($dumpedConfig !== null) {
            // For performance reasons, we prefer to get beanNamespace and daoNamespace from the dumped config (this avoids reading the composer.json file to find the namespace).
            $beanNamespace = $dumpedConfig->getBeanNamespace();
            $daoNamespace = $dumpedConfig->getDaoNamespace();
        } else {
            $beanNamespace = $container->get('tdbm.beanNamespace');
            $daoNamespace = $container->get('tdbm.daoNamespace');
        }

        return new Configuration($beanNamespace, $daoNamespace, $connection, $namingStrategy, $cache, null, $logger, [$daoDumper]);
    }

    public static function getTdbmService(ContainerInterface $container): TDBMService
    {
        return new TDBMService($container->get(Configuration::class));
    }

    public static function addCommands(ContainerInterface $container, Application $application): Application
    {
        $application->addCommands([
            new GenerateCommand($container->get(Configuration::class))
        ]);
        return $application;
    }
}
