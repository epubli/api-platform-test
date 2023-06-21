<?php

namespace Epubli\ApiPlatform\TestBundle;

use ApiPlatform\Core\Annotation\ApiProperty;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping\Column;
use Hautelook\AliceBundle\PhpUnit\RefreshDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * #Class ApiPlatformTest
 *
 * This class provides simple CRUD tests for an ApiPlatform-Entity
 *
 * Extend this class and provide a
 * RESOURCE_URI (e.g. '/api/languages/') and a
 * RESOURCE_CLASS (e.g. Language::class)
 * by overriding the consts in the TestCase.
 *
 * When you run your test the CRUD tests provided by this class will be executed as well,
 * so you can focus on the special methods of your entity.
 *
 * @package App\Tests\Api
 */
abstract class ApiPlatformTestCase extends ApiPlatformBaseTestCase
{
    // This trait provided by AliceBundle will take care of refreshing the database content to a known state before each test
    use RefreshDatabaseTrait;

    /** Endpoint to test (override in your testcase) */
    protected const RESOURCE_URI = '/';
    /** Entity class to test (override in your testcase) */
    protected const RESOURCE_CLASS = '';

    /**
     * ##Create a new TestEntity
     * Use a simple instance of the class that gets tested (e.g. new Book())
     * and set the attributes to a valid state. (not null, etc.)
     * This entity will get inserted to the database during the testCreateAResource.
     * This entity will be used to update a random database entity in testUpdateAResource
     * @return object of type static::RESOURCE_CLASS
     * @see testCreateAResource, testUpdateAResource
     */
    abstract protected function getTestEntity(): object;

    /**
     * ##Create an invalid TestEntity
     * The invalid value will be posted to the static::RESOURCE_URI and an error is expected.
     * Defaults to a simple stdClass
     * @return object
     * @see testThrowErrorWhenDataAreInvalid
     */
    protected function getInvalidTestEntity(): object
    {
        return new \stdClass();
    }

    //<editor-fold desc="*** CRUD Tests ***">

    /**
     * Test read on the collection.
     * Ensures:
     *  * the count is > 0
     *  * the hydra:totalItems matches the hydra:members count as well
     * @return void
     * @throws TransportExceptionInterface
     */
    public function testReadAResourceCollection(): void
    {
        $this->request(
            url: static::RESOURCE_URI
        );
        $this->assertReadAResourceCollection();
    }

    /**
     * Test read on a single resource
     * Ensures:
     *  * Status-Code 200,
     *  * Response is ld+json,
     *  * Read Entity has an id
     *  * Optional 'createdAt', 'updatedAt' are not empty
     * @return void
     * @throws TransportExceptionInterface
     * @throws \Exception
     * @throws DecodingExceptionInterface
     */
    public function testReadAResource(): void
    {
        $resource = $this->findOne(static::RESOURCE_CLASS);
        $this->request(
            url: static::RESOURCE_URI . $resource->getId()
        );

        $jsonResource = $this->decodeToJson($resource);
        $this->assertReadAResource($jsonResource);

    }

    /**
     * Test the update route
     * Use the TestEntity to be inserted into the database.
     * Ensures:
     *  * Status-Code 200
     *  * ld+json format
     *  * updated values are equal to the test entity
     * @return void
     * @throws \Exception | ExceptionInterface
     */
    public function testUpdateAResource(): void
    {
        $entity = $this->findOne(static::RESOURCE_CLASS);
        $resource = $this->getTestEntity();
        $jsonResource = $this->decodeToJson($resource);

        $this->request(
            url: static::RESOURCE_URI . $entity->getId(),
            method: 'PUT',
            content: $jsonResource
        );

        $this->assertUpdateAResource($jsonResource);
    }

    /**
     * Test the create-route
     * Add the test entity to the database and ensure status 200
     * @return void
     * @throws TransportExceptionInterface
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     */
    public function testCreateAResource(): void
    {
        $resource = $this->getTestEntity();
        $jsonResource = $this->decodeToJson($resource);
        $this->request(
            url: static::RESOURCE_URI,
            method: 'POST',
            content: $jsonResource
        );

        $this->assertCreateAResource($jsonResource);
    }

    /**
     * Test the delete-route by getting a random resource from the database and ensuring status code 204
     * @return void
     * @throws \Exception|ExceptionInterface
     */
    public function testDeleteAResource(): void
    {
        $resource = $this->findOne(static::RESOURCE_CLASS);
        $this->request(
            url: static::RESOURCE_URI . $resource->getId(),
            method: 'DELETE'
        );

        $this->assertDeleteAResource($resource->getId());
    }

    /**
     * TODO: this
     * We try to POST invalid data to the RESOURCE_URI and ensure an exception is thrown
     * @return void
     * @throws TransportExceptionInterface
     */
    public function testThrowErrorWhenDataAreInvalid(): void
    {
        $this->disableSymfonyExceptionHandling();
        $this->expectException(\Exception::class);
        $this->request(static::RESOURCE_URI, 'POST', $this->getInvalidTestEntity());
    }

    /**
     * We try to access an invalid route and expect a MethodNotAllowedHttpException is thrown
     */
    protected function testThrowErrorWhenRouteIsForbidden(): void
    {
        /** @noinspection PhpInternalEntityUsedInspection */
        $this->disableSymfonyExceptionHandling();
        $this->expectException(MethodNotAllowedHttpException::class);
    }

    //</editor-fold>

    //<editor-fold desc="'*** Deprecated on v0.3.0 -> will be removed with the next version ***'">

    /**
     * Deprecated on v0.3.0 - renamed
     *  -> will be removed with the next version
     * @return array
     * @throws TransportExceptionInterface
     * @deprecated use getResponseAsJson instead
     */
    public function getJson(): array
    {
        return $this->getResponseAsJson();
    }

    /**
     * Deprecated on v0.3.0 - non-static
     *  -> will be removed with the next version
     * @param mixed|null $content
     * @return string|null $json
     * @deprecated use non-static serializeToJson with serializeContext instead
     */
    protected static function serializeToJsonForPOST(mixed $content): ?string
    {
        if (!$content) {
            return null;
        } elseif (is_string($content)) {
            return $content;
        } elseif ($content instanceof \stdClass) {
            return self::$serializer->encode($content, 'json');
        } else {
            return self::$serializer->serialize($content, 'json');
        }
    }

    /**
     * Deprecated on v0.3.0 - renamed
     *  -> will be removed with the next version
     * @return object
     * @deprecated use getTestEntity instead
     */
    protected function getDemoEntity(): object
    {
        return $this->getTestEntity();
    }

    /**
     * Deprecated on v0.3.0 - renamed
     *  -> will be removed with the next version
     * @return void
     * @throws TransportExceptionInterface
     * @deprecated use testReadAResourceCollection instead
     */
    protected function testRetrieveTheResourceList(): void
    {
        $this->testReadAResourceCollection();
    }

    /**
     * Deprecated on v0.3.0 - renamed
     *  -> will be removed with the next version
     * @return void
     * @throws DecodingExceptionInterface
     * @throws TransportExceptionInterface
     * @deprecated use testReadResource instead
     */
    protected function testRetrieveAResource(): void
    {
        $this->testReadAResource();
    }

    /**
     * Deprecated on v0.3.0
     *  -> will be removed with the next version
     * @return void
     * @throws TransportExceptionInterface
     * @deprecated use assertTimestampable instead
     */
    protected function assertHasGedmoDates(): void
    {
        $json = $this->getResponseAsJson();
        foreach (['createdAt', 'updatedAt'] as $dateProp) {
            $this->assertArrayHasKey($dateProp, $json);
            $this->assertIsString($json[$dateProp]);
            $this->assertNotEmpty($json[$dateProp]);
        }
    }

    /**
     * Deprecated on v0.3.0 - not needed anymore
     *  -> will be removed with the next version
     * @param \ReflectionProperty $reflectionProperty
     * @return bool
     * @deprecated not needed anymore
     */
    protected function isPropertyReadable(\ReflectionProperty $reflectionProperty): bool
    {
        /** @var bool[] $readables */
        $readables =
            array_map(
                function (ApiProperty $x) {
                    return $x->readable;
                },
                array_filter(
                    self::$annotationReader->getPropertyAnnotations(
                        $reflectionProperty
                    ),
                    function ($x) {
                        return $x instanceof ApiProperty;
                    }
                )
            );
        return !in_array(false, $readables, true);
    }

    /**
     * Deprecated on v0.3.0 - not needed anymore
     *  -> will be removed with the next version
     * @param \ReflectionProperty $reflectionProperty
     * @return ?string
     * @deprecated not needed anymore
     */
    protected function getPropertyType(\ReflectionProperty $reflectionProperty): ?string
    {
        $annotations = self::$annotationReader->getPropertyAnnotations(
            $reflectionProperty
        );
        foreach ($annotations as $annotation) {
            $reflectionAnnotation = new \ReflectionClass($annotation);
            if ($reflectionAnnotation->getNamespaceName() == 'Doctrine\ORM\Mapping\Column'
            ) {
                /** @var Column $annotation */
                return $annotation->type;
            }
        }
        return null;
    }

    /**
     * Deprecated on v0.3.0 - not needed anymore
     *  -> will be removed with the next version
     * @param $newValue
     * @param $oldValue
     * @deprecated not needed anymore
     */
    protected function assertUpdateSuccess($newValue, $oldValue): void
    {
        $this->assertNotEquals($oldValue, $newValue);
    }

    /**
     * Deprecated on v0.3.0 - not needed anymore
     *  -> will be removed with the next version
     * @param object $transmittedData
     * @param array $jsonResponse
     * @throws \ReflectionException
     * @throws \Exception
     * @deprecated not needed anymore
     */
    protected function assertCreateSuccess(object $transmittedData, array $jsonResponse): void
    {
//        $this->assertHasId($transmittedData, $jsonResponse);
        $this->assertTimestampable($jsonResponse);

        $getTypeMapping = [
            'boolean' => 'assertIsBool',
            'bool' => 'assertIsBool',
            'integer' => 'assertIsInt',
            'int' => 'assertIsInt',
            'double' => 'assertIsFloat',
            'string' => 'assertIsString',
            'date' => 'assertIsString',
            'array' => 'assertIsArray',
            'object' => 'assertIsObject',
        ];

        try {
            $reflectionClass = new \ReflectionClass($transmittedData);
        } catch (\Exception) {
            $this->fail('Failed due to ReflectionException');
        }
        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            /** @noinspection PhpDeprecationInspection */
            if (!$this->isPropertyReadable($reflectionProperty)) {
                continue;
            }

            $propertyValue = $this->getPropertyValue(
                $reflectionProperty,
                $transmittedData
            );

            if ($propertyValue !== null) {
                $this->assertArrayHasKey($reflectionProperty->name, $jsonResponse);
            }

            if ($reflectionProperty->name === 'createdAt'
                || $reflectionProperty->name === 'updatedAt') {
                continue;
            }

            /** @noinspection PhpDeprecationInspection */
            $dataType = $this->getPropertyType($reflectionProperty);
            if (in_array($dataType, array_keys($getTypeMapping), true)) {
                if ($jsonResponse[$reflectionProperty->name] !== null) {
                    $this->{$getTypeMapping[$dataType]}(
                        $jsonResponse[$reflectionProperty->name]
                    );
                } else {
                    $this->assertNull($jsonResponse[$reflectionProperty->name]);
                }
            }

            if ($propertyValue !== null) {
                if ($propertyValue instanceof ArrayCollection) {
                    $this->assertEquals(
                        $propertyValue->toArray(),
                        $jsonResponse[$reflectionProperty->name]
                    );
                } elseif ($propertyValue instanceof \DateTime) {
                    $this->assertEquals(
                        $propertyValue,
                        new \DateTime($jsonResponse[$reflectionProperty->name])
                    );
                } elseif (is_object($propertyValue)) {
                    //SKIP assertion, because it is probably an entity.
                    //And entities will be returned as an url instead of the whole object.
                    //So any assertion will fail.
                } else {
                    $this->assertEquals(
                        $propertyValue,
                        $jsonResponse[$reflectionProperty->name]
                    );
                }
            }
        }
    }

    /**
     * Deprecated on v0.3.0 - renamed
     *  -> will be removed with the next version
     * @param int $allowedTimeDifferenceInSeconds
     * @throws TransportExceptionInterface
     * @deprecated renamed
     */
    protected function assertTimestampsForCreate(int $allowedTimeDifferenceInSeconds = 120)
    {
        $this->assertCreateAResourceTimestamps($allowedTimeDifferenceInSeconds);
    }

    /**
     * Deprecated on v0.3.0 - not needed anymore
     *  -> will be removed with the next version
     * @return void
     * @throws TransportExceptionInterface
     * @deprecated not needed anymore
     */
    protected function assertHasId(): void
    {
        /** @noinspection PhpDeprecationInspection */
        $json = $this->getJson();
        $this->assertArrayHasKey('id', $json);
        $this->assertIsInt($json['id']);
        $this->assertNotEmpty($json['id']);
    }

    /**
     * Deprecated on v0.3.0 - not needed anymore
     *  -> will be removed with the next version
     * @var KernelBrowser
     * @deprecated its internal via the http client .... dont use it?!
     */
    static KernelBrowser $kernelBrowser;

    // TODO: Remove next version
    public function setUp(): void
    {
        parent::setUp();
        /** @noinspection PhpDeprecationInspection */
        /** @noinspection PhpInternalEntityUsedInspection */
        self::$kernelBrowser = self::$client->getKernelBrowser();
    }

    /**
     * Deprecated on v0.3.0 - renamed and modified
     *  -> will be removed with the next version
     * Assert that the updatedAt attribute is set and did not take too long
     * @throws \Exception
     * @throws TransportExceptionInterface
     * @deprecated use assertUpdateAResourceTimestamps instead
     */
    protected function assertTimestampsForUpdate(int $allowedTimeDifferenceInSeconds = 120)
    {
        $jsonResponse = $this->getResponseAsJson();
        $this->assertTimestampable($jsonResponse);
        $updatedAt = new \DateTime($jsonResponse['updatedAt']);
        $difference = $updatedAt->diff(new \DateTime());
        $this->assertLessThan(
            $allowedTimeDifferenceInSeconds,
            $difference->s,
            "updated_at was last touched more than $allowedTimeDifferenceInSeconds seconds ago"
        );
    }


    //</editor-fold>
}
