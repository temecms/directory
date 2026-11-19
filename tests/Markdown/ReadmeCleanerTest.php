<?php

declare(strict_types=1);

namespace Teme\Directory\Tests\Markdown;

use PHPUnit\Framework\TestCase;
use Teme\Directory\Markdown\ImageCopier;
use Teme\Directory\Markdown\Location;
use Teme\Directory\Markdown\ReadmeCleaner;

final class ReadmeCleanerTest extends TestCase
{
    /** @var list<array{string, bool}> */
    private array $copied = [];

    /** @var list<string> */
    private array $missing = [];

    public function test_the_first_heading_is_dropped_because_the_page_has_the_title(): void
    {
        self::assertSame("Audits every post.\n", $this->clean("# SEO Audit\n\nAudits every post.\n"));
        self::assertSame("Audits every post.\n", $this->clean("SEO Audit\n=========\n\nAudits every post.\n"));
    }

    public function test_a_first_heading_below_level_one_is_kept(): void
    {
        self::assertSame("## Features\n\nFast.\n", $this->clean("## Features\n\nFast.\n"));
    }

    public function test_headings_stay_between_levels_two_and_four(): void
    {
        self::assertSame("Intro.\n\n## Usage\n\n#### Deep\n", $this->clean("# Name\n\nIntro.\n\n# Usage\n\n###### Deep\n"));
    }

    public function test_raw_html_blocks_are_removed_and_their_words_kept(): void
    {
        $readme = "# Name\n\n<p align=\"center\">\n  <img src=\"logo.png\" width=\"200\">\n</p>\n\n<details>\n<summary>More options</summary>\n\nText.\n\n</details>\n";

        self::assertSame("More options\n\nText.\n", $this->clean($readme));
    }

    public function test_scripts_styles_and_comments_are_removed_with_everything_in_them(): void
    {
        $readme = "Intro.\n\n<script>\nalert(1)\n</script>\n\n<!-- a note\nover lines -->\n\n<style>p { color: red }</style>\n\nEnd.\n";

        self::assertSame("Intro.\n\nEnd.\n", $this->clean($readme, false));
    }

    public function test_inline_html_is_removed_and_its_words_kept(): void
    {
        self::assertSame("Press Ctrl and S.\n", $this->clean('Press <kbd>Ctrl</kbd> and <b onclick="x()">S</b>.', false));
    }

    public function test_code_is_left_exactly_as_written(): void
    {
        $readme = "Use `<br>` here.\n\n```html\n<p align=\"center\">[a](b.md)</p>\n```\n\n~~~\n# not a heading\n~~~\n";

        self::assertSame($readme, $this->clean($readme, false));
    }

    public function test_badges_are_removed(): void
    {
        $readme = "# Name\n\n[![Tests](https://github.com/acme/seo/actions/workflows/tests.yml/badge.svg)](https://github.com/acme/seo/actions) ![Licence](https://img.shields.io/badge/licence-MIT-blue)\n\nAudits every post.\n";

        self::assertSame("Audits every post.\n", $this->clean($readme));
        self::assertSame([], $this->copied);
    }

    public function test_relative_links_point_at_the_file_at_the_release_tag(): void
    {
        self::assertSame(
            "See [the guide](https://github.com/acme/seo/blob/v1.0.0/docs/guide.md), [this](https://github.com/acme/seo/blob/v1.0.0/docs/a.md#part) and [root](https://github.com/acme/seo/blob/v1.0.0/LICENSE \"Licence\").\n",
            $this->clean('See [the guide](docs/guide.md), [this](./docs/a.md#part) and [root](/LICENSE "Licence").', false),
        );
    }

    public function test_only_https_mailto_and_anchor_links_are_kept(): void
    {
        self::assertSame(
            "[a](https://example.com), [b](mailto:a@example.com), [c](#install), d, e and <https://example.com/x>.\n",
            $this->clean('[a](https://example.com), [b](mailto:a@example.com), [c](#install), [d](http://example.com), [e](javascript:alert(1)) and <https://example.com/x>.', false),
        );
    }

    public function test_a_link_out_of_the_repository_keeps_only_its_words(): void
    {
        self::assertSame("Read up.\n", $this->clean('Read [up](../../etc/passwd).', false));
    }

    public function test_reference_links_are_resolved_too(): void
    {
        self::assertSame(
            "See [the guide][g].\n\n[g]: https://github.com/acme/seo/blob/v1.0.0/docs/guide.md\n",
            $this->clean("See [the guide][g].\n\n[g]: docs/guide.md\n", false),
        );
    }

    public function test_images_are_copied_and_point_at_the_copy(): void
    {
        $cleaned = $this->clean("![The audit](docs/audit.png)\n\n![Chart](https://example.com/chart.webp \"Clicks\")\n", false);

        self::assertSame("![The audit](https://copy/docs/audit.png)\n\n![Chart](https://copy/https://example.com/chart.webp \"Clicks\")\n", $cleaned);
        self::assertSame([['docs/audit.png', true], ['https://example.com/chart.webp', false]], $this->copied);
    }

    public function test_an_image_that_cannot_be_copied_is_dropped_never_left_broken(): void
    {
        $this->missing = ['docs/gone.png'];

        self::assertSame("Before. After.\n", $this->clean('Before. ![Gone](docs/gone.png) After.', false));
        self::assertSame("Before.\n", $this->clean("Before.\n\n![Plain](http://example.com/a.png)\n", false));
    }

    public function test_at_most_ten_images_are_kept(): void
    {
        $readme = implode("\n\n", array_map(static fn (int $n): string => "![{$n}](shots/{$n}.png)", range(1, 12)))."\n";

        $cleaned = $this->clean($readme, false);

        self::assertSame(10, substr_count($cleaned, '!['));
        self::assertStringNotContainsString('shots/11.png', $cleaned);
    }

    public function test_a_readme_over_100_kb_is_cut_with_a_link_to_the_rest(): void
    {
        $paragraph = str_repeat('word ', 199)."word\n\n";
        $readme = str_repeat($paragraph, 120);

        $cleaned = $this->clean($readme, false);

        self::assertLessThanOrEqual(100 * 1024, strlen($cleaned));
        self::assertStringEndsWith("\n\n[Read the rest on GitHub](https://github.com/acme/seo/blob/v1.0.0/README.md)\n", $cleaned);
        self::assertStringStartsWith($paragraph, $cleaned);
    }

    public function test_the_first_paragraph_is_the_summary_fallback(): void
    {
        self::assertSame('Audits every post, with links.', ReadmeCleaner::firstParagraph("## Intro\n\nAudits **every** post, with [links](https://example.com).\n\nMore.\n"));
        self::assertNull(ReadmeCleaner::firstParagraph("## Only a heading\n"));
    }

    private function clean(string $markdown, bool $dropFirstHeading = true): string
    {
        $copier = new class($this->copied, $this->missing) implements ImageCopier
        {
            /**
             * @param  list<array{string, bool}>  $copied
             * @param  list<string>  $missing
             */
            public function __construct(private array &$copied, private array $missing) {}

            public function copy(string $source, bool $inPackage): ?string
            {
                $this->copied[] = [$source, $inPackage];

                return in_array($source, $this->missing, true) ? null : 'https://copy/'.$source;
            }
        };

        return (new ReadmeCleaner($copier))->clean($markdown, new Location('acme', 'seo', 'v1.0.0'), $dropFirstHeading);
    }
}
