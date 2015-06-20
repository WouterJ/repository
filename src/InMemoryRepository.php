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

use ArrayIterator;
use Puli\Repository\Api\EditableRepository;
use Puli\Repository\Api\Resource\Resource;
use Puli\Repository\Api\ResourceCollection;
use Puli\Repository\Api\ResourceNotFoundException;
use Puli\Repository\Api\UnsupportedLanguageException;
use Puli\Repository\Api\UnsupportedResourceException;
use Puli\Repository\Resource\Collection\ArrayResourceCollection;
use Puli\Repository\Resource\GenericResource;
use Webmozart\Assert\Assert;
use Webmozart\Glob\Iterator\GlobFilterIterator;
use Webmozart\Glob\Iterator\RegexFilterIterator;
use Webmozart\PathUtil\Path;

/**
 * An in-memory resource repository.
 *
 * Resources can be added with the method {@link add()}:
 *
 * ```php
 * use Puli\Repository\InMemoryRepository;
 *
 * $repo = new InMemoryRepository();
 * $repo->add('/css', new DirectoryResource('/path/to/project/res/css'));
 * ```
 *
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class InMemoryRepository implements EditableRepository
{
    /**
     * @var Resource[]
     */
    private $resources = array();

    /**
     * Creates a new repository.
     */
    public function __construct()
    {
        $this->clear();
    }

    /**
     * {@inheritdoc}
     */
    public function get($path)
    {
        Assert::stringNotEmpty($path, 'The path must be a non-empty string. Got: %s');
        Assert::startsWith($path, '/', 'The path %s is not absolute.');

        $path = Path::canonicalize($path);

        if (!isset($this->resources[$path])) {
            throw ResourceNotFoundException::forPath($path);
        }

        return $this->resources[$path];
    }

    /**
     * {@inheritdoc}
     */
    public function find($query, $language = 'glob')
    {
        if ('glob' !== $language) {
            throw UnsupportedLanguageException::forLanguage($language);
        }

        Assert::stringNotEmpty($query, 'The glob must be a non-empty string. Got: %s');
        Assert::startsWith($query, '/', 'The glob %s is not absolute.');

        $query = Path::canonicalize($query);
        $resources = array();

        if (false !== strpos($query, '*')) {
            $resources = $this->getGlobIterator($query);
        } elseif (isset($this->resources[$query])) {
            $resources = array($this->resources[$query]);
        }

        return new ArrayResourceCollection($resources);
    }

    /**
     * {@inheritdoc}
     */
    public function contains($query, $language = 'glob')
    {
        if ('glob' !== $language) {
            throw UnsupportedLanguageException::forLanguage($language);
        }

        Assert::stringNotEmpty($query, 'The glob must be a non-empty string. Got: %s');
        Assert::startsWith($query, '/', 'The glob %s is not absolute.');

        $query = Path::canonicalize($query);

        if (false !== strpos($query, '*')) {
            $iterator = $this->getGlobIterator($query);
            $iterator->rewind();

            return $iterator->valid();
        }

        return isset($this->resources[$query]);
    }

    /**
     * {@inheritdoc}
     */
    public function add($path, $resource)
    {
        Assert::stringNotEmpty($path, 'The path must be a non-empty string. Got: %s');
        Assert::startsWith($path, '/', 'The path %s is not absolute.');

        $path = Path::canonicalize($path);

        if ($resource instanceof ResourceCollection) {
            $this->ensureDirectoryExists($path);
            foreach ($resource as $child) {
                $this->addResource($path.'/'.$child->getName(), $child);
            }

            // Keep the resources sorted by file name
            ksort($this->resources);

            return;
        }

        if ($resource instanceof Resource) {
            $this->ensureDirectoryExists(Path::getDirectory($path));
            $this->addResource($path, $resource);

            ksort($this->resources);

            return;
        }

        throw new UnsupportedResourceException(sprintf(
            'The passed resource must be a Resource or ResourceCollection. Got: %s',
            is_object($resource) ? get_class($resource) : gettype($resource)
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function move($sourceQuery, $targetPath, $language = 'glob')
    {
        $iterator = $this->find($sourceQuery, $language);
        $moved = 0;

        Assert::notEq('', trim($sourceQuery, '/'), 'The root directory cannot be moved.');
        Assert::stringNotEmpty($targetPath, 'The target path must be a non-empty string. Got: %s');
        Assert::startsWith($targetPath, '/', 'The target path %s is not absolute.');

        $targetPath = Path::canonicalize($targetPath);

        $sources = iterator_to_array($iterator);

        if (1 === count($sources) && !$this->hasChildren($sources[0]->getPath())) {
            $this->moveResource($sources[0], $targetPath, $moved);
        } else {
            $this->ensureDirectoryExists($targetPath);

            foreach ($sources as $source) {
                $this->moveResource($source, $targetPath.'/'.Path::getFilename($source->getPath()), $moved);
            }
        }

        return $moved;
    }

    /**
     * {@inheritdoc}
     */
    public function remove($query, $language = 'glob')
    {
        $resources = $this->find($query, $language);
        $nbOfResources = count($this->resources);

        // Run the assertion after find(), so that we know that $query is valid
        Assert::notEq('', trim($query, '/'), 'The root directory cannot be removed.');

        foreach ($resources as $resource) {
            $this->removeResource($resource);
        }

        return $nbOfResources - count($this->resources);
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        $root = new GenericResource('/');
        $root->attachTo($this);

        // Subtract root
        $removed = count($this->resources) - 1;

        $this->resources = array('/' => $root);

        return $removed;
    }

    /**
     * {@inheritdoc}
     */
    public function listChildren($path)
    {
        $iterator = $this->getChildIterator($this->get($path));

        return new ArrayResourceCollection($iterator);
    }

    /**
     * {@inheritdoc}
     */
    public function hasChildren($path)
    {
        $iterator = $this->getChildIterator($this->get($path));
        $iterator->rewind();

        return $iterator->valid();
    }

    /**
     * Recursively creates a directory for a path.
     *
     * @param string $path A directory path.
     */
    private function ensureDirectoryExists($path)
    {
        if (!isset($this->resources[$path])) {
            // Recursively initialize parent directories
            if ($path !== '/') {
                $this->ensureDirectoryExists(Path::getDirectory($path));
            }

            $this->resources[$path] = new GenericResource($path);
            $this->resources[$path]->attachTo($this);

            return;
        }
    }

    private function addResource($path, Resource $resource)
    {
        // Don't modify resources attached to other repositories
        if ($resource->isAttached()) {
            $resource = clone $resource;
        }

        $basePath = '/' === $path ? $path : $path.'/';

        // Read children before attaching the resource to this repository
        $children = $resource->listChildren();

        $resource->attachTo($this, $path);

        // Add the resource before adding its children, so that the array
        // stays sorted
        $this->resources[$path] = $resource;

        foreach ($children as $name => $child) {
            $this->addResource($basePath.$name, $child);
        }
    }

    private function moveResource(Resource $resource, $targetPath, &$moved)
    {
        $sourcePath = $resource->getPath();

        foreach ($this->getChildIterator($resource) as $child) {
            $this->moveResource($child, $targetPath.'/'.Path::getFilename($child->getPath()), $moved);
        }

        $this->resources[$targetPath] = $this->resources[$sourcePath];

        ++$moved;
        unset($this->resources[$sourcePath]);
    }

    private function removeResource(Resource $resource)
    {
        $path = $resource->getPath();

        // Ignore non-existing resources
        if (!isset($this->resources[$path])) {
            return;
        }

        // Recursively register directory contents
        foreach ($this->getChildIterator($resource) as $child) {
            $this->removeResource($child);
        }

        unset($this->resources[$path]);

        // Detach from locator
        $resource->detach($this);
    }

    /**
     * Returns an iterator for the children of a resource.
     *
     * @param Resource $resource The resource.
     *
     * @return RegexFilterIterator|Resource[] The iterator.
     */
    private function getChildIterator(Resource $resource)
    {
        $staticPrefix = rtrim($resource->getPath(), '/').'/';
        $regExp = '~^'.preg_quote($staticPrefix, '~').'[^/]+$~';

        return new RegexFilterIterator(
            $regExp,
            $staticPrefix,
            new ArrayIterator($this->resources),
            RegexFilterIterator::FILTER_KEY
        );
    }

    /**
     * Returns an iterator for a glob.
     *
     * @param string $glob The glob.
     *
     * @return GlobFilterIterator|Resource[] The iterator.
     */
    protected function getGlobIterator($glob)
    {
        return new GlobFilterIterator(
            $glob,
            new ArrayIterator($this->resources),
            GlobFilterIterator::FILTER_KEY
        );
    }
}
