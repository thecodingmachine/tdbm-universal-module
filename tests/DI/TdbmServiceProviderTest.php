<?php

namespace TheCodingMachine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Table;
use Interop\Container\ServiceProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Simplex\Container;
use Symfony\Component\Console\Application;
use TheCodingMachine\TDBM\DI\TdbmServiceProvider;
use TheCodingMachine\TDBM\TDBMService;

class TdbmServiceProviderTest extends TestCase
{
    private static function getAdminConnectionParams(): array
    {
        return array(
            'user' => $GLOBALS['db_username'],
            'password' => $GLOBALS['db_password'],
            'host' => $GLOBALS['db_host'],
            'port' => $GLOBALS['db_port'],
            'driver' => $GLOBALS['db_driver'],
        );
    }

    public static function setUpBeforeClass()
    {
        $config = new \Doctrine\DBAL\Configuration();

        $adminConn = DriverManager::getConnection(self::getAdminConnectionParams(), $config);
        $adminConn->getSchemaManager()->dropAndCreateDatabase($GLOBALS['db_name']);

        if (file_exists(__DIR__.'/../Fixtures/tdbmServiceProviderConfigStore.php')) {
            unlink(__DIR__.'/../Fixtures/tdbmServiceProviderConfigStore.php');
        }
    }


    public function testServiceProvider()
    {
        $container = $this->createContainer();

        $tdbmService = $container->get(TDBMService::class);
        /* @var $tdbmService TDBMService */
        $this->assertInstanceOf(TDBMService::class, $tdbmService);

        $connection = $container->get(Connection::class);
        /* @var $connection Connection */
        $schemaManager = $connection->getSchemaManager();

        $myTable = new Table("my_table");
        $myTable->addColumn("id", "integer", array("unsigned" => true));
        $myTable->addColumn("username", "string", array("length" => 32));
        $myTable->setPrimaryKey(array("id"));
        $myTable->addUniqueIndex(array("username"));

        $schemaManager->createTable($myTable);

        $tdbmService->generateAllDaosAndBeans();

        $this->assertFileExists(__DIR__.'/../../src/Beans/MyTable.php');
        $this->assertFileExists(__DIR__.'/../Fixtures/tdbmServiceProviderConfigStore.php');

        // Let's restart, with the file generated.
        $container = $this->createContainer();
        $tdbmService = $container->get(TDBMService::class);
        /* @var $tdbmService TDBMService */
        $this->assertInstanceOf(TDBMService::class, $tdbmService);
    }

    public function testCommand()
    {
        $container = $this->createContainer();

        $console = $container->get(Application::class);
        /* @var $console Application */

        $this->assertTrue($console->has('tdbm:generate'));
    }

    private function createContainer(): ContainerInterface
    {
        $serviceProviders = [];

        foreach (\TheCodingMachine\Discovery\Discovery::getInstance()->get(ServiceProviderInterface::class) as $serviceProviderName)
        {
            if ($serviceProviderName !== TdbmServiceProvider::class) {
                $serviceProviders[] = new $serviceProviderName();
            } else {
                $serviceProviders[] = new TdbmServiceProvider(__DIR__.'/../Fixtures/tdbmServiceProviderConfigStore.php');
            }
        }

        $container = new Container($serviceProviders);

        global $db_host, $db_username, $db_password, $db_name, $db_port;

        $container->set('dbal.dbname', $db_name);
        $container->set('dbal.host', $db_host);
        $container->set('dbal.user', $db_username);
        $container->set('dbal.password', $db_password);
        $container->set('dbal.port', $db_port);

        return $container;
    }

}
