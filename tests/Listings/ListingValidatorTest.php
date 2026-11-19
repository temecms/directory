<?php

declare(strict_types=1);

namespace Teme\Directory\Tests\Listings;

use PHPUnit\Framework\TestCase;
use Teme\Directory\Listings\Categories;
use Teme\Directory\Listings\ListingValidator;

final class ListingValidatorTest extends TestCase
{
    private ListingValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ListingValidator(new Categories([
            'plugin' => ['seo' => 'SEO', 'forms' => 'Forms'],
            'theme' => ['blog' => 'Blog'],
        ]));
    }

    public function test_a_good_listing_has_no_problems(): void
    {
        self::assertSame([], $this->problems('listings/acme/seo-audit.json', $this->listing()));
    }

    public function test_the_shipped_listings_and_categories_are_valid(): void
    {
        $root = dirname(__DIR__, 2);
        $validator = new ListingValidator(Categories::fromFile($root.'/categories.json'));

        foreach (glob($root.'/listings/*/*.json') ?: [] as $file) {
            $path = substr($file, strlen($root) + 1);
            self::assertSame([], $validator->validate($path, (string) file_get_contents($file), null, false), $path);
        }
    }

    public function test_the_file_must_be_named_after_a_composer_name(): void
    {
        self::assertSame(
            ['Name the file listings/<vendor>/<name>.json, after the package\'s Composer name, in lower case.'],
            $this->problems('listings/Acme/SEO.json', $this->listing()),
        );
        self::assertSame(
            ['Name the file listings/<vendor>/<name>.json, after the package\'s Composer name, in lower case.'],
            $this->problems('listings/seo.json', $this->listing()),
        );
    }

    public function test_broken_json_is_named(): void
    {
        self::assertSame(['The file is not valid JSON.'], $this->problems('listings/acme/seo-audit.json', '{"repository":'));
    }

    public function test_each_field_is_checked_in_plain_words(): void
    {
        $problems = $this->problems('listings/acme/seo-audit.json', $this->listing([
            'repository' => 'https://gitlab.com/acme/seo',
            'kind' => 'module',
            'categories' => [],
            'picked' => 'no',
            'stars' => 5,
        ]));

        self::assertSame([
            'Unknown field: stars.',
            'repository must be a public GitHub address, such as https://github.com/<owner>/<repo>.',
            'kind must be plugin or theme.',
            'categories must name one to three categories.',
            'picked must be true or false.',
        ], $problems);
    }

    public function test_categories_come_from_the_list_for_the_kind(): void
    {
        self::assertSame(
            ['blog is not a plugin category. The plugin categories are: seo, forms.'],
            $this->problems('listings/acme/seo-audit.json', $this->listing(['categories' => ['seo', 'blog']])),
        );
        self::assertSame(
            ['categories must name one to three categories.'],
            $this->problems('listings/acme/seo-audit.json', $this->listing(['categories' => ['seo', 'forms', 'seo', 'forms']])),
        );
    }

    public function test_missing_fields_are_named(): void
    {
        self::assertSame(
            ['repository is missing.', 'kind is missing.', 'categories is missing.', 'picked is missing.'],
            $this->problems('listings/acme/seo-audit.json', '{}'),
        );
    }

    public function test_only_maintainers_set_picked(): void
    {
        $picked = $this->listing(['picked' => true]);

        self::assertSame(
            ['Only the directory\'s maintainers set picked to true. Set it to false.'],
            $this->validator->validate('listings/acme/seo-audit.json', $picked, null, false),
        );
        self::assertSame([], $this->validator->validate('listings/acme/seo-audit.json', $picked, null, true));
        self::assertSame([], $this->validator->validate('listings/acme/seo-audit.json', $picked, $picked, false));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function listing(array $overrides = []): string
    {
        return (string) json_encode(array_replace([
            'repository' => 'https://github.com/acme/seo-audit',
            'kind' => 'plugin',
            'categories' => ['seo'],
            'picked' => false,
        ], $overrides));
    }

    /**
     * @return list<string>
     */
    private function problems(string $path, string $contents): array
    {
        return $this->validator->validate($path, $contents, null, false);
    }
}
