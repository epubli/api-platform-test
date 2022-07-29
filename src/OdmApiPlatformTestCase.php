<?php

namespace Epubli\ApiPlatform\TestBundle;

// use Hautelook\AliceBundle\PhpUnit\RefreshMongoDbTrait; - We do not include this any more
use Exception;

/**
 * @deprecated We handle different databases by getting the 'doctrine' container and relying on it
 */
abstract class OdmApiPlatformTestCase extends ApiPlatformTestCase
{
    // use RefreshMongoDbTrait; we do not include this anymore

    /**
     * @throws Exception
     * @deprecated don't use this class anymore
     */
    protected function findOne(string $class, array $criteria = []): mixed
    {
     return parent::findOne($class, $criteria);
    }

    /**
     * @deprecated removed special mongodb handling
     * @return mixed
     */
    abstract protected function getDemoDocument(): mixed;
}
