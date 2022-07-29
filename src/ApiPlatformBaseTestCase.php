<?php

namespace Epubli\ApiPlatform\TestBundle;

use ApiPlatform\Core\Bridge\Symfony\Bundle\Test\ApiTestCase;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ObjectRepository;

class ApiPlatformBaseTestCase extends ApiTestCase
{

    //<editor-fold desc="*** Doctrine helper ***">

    /**
     * @throws \Exception
     * @noinspection PhpIncompatibleReturnTypeInspection
     */
    private function getEntityManager(): EntityManager
    {
        /** @var Registry $registry */
        $registry = self::getContainer()->get('doctrine');
        return $registry->getManager();
    }

    /**
     * @throws \Exception
     */
    private function getRepository(string $class): EntityRepository|ObjectRepository
    {
        return $this->getEntityManager()->getRepository($class);
    }

    /**
     * @throws \Exception
     */
    protected function findOne(string $class, array $criteria = []): mixed
    {
        return $this->getRepository($class)->findOneBy($criteria);
    }

    /**
     * @throws \Exception
     */
    protected function getQueryBuilder(string $class, string $tableAlias = 't'): QueryBuilder
    {
        return $this->getRepository($class)->createQueryBuilder($tableAlias);
    }

    /**
     * @throws \Exception
     */
    protected function persistAndFlush($entity)
    {
        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();
    }

    //</editor-fold>

}