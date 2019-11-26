<?php

namespace Epubli\ApiPlatform\TestBundle;

use Doctrine\Common\Annotations\AnnotationException;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Collections\ArrayCollection;
use Faker\Factory;
use Faker\Generator;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Type;

/**
 * Class ApiPlatformTest
 * @package App\Tests\Api
 */
abstract class ApiPlatformTestCase extends WebTestCase
{
    /**
     * @var KernelBrowser
     */
    protected static $kernelBrowser;

    /**
     * @var Generator
     */
    protected static $faker;

    public function setUp(): void
    {
        parent::setUp();
        self::init();
    }

    public static function init(): void
    {
        self::$kernelBrowser = self::createClient();
        if (!self::$faker) {
            self::$faker = Factory::create('de_DE');
        }
        if (!self::$container) {
            self::$kernel = self::bootKernel();
            self::$container = self::$kernel->getContainer();
        }
    }

    /**
     * @param string $uri
     * @param string $method
     * @param object|null $content
     * @param array $files
     * @param array $parameters
     * @param array $headers
     * @param bool $changeHistory
     *
     * @return Response
     */
    protected function request(
        string $uri,
        string $method = 'GET',
        object $content = null,
        array $files = [],
        array $parameters = [],
        array $headers = [],
        bool $changeHistory = true
    ): Response {
        $server = [
            'CONTENT_TYPE' => 'application/ld+json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ];
        foreach ($headers as $key => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
        }

        // POST request doesn't follow 301, symfony creates 301 for trailing slash routes
        $uri = rtrim($uri, '/');

        self::$kernelBrowser->request(
            $method,
            $uri,
            $parameters,
            $files,
            $server,
            json_encode($content, JSON_THROW_ON_ERROR),
            $changeHistory
        );

        return self::$kernelBrowser->getResponse();
    }

    /**
     * @param $class
     * @param array $criteria
     *
     * @return mixed
     */
    abstract protected function findOne(string $class, array $criteria = []);


    /**
     * @param object $transmittedData
     */
    protected function assertCreateSuccess(object $transmittedData): void
    {
        $json = $this->getJson();
        $this->assertHasId();

        foreach (['createdAt', 'updatedAt'] as $dateProp) {
            if (property_exists($transmittedData, $dateProp)) {
                $this->assertArrayHasKey($dateProp, $json);
                $this->assertIsString($json[$dateProp]);
                $this->assertNotEmpty($json[$dateProp]);
            }
        }

        $getTypeMapping = [
            'boolean' => 'assertIsBool',
            'integer' => 'assertIsInt',
            'double' => 'assertIsFloat',
            'string' => 'assertIsString',
            'array' => 'assertIsArray',
            'object' => 'assertIsObject',
        ];

        foreach (array_keys(get_object_vars($transmittedData)) as $property) {
            $this->assertArrayHasKey($property, $json);

            $dataType = gettype($transmittedData->{$property});
            if (in_array($dataType, $getTypeMapping, true)) {
                $this->{$getTypeMapping[$dataType]}($json[$property]);
            }
            if ($transmittedData->{$property} !== null) {
                if ($transmittedData->{$property} instanceof ArrayCollection) {
                    $this->assertEquals($transmittedData->{$property}->toArray(), $json[$property]);
                } else {
                    $this->assertEquals($transmittedData->{$property}, $json[$property]);
                }
            }
        }
    }

    /**
     * @return array
     */
    protected function getJson(): array
    {
        return json_decode(
            $this->lastResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * @return Response
     */
    protected function lastResponse(): Response
    {
        return self::$kernelBrowser->getResponse();
    }

    /**
     *
     */
    protected function assertHasId(): void
    {
        $json = $this->getJson();
        $this->assertArrayHasKey('id', $json);
        $this->assertIsInt($json['id']);
        $this->assertNotEmpty($json['id']);
    }

    /**
     * @param object $data
     */
    protected function assertViolations(object $data): void
    {
        $json = $this->getJson();
        $this->assertArrayHasKey('violations', $json);
        $violations = $json['violations'];
        $violationPropertyIndexes = array_flip(
            array_column($violations, 'propertyPath')
        );
        $calculatedViolationCount = 0;
        try {
            $annotationReader = new AnnotationReader();
        } catch (AnnotationException $e) {
            $this->assertFalse(true, 'Failed due to AnnotationException');
            return;
        }
        try {
            $reflectionClass = new ReflectionClass($data);
        } catch (ReflectionException $e) {
            $this->assertFalse(true, 'Failed due to ReflectionException');
            return;
        }
        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            /** @var ReflectionProperty $reflectionProperty */
            foreach ($annotationReader->getPropertyAnnotations(
                $reflectionProperty
            ) as $propertyAnnotation) {
                try {
                    $reflectionAnnotation = new ReflectionClass($propertyAnnotation);
                } catch (ReflectionException $e) {
                    $this->assertFalse(true, 'Failed due to ReflectionException');
                    continue;
                }
                if ($reflectionAnnotation->getNamespaceName()
                    !== 'Symfony\Component\Validator\Constraints'
                ) {
                    continue;
                }
                $propertyValue = $data->{$reflectionProperty->name};
                $propertyType = strtolower(gettype($propertyValue));

                if ($propertyAnnotation instanceof NotBlank
                    && empty($propertyValue)
                ) {
                    /** @var NotBlank $propertyAnnotation */
                    $expectedMessage = $propertyAnnotation->message;
                    $calculatedViolationCount++;
                } elseif ($propertyAnnotation instanceof NotNull
                    && $propertyValue === null
                ) {
                    /** @var NotNull $propertyAnnotation */
                    $expectedMessage = $propertyAnnotation->message;
                    $calculatedViolationCount++;
                } elseif ($propertyAnnotation instanceof Type
                    && !empty($propertyValue)
                    && $propertyType !== $propertyAnnotation->type
                ) {
                    /** @var Type $propertyAnnotation */
                    $expectedMessage = str_replace(
                        '{{ type }}',
                        $propertyAnnotation->type,
                        $propertyAnnotation->message
                    );
                    $calculatedViolationCount++;
                } else {
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

    /**
     *
     */
    protected function assertLdJsonHeader(): void
    {
        self::assertResponseHeaderSame(
            'Content-Type',
            'application/ld+json; charset=utf-8'
        );
    }

    /**
     *
     */
    protected function assertHasGedmoDates(): void
    {
        $json = $this->getJson();
        foreach (['createdAt', 'updatedAt'] as $dateProp) {
            $this->assertArrayHasKey($dateProp, $json);
            $this->assertIsString($json[$dateProp]);
            $this->assertNotEmpty($json[$dateProp]);
        }
    }

    /**
     * @param $newValue
     * @param $oldValue
     * @param $responseValue
     */
    protected function assertUpdateSuccess($newValue, $oldValue): void
    {
        $this->assertNotEquals($oldValue, $newValue);
    }

    /**
     * @param int $count
     */
    protected function assertCollectionCount(int $count): void
    {
        $json = $this->getJson();
        $this->assertArrayHasKey('hydra:totalItems', $json);
        $this->assertEquals($count, $json['hydra:totalItems']);

        $this->assertArrayHasKey('hydra:member', $json);
        $this->assertCount($count, $json['hydra:member']);
    }

    protected function assertResourcePropertyCount(int $count): void
    {
        $json = $this->getJson();
        $this->assertCount($count, $json);
    }

    abstract protected function testRetrieveTheResourceList(): void;

    abstract protected function testRetrieveAResource(): void;

    abstract protected function testUpdateAResource(): void;

    abstract protected function testThrowErrorWhenDataAreInvalid(): void;

    abstract protected function testCreateAResource(): void;

    abstract protected function testDeleteAResource(): void;
}
