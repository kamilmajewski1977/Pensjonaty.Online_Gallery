<?php

declare(strict_types=1);

namespace Pensjonaty\Gallery;

use InvalidArgumentException;

/**
 * ImageUploader handles multi-file uploads, renaming, resizing and progress reporting.
 */
class ImageUploader
{
    private array $config;

    public function __construct(array $config)
    {
        $this->validateConfig($config);
        $this->config = $config;
    }

    /**
     * Process a batch of uploaded images.
     *
     * @param array $files      Array in the same structure as $_FILES['images'].
     * @param array $context    id_property, id_building, id_room, id_feature, type, placement, description, alt, title.
     * @param callable|null $progressCallback Callback invoked as function(int $processed, int $total, array $details).
     *
     * @return array<int, array<string, mixed>> Information about each uploaded image.
     */
    public function processUploads(array $files, array $context, ?callable $progressCallback = null): array
    {
        $normalizedFiles = $this->normalizeFilesArray($files);
        $total = count($normalizedFiles);
        $results = [];

        foreach ($normalizedFiles as $index => $file) {
            $result = [
                'original_name' => $file['name'] ?? null,
                'success' => false,
                'messages' => [],
                'generated_files' => [],
            ];

            try {
                $this->guardUploadedFile($file);
                $imageInfo = getimagesize($file['tmp_name']);

                if ($imageInfo === false) {
                    throw new InvalidArgumentException('Uploaded file is not a valid image.');
                }

                [$width, $height] = $imageInfo;
                $this->checkMinimumDimensions($width, $height, $result['messages']);

                $resource = $this->createImageResource($file['tmp_name'], (int) $imageInfo[2]);

                $result['generated_files'] = $this->generateResizedImages(
                    $resource,
                    $file,
                    $context,
                    $index
                );

                imagedestroy($resource);
                $result['success'] = true;
            } catch (\Throwable $exception) {
                $result['messages'][] = $exception->getMessage();
            }

            $results[] = $result;

            if ($progressCallback !== null) {
                $progressCallback($index + 1, $total, $result);
            }
        }

        return $results;
    }

    private function validateConfig(array $config): void
    {
        if (!isset($config['sizes']) || !is_array($config['sizes'])) {
            throw new InvalidArgumentException('Configuration must contain a sizes array.');
        }

        if (count($config['sizes']) > ($config['max_sizes'] ?? 10)) {
            throw new InvalidArgumentException('Configuration defines more than the allowed number of sizes.');
        }

        if (!isset($config['upload_root'])) {
            throw new InvalidArgumentException('Configuration must define an upload_root.');
        }
    }

    private function normalizeFilesArray(array $files): array
    {
        if (!isset($files['name'])) {
            return $files;
        }

        $normalized = [];
        $fileCount = is_array($files['name']) ? count($files['name']) : 1;

        for ($i = 0; $i < $fileCount; $i++) {
            $normalized[$i] = [
                'name' => is_array($files['name']) ? $files['name'][$i] : $files['name'],
                'type' => is_array($files['type']) ? $files['type'][$i] : $files['type'],
                'tmp_name' => is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'],
                'error' => is_array($files['error']) ? $files['error'][$i] : $files['error'],
                'size' => is_array($files['size']) ? $files['size'][$i] : $files['size'],
            ];
        }

        return $normalized;
    }

    private function guardUploadedFile(array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(sprintf('Upload failed for %s (error code %d).', $file['name'], $file['error']));
        }

        if (!is_uploaded_file($file['tmp_name']) && !is_file($file['tmp_name'])) {
            throw new InvalidArgumentException('Potential file upload attack detected.');
        }
    }

    private function checkMinimumDimensions(int $width, int $height, array &$messages): void
    {
        $minWidth = $this->config['min_dimensions']['width'] ?? null;
        $minHeight = $this->config['min_dimensions']['height'] ?? null;

        if (($minWidth && $width < $minWidth) || ($minHeight && $height < $minHeight)) {
            $messages[] = sprintf(
                'Notice: source image is smaller than the recommended minimum (%dx%d).',
                $minWidth ?? $width,
                $minHeight ?? $height
            );
        }
    }

    /**
     * @return resource
     */
    private function createImageResource(string $path, int $imageType)
    {
        return match ($imageType) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG => imagecreatefrompng($path),
            IMAGETYPE_GIF => imagecreatefromgif($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : throw new InvalidArgumentException('WebP is not supported by this GD installation.'),
            default => throw new InvalidArgumentException('Unsupported image type.'),
        };
    }

    private function generateResizedImages($resource, array $file, array $context, int $sequence): array
    {
        $savedFiles = [];

        foreach ($this->config['sizes'] as $index => $size) {
            $targetPath = $this->buildTargetPath($size, $context, $sequence, $file['name']);
            $this->ensureDirectory(dirname($targetPath));

            $resized = $this->resize($resource, (int) $size['width'], (int) $size['height'], (bool) ($size['crop'] ?? false));

            $this->saveImage($resized, $targetPath, $size);
            imagedestroy($resized);

            $savedFiles[] = [
                'label' => $size['label'] ?? 'size_' . ($index + 1),
                'path' => $targetPath,
                'width' => $size['width'],
                'height' => $size['height'],
            ];
        }

        return $savedFiles;
    }

    private function resize($resource, int $width, int $height, bool $crop)
    {
        $sourceWidth = imagesx($resource);
        $sourceHeight = imagesy($resource);

        if ($crop) {
            $sourceRatio = $sourceWidth / $sourceHeight;
            $targetRatio = $width / $height;

            if ($sourceRatio > $targetRatio) {
                $newHeight = $sourceHeight;
                $newWidth = (int) ($sourceHeight * $targetRatio);
                $srcX = (int) (($sourceWidth - $newWidth) / 2);
                $srcY = 0;
            } else {
                $newWidth = $sourceWidth;
                $newHeight = (int) ($sourceWidth / $targetRatio);
                $srcX = 0;
                $srcY = (int) (($sourceHeight - $newHeight) / 2);
            }
        } else {
            $srcX = 0;
            $srcY = 0;
            $newWidth = $sourceWidth;
            $newHeight = $sourceHeight;
        }

        $destination = imagecreatetruecolor($width, $height);
        imagealphablending($destination, false);
        imagesavealpha($destination, true);
        $transparent = imagecolorallocatealpha($destination, 255, 255, 255, 127);
        imagefilledrectangle($destination, 0, 0, $width, $height, $transparent);

        imagecopyresampled(
            $destination,
            $resource,
            0,
            0,
            $srcX,
            $srcY,
            $width,
            $height,
            $newWidth,
            $newHeight
        );

        return $destination;
    }

    private function saveImage($resource, string $path, array $size): void
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $quality = (int) ($size['quality'] ?? 85);

        switch ($extension) {
            case 'png':
                $compression = (int) round((100 - $quality) / 10);
                $compression = max(0, min(9, $compression));
                imagepng($resource, $path, $compression);
                break;
            case 'gif':
                imagegif($resource, $path);
                break;
            case 'webp':
                if (!function_exists('imagewebp')) {
                    throw new InvalidArgumentException('WebP output is not supported by this GD installation.');
                }
                imagewebp($resource, $path, $quality);
                break;
            case 'jpg':
            case 'jpeg':
            default:
                imagejpeg($resource, $path, $quality);
                break;
        }
    }

    private function buildTargetPath(array $size, array $context, int $sequence, string $originalName): string
    {
        $directoryPattern = $this->config['naming']['directory_pattern'] ?? 'property/{id_property}';
        $directory = $this->replaceTokens($directoryPattern, $context);

        $extension = $this->detectExtension($originalName, $size);
        $filePattern = $this->config['naming']['file_pattern'];
        $fileName = $this->replaceTokens($filePattern, array_merge($context, [
            'width' => $size['width'],
            'height' => $size['height'],
            'index' => $sequence + 1,
            'extension' => $extension,
            'feature_segment' => $this->buildFeatureSegment($context['id_feature'] ?? null),
        ]));

        $directory = rtrim($this->config['upload_root'], '/') . '/' . trim($directory, '/');

        return $directory . '/' . $fileName;
    }

    private function replaceTokens(string $pattern, array $data): string
    {
        return preg_replace_callback('/\{([a-z0-9_]+)\}/i', function (array $matches) use ($data) {
            $key = $matches[1];
            return $data[$key] ?? '';
        }, $pattern);
    }

    private function detectExtension(string $originalName, array $size): string
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);

        if (!$extension) {
            $extension = $size['format'] ?? 'jpg';
        }

        return strtolower($extension);
    }

    private function buildFeatureSegment(?int $featureId): string
    {
        if (!$featureId) {
            return '';
        }

        $format = $this->config['naming']['feature_segment_format'] ?? '_F{id_feature}';

        return $this->replaceTokens($format, ['id_feature' => $featureId]);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new InvalidArgumentException(sprintf('Unable to create directory %s', $directory));
        }
    }
}
