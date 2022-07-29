<?php

namespace Epubli\ApiPlatform\TestBundle;

use ApiPlatform\Core\Bridge\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Core\Bridge\Symfony\Bundle\Test\Client;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ObjectRepository;
use Faker\Factory;
use Faker\Generator;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\ClassDiscriminatorFromClassMetadata;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AnnotationLoader;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ApiPlatformBaseTestCase extends ApiTestCase
{
    // This trait provided by AliceBundle will take care of refreshing the database content to a known state before each test
    use RecreateDatabaseTrait;

    /** @var Generator Use to generate fake data */
    protected static Generator $faker;
    /** @var ResponseInterface The response object after the request was sent */
    protected static ResponseInterface $response;
    /** @var Client the http client used to send the requests */
    protected static Client $client;
    /** @var Serializer used to serialize/deserialize/normalize/decode... the test entity and the response */
    protected static Serializer $serializer;
    /** @var AnnotationReader used to access the annotations */
    protected static AnnotationReader $annotationReader;

    //<editor-fold desc="*** Setup the Test Case ***">

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$faker = Factory::create('de_DE');
        $classMetadataFactory = new ClassMetadataFactory(new AnnotationLoader(new AnnotationReader()));
        $discriminator = new ClassDiscriminatorFromClassMetadata($classMetadataFactory);
        self::$serializer = new Serializer(
            normalizers: [
                new DateTimeNormalizer(),
                new ObjectNormalizer(
                    classMetadataFactory: $classMetadataFactory,
                    classDiscriminatorResolver: $discriminator,
                    defaultContext: [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]
                )
            ],
            encoders: [new JsonEncoder()]
        );
        self::$annotationReader ??= new AnnotationReader();
    }

    public function setUp(): void
    {
        parent::setUp();
        // Set up a new http-client for each test run is mandatory to prevent unwanted states.
        list($kernelOptions, $defaultOptions) = static::getClientOptions();
        self::$client = static::createClient($kernelOptions, $defaultOptions);
    }

    /**
     * Http-Client options
     * @see https://symfony.com/doc/current/reference/configuration/framework.html#reference-http-client
     * @return array[]
     */
    protected static function getClientOptions(): array
    {
        $kernelOptions = [];
        $defaultClientOptions = [];
        return [$kernelOptions, $defaultClientOptions];
    }

    /**
     * Serializer Context handles circular references by setting an IRI instead
     * @see https://symfony.com/doc/current/components/serializer.html
     * @return array
     */
    protected function getSerializerContext(): array
    {
        return [
            AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => true,
            AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => function ($object) {
                return static::RESOURCE_URI . ($object->getId() ?? 'circular reference');
            },
            AbstractNormalizer::CIRCULAR_REFERENCE_LIMIT => 1,
            AbstractNormalizer::IGNORED_ATTRIBUTES => $this->getIgnoredAttributes(),
        ];
    }

    /** @return array - list of ignored attribute names (will not get compared on asserts) */
    protected function getIgnoredAttributes(): array
    {
        return [];
    }
    //</editor-fold>

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