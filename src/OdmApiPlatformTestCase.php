<?php

namespace Epubli\ApiPlatform\TestBundle;

use Hautelook\AliceBundle\PhpUnit\RefreshMongoDbTrait;

abstract class OdmApiPlatformTestCase extends ApiPlatformTestCase
{
    use RefreshMongoDbTrait;

    protected function findOne(string $class, array $criteria = [])
    {
        if (!self::$container) {
            self::$kernel = self::bootKernel();
            self::$container = self::$kernel->getContainer();
        }
        $manager = self::$container->get('doctrine_mongodb.odm.document_manager');
        return $manager->getRepository($class)->findOneBy($criteria);
    }
}
