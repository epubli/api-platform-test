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
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
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

    //<editor-fold desc="*** Request/Response helper ***">

    /**
     * Request wrapper around the http client which sets some headers and serializes the content to a json string
     * @param string $url
     * @param string $method
     * @param mixed $content Objects will be serialized and strings will be treated as already serialized json.
     * @param array $files
     * @param array $parameters
     * @param array $headers
     *
     * @return ResponseInterface
     * @throws TransportExceptionInterface
     */
    protected function request(string $url, string $method = 'GET', mixed $content = null,
                               array  $files = [], array $parameters = [], array $headers = []): ResponseInterface
    {
        $headers = array_merge([
            'CONTENT_TYPE' => ($method === 'PATCH') ? 'application/merge-patch+json' : 'application/ld+json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ], $headers);

        // POST request doesn't follow 301, symfony creates 301 for trailing slash routes
        $url = rtrim($url, '/');

        // options json or body is okay
        $options = [
            'headers' => $headers,
            'body' => $this->serializeToJson($content),
            'extra' => [
                'parameters' => $parameters,
                'files' => $files,
            ]
        ];

        return self::$response = self::$client->request(
            method: $method,
            url: $url,
            options: $options
        );
    }

    /**
     * Access the http response directly
     * @return ResponseInterface
     * @throws \Exception
     */
    protected static function getResponse(): ResponseInterface
    {
        if (!isset(self::$response)) {
            throw new \Exception('No Request was executed and so no Response is available.', 1658845434453);
        }
        return self::$response;
    }

    /**
     * Access the http response content directly
     * @throws \Exception
     * @throws TransportExceptionInterface
     */
    protected function getResponseContent(): string
    {
        return self::getResponse()->getContent(throw: false);
    }

    /**
     * Decodes the http response into a json array
     * @return array
     * @throws \Exception
     * @throws TransportExceptionInterface
     */
    protected function getResponseAsJson(): array
    {
        $content = $this->getResponseContent();
        return empty($content)
            ? []
            : static::$serializer->decode($content, JsonEncoder::FORMAT);
    }

    /**
     * Serialize the content into a json string
     * @param mixed|null $content
     * @return string|null $json
     * @see getSerializerContext
     */
    protected function serializeToJson(mixed $content, array $skipAttributes = []): ?string
    {
        if (!$content) {
            return null;
        } elseif (is_string($content)) {
            return $content;
        } else {
            $context = $this->getSerializerContext();
            $context[AbstractNormalizer::IGNORED_ATTRIBUTES] = array_merge($context[AbstractNormalizer::IGNORED_ATTRIBUTES], $skipAttributes);
            return self::$serializer->serialize($content, JsonEncoder::FORMAT, $context);
        }
    }

    /**
     * Serialize the content into a json string
     * @see getSerializerContext
     */
    protected function decodeToJson(mixed $content, array $skipAttributes = []): array
    {
        $asJsonString = $this->serializeToJson($content, $skipAttributes);
        $context = $this->getSerializerContext();
        $context[AbstractNormalizer::IGNORED_ATTRIBUTES] = array_merge($context[AbstractNormalizer::IGNORED_ATTRIBUTES], $skipAttributes);
        return self::$serializer->decode($asJsonString, JsonEncoder::FORMAT, $context);

    }

    //</editor-fold>

    //<editor-fold desc="*** Reflection helper ***">

    /**
     * @param \ReflectionProperty $reflectionProperty
     * @param object $data
     *
     * @return mixed
     * @throws \ReflectionException
     */
    protected function getPropertyValue(\ReflectionProperty $reflectionProperty, object $data): mixed
    {
        $reflectionClass = new \ReflectionClass($data);
        if ($reflectionProperty->isPublic()) {
            return $data->{$reflectionProperty->name};
        }

        if ($reflectionClass->hasMethod(
            'get' . ucfirst($reflectionProperty->name)
        )
        ) {
            return $data->{$reflectionClass->getMethod(
                'get' . ucfirst($reflectionProperty->name)
            )->name}();
        }

        throw new \RuntimeException('Can\'t get property value!');
    }

    protected function getNonAliasType($type): string
    {
        $types = [
            'boolean' => 'bool',
            'integer' => 'int',
            'double' => 'float',
        ];
        if (in_array($type, array_keys($types), true)) {
            return $types[$type];
        }
        return $type;
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