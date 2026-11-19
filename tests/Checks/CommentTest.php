<?php

declare(strict_types=1);

namespace Teme\Directory\Tests\Checks;

use PHPUnit\Framework\TestCase;
use Teme\Directory\Checks\Comment;
use Teme\Directory\Checks\InstallResults;

final class CommentTest extends TestCase
{
    public function test_install_results_are_complete_only_when_every_teme_version_answered(): void
    {
        $results = new InstallResults([
            ['name' => 'acme/seo-audit', 'version' => '1.0.0', 'teme' => 'v1.1.0', 'passed' => true, 'checks' => [['passed' => true, 'words' => 'It installs on Teme v1.1.0 and is enabled.']], 'log' => ''],
        ]);

        self::assertNull($results->settled('acme/seo-audit', '1.0.0', ['v1.1.0', 'v1.2.0']));
        self::assertSame(['passed' => true, 'words' => []], $results->settled('acme/seo-audit', '1.0.0', ['v1.1.0']));
    }

    public function test_one_failing_teme_version_fails_the_release_with_its_words(): void
    {
        $results = new InstallResults([
            ['name' => 'acme/seo-audit', 'version' => '1.0.0', 'teme' => 'v1.1.0', 'passed' => true, 'checks' => [['passed' => true, 'words' => 'It installs on Teme v1.1.0 and is enabled.']], 'log' => ''],
            ['name' => 'acme/seo-audit', 'version' => '1.0.0', 'teme' => 'v1.2.0', 'passed' => false, 'checks' => [['passed' => false, 'words' => 'It does not install on Teme v1.2.0: Composer could not add it.']], 'log' => 'x'],
        ]);

        self::assertSame(
            ['passed' => false, 'words' => ['It does not install on Teme v1.2.0: Composer could not add it.']],
            $results->settled('acme/seo-audit', '1.0.0', ['v1.1.0', 'v1.2.0']),
        );
    }

    public function test_the_comment_lists_each_check_in_plain_words(): void
    {
        $report = [
            'listings' => [[
                'path' => 'listings/acme/seo-audit.json',
                'name' => 'acme/seo-audit',
                'status' => 'checked',
                'version' => '1.0.0',
                'problems' => [],
                'checks' => [
                    ['passed' => true, 'words' => 'composer.json names the package acme/seo-audit.'],
                    ['passed' => false, 'words' => 'README.md is missing from the package\'s root.'],
                ],
                'teme' => ['v1.2.0'],
            ]],
            'other' => ['README.md'],
        ];
        $results = new InstallResults([
            ['name' => 'acme/seo-audit', 'version' => '1.0.0', 'teme' => 'v1.2.0', 'passed' => false, 'checks' => [['passed' => false, 'words' => 'It does not install on Teme v1.2.0: Composer could not add it.']], 'log' => "Problem 1\n  - acme/seo-audit requires php ^9.0"],
        ]);

        $comment = Comment::forPullRequest($report, $results);

        self::assertFalse($comment->passed);
        self::assertStringStartsWith("<!-- directory-checks -->\n## Directory checks\n", $comment->markdown);
        self::assertStringContainsString("### acme/seo-audit 1.0.0: 2 checks need attention\n", $comment->markdown);
        self::assertStringContainsString("- ✅ composer.json names the package acme/seo-audit.\n", $comment->markdown);
        self::assertStringContainsString("- ❌ README.md is missing from the package's root.\n", $comment->markdown);
        self::assertStringContainsString("- ❌ It does not install on Teme v1.2.0: Composer could not add it.\n", $comment->markdown);
        self::assertStringContainsString("acme/seo-audit requires php ^9.0", $comment->markdown);
        self::assertStringContainsString('This pull request also changes files outside listings/: README.md. A maintainer looks at those by hand.', $comment->markdown);
    }

    public function test_a_listing_that_does_not_validate_shows_its_problems(): void
    {
        $comment = Comment::forPullRequest([
            'listings' => [['path' => 'listings/Acme/x.json', 'name' => null, 'status' => 'invalid', 'version' => null, 'problems' => ['kind must be plugin or theme.'], 'checks' => [], 'teme' => []]],
            'other' => [],
        ], new InstallResults([]));

        self::assertFalse($comment->passed);
        self::assertStringContainsString("### listings/Acme/x.json: the listing needs changes\n\n- ❌ kind must be plugin or theme.\n", $comment->markdown);
    }

    public function test_a_clean_pull_request_says_what_happens_next(): void
    {
        $comment = Comment::forPullRequest([
            'listings' => [[
                'path' => 'listings/acme/seo-audit.json', 'name' => 'acme/seo-audit', 'status' => 'checked', 'version' => '1.0.0', 'problems' => [],
                'checks' => [['passed' => true, 'words' => 'README.md is at the package\'s root.']], 'teme' => ['v1.2.0'],
            ]],
            'other' => [],
        ], new InstallResults([
            ['name' => 'acme/seo-audit', 'version' => '1.0.0', 'teme' => 'v1.2.0', 'passed' => true, 'checks' => [['passed' => true, 'words' => 'It installs on Teme v1.2.0 and is enabled.']], 'log' => ''],
        ]));

        self::assertTrue($comment->passed);
        self::assertStringContainsString("### acme/seo-audit 1.0.0: every check passed\n", $comment->markdown);
        self::assertStringContainsString('A maintainer reviews the listing next.', $comment->markdown);
    }

    public function test_an_install_that_never_reported_is_named(): void
    {
        $comment = Comment::forPullRequest([
            'listings' => [[
                'path' => 'listings/acme/seo-audit.json', 'name' => 'acme/seo-audit', 'status' => 'checked', 'version' => '1.0.0', 'problems' => [],
                'checks' => [], 'teme' => ['v1.2.0'],
            ]],
            'other' => [],
        ], new InstallResults([]));

        self::assertFalse($comment->passed);
        self::assertStringContainsString('- ❌ The install on Teme v1.2.0 did not finish. A maintainer can run the checks again.', $comment->markdown);
    }
}
