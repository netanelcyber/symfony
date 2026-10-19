<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Doctrine\Tests\Console\ArgumentResolver;

use Doctrine\DBAL\Types\ConversionException;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\ArgumentResolver\Console\EntityValueResolver;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Console\ArgumentResolver\Exception\NearMissValueResolverException;
use Symfony\Component\Console\ArgumentResolver\ValueResolver\ValueResolverInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Attribute\Reflection\ReflectionMember;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class EntityValueResolverTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!interface_exists(ValueResolverInterface::class)) {
            self::markTestSkipped('Requires symfony/console >= 8.1');
        }
    }

    public function testResolveWithoutArgumentAttribute()
    {
        $manager = $this->createStub(ObjectManager::class);
        $registry = $this->createRegistry($manager);
        $resolver = new EntityValueResolver($registry);

        $input = new ArrayInput(['entity' => 1], new InputDefinition([
            new InputArgument('entity'),
        ]));

        // Without #[Argument] attribute, resolver should return []
        $member = $this->createMember('entity', \stdClass::class, new MapEntity(), withArgument: false);

        $this->assertSame([], iterator_to_array($resolver->resolve('entity', $input, $member)));
    }

    public function testResolveWithoutManager()
    {
        $registry = $this->createRegistry(null);
        $resolver = new EntityValueResolver($registry);

        $input = new ArrayInput(['entity' => 1], new InputDefinition([
            new InputArgument('entity'),
        ]));

        $member = $this->createMember('entity', \stdClass::class, new MapEntity());

        $this->assertSame([], iterator_to_array($resolver->resolve('entity', $input, $member)));
    }

    public function testResolveWithNoIdAndDataOptional()
    {
        $manager = $this->createStub(ObjectManager::class);
        $registry = $this->createRegistry($manager);
        $resolver = new EntityValueResolver($registry);

        $input = new ArrayInput([], new InputDefinition([
            new InputArgument('entity'),
        ]));

        $member = $this->createMember('entity', \stdClass::class, new MapEntity(), nullable: true);

        $this->expectException(NearMissValueResolverException::class);
        $this->expectExceptionMessage('Cannot find mapping for "stdClass"');

        iterator_to_array($resolver->resolve('entity', $input, $member));
    }

    public function testResolveById()
    {
        $manager = $this->createMock(ObjectManager::class);
        $registry = $this->createRegistry($manager);
        $resolver = new EntityValueResolver($registry);

        $input = new ArrayInput(['entity' => 1], new InputDefinition([
            new InputArgument('entity'),
        ]));

        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($object = new \stdClass());

        $manager->expects($this->once())
            ->method('getRepository')
            ->with(\stdClass::class)
            ->willReturn($repository);

        $member = $this->createMember('entity', \stdClass::class, new MapEntity());

        $this->assertSame([$object], iterator_to_array($resolver->resolve('entity', $input, $member)));
    }

    public function testResolveByIdWithCustomName()
    {
        $manager = $this->createMock(ObjectManager::class);
        $registry = $this->createRegistry($manager);
        $resolver = new EntityValueResolver($registry);

        $input = new ArrayInput(['user-id' => 5], new InputDefinition([
            new InputArgument('user-id'),
        ]));

        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with(5)
            ->willReturn($object = new \stdClass());

        $manager->expects($this->once())
            ->method('getRepository')
            ->with(\stdClass::class)
            ->willReturn($repository);

        $member = $this->createMember('entity', \stdClass::class, new MapEntity(id: 'userId'));

        $this->assertSame([$object], iterator_to_array($resolver->resolve('entity', $input, $member)));
    }

    public function testResolveByMapping()
    {
        $manager = $this->createMock(ObjectManager::class);
        $registry = $this->createRegistry($manager);
        $resolver = new EntityValueResolver($registry);

        $input = new ArrayInput(['name' => 'foo'], new InputDefinition([
            new InputArgument('name'),
        ]));

        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['name' => 'foo'])
            ->willReturn($object = new \stdClass());

        $manager->expects($this->once())
            ->method('getRepository')
            ->with(\stdClass::class)
            ->willReturn($repository);

        $member = $this->createMember('entity', \stdClass::class, new MapEntity(mapping: ['name']));

        $this->assertSame([$object], iterator_to_array($resolver->resolve('entity', $input, $member)));
    }

    public function testResolveNotFoundThrowsException()
    {
        $manager = $this->createMock(ObjectManager::class);
        $registry = $this->createRegistry($manager);
        $resolver = new EntityValueResolver($registry);

        $input = new ArrayInput(['entity' => 1], new InputDefinition([
            new InputArgument('entity'),
        ]));

        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn(null);

        $manager->expects($this->once())
            ->method('getRepository')
            ->with(\stdClass::class)
            ->willReturn($repository);

        $member = $this->createMember('entity', \stdClass::class, new MapEntity());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"stdClass" object not found');

        iterator_to_array($resolver->resolve('entity', $input, $member));
    }

    public function testResolveNotFoundReturnsNullWhenNullable()
    {
        $manager = $this->createMock(ObjectManager::class);
        $registry = $this->createRegistry($manager);
        $resolver = new EntityValueResolver($registry);

        $input = new ArrayInput(['entity' => 1], new InputDefinition([
            new InputArgument('entity'),
        ]));

        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn(null);

        $manager->expects($this->once())
            ->method('getRepository')
            ->with(\stdClass::class)
            ->willReturn($repository);

        $member = $this->createMember('entity', \stdClass::class, new MapEntity(), nullable: true);

        $this->assertSame([null], iterator_to_array($resolver->resolve('entity', $input, $member)));
    }

    public function testResolveWithExpression()
    {
        $manager = $this->createMock(ObjectManager::class);
        $registry = $this->createRegistry($manager);
        $language = $this->createMock(ExpressionLanguage::class);
        $resolver = new EntityValueResolver($registry, $language);

        $input = new ArrayInput(['entity' => 1], new InputDefinition([
            new InputArgument('entity'),
        ]));

        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects($this->never())
            ->method('find');

        $manager->expects($this->once())
            ->method('getRepository')
            ->with(\stdClass::class)
            ->willReturn($repository);

        $language->expects($this->once())
            ->method('evaluate')
            ->with('repository.findOneByCustomMethod(entity)', [
                'repository' => $repository,
                'input' => $input,
                'entity' => 1,
            ])
            ->willReturn($object = new \stdClass());

        $member = $this->createMember('entity', \stdClass::class, new MapEntity(expr: 'repository.findOneByCustomMethod(entity)'));

        $this->assertSame([$object], iterator_to_array($resolver->resolve('entity', $input, $member)));
    }

    public function testResolveWithStripNull()
    {
        $manager = $this->createMock(ObjectManager::class);
        $registry = $this->createRegistry($manager);
        $resolver = new EntityValueResolver($registry);

        $input = new ArrayInput(['entity' => null], new InputDefinition([
            new InputArgument('entity'),
        ]));

        $member = $this->createMember('entity', \stdClass::class, new MapEntity(mapping: ['entity'], stripNull: true), nullable: true);

        $manager->expects($this->never())
            ->method('getClassMetadata');

        $manager->expects($this->never())
            ->method('getRepository');

        $this->expectException(NearMissValueResolverException::class);

        iterator_to_array($resolver->resolve('entity', $input, $member));
    }

    public function testAlreadyResolved()
    {
        $manager = $this->createStub(ObjectManager::class);
        $registry = $this->createRegistry($manager);
        $resolver = new EntityValueResolver($registry);

        $object = new \stdClass();
        $input = new ArrayInput(['entity' => $object], new InputDefinition([
            new InputArgument('entity'),
        ]));

        $member = $this->createMember('entity', \stdClass::class, new MapEntity());

        $this->assertSame([], iterator_to_array($resolver->resolve('entity', $input, $member)));
    }

    #[TestWith([[12]])]
    #[TestWith([['foo']])]
    #[TestWith([[1, 2, 3, 4]])]
    #[TestWith([['foo', 'bar', 'baz']])]
    public function testResolveWithFindBy(array $commandArg)
    {
        $manager = $this->createMock(ObjectManager::class);
        $registry = $this->createRegistry($manager);
        $resolver = new EntityValueResolver($registry);

        $input = new ArrayInput(['entity' => $commandArg], new InputDefinition([
            new InputArgument('entity'),
        ]));

        $expectedResult = array_fill(0, \count($commandArg), new \stdClass());

        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects($this->once())
            ->method('findBy')
            ->with(['entity' => $commandArg], null, null, null)
            ->willReturn($expectedResult);

        $manager->expects($this->once())
            ->method('getRepository')
            ->with(\stdClass::class)
            ->willReturn($repository);

        $member = $this->createMember('entity', 'array', new MapEntity(class: \stdClass::class, mapping: ['entity']));

        $this->assertSame([$expectedResult], iterator_to_array($resolver->resolve('entity', $input, $member)));
    }

    public function testResolveWithClosure()
    {
        $manager = $this->createMock(ObjectManager::class);
        $registry = $this->createRegistry($manager);
        $resolver = new EntityValueResolver($registry, null, new MapEntity(expr: static fn (InputInterface $input, ObjectRepository $repository) => $repository->findOneBy(['id' => $input->getArgument('entity')])));

        $input = new ArrayInput(['entity' => 1], new InputDefinition([
            new InputArgument('entity'),
        ]));

        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects($this->never())
            ->method('find');
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['id' => 1])
            ->willReturn($object = new \stdClass());

        $manager->expects($this->once())
            ->method('getRepository')
            ->with(\stdClass::class)
            ->willReturn($repository);

        $member = $this->createMember('entity', \stdClass::class);

        $this->assertSame([$object], iterator_to_array($resolver->resolve('entity', $input, $member)));
    }

    public function testResolveWithClosureFailureThrowsException()
    {
        $manager = $this->createMock(ObjectManager::class);
        $registry = $this->createRegistry($manager);
        $resolver = new EntityValueResolver($registry, null, new MapEntity(expr: static function (InputInterface $input, ObjectRepository $repository) {
            $repository->findOneBy(['id' => $input->getArgument('entity')]);

            throw new ConversionException();
        }));

        $input = new ArrayInput(['entity' => 1], new InputDefinition([
            new InputArgument('entity'),
        ]));

        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['id' => 1]);

        $manager->expects($this->once())
            ->method('getRepository')
            ->with(\stdClass::class)
            ->willReturn($repository);

        $member = $this->createMember('entity', \stdClass::class);

        $this->expectException(RuntimeException::class);

        iterator_to_array($resolver->resolve('entity', $input, $member));
    }

    public function testResolveByIdFromOption()
    {
        $manager = $this->createMock(ObjectManager::class);
        $registry = $this->createRegistry($manager);
        $resolver = new EntityValueResolver($registry);

        $input = new ArrayInput(['--entity' => 1], new InputDefinition([
            new InputOption('entity', null, InputOption::VALUE_REQUIRED),
        ]));

        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($object = new \stdClass());

        $manager->expects($this->once())
            ->method('getRepository')
            ->with(\stdClass::class)
            ->willReturn($repository);

        $member = $this->createMember('entity', \stdClass::class, new MapEntity(), asOption: true);

        $this->assertSame([$object], iterator_to_array($resolver->resolve('entity', $input, $member)));
    }

    public function testResolveByIdFromOptionWithCustomName()
    {
        $manager = $this->createMock(ObjectManager::class);
        $registry = $this->createRegistry($manager);
        $resolver = new EntityValueResolver($registry);

        $input = new ArrayInput(['--user-id' => 5], new InputDefinition([
            new InputOption('user-id', null, InputOption::VALUE_REQUIRED),
        ]));

        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with(5)
            ->willReturn($object = new \stdClass());

        $manager->expects($this->once())
            ->method('getRepository')
            ->with(\stdClass::class)
            ->willReturn($repository);

        $member = $this->createMember('entity', \stdClass::class, new MapEntity(id: 'userId'), asOption: true);

        $this->assertSame([$object], iterator_to_array($resolver->resolve('entity', $input, $member)));
    }

    public function testResolveByMappingFromOption()
    {
        $manager = $this->createMock(ObjectManager::class);
        $registry = $this->createRegistry($manager);
        $resolver = new EntityValueResolver($registry);

        $input = new ArrayInput(['--name' => 'foo'], new InputDefinition([
            new InputOption('name', null, InputOption::VALUE_REQUIRED),
        ]));

        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['name' => 'foo'])
            ->willReturn($object = new \stdClass());

        $manager->expects($this->once())
            ->method('getRepository')
            ->with(\stdClass::class)
            ->willReturn($repository);

        $member = $this->createMember('entity', \stdClass::class, new MapEntity(mapping: ['name']), asOption: true);

        $this->assertSame([$object], iterator_to_array($resolver->resolve('entity', $input, $member)));
    }

    public function testResolveOptionAlreadyResolved()
    {
        $manager = $this->createStub(ObjectManager::class);
        $registry = $this->createRegistry($manager);
        $resolver = new EntityValueResolver($registry);

        $object = new \stdClass();
        $input = new ArrayInput(['--entity' => $object], new InputDefinition([
            new InputOption('entity', null, InputOption::VALUE_REQUIRED),
        ]));

        $member = $this->createMember('entity', \stdClass::class, new MapEntity(), asOption: true);

        $this->assertSame([], iterator_to_array($resolver->resolve('entity', $input, $member)));
    }

    private function createMember(string $name, string $type, ?MapEntity $entity = null, bool $nullable = false, bool $withArgument = true, bool $asOption = false): ReflectionMember
    {
        if ($asOption) {
            $method = 'optionEntity';
        } elseif (!$withArgument) {
            $method = 'noAttributeEntity';
        } elseif ($nullable) {
            $method = 'argumentNullableEntity';
        } elseif ('array' === $type) {
            $method = 'argumentArrayEntity';
        } else {
            $method = 'argumentEntity';
        }

        $parameter = (new \ReflectionMethod(EntityValueResolverFixture::class, $method))->getParameters()[0];

        return new class($parameter, $entity) extends ReflectionMember {
            public function __construct(
                \ReflectionParameter $parameter,
                private readonly ?MapEntity $mapEntity = null,
            ) {
                parent::__construct($parameter);
            }

            public function getAttribute(string $class): ?object
            {
                if (MapEntity::class === $class) {
                    return $this->mapEntity;
                }

                return parent::getAttribute($class);
            }
        };
    }

    private function createRegistry(?ObjectManager $manager = null): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);

        $registry
            ->method('getManagerForClass')
            ->willReturn($manager);

        if (null === $manager) {
            $registry->method('getManager')
                ->willThrowException(new \InvalidArgumentException());
        } else {
            $registry->method('getManager')->willReturn($manager);
        }

        return $registry;
    }
}

class EntityValueResolverFixture
{
    public function argumentEntity(#[Argument] \stdClass $entity): void
    {
    }

    public function argumentNullableEntity(#[Argument] ?\stdClass $entity = null): void
    {
    }

    public function argumentArrayEntity(#[Argument] array $entity): void
    {
    }

    public function optionEntity(#[Option] ?\stdClass $entity = null): void
    {
    }

    public function noAttributeEntity(\stdClass $entity): void
    {
    }
}
