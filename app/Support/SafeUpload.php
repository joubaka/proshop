<?php

namespace App\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class SafeUpload
{
    public static function filename(UploadedFile $file, bool $imagesOnly = false): string
    {
        $types = [
            'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
            'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
        ];
        if (!$imagesOnly) {
            $types += [
                'pdf' => ['application/pdf'], 'txt' => ['text/plain'],
                'csv' => ['text/plain', 'text/csv'],
                'doc' => ['application/msword'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                'xls' => ['application/vnd.ms-excel'],
                'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                'zip' => ['application/zip'],
            ];
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (!$file->isValid() || $file->getSize() > (int) config('constants.document_size_limit')
            || !isset($types[$extension])
            || !in_array((new \Symfony\Component\HttpFoundation\File\File($file->getPathname()))->getMimeType(), $types[$extension], true)) {
            throw ValidationException::withMessages(['file' => 'Upload a supported file with matching contents, within the size limit.']);
        }

        // Remove embedded extensions and unsafe characters; retain a readable download name.
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));

        return time().'_'.Str::uuid().'_'.substr($name ?: 'file', 0, 100).'.'.$extension;
    }
}
