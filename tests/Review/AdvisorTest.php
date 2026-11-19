<?php

declare(strict_types=1);

namespace Teme\Directory\Tests\Review;

use PHPUnit\Framework\TestCase;
use Teme\Directory\Packages\Archive;
use Teme\Directory\Review\Advisor;
use Teme\Directory\Review\Material;
use Teme\Directory\Tests\Support\FakeTransport;
use Teme\Directory\Tests\Support\PackageZip;

final class AdvisorTest extends TestCase
{
    private FakeTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport;
    }

    public function test_with_no_key_or_provider_nothing_is_sent(): void
    {
        self::assertNull(Advisor::configured($this->transport, '', 'google', '')?->advise('code', 'acme/seo-audit 1.0.0'));
        self::assertNull(Advisor::configured($this->transport, 'key', '', ''));
        self::assertNull(Advisor::configured($this->transport, 'key', 'elsewhere', ''));
        self::assertSame([], $this->transport->requests);
    }

    public function test_google_gets_the_key_in_a_header_never_in_the_address(): void
    {
        $this->transport->answer = ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => '- Nothing stands out.']]]]]])];

        $notes = Advisor::configured($this->transport, 'placeholder-key', 'google', '')?->advise('<?php echo 1;', 'acme/seo-audit 1.0.0');

        self::assertSame('- Nothing stands out.', $notes);
        $request = $this->transport->requests[0];
        self::assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent', $request['url']);
        self::assertContains('x-goog-api-key: placeholder-key', $request['headers']);
        self::assertStringNotContainsString('placeholder-key', $request['url']);
        self::assertStringContainsString('<?php echo 1;', $request['body']);
    }

    public function test_openrouter_uses_the_model_named_in_the_settings(): void
    {
        $this->transport->answer = ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => '- Fine.']]]])];

        $notes = Advisor::configured($this->transport, 'placeholder-key', 'openrouter', 'some/model:free')?->advise('code', 'acme/seo-audit 1.0.0');

        self::assertSame('- Fine.', $notes);
        $request = $this->transport->requests[0];
        self::assertSame('https://openrouter.ai/api/v1/chat/completions', $request['url']);
        self::assertContains('Authorization: Bearer placeholder-key', $request['headers']);
        self::assertSame('some/model:free', json_decode($request['body'], true)['model']);
    }

    public function test_openrouter_without_a_model_is_skipped(): void
    {
        self::assertNull(Advisor::configured($this->transport, 'placeholder-key', 'openrouter', ''));
    }

    public function test_a_failed_answer_gives_no_notes_and_never_repeats_the_key(): void
    {
        $this->transport->answer = ['status' => 429, 'body' => 'slow down placeholder-key'];

        self::assertNull(Advisor::configured($this->transport, 'placeholder-key', 'google', '')?->advise('code', 'acme/seo-audit 1.0.0'));
    }

    public function test_the_material_is_the_package_text_files_within_a_limit(): void
    {
        $files = PackageZip::plugin();
        $files['src/Big.php'] = str_repeat('x', 400 * 1024);

        $material = Material::fromArchive(Archive::fromBytes(PackageZip::bytes($files)), 200 * 1024);

        self::assertStringContainsString("=== src/Plugin.php ===\n<?php\n", $material);
        self::assertStringContainsString('=== teme-plugin.json ===', $material);
        self::assertStringNotContainsString('=== art/icon.png ===', $material);
        self::assertLessThanOrEqual(200 * 1024 + 200, strlen($material));
        self::assertStringContainsString('(cut here: the rest is over the size limit)', $material);
    }

    public function test_the_material_for_a_later_release_is_the_diff_since_the_listed_one(): void
    {
        $old = Archive::fromBytes(PackageZip::bytes(PackageZip::plugin()));
        $files = PackageZip::plugin(version: '1.1.0');
        $files['src/Plugin.php'] = "<?php\n\nfile_get_contents('https://tracker.example.net');\n";
        $new = Archive::fromBytes(PackageZip::bytes($files));

        $material = Material::diff($old, $new, 200 * 1024);

        self::assertStringContainsString('+file_get_contents(\'https://tracker.example.net\');', $material);
        self::assertStringNotContainsString('README.md', $material);
    }
}
