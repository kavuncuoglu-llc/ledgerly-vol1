<?php

namespace Tests\Unit\Ai\Vision;

use App\Ai\Exceptions\UnreadableInvoice;
use App\Ai\Vision\ImageEncoder;
use App\Ai\Vision\PdfPageRenderer;
use Tests\TestCase;

class ImageEncoderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/ledgerly-encoder-test-'.uniqid();

        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->directory}/*"));

        rmdir($this->directory);

        parent::tearDown();
    }

    public function test_data_url_scales_a_large_image_down_to_1024_pixels_and_encodes_jpeg(): void
    {
        $path = $this->makeImage(width: 3000, height: 2000, extension: 'png');

        $dataUrl = $this->encoder()->dataUrl($path);

        $this->assertStringStartsWith('data:image/jpeg;base64,', $dataUrl);

        [$width, $height] = $this->dimensionsOf($dataUrl);

        $this->assertSame(1024, $width);
        $this->assertSame(683, $height);
    }

    public function test_data_url_does_not_enlarge_a_small_image(): void
    {
        $path = $this->makeImage(width: 400, height: 300, extension: 'jpg');

        [$width, $height] = $this->dimensionsOf($this->encoder()->dataUrl($path));

        $this->assertSame(400, $width);
        $this->assertSame(300, $height);
    }

    public function test_data_url_throws_for_a_missing_file(): void
    {
        $this->expectException(UnreadableInvoice::class);
        $this->expectExceptionMessage('No file at');

        $this->encoder()->dataUrl("{$this->directory}/nope.jpg");
    }

    public function test_data_url_throws_for_an_unsupported_extension(): void
    {
        $path = "{$this->directory}/notes.txt";
        file_put_contents($path, 'hello');

        $this->expectException(UnreadableInvoice::class);
        $this->expectExceptionMessage('.txt');

        $this->encoder()->dataUrl($path);
    }

    public function test_data_url_renders_the_first_page_of_a_pdf(): void
    {
        if (trim((string) shell_exec('command -v pdftoppm')) === '') {
            $this->markTestSkipped('pdftoppm (Poppler) is not installed.');
        }

        $dataUrl = $this->encoder()->dataUrl(base_path('storage/samples/clean.pdf'));

        [$width, $height] = $this->dimensionsOf($dataUrl);

        $this->assertStringStartsWith('data:image/jpeg;base64,', $dataUrl);
        $this->assertSame(1024, max($width, $height));
    }

    private function encoder(): ImageEncoder
    {
        return new ImageEncoder(new PdfPageRenderer);
    }

    private function makeImage(int $width, int $height, string $extension): string
    {
        $image = imagecreatetruecolor($width, $height);
        $path = "{$this->directory}/invoice.{$extension}";

        match ($extension) {
            'png' => imagepng($image, $path),
            default => imagejpeg($image, $path),
        };

        return $path;
    }

    private function dimensionsOf(string $dataUrl): array
    {
        $binary = base64_decode(substr($dataUrl, strlen('data:image/jpeg;base64,')));

        [$width, $height] = getimagesizefromstring($binary);

        return [$width, $height];
    }
}
