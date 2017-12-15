<?php
namespace TheCodingMachine\TDBM\Services;

use Symfony\Component\Filesystem\Filesystem;
use TheCodingMachine\TDBM\ConfigurationInterface;
use TheCodingMachine\TDBM\Utils\BeanDescriptorInterface;
use TheCodingMachine\TDBM\Utils\GeneratorListenerInterface;

/**
 * Dumps the list of DAOs in a file. Useful for registering DAOs on the fly in Pimple.
 */
class DaoDumper implements GeneratorListenerInterface
{
    /**
     * @var string
     */
    private $dumpFilePath;

    public function __construct(string $dumpFilePath)
    {
        $this->dumpFilePath = $dumpFilePath;
    }

    /**
     * @param ConfigurationInterface $configuration
     * @param BeanDescriptorInterface[] $beanDescriptors
     */
    public function onGenerate(ConfigurationInterface $configuration, array $beanDescriptors): void
    {
        $daos = [];

        foreach ($beanDescriptors as $beanDescriptor) {
            $daoClassName = $beanDescriptor->getDaoClassName();

            $daos[$daoClassName] = $configuration->getDaoNamespace().'\\'.$daoClassName;
        }

        $this->dumpFile($configuration, $daos);
    }

    private function dumpFile(ConfigurationInterface $configuration, array $daos) {
        $fileSystem = new Filesystem();
        $exportedDaoNamespace = var_export($configuration->getDaoNamespace(), true);
        $exportedBeanNamespace = var_export($configuration->getBeanNamespace(), true);

        $daosDeclaration = '';
        $methods = '';

        foreach ($daos as $shortName => $fqcn) {
            $methods .= <<<EOF
    public static function create$shortName(ContainerInterface \$container): \\$fqcn
    {
        return new \\$fqcn(\$container->get(TDBMService::class));
    }

EOF;
            $daosDeclaration .= '        '.var_export($fqcn, true).' => [ __CLASS__, "create'.$shortName.'"],'."\n";
        }



        $file = <<<EOF
<?php
use Psr\Container\ContainerInterface;
use TheCodingMachine\TDBM\TDBMService;

return new class {
    public static function getDaoNamespace(): string
    {
        return $exportedDaoNamespace;
    }

    public static function getBeanNamespace(): string
    {
        return $exportedBeanNamespace;
    }

    public static function getFactories(): array
    {
        return [
$daosDeclaration
        ];
    }
    
$methods
};

EOF;

        $fileSystem->dumpFile($this->dumpFilePath, $file);
    }
}
