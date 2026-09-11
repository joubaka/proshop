<?php

namespace Tests\Feature;

use App\Media;
use App\Support\SafeUpload;
use App\Utils\Util;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UploadSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.default' => 'local']);
        Storage::fake('local');
    }

    public function test_html_svg_and_disguised_images_are_rejected(): void
    {
        foreach (['payload.html', 'payload.svg', 'payload.php', 'payload.jpg'] as $name) {
            try {
                Media::uploadFile(UploadedFile::fake()->createWithContent($name, '<html><script>alert(1)</script></html>'));
                $this->fail('Unsafe upload accepted: '.$name);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('file', $e->errors());
            }
        }
        $this->assertSame([], Storage::allFiles());
    }

    public function test_images_keep_a_safe_name_and_are_stored(): void
    {
        $name = Media::uploadFile(UploadedFile::fake()->image('avatar.php.jpg'));
        $this->assertStringEndsWith('_avatar_php.jpg', $name);
        Storage::assertExists('media/'.$name);
    }

    public function test_supported_document_contents_are_accepted(): void
    {
        $file = UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        $name = Media::uploadFile($file);
        Storage::assertExists('media/'.$name);
        $this->assertStringEndsWith('.pdf', $name);
    }

    public function test_oversized_uploads_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        SafeUpload::filename(UploadedFile::fake()->image('large.jpg')->size(6000));
    }

    public function test_general_upload_helper_cannot_bypass_validation(): void
    {
        $request = Request::create('/upload', 'POST', [], [], [
            'image' => UploadedFile::fake()->createWithContent('photo.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
        ]);
        $this->expectException(ValidationException::class);
        (new Util)->uploadFile($request, 'image', 'img', 'image');
    }

    public function test_base64_active_content_and_invalid_data_are_rejected(): void
    {
        foreach ([base64_encode('<script>alert(1)</script>'), 'not valid base64!'] as $encoded) {
            try {
                Media::uploadBase64Image($encoded);
                $this->fail('Invalid base64 image accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('file', $e->errors());
            }
        }
        $this->assertSame([], Storage::allFiles());
    }

    public function test_base64_images_are_reencoded_and_use_the_configured_disk(): void
    {
        $file = UploadedFile::fake()->image('avatar.png');
        $name = Media::uploadBase64Image(base64_encode($file->getContent()));
        Storage::assertExists('media/'.$name);
        $this->assertSame('image/jpeg', getimagesizefromstring(Storage::get('media/'.$name))['mime']);
    }
}
