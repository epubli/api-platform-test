<?php

namespace Epubli\ApiPlatform\TestBundle;

use Doctrine\ORM\EntityManager;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;

abstract class OrmApiPlatformTestCase extends ApiPlatformTestCase
{
    use RecreateDatabaseTrait;

    private function getEnitityManager(): EntityManager
    {
        if (!self::$container) {
            self::$kernel = self::bootKernel();
            self::$container = self::$kernel->getContainer();
        }
        return self::$container->get('doctrine.orm.entity_manager');
    }

    private function getRepository(string $class)
    {
        return $this->getEnitityManager()->getRepository($class);
    }

    protected function findOne(string $class, array $criteria = [])
    {
        return $this->getRepository($class)->findOneBy($criteria);
    }

    protected function getQueryBuilder(string $class, string $tableAlias = 't')
    {
        return $this->getRepository($class)->createQueryBuilder($tableAlias);
    }

    protected function persistAndFlush($entity)
    {
        $em = $this->getEnitityManager();
        $em->persist($entity);
        $em->flush();
    }

    abstract protected function getDemoEntity();
}
