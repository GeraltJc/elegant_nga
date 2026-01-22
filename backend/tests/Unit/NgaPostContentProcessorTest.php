<?php

namespace Tests\Unit;

use App\Services\Nga\NgaPostContentProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class NgaPostContentProcessorTest extends TestCase
{
    #[DataProvider('provideCases')]
    public function test_to_safe_html(array $case): void
    {
        $processor = NgaPostContentProcessor::makeDefault();
        $output = $this->normalizeHtml(
            $processor->toSafeHtml((string) $case['input'], (string) ($case['format'] ?? 'ubb'))
        );

        if (array_key_exists('expected', $case)) {
            $this->assertSame($this->normalizeHtml((string) $case['expected']), $output);
        }

        if (array_key_exists('expected_contains', $case) && is_array($case['expected_contains'])) {
            foreach ($case['expected_contains'] as $fragment) {
                $this->assertStringContainsString((string) $fragment, $output);
            }
        }

        if (array_key_exists('expected_not_contains', $case) && is_array($case['expected_not_contains'])) {
            foreach ($case['expected_not_contains'] as $fragment) {
                $this->assertStringNotContainsString((string) $fragment, $output);
            }
        }
    }

    public static function provideCases(): iterable
    {
        $path = __DIR__.'/../Fixtures/ubb-cases.json';
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read fixtures: {$path}");
        }

        $cases = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($cases)) {
            throw new RuntimeException("Invalid fixtures payload: {$path}");
        }

        foreach ($cases as $case) {
            if (!is_array($case) || !isset($case['name'])) {
                continue;
            }
            yield $case['name'] => [$case];
        }
    }

    private function normalizeHtml(string $html): string
    {
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $html = str_replace(['<br />', '<br/>'], '<br>', $html);

        return trim($html);
    }
}
