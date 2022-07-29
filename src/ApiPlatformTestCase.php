<?php

namespace Epubli\ApiPlatform\TestBundle;

use Hautelook\AliceBundle\PhpUnit\RefreshDatabaseTrait;
use Symfony\Component\HttpFoundation\Response as STATUS;
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
            url: static::RESOURCE_URI,
            method: 'GET'
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
            url: static::RESOURCE_URI . $resource->getId(),
            method: 'GET'
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

        $this->assertUpdatedAResource($jsonResource);
    }

    /**
     * Test the create route
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
        //TODO: this is not okay... try catch maybe... not sure
        static::$client->getKernelBrowser()->catchExceptions(false);
        $this->expectException(\Exception::class); // TODO: MethodNotAllowedHttpException::class
        $this->request(static::RESOURCE_URI, 'POST', $this->getInvalidTestEntity());
        //TODO: this is not reached exception is thrown....
        self::assertResponseStatusCodeSame(STATUS::HTTP_OK);
    }

    //</editor-fold>

}
