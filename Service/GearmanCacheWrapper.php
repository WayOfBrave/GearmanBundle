<?php

/**
 * Gearman Bundle for Symfony2
 * 
 * @author Marc Morera <yuhu@mmoreram.com>
 * @since 2013
 */

namespace Mmoreram\GearmanBundle\Service;

use Symfony\Component\Config\FileLocator;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\AnnotationDirectoryLoader;
use Doctrine\Common\Cache\Cache;
use Symfony\Component\Finder\Finder;

use Mmoreram\GearmanBundle\Module\WorkerCollection;
use Mmoreram\GearmanBundle\Module\WorkerDirectoryLoader;
use Mmoreram\GearmanBundle\Module\WorkerClass as Worker;
use Mmoreram\GearmanBundle\Driver\Gearman\Work;
use ReflectionClass;

/**
 * Gearman cache loader class
 *
 * @author Marc Morera <yuhu@mmoreram.com>
 */
class GearmanCacheWrapper
{

    /**
     * Bundles loaded by kernel
     *
     * @var Array
     */
    private $kernelBundles;


    /**
     * Bundles available to perform search
     *
     * @var Array
     */
    private $bundles;


    /**
     * @var Array
     *
     * paths to search on
     */
    private $paths = array();


    /**
     * @var Array
     *
     * paths to ignore
     */
    private $excludedPaths = array();


    /**
     * @var GearmanCache
     *
     * Gearman Cache
     */
    private $cache;


    /**
     * @var string
     * 
     * Gearman cache id
     */
    private $cacheId;


    /**
     * @var array
     * 
     * WorkerCollection with all workers and jobs available
     */
    private $workerCollection;


    /**
     * @var array
     * 
     * Collection of servers to connect
     */
    private $servers;


    /**
     * @var array
     * 
     * Default settings defined by user in config.yml
     */
    private $defaultSettings;


    /**
     * Return workerCollection
     * 
     * @return array all available workers
     */
    public function getWorkers()
    {
        return $this->workerCollection;
    }


    /**
     * Construct method
     *
     * @param Kernel $kernel          Kernel instance
     * @param Cache  $cache           Cache
     * @param string $cacheId         Cache id where to save parsing data
     * @param array  $bundles         Bundle array where to parse workers, defined on condiguration
     * @param array  $servers         Server list defined on configuration
     * @param array  $defaultSettings Default settings defined on configuration
     */
    public function __construct(Kernel $kernel, Cache $cache, $cacheId, array $bundles, array $servers, array $defaultSettings)
    {
        $this->kernelBundles = $kernel->getBundles();
        $this->bundles = $bundles;
        $this->cache = $cache;
        $this->cacheId = $cacheId;
        $this->servers = $servers;
        $this->defaultSettings = $defaultSettings;
    }


    /**
     * loads Gearman cache, only if is not loaded yet
     *
     * @return GearmanCacheLoader self Object
     */
    public function load()
    {
        if ($this->cache->contains($this->cacheId)) {

            $this->workerCollection = $this->cache->get($this->cacheId);

        } else {

            $this->workerCollection = $this->parseNamespaceMap()->toArray();
            $this->cache->save($this->cacheId, $this->workerCollection);
        }

        return $this;
    }


    /**
     * flush all cache
     * 
     * @return GearmanCacheLoader self Object
     */
    public function flush()
    {
        $this->cache->delete($this->cacheId);

        return $this;
    }


    /**
     * Return Gearman bundle settings, previously loaded by method load()
     * If settings are not loaded, a SettingsNotLoadedException Exception is thrown
     *
     * @return array Bundles that gearman will be able to search annotations
     */
    public function loadNamespaceMap()
    {
        foreach ($this->bundles as $bundleSettings) {

            $bundleNamespace = $bundleSettings['name'];

            if ($bundleSettings['active']) {

                $bundlePath = $this->kernelBundles[$bundleNamespace]->getPath();

                if (!empty($bundleSettings['include'])) {

                    foreach ($bundleSettings['include'] as $include) {

                        $this->paths[] = rtrim(rtrim($bundlePath, '/') . '/' . $include, '/') . '/';
                    }

                } else {

                    /**
                     * If no include is set, include all namespace
                     */
                    $this->paths[] = rtrim($bundlePath, '/') . '/';
                }

                foreach ($bundleSettings['ignore'] as $ignore) {

                    $this->excludedPaths[] = trim($ignore, '/');
                }
            }
        }
    }


    /**
     * Perform a parsing inside all namespace map
     *
     * @return WorkerCollection collection of all info
     */
    private function parseNamespaceMap()
    {
        AnnotationRegistry::registerFile(__DIR__ . "/../Driver/Gearman/Work.php");
        AnnotationRegistry::registerFile(__DIR__ . "/../Driver/Gearman/Job.php");

        /**
         * Depending on Symfony2 version
         */
        if (version_compare(\Doctrine\Common\Version::VERSION, '2.2.0-DEV', '>=')) {

            $reader = new \Doctrine\Common\Annotations\SimpleAnnotationReader();
            $reader->addNamespace('Mmoreram\GearmanBundle\Driver');
        } else {

            $reader = new AnnotationReader();
            $reader->setDefaultAnnotationNamespace('Mmoreram\GearmanBundle\Driver\\');
        }

        $workerCollection = new WorkerCollection;
        $finder = new Finder();
        $finder
            ->files()
            ->followLinks()
            ->exclude($this->excludedPaths)
            ->in($this->paths);

        foreach ($finder as $file) {

            /**
             * File is accepted to be parsed
             */
            $classNamespace = $this->getFileClassNamespace($file->getRealpath());
            $reflClass = new ReflectionClass($classNamespace);
            $classAnnotations = $reader->getClassAnnotations($reflClass);

            foreach ($classAnnotations as $annot) {

                if ($annot instanceof Work) {

                    $worker = new Worker($annot, $reflClass, $reader, $this->servers, $this->defaultSettings);
                    $workerCollection->add($worker);
                }
            }
        }

        return $workerCollection;
    }



    /**
     * Returns the full class name for the first class in the file.
     *
     * @param string $file A PHP file path
     *
     * @return string|false Full class name if found, false otherwise
     */
    protected function getFileClassNamespace($file)
    {
        $class = false;
        $namespace = false;
        $tokens = token_get_all(file_get_contents($file));
        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            if (true === $class && T_STRING === $token[0]) {
                return $namespace.'\\'.$token[1];
            }

            if (true === $namespace && T_STRING === $token[0]) {
                $namespace = '';
                do {
                    $namespace .= $token[1];
                    $token = $tokens[++$i];
                } while ($i < $count && is_array($token) && in_array($token[0], array(T_NS_SEPARATOR, T_STRING)));
            }

            if (T_CLASS === $token[0]) {
                $class = true;
            }

            if (T_NAMESPACE === $token[0]) {
                $namespace = true;
            }
        }

        return false;
    }
}