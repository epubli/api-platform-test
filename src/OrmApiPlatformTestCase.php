<?php

namespace Epubli\ApiPlatform\TestBundle;

use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;

abstract class OrmApiPlatformTestCase extends ApiPlatformTestCase
{
    use RecreateDatabaseTrait;

    private function getRepository(string $class)
    {
        if (!self::$container) {
            self::$kernel = self::bootKernel();
            self::$container = self::$kernel->getContainer();
        }
        $manager = self::$container->get('doctrine.orm.entity_manager');
        return $manager->getRepository($class);
    }

    protected function findOne(string $class, array $criteria = [])
    {
        return $this->getRepository($class)->findOneBy($criteria);
    }

    protected function getQueryBuilder(string $class, string $tableAlias = 't')
    {
        return $this->getRepository($class)->createQueryBuilder($tableAlias);
    }

    abstract protected function getDemoEntity();
}
