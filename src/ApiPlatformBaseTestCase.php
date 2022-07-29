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
use Symfony\Component\HttpFoundation\Response as STATUS;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\ClassDiscriminatorFromClassMetadata;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AnnotationLoader;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
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

    /**
     * Replace ignored attributes with values
     * @param string $key
     * @param mixed $value
     * @return ?string - a replacement value for the ignored attribute
     */
    protected function replaceIgnoredAttribute(string $key, mixed $value):?string
    {
        return null;
    }

    public static function disableSymfonyExceptionHandling(): void
    {
        /** @noinspection PhpInternalEntityUsedInspection */
        static::$client->getKernelBrowser()->catchExceptions(false);
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
    protected function decodeToJson(object|array $content, array $skipAttributes = []): array
    {
        $asJsonString = $this->serializeToJson($content, $skipAttributes);
        $context = $this->getSerializerContext();
        $context[AbstractNormalizer::IGNORED_ATTRIBUTES] = array_merge($context[AbstractNormalizer::IGNORED_ATTRIBUTES], $skipAttributes);
        $decoded = self::$serializer->decode($asJsonString, JsonEncoder::FORMAT, $context);
        foreach ($context[AbstractNormalizer::IGNORED_ATTRIBUTES] as $attrKey) {
            $replacedValue = $this->replaceIgnoredAttribute($attrKey, $content);
            if($replacedValue !== null) {
                $decoded[$attrKey] = $replacedValue;
            }
        }
        return $decoded;
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

    //<editor-fold desc="*** Assert methods ***">

    /**
     * Assert that the response header contains Content-Type:'application/ld+json; charset=utf-8'
     * @return void
     */
    protected function assertLdJsonHeader(): void
    {
        self::assertResponseHeaderSame(
            'Content-Type',
            'application/ld+json; charset=utf-8'
        );
    }

    /**
     * Assert that the 'createdAt' and 'updatedAt' properties are set in the response
     * @param array $jsonResponse
     * @return void
     */
    protected function assertTimestampable(array $jsonResponse): void
    {
        foreach (['createdAt', 'updatedAt'] as $dateProp) {
            $this->assertArrayHasKey($dateProp, $jsonResponse);
            $this->assertIsString($jsonResponse[$dateProp]);
            $this->assertNotEmpty($jsonResponse[$dateProp]);
        }
    }

    /**
     * Assert that the response contains a hydra:totalItems and hydra:member attribute
     * The count of hydra:member must be > 0 and #hydra:member === hydra:totalItems
     * @throws TransportExceptionInterface
     */
    protected function assertCollectionHasItems(): void
    {
        $json = $this->getResponseAsJson();
        $this->assertArrayHasKey('hydra:totalItems', $json);
        $count = $json['hydra:totalItems'];
        $this->assertGreaterThan(0, $count);
        $this->assertArrayHasKey('hydra:member', $json);
        $this->assertCount($count, $json['hydra:member']);
    }

    /**
     * Assert that the response contains $count hydra:members
     * @throws TransportExceptionInterface
     */
    protected function assertCollectionCount(int $count, ?array $jsonResponse = null): void
    {
        $jsonResponse = $jsonResponse ?? $this->getResponseAsJson();
        $this->assertArrayHasKey('hydra:totalItems', $jsonResponse);
        $this->assertEquals($count, $jsonResponse['hydra:totalItems']);

        $this->assertArrayHasKey('hydra:member', $jsonResponse);
        $this->assertCount($count, $jsonResponse['hydra:member']);
    }

    /**
     * Assert the response has $count attributes
     * @throws TransportExceptionInterface
     */
    protected function assertResourcePropertyCount(int $count, ?array $jsonResponse = null): void
    {
        $jsonResponse = $jsonResponse ?? $this->getResponseAsJson();
        $this->assertCount($count, $jsonResponse);
    }

    // ------------------------------- CRUD - Asserts ----------------------

    /**
     * Assert that the right resource is returned to the response
     * @param array $jsonResource
     * @return void
     * @throws TransportExceptionInterface
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     */
    protected function assertReadAResource(array $jsonResource): void
    {
        self::assertResponseStatusCodeSame(STATUS::HTTP_OK);
        $this->assertLdJsonHeader();
        $this->assertJsonContains($jsonResource);
    }

    /**
     * Assert that a collection of items is returned to the response
     * @return void
     * @throws TransportExceptionInterface
     */
    protected function assertReadAResourceCollection(): void
    {
        self::assertResponseStatusCodeSame(STATUS::HTTP_OK);
        $this->assertLdJsonHeader();
        $this->assertCollectionHasItems();
    }

    /**
     * Assert that the response contains the submitted values from the updated resource
     * @param array $jsonResource
     * @return void
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     * @throws \Exception
     */
    protected function assertUpdateAResource(array $jsonResource): void
    {
        self::assertResponseStatusCodeSame(STATUS::HTTP_OK);
        $this->assertLdJsonHeader();

        $jsonResponse = $this->getResponseAsJson();
        // assert the response contains the new values
        $this->assertJsonContains($jsonResource);
        // assert timestamps got updated
        $this->assertUpdateAResourceTimestamps($jsonResponse);
    }

    /**
     * Assert that the updatedAt attribute is set and did not take too long
     * @throws \Exception
     */
    protected function assertUpdateAResourceTimestamps(array $jsonResponse, int $allowedTimeDifferenceInSeconds = 120)
    {
        $this->assertTimestampable($jsonResponse);
        $updatedAt = new \DateTime($jsonResponse['updatedAt']);
        $difference = $updatedAt->diff(new \DateTime());
        $this->assertLessThan(
            $allowedTimeDifferenceInSeconds,
            $difference->s,
            "updated_at was last touched more than $allowedTimeDifferenceInSeconds seconds ago"
        );
    }

    /**
     * Assert that the response contains the submitted values and that the timestamps are updated
     * @param array $jsonResource
     * @return void
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    protected function assertCreateAResource(array $jsonResource): void
    {
        self::assertResponseStatusCodeSame(STATUS::HTTP_CREATED);
        $this->assertLdJsonHeader();
        $this->assertJsonContains($jsonResource);
        $this->assertCreateAResourceTimestamps();
    }

    /**
     * Assert that the createdAt updatedAt values are set not long ago
     * @throws TransportExceptionInterface
     * @throws \Exception
     */
    protected function assertCreateAResourceTimestamps(int $allowedTimeDifferenceInSeconds = 120)
    {
        $jsonResponse = $this->getResponseAsJson();
        $this->assertTimestampable($jsonResponse);
        $this->assertArrayNotHasKey('deletedAt', $jsonResponse);

        foreach (['createdAt', 'updatedAt'] as $dateProp) {
            $dateValue = new \DateTime($jsonResponse[$dateProp]);
            $difference = $dateValue->diff(new \DateTime());
            $this->assertGreaterThan(0, $difference->f); // should not be the same time as before
            $this->assertLessThan(
                $allowedTimeDifferenceInSeconds,
                $difference->s,
                "$dateProp was last touched more than $allowedTimeDifferenceInSeconds seconds ago"
            );
        }
    }

    /**
     * Assert that the response is empty and that the resource is softdeleted
     * @param string $resourceId
     * @return void
     * @throws TransportExceptionInterface
     * @throws \Exception
     */
    protected function assertDeleteAResource(string $resourceId): void
    {
        self::assertResponseStatusCodeSame(STATUS::HTTP_NO_CONTENT);
        $jsonResponse = $this->getResponseAsJson();
        $this->assertEmpty($jsonResponse);
        $this->assertDeletedAt(static::RESOURCE_CLASS, $resourceId);
    }

    /**
     * @throws \Exception
     */
    protected function assertDeletedAt(string $class, int $id, int $allowedTimeDifferenceInSeconds = 120)
    {
        // disable the softdeletable filter or nothing is returned by findOne
        $filters = $this->getEntityManager()->getFilters();
        $filters->disable('softdeleteable');

        $entity = $this->findOne($class, ['id' => $id]);
        if($entity === null){
            $this->fail('Could not find entity '.  $class . ' with id '. $id);
        }
        $this->assertObjectHasAttribute('deletedAt', $entity);
        $dateValue = $entity->getDeletedAt();
        $difference = $dateValue->diff(new \DateTime());

        $this->assertGreaterThan(0, $difference->f); // should not be the same time as before
        $this->assertLessThan(
            $allowedTimeDifferenceInSeconds,
            $difference->s,
            "deleted_at was last touched more than $allowedTimeDifferenceInSeconds seconds ago"
        );// should not be too long ago

        // enable the softdeleteable filter for the following tests
        $filters->enable('softdeleteable');
    }

    // -------------------------------

    /**
     * @throws TransportExceptionInterface
     */
    protected function assertHasViolations(): void
    {
        $json = $this->getResponseAsJson();
        $this->assertArrayHasKey('violations', $json);
    }

    /**
     * @param object $data
     * @throws \ReflectionException|TransportExceptionInterface
     */
    protected function assertViolations(object $data): void
    {
        $json = $this->getResponseAsJson();
        $this->assertArrayHasKey('violations', $json);
        $violations = $json['violations'];
        $violationPropertyIndexes = array_flip(
            array_column($violations, 'propertyPath')
        );
        $calculatedViolationCount = 0;
        try {
            $reflectionClass = new \ReflectionClass($data);
        } catch (\Exception) {
            $this->fail('Failed due to ReflectionException');
        }
        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            foreach (self::$annotationReader->getPropertyAnnotations(
                $reflectionProperty
            ) as $propertyAnnotation) {
                try {
                    $reflectionAnnotation = new \ReflectionClass($propertyAnnotation);
                } catch (\Exception) {
                    $this->fail('Failed due to ReflectionException');
                }
                if ($reflectionAnnotation->getNamespaceName()
                    !== 'Symfony\Component\Validator\Constraints'
                ) {
                    continue;
                }
                $propertyValue = $this->getPropertyValue($reflectionProperty, $data);
                $propertyType = $this->getNonAliasType(strtolower(gettype($propertyValue)));

                if ($propertyAnnotation instanceof Type
                    && $propertyAnnotation->type === 'numeric') {
                    throw new \RuntimeException(
                        'The assertion type can not be "numeric"! '
                        . 'This package is not programmed to deal with this type. '
                        . 'Please edit the entity "' . $reflectionProperty->class . '". '
                        . 'Change the annotation of the variable "' . $reflectionProperty->name . '". '
                        . 'Change "@Assert\Type(type="numeric")" to either "integer" or "float". '
                        . 'If your integer can be bigger than 19 digits then choose float.'
                    );
                }

                if ($propertyAnnotation instanceof NotBlank
                    && empty($propertyValue)
                ) {
                    $expectedMessage = $propertyAnnotation->message;
                    $calculatedViolationCount++;
                } elseif ($propertyAnnotation instanceof NotNull
                    && $propertyValue === null
                ) {
                    $expectedMessage = $propertyAnnotation->message;
                    $calculatedViolationCount++;
                } elseif ($propertyAnnotation instanceof Type
                    && $propertyValue !== null
                    && $propertyType !== $this->getNonAliasType($propertyAnnotation->type)
                ) {
                    /** @var Type $propertyAnnotation */
                    $expectedMessage = str_replace(
                        '{{ type }}',
                        $propertyAnnotation->type,
                        $propertyAnnotation->message
                    );
                    $calculatedViolationCount++;
                } else {
                    //TODO Add more assertion types
                    continue;
                }

                $index = $violationPropertyIndexes[$reflectionProperty->name];
                $this->assertArrayHasKey('propertyPath', $violations[$index]);
                $this->assertEquals(
                    $reflectionProperty->name,
                    $violations[$index]['propertyPath']
                );
                $this->assertEquals(
                    $expectedMessage,
                    $violations[$index]['message']
                );
            }
        }
        $this->assertCount($calculatedViolationCount, $violations);
    }

    //</editor-fold>
}