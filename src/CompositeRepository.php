<?php

/*
 * This file is part of the puli/repository package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Repository;

use Puli\Repository\Resource\Collection\ResourceCollection;
use Puli\Repository\Resource\Collection\ResourceCollectionInterface;
use Puli\Repository\Uri\RepositoryFactoryException;
use Webmozart\PathUtil\Path;

/**
 * A repository combining multiple other repository instances.
 *
 * You can mount repositories to specific paths in the composite repository.
 * Requests for these paths will then be routed to the mounted repository:
 *
 * ```php
 * use Puli\Repository\CompositeRepository;
 * use Puli\Repository\ResourceRepository;
 *
 * $puliRepo = new ResourceRepository();
 * $psr4Repo = new ResourceRepository();
 *
 * $repo = new CompositeRepository();
 * $repo->mount('/puli', $puliRepo);
 * $repo->mount('/psr4', $psr4Repo);
 *
 * $resource = $repo->get('/puli/css/style.css');
 * // => $puliRepo->get('/css/style.css');
 *
 * $resource = $repo->get('/psr4/Webmozart/Puli/Puli.php');
 * // => $psr4Repo->get('/Webmozart/Puli/Puli.php');
 * ```
 *
 * If not all repositories are needed in every request, you can pass callables
 * which create the repository on demand:
 *
 * ```php
 * use Puli\Repository\CompositeRepository;
 * use Puli\Repository\ResourceRepository;
 *
 * $repo = new CompositeRepository();
 * $repo->mount('/puli', function () {
 *     $repo = new ResourceRepository();
 *     // configuration...
 *
 *     return $repo;
 * });
 * ```
 *
 * If a path is accessed that is not mounted, the repository acts as if the
 * path did not exist.
 *
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class CompositeRepository implements ResourceRepositoryInterface
{
    /**
     * @var ResourceRepositoryInterface[]|callable[]
     */
    private $repos = array();

    /**
     * @var string[][]
     */
    private $shadows = array();

    /**
     * Mounts a repository to a path.
     *
     * The repository may either be passed as {@link ResourceRepositoryInterface}
     * or as callable. If a callable is passed, the callable is invoked as soon
     * as the scheme is used for the first time. The callable should return a
     * {@link ResourceRepositoryInterface} object.
     *
     * @param string                               $path              An absolute path.
     * @param callable|ResourceRepositoryInterface $repositoryFactory The repository to use.
     *
     * @throws InvalidPathException If the path is invalid. The path must be a
     *                              non-empty string starting with "/".
     * @throws \InvalidArgumentException If the repository factory is invalid.
     */
    public function mount($path, $repositoryFactory)
    {
        if (!$repositoryFactory instanceof ResourceRepositoryInterface
                && !is_callable($repositoryFactory)) {
            throw new \InvalidArgumentException(
                'The repository factory should be a callable or an instance '.
                'of "Puli\Repository\ResourceRepositoryInterface".'
            );
        }

        if ('' === $path) {
            throw new InvalidPathException('The mount point must not be empty.');
        }

        if (!is_string($path)) {
            throw new InvalidPathException(sprintf(
                'The mount point must be a string. Is: %s.',
                is_object($path) ? get_class($path) : gettype($path)
            ));
        }

        if ('/' !== $path[0]) {
            throw new InvalidPathException(sprintf(
                'The mount point "%s" is not absolute.',
                $path
            ));
        }

        $path = Path::canonicalize($path);

        $this->repos[$path] = $repositoryFactory;
        $this->shadows[$path] = array();

        // Prefer more specific mount points (e.g. "/app) over less specific
        // ones (e.g. "/")
        krsort($this->repos);

        $this->rebuildShadows();
    }

    /**
     * Unmounts the repository mounted at a path.
     *
     * If no repository is mounted to this path, this method does nothing.
     *
     * @param string $path The path of the mount point.
     *
     * @throws InvalidPathException If the path is invalid. The path must be a
     *                              non-empty string starting with "/".
     */
    public function unmount($path)
    {
        if ('' === $path) {
            throw new InvalidPathException('The mount point must not be empty.');
        }

        if (!is_string($path)) {
            throw new InvalidPathException(sprintf(
                'The mount point must be a string. Is: %s.',
                is_object($path) ? get_class($path) : gettype($path)
            ));
        }

        if ('/' !== $path[0]) {
            throw new InvalidPathException(sprintf(
                'The mount point "%s" is not absolute.',
                $path
            ));
        }

        $path = Path::canonicalize($path);

        unset($this->repos[$path]);
        unset($this->shadows[$path]);

        $this->rebuildShadows();
    }

    /**
     * {@inheritdoc}
     */
    public function get($path)
    {
        list ($mountPoint, $subPath) = $this->splitPath($path);

        if (null === $mountPoint) {
            throw new ResourceNotFoundException(sprintf(
                'Could not find a matching mount point for the path "%s".',
                $path
            ));
        }

        $resource = $this->getRepository($mountPoint)->get($subPath);

        return '/' === $mountPoint ? $resource : $resource->createReference($path);
    }

    /**
     * {@inheritdoc}
     */
    public function find($selector)
    {
        list ($mountPoint, $subSelector) = $this->splitPath($selector);

        if (null === $mountPoint) {
            return new ResourceCollection();
        }

        $resources = $this->getRepository($mountPoint)->find($subSelector);
        $this->replaceByReferences($resources, $mountPoint);

        return $resources;
    }

    /**
     * {@inheritdoc}
     */
    public function contains($selector)
    {
        list ($mountPoint, $subSelector) = $this->splitPath($selector);

        if (null === $mountPoint) {
            return false;
        }

        return $this->getRepository($mountPoint)->contains($subSelector);
    }

    /**
     * {@inheritdoc}
     */
    public function listDirectory($path)
    {
        list ($mountPoint, $subPath) = $this->splitPath($path);

        if (null === $mountPoint) {
            throw new ResourceNotFoundException(sprintf(
                'Could not find a matching mount point for the path "%s".',
                $path
            ));
        }

        $resources = $this->getRepository($mountPoint)->listDirectory($subPath);
        $this->replaceByReferences($resources, $mountPoint);

        return $resources;
    }

    /**
     * Splits a path into mount point and path.
     *
     * @param string $path The path to split.
     *
     * @return array An array with the mount point and the path. If no mount
     *               point was found, both are `null`.
     */
    private function splitPath($path)
    {
        if ('' === $path) {
            throw new InvalidPathException('The mount point must not be empty.');
        }

        if (!is_string($path)) {
            throw new InvalidPathException(sprintf(
                'The mount point must be a string. Is: %s.',
                is_object($path) ? get_class($path) : gettype($path)
            ));
        }

        if ('/' !== $path[0]) {
            throw new InvalidPathException(sprintf(
                'The mount point "%s" is not absolute.',
                $path
            ));
        }

        $path = Path::canonicalize($path);

        foreach ($this->repos as $mountPoint => $_) {
            if (Path::isBasePath($mountPoint, $path)) {
                // Special case "/": return the complete path
                if ('/' === $mountPoint) {
                    return array($mountPoint, $path);
                }

                return array($mountPoint, substr($path, strlen($mountPoint)));
            }
        }

        return array(null, null);
    }

    /**
     * If necessary constructs and returns the repository for the given mount
     * point.
     *
     * @param string $mountPoint An existing mount point.
     *
     * @return ResourceRepositoryInterface The resource repository.
     *
     * @throws RepositoryFactoryException If the callable did not return an
     *                                    instance of {@link ResourceRepositoryInterface}.
     */
    private function getRepository($mountPoint)
    {
        if (is_callable($this->repos[$mountPoint])) {
            $callable = $this->repos[$mountPoint];
            $result = $callable($mountPoint);

            if (!$result instanceof ResourceRepositoryInterface) {
                throw new RepositoryFactoryException(sprintf(
                    'The value of type "%s" returned by the locator factory '.
                    'registered for the mount point "%s" does not implement '.
                    '"\Puli\Repository\ResourceRepositoryInterface".',
                    gettype($result),
                    $mountPoint
                ));
            }

            $this->repos[$mountPoint] = $result;
        }

        return $this->repos[$mountPoint];
    }

    /**
     * Filters out overshadowed resources from a resource collection.
     *
     * Read {@link rebuildShadows()} to learn more about shadows.
     *
     * @param ResourceCollectionInterface $resources  The resources to filter.
     * @param string                      $mountPoint The mount point from which
     *                                                the resources were loaded.
     */
    private function filterOvershadowedResources(ResourceCollectionInterface $resources, $mountPoint)
    {
        foreach ($resources as $key => $resource) {
            $path = $resource->getPath();

            foreach ($this->shadows[$mountPoint] as $shadow) {
                if (Path::isBasePath($shadow, $path)) {
                    unset($resources[$key]);
                }
            }
        }
    }

    /**
     * Replaces all resources in the collection by references.
     *
     * If a resource "/resource" was loaded from a mount point "/mount", the
     * resource is replaced by a reference with the path "/mount/resource".
     *
     * @param ResourceCollectionInterface $resources  The resources to replace.
     * @param string                      $mountPoint The mount point from which
     *                                                the resources were loaded.
     */
    private function replaceByReferences(ResourceCollectionInterface $resources, $mountPoint)
    {
        if ('/' !== $mountPoint) {
            foreach ($resources as $key => $resource) {
                $resources[$key] = $resource->createReference($mountPoint.$resource->getPath());
            }
        }
    }

    /**
     * Rebuilds the shadows of the mount points.
     *
     * This method should be called after adding or removing mount points.
     *
     * Shadows are mount points that are located within another mount point.
     * For example, if the paths "/app" and "/app/data" are mounted, then
     * "/data" is a shadow of "/app". This means that Resources in the "/data"
     * directory are overshadowed by the resources in the "/app/data"
     * repository.
     *
     * Overshadowed resources are filtered out from the results of all methods.
     */
    private function rebuildShadows()
    {
        $mountPoints = array_keys($this->shadows);

        foreach ($mountPoints as $mountPoint) {
            $this->shadows[$mountPoint] = array();

            foreach ($mountPoints as $maybeShadow) {
                if ($mountPoint === $maybeShadow) {
                    continue;
                }

                // $mountPoint = "/app"
                // $maybeShadow = "/app/data"
                // => $maybeShadow is a shadow

                // $mountPoint = "/app"
                // $maybeShadow = "/data"
                // => $maybeShadow is not a shadow

                // The root "/" is overshadowed by all other mount points
                if ('/' === $mountPoint) {
                    $this->shadows[$mountPoint][] = $maybeShadow;
                } elseif (Path::isBasePath($mountPoint, $maybeShadow)) {
                    // Only store "/data" for the shadow "/app/data" in "/app"
                    $this->shadows[$mountPoint][] = substr($maybeShadow, strlen($mountPoint));
                }
            }
        }
    }
}