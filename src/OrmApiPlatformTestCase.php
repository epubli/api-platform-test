<?php

namespace Epubli\ApiPlatform\TestBundle;

use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;

abstract class OrmApiPlatformTestCase extends ApiPlatformTestCase
{
    use RecreateDatabaseTrait;

    protected function findOne(string $class, array $criteria = [])
    {
        if (!self::$container) {
            self::$kernel = self::bootKernel();
            self::$container = self::$kernel->getContainer();
        }
        $manager = self::$container->get('doctrine.orm.entity_manager');
        return $manager->getRepository($class)->findOneBy($criteria);
    }

    abstract protected function getDemoEntity();
}
