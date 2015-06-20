<?php

/*
 * This file is part of the puli/repository package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Repository\Tests;

use Puli\Repository\Api\EditableRepository;
use Puli\Repository\Resource\Collection\ArrayResourceCollection;
use Puli\Repository\Tests\Resource\TestDirectory;
use Puli\Repository\Tests\Resource\TestFile;

/**
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
abstract class AbstractEditableRepositoryTest extends AbstractRepositoryTest
{
    /**
     * The instance used to change the contents of the repository.
     *
     * @var EditableRepository
     */
    protected $writeRepo;

    /**
     * The instance used to check the changed contents of the repository.
     *
     * This is either the same instance as {@link $writeRepo} or a different
     * instance that uses the same data source.
     *
     * @var EditableRepository
     */
    protected $readRepo;

    /**
     * @return EditableRepository
     */
    abstract protected function createWriteRepository();

    /**
     * @param EditableRepository $writeRepo
     *
     * @return EditableRepository
     */
    abstract protected function createReadRepository(EditableRepository $writeRepo);

    protected function setUp()
    {
        parent::setUp();

        $this->writeRepo = $this->createWriteRepository();
        $this->readRepo = $this->createReadRepository($this->writeRepo);
    }

    public function testRootIsEmptyBeforeAdding()
    {
        $root = $this->readRepo->get('/');

        $this->assertInstanceOf('Puli\Repository\Api\Resource\Resource', $root);
        $this->assertCount(0, $root->listChildren());
        $this->assertSame('/', $root->getPath());
    }

    public function testAddFile()
    {
        $this->writeRepo->add('/webmozart/puli', new TestDirectory());
        $this->writeRepo->add('/webmozart/puli/file', new TestFile());

        $dir = $this->readRepo->get('/webmozart/puli');
        $file = $this->readRepo->get('/webmozart/puli/file');

        $this->assertInstanceOf('Puli\Repository\Api\Resource\Resource', $dir);
        $this->assertSame('/webmozart/puli', $dir->getPath());
        $this->assertSame($this->readRepo, $dir->getRepository());

        $this->assertInstanceOf('Puli\Repository\Api\Resource\BodyResource', $file);
        $this->assertSame('/webmozart/puli/file', $file->getPath());
        $this->assertSame($this->readRepo, $file->getRepository());
        $this->assertSame(TestFile::BODY, $file->getBody());
    }

    public function testAddMergesResourceChildren()
    {
        $this->writeRepo->add('/webmozart/puli', new TestDirectory(null, array(
            new TestFile('/file1', 'original 1'),
            new TestFile('/file2', 'original 2'),
        )));
        $this->writeRepo->add('/webmozart/puli', new TestDirectory(null, array(
            new TestFile('/file1', 'override 1'),
            new TestFile('/file3', 'override 3'),
        )));

        $dir = $this->readRepo->get('/webmozart/puli');
        $file1 = $this->readRepo->get('/webmozart/puli/file1');
        $file2 = $this->readRepo->get('/webmozart/puli/file2');
        $file3 = $this->readRepo->get('/webmozart/puli/file3');

        $this->assertTrue($this->readRepo->hasChildren('/webmozart/puli'));
        $this->assertCount(3, $this->readRepo->listChildren('/webmozart/puli'));

        $this->assertInstanceOf('Puli\Repository\Api\Resource\Resource', $dir);
        $this->assertSame('/webmozart/puli', $dir->getPath());

        $this->assertInstanceOf('Puli\Repository\Api\Resource\BodyResource', $file1);
        $this->assertSame('/webmozart/puli/file1', $file1->getPath());
        $this->assertSame('override 1', $file1->getBody());

        $this->assertInstanceOf('Puli\Repository\Api\Resource\BodyResource', $file2);
        $this->assertSame('/webmozart/puli/file2', $file2->getPath());
        $this->assertSame('original 2', $file2->getBody());

        $this->assertInstanceOf('Puli\Repository\Api\Resource\BodyResource', $file3);
        $this->assertSame('/webmozart/puli/file3', $file3->getPath());
        $this->assertSame('override 3', $file3->getBody());
    }

    public function testAddDot()
    {
        $this->writeRepo->add('/webmozart/puli/file/.', new TestFile());

        $file = $this->readRepo->get('/webmozart/puli/file');

        $this->assertInstanceOf('Puli\Repository\Api\Resource\BodyResource', $file);
        $this->assertSame('/webmozart/puli/file', $file->getPath());
    }

    public function testAddDotDot()
    {
        $this->writeRepo->add('/webmozart/puli/file/..', new TestFile());

        $file = $this->readRepo->get('/webmozart/puli');

        $this->assertInstanceOf('Puli\Repository\Api\Resource\BodyResource', $file);
        $this->assertSame('/webmozart/puli', $file->getPath());
    }

    public function testAddTrimsTrailingSlash()
    {
        $this->writeRepo->add('/webmozart/puli/file/', new TestFile());

        $file = $this->readRepo->get('/webmozart/puli/file');

        $this->assertInstanceOf('Puli\Repository\Api\Resource\BodyResource', $file);
        $this->assertSame('/webmozart/puli/file', $file->getPath());
    }

    public function testAddCollection()
    {
        $this->writeRepo->add('/webmozart/puli', new ArrayResourceCollection(array(
            new TestFile('/file1'),
            new TestFile('/file2'),
        )));

        $file1 = $this->readRepo->get('/webmozart/puli/file1');
        $file2 = $this->readRepo->get('/webmozart/puli/file2');

        $this->assertInstanceOf('Puli\Repository\Api\Resource\BodyResource', $file1);
        $this->assertSame('/webmozart/puli/file1', $file1->getPath());

        $this->assertInstanceOf('Puli\Repository\Api\Resource\BodyResource', $file2);
        $this->assertSame('/webmozart/puli/file2', $file2->getPath());
    }

    public function testAddRoot()
    {
        $this->writeRepo->add('/', new TestDirectory('/', array(
            new TestDirectory('/webmozart', array(
                new TestFile('/webmozart/file'),
            )),
        )));

        $root = $this->readRepo->get('/');
        $dir = $this->readRepo->get('/webmozart');
        $file = $this->readRepo->get('/webmozart/file');

        $this->assertInstanceOf('Puli\Repository\Api\Resource\Resource', $root);
        $this->assertSame('/', $root->getPath());
        $this->assertSame($this->readRepo, $root->getRepository());
        $this->assertCount(1, $root->listChildren());

        $this->assertInstanceOf('Puli\Repository\Api\Resource\Resource', $dir);
        $this->assertSame('/webmozart', $dir->getPath());
        $this->assertSame($this->readRepo, $dir->getRepository());
        $this->assertCount(1, $dir->listChildren());

        $this->assertInstanceOf('Puli\Repository\Api\Resource\BodyResource', $file);
        $this->assertSame('/webmozart/file', $file->getPath());
        $this->assertSame($this->readRepo, $file->getRepository());
        $this->assertSame(TestFile::BODY, $file->getBody());
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testAddExpectsAbsolutePath()
    {
        $this->writeRepo->add('webmozart', new TestDirectory());
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testAddExpectsNonEmptyPath()
    {
        $this->writeRepo->add('', new TestDirectory());
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testAddExpectsStringPath()
    {
        $this->writeRepo->add(new \stdClass(), new TestDirectory());
    }

    /**
     * @expectedException \Puli\Repository\Api\UnsupportedResourceException
     */
    public function testAddExpectsResource()
    {
        $this->writeRepo->add('/webmozart', new \stdClass());
    }

    public function testRemoveFile()
    {
        $this->writeRepo->add('/webmozart/puli/file1', new TestFile());
        $this->writeRepo->add('/webmozart/puli/file2', new TestFile());

        $this->assertTrue($this->readRepo->contains('/webmozart'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file2'));

        $this->assertSame(1, $this->writeRepo->remove('/webmozart/puli/file1'));

        $this->assertTrue($this->readRepo->contains('/webmozart'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file2'));
    }

    public function testRemoveMany()
    {
        $this->writeRepo->add('/webmozart/puli/file1', new TestFile());
        $this->writeRepo->add('/webmozart/puli/file2', new TestFile());

        $this->assertTrue($this->readRepo->contains('/webmozart/puli'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file2'));

        $this->assertSame(2, $this->writeRepo->remove('/webmozart/puli/file*'));

        $this->assertTrue($this->readRepo->contains('/webmozart/puli'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file2'));
    }

    public function provideDirectoryGlob()
    {
        return array(
            array('/webmozart/puli'),
            array('/webmozart/pu*'),
        );
    }

    /**
     * @dataProvider provideDirectoryGlob
     */
    public function testRemoveDirectory($glob)
    {
        $this->writeRepo->add('/webmozart/puli/file1', new TestFile());
        $this->writeRepo->add('/webmozart/puli/file2', new TestFile());

        $this->assertTrue($this->readRepo->contains('/webmozart'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file2'));

        $this->assertSame(3, $this->writeRepo->remove($glob));

        $this->assertTrue($this->readRepo->contains('/webmozart'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file2'));
    }

    public function testRemoveDot()
    {
        $this->writeRepo->add('/webmozart/puli/file1', new TestFile());
        $this->writeRepo->add('/webmozart/puli/file2', new TestFile());

        $this->assertTrue($this->readRepo->contains('/webmozart'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file2'));

        $this->writeRepo->remove('/webmozart/puli/.');

        $this->assertTrue($this->readRepo->contains('/webmozart'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file2'));
    }

    public function testRemoveDotDot()
    {
        $this->writeRepo->add('/webmozart/puli/file1', new TestFile());
        $this->writeRepo->add('/webmozart/puli/file2', new TestFile());

        $this->assertTrue($this->readRepo->contains('/'));
        $this->assertTrue($this->readRepo->contains('/webmozart'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file2'));

        $this->writeRepo->remove('/webmozart/puli/..');

        $this->assertTrue($this->readRepo->contains('/'));
        $this->assertFalse($this->readRepo->contains('/webmozart'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file2'));
    }

    public function testRemoveDiscardsTrailingSlash()
    {
        $this->writeRepo->add('/webmozart/puli/file1', new TestFile());
        $this->writeRepo->add('/webmozart/puli/file2', new TestFile());

        $this->assertTrue($this->readRepo->contains('/webmozart/puli'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file2'));

        $this->writeRepo->remove('/webmozart/puli/');

        $this->assertFalse($this->readRepo->contains('/webmozart/puli'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file2'));
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testCannotRemoveRoot()
    {
        $this->writeRepo->remove('/');
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testRemoveInterpretsConsecutiveSlashesAsRoot()
    {
        $this->writeRepo->remove('///');
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testRemoveExpectsAbsolutePath()
    {
        $this->writeRepo->remove('webmozart');
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testRemoveExpectsNonEmptyPath()
    {
        $this->writeRepo->remove('');
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testRemoveExpectsStringPath()
    {
        $this->writeRepo->remove(new \stdClass());
    }

    public function testMoveFile()
    {
        $this->writeRepo->add('/webmozart/puli/file1', new TestFile());
        $this->writeRepo->add('/webmozart/puli/file2', new TestFile());

        $this->assertTrue($this->readRepo->contains('/webmozart'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file2'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file3'));

        $this->assertSame(1, $this->writeRepo->move('/webmozart/puli/file1', '/webmozart/puli/file3'));

        $this->assertTrue($this->readRepo->contains('/webmozart'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file2'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file3'));
    }

    public function testMoveMany()
    {
        $this->writeRepo->add('/webmozart/puli/file1', new TestFile());
        $this->writeRepo->add('/webmozart/puli/file2', new TestFile());

        $this->assertTrue($this->readRepo->contains('/webmozart/puli'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file2'));

        $this->assertSame(2, $this->writeRepo->move('/webmozart/puli/file*', '/webmozart'));

        $this->assertTrue($this->readRepo->contains('/webmozart/puli'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file2'));
        $this->assertTrue($this->readRepo->contains('/webmozart/file1'));
        $this->assertTrue($this->readRepo->contains('/webmozart/file2'));
    }

    /**
     * @dataProvider provideDirectoryGlob
     */
    public function testMoveDirectory($glob)
    {
        $this->writeRepo->add('/webmozart/puli/file1', new TestFile());
        $this->writeRepo->add('/webmozart/puli/file2', new TestFile());

        $this->assertTrue($this->readRepo->contains('/webmozart'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file2'));

        $this->assertSame(3, $this->writeRepo->move($glob, '/webmozart/p'));

        $this->assertTrue($this->readRepo->contains('/webmozart'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file2'));
        $this->assertTrue($this->readRepo->contains('/webmozart/p/puli'));
        $this->assertTrue($this->readRepo->contains('/webmozart/p/puli/file1'));
        $this->assertTrue($this->readRepo->contains('/webmozart/p/puli/file2'));
    }

    public function testMoveDot()
    {
        $this->writeRepo->add('/webmozart/puli/file1', new TestFile());
        $this->writeRepo->add('/webmozart/puli/file2', new TestFile());

        $this->assertTrue($this->readRepo->contains('/webmozart'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file2'));

        $this->writeRepo->move('/webmozart/puli/.', '/webmozart/p');

        $this->assertTrue($this->readRepo->contains('/webmozart'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file2'));
        $this->assertTrue($this->readRepo->contains('/webmozart/p/puli'));
        $this->assertTrue($this->readRepo->contains('/webmozart/p/puli/file1'));
        $this->assertTrue($this->readRepo->contains('/webmozart/p/puli/file2'));
    }

    public function testMoveDotDot()
    {
        $this->writeRepo->add('/webmozart/puli/file1', new TestFile());
        $this->writeRepo->add('/webmozart/puli/file2', new TestFile());

        $this->assertTrue($this->readRepo->contains('/'));
        $this->assertTrue($this->readRepo->contains('/webmozart'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file2'));

        $this->writeRepo->move('/webmozart/puli/..', '/w');

        $this->assertTrue($this->readRepo->contains('/'));
        $this->assertFalse($this->readRepo->contains('/webmozart'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file2'));
        $this->assertTrue($this->readRepo->contains('/w'));
        $this->assertTrue($this->readRepo->contains('/w/webmozart'));
        $this->assertTrue($this->readRepo->contains('/w/webmozart/puli'));
        $this->assertTrue($this->readRepo->contains('/w/webmozart/puli/file1'));
        $this->assertTrue($this->readRepo->contains('/w/webmozart/puli/file2'));
    }

    public function testMoveDiscardsTrailingSlash()
    {
        $this->writeRepo->add('/webmozart/puli/file1', new TestFile());
        $this->writeRepo->add('/webmozart/puli/file2', new TestFile());

        $this->assertTrue($this->readRepo->contains('/webmozart/puli'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file2'));

        $this->writeRepo->move('/webmozart/puli/', '/webmozart/p/');

        $this->assertFalse($this->readRepo->contains('/webmozart/puli'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file2'));
        $this->assertTrue($this->readRepo->contains('/webmozart/p/puli'));
        $this->assertTrue($this->readRepo->contains('/webmozart/p/puli/file1'));
        $this->assertTrue($this->readRepo->contains('/webmozart/p/puli/file2'));
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testMoveExpectsAbsolutePathAsSource()
    {
        $this->writeRepo->move('webmozart', '/w');
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testMoveExpectsAbsolutePathAsTarget()
    {
        $this->writeRepo->move('/webmozart', 'w');
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testMoveExpectsNonEmptyPathAsSource()
    {
        $this->writeRepo->move('', '/w');
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testMoveExpectsNonEmptyPathAsTarget()
    {
        $this->writeRepo->move('/webmozart', '');
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testMoveExpectsStringPathAsSource()
    {
        $this->writeRepo->move(new \stdClass(), '/w');
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testMoveExpectsStringPathAsTarget()
    {
        $this->writeRepo->move('/webmozart', new \stdClass());
    }

    public function testClear()
    {
        $this->writeRepo->add('/webmozart/puli/file1', new TestFile());
        $this->writeRepo->add('/webmozart/puli/file2', new TestFile());

        $this->assertTrue($this->readRepo->contains('/'));
        $this->assertTrue($this->readRepo->contains('/webmozart'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertTrue($this->readRepo->contains('/webmozart/puli/file2'));

        $this->assertSame(4, $this->writeRepo->clear());

        $this->assertTrue($this->readRepo->contains('/'));
        $this->assertFalse($this->readRepo->contains('/webmozart'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file1'));
        $this->assertFalse($this->readRepo->contains('/webmozart/puli/file2'));
    }
}
