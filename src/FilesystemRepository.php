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

use Iterator;
use Puli\Repository\Api\EditableRepository;
use Puli\Repository\Api\Resource\BodyResource;
use Puli\Repository\Api\Resource\FilesystemResource;
use Puli\Repository\Api\Resource\Resource;
use Puli\Repository\Api\ResourceCollection;
use Puli\Repository\Api\ResourceNotFoundException;
use Puli\Repository\Api\UnsupportedLanguageException;
use Puli\Repository\Api\UnsupportedOperationException;
use Puli\Repository\Api\UnsupportedResourceException;
use Puli\Repository\Resource\Collection\FilesystemResourceCollection;
use Puli\Repository\Resource\DirectoryResource;
use Puli\Repository\Resource\FileResource;
use RecursiveIteratorIterator;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Webmozart\Assert\Assert;
use Webmozart\Glob\Iterator\GlobIterator;
use Webmozart\Glob\Iterator\RecursiveDirectoryIterator;
use Webmozart\PathUtil\Path;

/**
 * A repository reading from the file system.
 *
 * Resources can be read using their absolute file system paths:
 *
 * ```php
 * use Puli\Repository\FilesystemRepository;
 *
 * $repo = new FilesystemRepository();
 * $resource = $repo->get('/home/puli/.gitconfig');
 * ```
 *
 * The returned resources implement {@link FilesystemResource}.
 *
 * Optionally, a root directory can be passed to the constructor. Then all paths
 * will be read relative to that directory:
 *
 * ```php
 * $repo = new FilesystemRepository('/home/puli');
 * $resource = $repo->get('/.gitconfig');
 * ```
 *
 * While "." and ".." segments are supported, files outside the root directory
 * cannot be read. Any leading ".." segments will simply be stripped off.
 *
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class FilesystemRepository implements EditableRepository
{
    /**
     * @var bool|null
     */
    private static $symlinkSupported;

    /**
     * @var string
     */
    private $baseDir;

    /**
     * @var bool
     */
    private $symlink;

    /**
     * @var bool
     */
    private $relative;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * Returns whether symlinks are supported in the local environment.
     *
     * @return bool Returns `true` if symlinks are supported.
     */
    public static function isSymlinkSupported()
    {
        if (null === self::$symlinkSupported) {
            // http://php.net/manual/en/function.symlink.php
            // Symlinks are only supported on Windows Vista, Server 2008 or
            // greater on PHP 5.3+
            if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
                self::$symlinkSupported = PHP_WINDOWS_VERSION_MAJOR >= 6;
            } else {
                self::$symlinkSupported = true;
            }
        }

        return self::$symlinkSupported;
    }

    /**
     * Creates a new repository.
     *
     * @param string $baseDir  The base directory of the repository on the file
     *                         system.
     * @param bool   $symlink  Whether to use symbolic links for added files. If
     *                         symbolic links are not supported on the current
     *                         system, the repository will create hard copies
     *                         instead.
     * @param bool   $relative Whether to create relative symbolic links. If
     *                         relative links are not supported on the current
     *                         system, the repository will create absolute links
     *                         instead.
     */
    public function __construct($baseDir = '/', $symlink = true, $relative = true)
    {
        Assert::directory($baseDir);
        Assert::boolean($symlink);

        $this->baseDir = rtrim(Path::canonicalize($baseDir), '/');
        $this->symlink = $symlink && self::isSymlinkSupported();
        $this->relative = $this->symlink && $relative;
        $this->filesystem = new Filesystem();
    }

    /**
     * {@inheritdoc}
     */
    public function get($path)
    {
        Assert::stringNotEmpty($path, 'The path must be a non-empty string. Got: %s');
        Assert::startsWith($path, '/', 'The path %s is not absolute.');

        $path = Path::canonicalize($path);
        $filesystemPath = $this->baseDir.$path;

        if (!file_exists($filesystemPath)) {
            throw ResourceNotFoundException::forPath($path);
        }

        return $this->createResource($filesystemPath, $path);
    }

    /**
     * {@inheritdoc}
     */
    public function find($query, $language = 'glob')
    {
        return $this->iteratorToCollection($this->getGlobIterator($query, $language));
    }

    /**
     * {@inheritdoc}
     */
    public function contains($query, $language = 'glob')
    {
        $iterator = $this->getGlobIterator($query, $language);
        $iterator->rewind();

        return $iterator->valid();
    }

    /**
     * {@inheritdoc}
     */
    public function hasChildren($path)
    {
        $filesystemPath = $this->getFilesystemPath($path);

        if (!is_dir($filesystemPath)) {
            return false;
        }

        $iterator = new RecursiveDirectoryIterator($filesystemPath);
        $iterator->rewind();

        return $iterator->valid();
    }

    /**
     * {@inheritdoc}
     */
    public function listChildren($path)
    {
        $filesystemPath = $this->getFilesystemPath($path);

        if (!is_dir($filesystemPath)) {
            return new FilesystemResourceCollection();
        }

        return $this->iteratorToCollection(new RecursiveDirectoryIterator($filesystemPath));
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

            return;
        }

        if ($resource instanceof Resource) {
            $this->ensureDirectoryExists(Path::getDirectory($path));
            $this->addResource($path, $resource);

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
    public function remove($query, $language = 'glob')
    {
        $iterator = $this->getGlobIterator($query, $language);
        $removed = 0;

        Assert::notEq('', trim($query, '/'), 'The root directory cannot be removed.');

        // There's some problem with concurrent deletions at the moment
        foreach (iterator_to_array($iterator) as $filesystemPath) {
            $this->removeResource($filesystemPath, $removed);
        }

        return $removed;
    }

    /**
     * {@inheritdoc}
     */
    public function move($sourceQuery, $targetPath, $language = 'glob')
    {
        $iterator = $this->getGlobIterator($sourceQuery, $language);
        $moved = 0;

        Assert::notEq('', trim($sourceQuery, '/'), 'The root directory cannot be moved.');
        Assert::stringNotEmpty($targetPath, 'The target path must be a non-empty string. Got: %s');
        Assert::startsWith($targetPath, '/', 'The target path %s is not absolute.');

        $targetPath = Path::canonicalize($targetPath);

        $sourcePaths = iterator_to_array($iterator);

        if (1 === count($sourcePaths) && is_file($sourcePaths[0])) {
            $this->moveResource($sourcePaths[0], $targetPath, $moved);
        } else {
            $this->ensureDirectoryExists($targetPath);

            foreach ($sourcePaths as $sourcePath) {
                $this->moveResource($sourcePath, $targetPath.'/'.Path::getFilename($sourcePath), $moved);
            }
        }

        return $moved;
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        $iterator = new RecursiveDirectoryIterator($this->baseDir);
        $removed = 0;

        foreach ($iterator as $filesystemPath) {
            $this->removeResource($filesystemPath, $removed);
        }

        return $removed;
    }

    private function ensureDirectoryExists($path)
    {
        $filesystemPath = $this->baseDir.$path;

        if (is_file($filesystemPath)) {
            throw new UnsupportedOperationException(sprintf(
                'Instances of BodyResource do not support child resources in '.
                'FilesystemRepository. Tried to add a child to %s.',
                $filesystemPath
            ));
        }

        if (!is_dir($filesystemPath)) {
            mkdir($filesystemPath, 0777, true);
        }
    }

    private function addResource($path, Resource $resource, $checkParentsForSymlinks = true)
    {
        $pathInBaseDir = $this->baseDir.$path;
        $hasChildren = $resource->hasChildren();
        $hasBody = $resource instanceof BodyResource;

        if ($hasChildren && $hasBody) {
            throw new UnsupportedResourceException(sprintf(
                'Instances of BodyResource do not support child resources in '.
                'FilesystemRepository. Tried to add a BodyResource with '.
                'children at %s.',
                $path
            ));
        }

        if ($this->symlink && $checkParentsForSymlinks) {
            $this->replaceParentSymlinksByCopies($path);
        }

        if ($resource instanceof FilesystemResource) {
            if ($this->symlink) {
                $this->symlinkMirror($resource->getFilesystemPath(), $pathInBaseDir);
            } elseif ($hasBody) {
                $this->filesystem->copy($resource->getFilesystemPath(), $pathInBaseDir);
            } else {
                $this->filesystem->mirror($resource->getFilesystemPath(), $pathInBaseDir);
            }

            return;
        }

        if ($hasBody) {
            file_put_contents($pathInBaseDir, $resource->getBody());

            return;
        }

        if (is_file($pathInBaseDir)) {
            $this->filesystem->remove($pathInBaseDir);
        }

        if (!file_exists($pathInBaseDir)) {
            mkdir($pathInBaseDir, 0777, true);
        }

        foreach ($resource->listChildren() as $child) {
            $this->addResource($path.'/'.$child->getName(), $child, false);
        }
    }

    private function removeResource($filesystemPath, &$removed)
    {
        // Skip paths that have already been removed
        if (!file_exists($filesystemPath)) {
            return;
        }

        ++$removed;

        if (is_dir($filesystemPath)) {
            $removed += $this->countChildren($filesystemPath);
        }

        $this->filesystem->remove($filesystemPath);
    }

    private function moveResource($sourcePath, $targetPath, &$moved)
    {
        $targetPathInBaseDir = $this->baseDir.$targetPath;
        ++$moved;

        if (is_dir($sourcePath)) {
            $moved += $this->countChildren($sourcePath);
        }

        $this->filesystem->rename($sourcePath, $targetPathInBaseDir);
    }

    private function createResource($filesystemPath, $path)
    {
        $resource = is_dir($filesystemPath)
            ? new DirectoryResource($filesystemPath)
            : new FileResource($filesystemPath);

        $resource->attachTo($this, $path);

        return $resource;
    }

    private function countChildren($filesystemPath)
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($filesystemPath),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $iterator->rewind();
        $count = 0;

        while ($iterator->valid()) {
            $count++;
            $iterator->next();
        }

        return $count;
    }

    private function iteratorToCollection(Iterator $iterator)
    {
        $offset = strlen($this->baseDir);
        $filesystemPaths = iterator_to_array($iterator);
        $resources = array();

        // RecursiveDirectoryIterator is not guaranteed to return sorted results
        sort($filesystemPaths);

        foreach ($filesystemPaths as $filesystemPath) {
            $path = substr($filesystemPath, $offset);

            $resource = is_dir($filesystemPath)
                ? new DirectoryResource($filesystemPath, $path)
                : new FileResource($filesystemPath, $path);

            $resource->attachTo($this);

            $resources[] = $resource;
        }

        return new FilesystemResourceCollection($resources);
    }

    private function getFilesystemPath($path)
    {
        Assert::stringNotEmpty($path, 'The path must be a non-empty string. Got: %s');
        Assert::startsWith($path, '/', 'The path %s is not absolute.');

        $path = Path::canonicalize($path);
        $filesystemPath = $this->baseDir.$path;

        if (!file_exists($filesystemPath)) {
            throw ResourceNotFoundException::forPath($path);
        }

        return $filesystemPath;
    }

    private function getGlobIterator($query, $language)
    {
        if ('glob' !== $language) {
            throw UnsupportedLanguageException::forLanguage($language);
        }

        Assert::stringNotEmpty($query, 'The glob must be a non-empty string. Got: %s');
        Assert::startsWith($query, '/', 'The glob %s is not absolute.');

        $query = Path::canonicalize($query);

        return new GlobIterator($this->baseDir.$query);
    }

    private function symlinkMirror($origin, $target, array $dirsToKeep = array())
    {
        $targetIsDir = is_dir($target);
        $forceDir = in_array($target, $dirsToKeep, true);

        // Merge directories
        if (is_dir($origin) && ($targetIsDir || $forceDir)) {
            if (is_link($target)) {
                $this->replaceLinkByCopy($target, $dirsToKeep);
            }

            $iterator = new RecursiveDirectoryIterator(
                $origin,
                RecursiveDirectoryIterator::CURRENT_AS_FILE
            );

            foreach ($iterator as $path => $filename) {
                $this->symlinkMirror($path, $target.'/'.$filename, $dirsToKeep);
            }

            return;
        }

        // Try creating a relative link
        if ($this->relative && $this->trySymlink(Path::makeRelative($origin, Path::getDirectory($target)), $target)) {
            return;
        }

        // Try creating a absolute link
        if ($this->trySymlink($origin, $target)) {
            return;
        }

        // Fall back to copy
        if (is_dir($origin)) {
            $this->filesystem->mirror($origin, $target);

            return;
        }

        $this->filesystem->copy($origin, $target);
    }

    private function replaceParentSymlinksByCopies($path)
    {
        $previousPath = null;

        // Collect all paths that MUST NOT be symlinks after doing the
        // replace operation.
        //
        // Example:
        //
        // $dirsToKeep = ['/path/to/webmozart', '/path/to/webmozart/views']
        //
        // Before:
        //   /webmozart -> target
        //
        // After:
        //   /webmozart
        //     /config -> target/config
        //     /views
        //       /index.html.twig -> target/views/index.html.twig

        $dirsToKeep = array();

        while ($previousPath !== ($path = Path::getDirectory($path))) {
            $filesystemPath = $this->baseDir.$path;
            $dirsToKeep[] = $filesystemPath;

            if (is_link($filesystemPath)) {
                $this->replaceLinkByCopy($filesystemPath, $dirsToKeep);

                return;
            }

            $previousPath = $path;
        }
    }

    private function replaceLinkByCopy($path, array $dirsToKeep = array())
    {
        $target = Path::makeAbsolute(readlink($path), Path::getDirectory($path));
        $this->filesystem->remove($path);
        $this->filesystem->mkdir($path);
        $this->symlinkMirror($target, $path, $dirsToKeep);
    }

    private function trySymlink($origin, $target)
    {
        try {
            $this->filesystem->symlink($origin, $target);

            if (file_exists($target)) {
                return true;
            }
        } catch (IOException $e) {
        }

        return false;
    }
}
