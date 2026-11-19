<?php

declare(strict_types=1);

namespace Teme\Directory\Tests\Index;

use PHPUnit\Framework\TestCase;
use Teme\Directory\Index\SiteWriter;

final class SiteWriterTest extends TestCase
{
    private string $site;

    protected function setUp(): void
    {
        $this->site = sys_get_temp_dir().'/directory-site-'.bin2hex(random_bytes(4));
        mkdir($this->site.'/packages/acme', 0777, true);
        mkdir($this->site.'/assets/acme/gone/1.0.0', 0777, true);
        file_put_contents($this->site.'/packages/acme/gone.json', '{}');
        file_put_contents($this->site.'/assets/acme/gone/1.0.0/a.png', 'png');
    }

    public function test_it_writes_the_build_and_the_signature_and_removes_stale_detail_files(): void
    {
        (new SiteWriter($this->site))->write([
            'index.json' => "{}\n",
            'composer/packages.json' => "{}\n",
            'packages/acme/seo-audit.json' => "{}\n",
        ], 'c2lnbmF0dXJl');

        self::assertSame("{}\n", file_get_contents($this->site.'/packages/acme/seo-audit.json'));
        self::assertSame('c2lnbmF0dXJl', file_get_contents($this->site.'/index.json.sig'));
        self::assertFileDoesNotExist($this->site.'/packages/acme/gone.json');
        self::assertFileExists($this->site.'/.nojekyll');
    }

    public function test_published_images_are_kept_and_never_rewritten(): void
    {
        $writer = new SiteWriter($this->site);

        $writer->assets(['assets/acme/gone/1.0.0/a.png' => 'other', 'assets/acme/new/1.0.0/b.png' => 'png']);

        self::assertSame('png', file_get_contents($this->site.'/assets/acme/gone/1.0.0/a.png'));
        self::assertSame('png', file_get_contents($this->site.'/assets/acme/new/1.0.0/b.png'));
    }

    public function test_it_refuses_a_path_outside_the_site(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SiteWriter($this->site))->assets(['../escape.png' => 'png']);
    }
}
