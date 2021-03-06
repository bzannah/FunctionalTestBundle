<?php

declare(strict_types=1);

namespace Speicher210\FunctionalTestBundle\FailTestExpectedOutputFileUpdater;

use Coduo\PHPMatcher\PHPMatcher;
use SebastianBergmann\Comparator\ComparisonFailure;

final class JsonFileUpdater
{
    public const DEFAULT_MATCHER_PATTERNS = [
        '@string@',
        '@integer@',
        '@number@',
        '@double@',
        '@boolean@',
        '@array@',
        '@...@',
        '@null@',
        '@*@',
        '@wildcard@',
        '@uuid@',
    ];

    /**
     * Fields that will always be updated with a fixed value.
     *
     * Ex: ['createdAt' => '@string@.isDateTime()']
     *
     * @var array<string,string>
     */
    private $fields;

    /**
     * Array of patterns that should be kept when updating.
     *
     * @var string[]
     */
    private $matcherPatterns;

    /**
     * @param string[] $fields          The fields to update in the expected output.
     * @param string[] $matcherPatterns
     */
    public function __construct(array $fields = [], array $matcherPatterns = self::DEFAULT_MATCHER_PATTERNS)
    {
        $this->fields          = $fields;
        $this->matcherPatterns = $matcherPatterns;
    }

    public function updateExpectedFile(string $expectedFile, ComparisonFailure $comparisonFailure) : void
    {
        if (! \file_exists($expectedFile)) {
            return;
        }

        // Always encode and decode in order to convert everything into an array.
        $expected = $originalExpected = $comparisonFailure->getExpected();
        if ($expected !== null) {
            $expected = \json_decode(\json_encode($expected), true);
            $expected = $this->parseExpectedData($expected, [], $originalExpected);
            if (\json_last_error() !== \JSON_ERROR_NONE) {
                // probably not expecting json.
                return;
            }
        } else {
            $expected = [];
        }

        // Always encode and decode in order to convert everything into an array.
        $actual = \json_decode(\json_encode($comparisonFailure->getActual()), true);
        if (\json_last_error() !== \JSON_ERROR_NONE) {
            // probably not expecting json.
            return;
        }

        try {
            \array_walk_recursive(
                $actual,
                function (&$value, $key) : void {
                    if (! \array_key_exists($key, $this->fields)) {
                        return;
                    }

                    $value = $this->fields[$key];
                }
            );

            $actual = $this->updateExpectedOutput($actual, $expected);

            // Indent the output with 2 spaces instead of 4.
            $data = \preg_replace_callback(
                '/^ +/m',
                static function ($m) {
                    return \str_repeat(' ', \strlen($m[0]) / 2);
                },
                \json_encode($actual, \JSON_PRETTY_PRINT)
            );

            \file_put_contents($expectedFile, $data);
        } catch (\Throwable $e) {
            print $e->getTraceAsString();
            exit;
        }
    }

    /**
     * Update the expected output.
     *
     * @param mixed[] $actual
     * @param mixed[] $expected
     *
     * @return mixed[]
     */
    private function updateExpectedOutput(array $actual, array $expected) : array
    {
        foreach ($actual as $actualKey => &$actualField) {
            if (! isset($expected[$actualKey])) {
                continue;
            }

            if (\is_array($actualField)) {
                if (\count($actualField) === 0) {
                    // Value for actual should be an empty object if expected had any properties, otherwise empty array.
                    $actualField = \is_array(\json_decode(\json_encode($expected[$actualKey]))) ? [] : new \stdClass();
                    continue;
                }

                if (\is_array($expected[$actualKey])) {
                    $actualField = $this->updateExpectedOutput($actualField, $expected[$actualKey]);
                    continue;
                }

                if (\is_object($expected[$actualKey])) {
                    // This is possible only for empty objects so we can safely pass an empty array as $expected.
                    $actualField = $this->updateExpectedOutput($actualField, []);
                    continue;
                }
            }

            foreach ($this->matcherPatterns as $matcherPattern) {
                if (\is_string($expected[$actualKey]) && \strpos($expected[$actualKey], $matcherPattern) === 0) {
                    if (! PHPMatcher::match($actualField, $expected[$actualKey])) {
                        break;
                    }

                    $actualField = $expected[$actualKey];
                    break;
                }
            }
        }

        return $actual;
    }

    /**
     * Perform additional parsing for array with expected data based on the original expected.
     *
     * @param mixed[]  $expectedData
     * @param string[] $parentKeys
     * @param mixed    $originalExpected
     *
     * @return mixed[]
     */
    private function parseExpectedData(array &$expectedData, array $parentKeys, $originalExpected) : array
    {
        if (\is_object($originalExpected)) {
            foreach ($expectedData as $key => &$value) {
                $keys = $parentKeys;
                if (! \is_array($value)) {
                    continue;
                }

                $keys[] = $key;
                if ($value === []) {
                    $value = $this->getOriginalEmptyJsonValue($originalExpected, $keys);
                } else {
                    $value = $this->parseExpectedData($value, $keys, $originalExpected);
                }
            }
        }

        return $expectedData;
    }

    /**
     * Try to determine if original expected contained empty object or empty array.
     *
     * @param mixed    $originalExpected
     * @param string[] $keys
     *
     * @return mixed Either empty array or empty object
     */
    private function getOriginalEmptyJsonValue($originalExpected, array $keys)
    {
        if (! \is_object($originalExpected)) {
            return [];
        }

        $key = \array_shift($keys);
        if (isset($originalExpected->{$key})) {
            if (\count($keys) > 0) {
                return $this->getOriginalEmptyJsonValue($originalExpected->{$key}, $keys);
            }

            return $originalExpected->{$key};
        }

        return [];
    }
}
