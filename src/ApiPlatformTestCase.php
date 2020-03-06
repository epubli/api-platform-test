<?php

namespace Epubli\ApiPlatform\TestBundle;

use ApiPlatform\Core\Annotation\ApiProperty;
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
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
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

    /** @var Serializer */
    protected static $serializer;

    /** @var Annotationreader */
    protected static $annotationReader;

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

        $encoders = [new JsonEncoder()];
        $normalizers = [new DateTimeNormalizer(), new ObjectNormalizer()];
        self::$serializer = new Serializer($normalizers, $encoders);

        if (!self::$annotationReader) {
            self::$annotationReader = new AnnotationReader();
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
        $server = array_merge($server, $headers);

        // POST request doesn't follow 301, symfony creates 301 for trailing slash routes
        $uri = rtrim($uri, '/');
        if ($content instanceof \stdClass) {
            $json = self::$serializer->encode($content, 'json');
        } else {
            $json = !$content ?: self::$serializer->serialize($content, 'json');
        }

        self::$kernelBrowser->request(
            $method,
            $uri,
            $parameters,
            $files,
            $server,
            $json,
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
     *
     * @throws ReflectionException
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
            $reflectionClass = new ReflectionClass($transmittedData);
        } catch (ReflectionException $e) {
            $this->assertFalse(true, 'Failed due to ReflectionException');
            return;
        }
        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            if (!$this->isPropertyReadable($reflectionProperty)) {
                continue;
            }

            $this->assertArrayHasKey($reflectionProperty->name, $json);

            $propertyValue = $this->getPropertyValue(
                $reflectionProperty,
                $transmittedData
            );
            $dataType = $this->getPropertyType($reflectionProperty);
            if (in_array($dataType, array_keys($getTypeMapping), true)) {
                if ($json[$reflectionProperty->name] !== null) {
                    $this->{$getTypeMapping[$dataType]}(
                        $json[$reflectionProperty->name]
                    );
                } else {
                    $this->assertNull($json[$reflectionProperty->name]);
                }
            }

            if ($propertyValue !== null) {
                if ($propertyValue instanceof ArrayCollection) {
                    $this->assertEquals(
                        $propertyValue->toArray(),
                        $json[$reflectionProperty->name]
                    );
                } elseif ($propertyValue instanceof \DateTime) {
                    $this->assertEquals(
                        $propertyValue,
                        new \DateTime($json[$reflectionProperty->name])
                    );
                } else {
                    $this->assertEquals(
                        $propertyValue,
                        $json[$reflectionProperty->name]
                    );
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
     * @param ReflectionProperty $reflectionProperty
     *
     * @return bool
     */
    private function isPropertyReadable(ReflectionProperty $reflectionProperty): bool
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
     * @param ReflectionProperty $reflectionProperty
     * @param object $data
     *
     * @return mixed
     * @throws ReflectionException
     */
    private function getPropertyValue(
        ReflectionProperty $reflectionProperty,
        object $data
    ) {
        $reflectionClass = new ReflectionClass($data);
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

    private function getPropertyType(ReflectionProperty $reflectionProperty)
    {
        $annotations = self::$annotationReader->getPropertyAnnotations(
            $reflectionProperty
        );
        foreach ($annotations as $annotation) {
            $reflectionAnnotation = new ReflectionClass($annotation);
            if (in_array(
                $reflectionAnnotation->getNamespaceName(),
                [
                    'Doctrine\ODM\MongoDB\Mapping\Annotations',
                    'Doctrine\ORM\Mapping\Annotation'
                ]
            )
            ) {
                /** @var Doctrine\ODM\MongoDB\Mapping\Annotations|Doctrine\ORM\Mapping\Annotation $annotation */
                return $annotation->type;
            }
        }
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
            $reflectionClass = new ReflectionClass($data);
        } catch (ReflectionException $e) {
            $this->assertFalse(true, 'Failed due to ReflectionException');
            return;
        }
        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            /** @var ReflectionProperty $reflectionProperty */
            foreach (self::$annotationReader->getPropertyAnnotations(
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

    private function getNonAliasType($type)
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

    /**
     * @param ReflectionProperty $reflectionProperty
     *
     * @return bool
     */
    private function isPropertyWritable(ReflectionProperty $reflectionProperty): bool
    {
        /** @var bool[] $writables */
        $writables =
            array_map(
                static function (ApiProperty $x) {
                    return $x->writable;
                },
                array_filter(
                    self::$annotationReader->getPropertyAnnotations(
                        $reflectionProperty
                    ),
                    static function ($x) {
                        return $x instanceof ApiProperty;
                    }
                )
            );
        return !in_array(false, $writables, true);
    }
}
