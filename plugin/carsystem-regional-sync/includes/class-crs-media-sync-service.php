<?php

namespace CRS;

if (! defined('ABSPATH')) {
    exit;
}

final class Media_Sync_Service
{
    private const MAX_MEDIA_RETRY_ATTEMPTS = 3;
    private const MEDIA_RETRY_BACKOFF_SECONDS = [1, 2, 4];

    private Media_Map_Repository $mediaMapRepository;
    /** @var array */
    private $settings;
    private bool $uploadProbeChecked = false;
    private string $uploadProbeError = '';

    public function __construct(?Media_Map_Repository $mediaMapRepository = null)
    {
        $this->mediaMapRepository = $mediaMapRepository ?? new Media_Map_Repository();
        $this->settings = Settings::get();
    }

    public function sync_category_media(int $termId, int $remoteCategoryId, array $remoteCategory): void
    {
        if ($termId <= 0 || $remoteCategoryId <= 0) {
            return;
        }

        $image = $remoteCategory['image'] ?? null;

        if (! is_array($image)) {
            return;
        }

        $remoteUrl = $this->sanitize_remote_media_url((string) ($image['src'] ?? ''));

        if ($remoteUrl === '') {
            return;
        }

        $attachmentId = $this->ensure_attachment('product_cat', $remoteCategoryId, $remoteUrl);

        if ($attachmentId > 0) {
            update_term_meta($termId, 'thumbnail_id', $attachmentId);
        }
    }

    public function sync_product_media(int $postId, int $remoteProductId, array $remoteProduct): void
    {
        if ($postId <= 0 || $remoteProductId <= 0) {
            return;
        }

        $images = (array) ($remoteProduct['images'] ?? []);

        if ($images === []) {
            return;
        }

        $attachmentIds = [];

        foreach ($images as $imageItem) {
            if (! is_array($imageItem)) {
                continue;
            }

            $remoteUrl = $this->sanitize_remote_media_url((string) ($imageItem['src'] ?? ''));

            if ($remoteUrl === '') {
                continue;
            }

            $attachmentId = $this->ensure_attachment('product', $remoteProductId, $remoteUrl);

            if ($attachmentId > 0) {
                $attachmentIds[] = $attachmentId;
            }
        }

        $attachmentIds = array_values(array_unique(array_map('intval', $attachmentIds)));

        if ($attachmentIds === []) {
            return;
        }

        set_post_thumbnail($postId, $attachmentIds[0]);

        $gallery = array_slice($attachmentIds, 1);
        update_post_meta($postId, '_product_image_gallery', implode(',', $gallery));
    }

    public function localize_product_media(
        int $postId,
        int $remoteProductId,
        string $rawDescription,
        string $rawShortDescription
    ): void {
        if ($postId <= 0 || $remoteProductId <= 0) {
            return;
        }

        $remoteUrls = array_merge(
            $this->extract_media_urls_from_content($rawDescription),
            $this->extract_media_urls_from_content($rawShortDescription)
        );
        $remoteUrls = array_values(array_unique($remoteUrls));

        if ($remoteUrls === []) {
            return;
        }

        $replacements = [];
        $localVideoAttachmentIds = [];

        foreach ($remoteUrls as $remoteUrl) {
            $attachmentId = $this->ensure_attachment('product', $remoteProductId, $remoteUrl);

            if ($attachmentId <= 0) {
                continue;
            }

            $localUrl = wp_get_attachment_url($attachmentId);

            if (is_string($localUrl) && $localUrl !== '') {
                $replacements[$remoteUrl] = $localUrl;
            }

            if ($this->is_video_media_url($remoteUrl)) {
                $localVideoAttachmentIds[] = $attachmentId;
            }
        }

        if ($replacements === []) {
            return;
        }

        $localizedDescription = strtr($rawDescription, $replacements);
        $localizedShortDescription = strtr($rawShortDescription, $replacements);
        $localizedDescription = $this->normalize_videopack_markup(
            $localizedDescription,
            array_values(array_unique(array_map('intval', $localVideoAttachmentIds)))
        );
        $localizedShortDescription = $this->normalize_videopack_markup(
            $localizedShortDescription,
            array_values(array_unique(array_map('intval', $localVideoAttachmentIds)))
        );

        if ($localizedDescription === $rawDescription && $localizedShortDescription === $rawShortDescription) {
            return;
        }

        $updateResult = wp_update_post([
            'ID'           => $postId,
            'post_content' => wp_kses_post($localizedDescription),
            'post_excerpt' => wp_kses_post($localizedShortDescription),
        ], true);

        if (is_wp_error($updateResult)) {
            throw new \RuntimeException('Product media localization failed: ' . $updateResult->get_error_message());
        }
    }

    private function normalize_videopack_markup(string $content, array $videoAttachmentIds): string
    {
        if ($content === '') {
            return $content;
        }

        $hasVideopackMarkup = stripos($content, 'kgvid_') !== false
            || stripos($content, 'kgvid_gallerywrapper') !== false
            || stripos($content, 'videopack') !== false;

        if (! $hasVideopackMarkup) {
            return $content;
        }

        if ($videoAttachmentIds === []) {
            return $content;
        }

        $shortcode = '[videopack gallery="true" gallery_include="' . implode(',', $videoAttachmentIds) . '" gallery_thumb="490" gallery_title="true" gallery_end="close"]';

        $contentWithoutKgvid = preg_replace('/<div class="kgvid_gallerywrapper[\s\S]*$/i', '', $content);
        $contentWithoutKgvid = is_string($contentWithoutKgvid) ? trim($contentWithoutKgvid) : trim($content);
        $contentWithoutKgvid = preg_replace('/(?:<p><strong>ВИДЕО:<\/strong><\/p>\s*)+$/ui', '', $contentWithoutKgvid);
        $contentWithoutKgvid = is_string($contentWithoutKgvid) ? trim($contentWithoutKgvid) : trim($contentWithoutKgvid);

        if ($contentWithoutKgvid === '') {
            return "<p><strong>ВИДЕО:</strong></p>\n" . $shortcode;
        }

        return $contentWithoutKgvid . "\n\n<p><strong>ВИДЕО:</strong></p>\n" . $shortcode;
    }

    public function localize_page_media(int $postId, int $remotePageId, string $rawContent): void
    {
        if ($postId <= 0 || $remotePageId <= 0 || trim($rawContent) === '') {
            return;
        }

        $remoteUrls = $this->extract_media_urls_from_content($rawContent);

        if ($remoteUrls === []) {
            return;
        }

        $replacements = [];

        foreach ($remoteUrls as $remoteUrl) {
            $attachmentId = $this->ensure_attachment('page', $remotePageId, $remoteUrl);

            if ($attachmentId <= 0) {
                continue;
            }

            $localUrl = wp_get_attachment_url($attachmentId);

            if (is_string($localUrl) && $localUrl !== '') {
                $replacements[$remoteUrl] = $localUrl;
            }
        }

        if ($replacements === []) {
            return;
        }

        $localizedContent = strtr($rawContent, $replacements);

        if ($localizedContent === $rawContent) {
            return;
        }

        $updateResult = wp_update_post([
            'ID'           => $postId,
            'post_content' => wp_kses_post($localizedContent),
        ], true);

        if (is_wp_error($updateResult)) {
            throw new \RuntimeException('Page media localization failed: ' . $updateResult->get_error_message());
        }
    }

    private function ensure_attachment(string $objectType, int $objectRemoteId, string $remoteUrl): int
    {
        if ($objectType === '' || $objectRemoteId <= 0 || $remoteUrl === '') {
            return 0;
        }

        $existing = $this->mediaMapRepository->find_by_remote_url($objectType, $objectRemoteId, $remoteUrl);
        $existingAttachmentId = is_array($existing) ? (int) ($existing['local_attachment_id'] ?? 0) : 0;

        if ($existingAttachmentId > 0 && get_post_type($existingAttachmentId) === 'attachment') {
            $this->normalize_attachment_title($existingAttachmentId, $remoteUrl);
            $this->mediaMapRepository->upsert([
                'object_type'           => $objectType,
                'object_remote_id'      => $objectRemoteId,
                'remote_media_url'      => $remoteUrl,
                'local_attachment_id'   => $existingAttachmentId,
                'remote_media_hash'     => $this->build_remote_media_hash($remoteUrl),
                'last_operation_status' => 'success',
                'last_error_message'    => null,
            ]);

            return $existingAttachmentId;
        }

        $this->load_media_dependencies();
        $storageError = $this->assert_upload_storage_writable();

        if ($storageError !== '') {
            $message = $storageError;
            $this->mediaMapRepository->upsert([
                'object_type'           => $objectType,
                'object_remote_id'      => $objectRemoteId,
                'remote_media_url'      => $remoteUrl,
                'local_attachment_id'   => 0,
                'remote_media_hash'     => $this->build_remote_media_hash($remoteUrl),
                'last_operation_status' => 'error',
                'last_error_message'    => $message,
            ]);

            throw new \RuntimeException($message);
        }

        $lastErrorMessage = 'Media sync failed.';
        $lastErrorCode = '';
        $localAttachmentId = 0;
        $localCopyAttempted = false;

        for ($attempt = 1; $attempt <= self::MAX_MEDIA_RETRY_ATTEMPTS; $attempt++) {
            $tmpFile = '';

            if ($attempt === 1) {
                $tmpFile = $this->try_copy_remote_media_locally($remoteUrl);
                $localCopyAttempted = $tmpFile !== '';
            }

            if ($tmpFile === '') {
                $tmpFile = download_url($remoteUrl, 30);
            }

            if (is_wp_error($tmpFile)) {
                $lastErrorMessage = 'Media download failed for ' . $remoteUrl . ': ' . $tmpFile->get_error_message();
                $lastErrorCode = (string) $tmpFile->get_error_code();

                if ($attempt < self::MAX_MEDIA_RETRY_ATTEMPTS && $this->should_retry_media_error($lastErrorCode, $lastErrorMessage)) {
                    sleep((int) self::MEDIA_RETRY_BACKOFF_SECONDS[$attempt - 1]);
                    continue;
                }

                break;
            }

            $filename = $this->build_filename_from_url($remoteUrl);
            $fileArray = [
                'name'     => $filename,
                'tmp_name' => $tmpFile,
            ];

            $attachmentId = $this->handle_sideload_attachment($fileArray, $filename);

            if (! is_wp_error($attachmentId)) {
                $localAttachmentId = (int) $attachmentId;
                break;
            }

            @unlink($tmpFile);
            $lastErrorMessage = 'Media sideload failed: ' . $attachmentId->get_error_message();
            $lastErrorCode = (string) $attachmentId->get_error_code();

            if ($attempt < self::MAX_MEDIA_RETRY_ATTEMPTS && $this->should_retry_media_error($lastErrorCode, $lastErrorMessage)) {
                sleep((int) self::MEDIA_RETRY_BACKOFF_SECONDS[$attempt - 1]);
                continue;
            }

            break;
        }

        if ($localAttachmentId <= 0) {
            $message = sanitize_text_field($lastErrorMessage);
            if ($message === '') {
                $message = 'Media sync failed.';
            }
            if ($localCopyAttempted) {
                $message .= ' (local-copy mode fallback failed)';
            }

            $this->mediaMapRepository->upsert([
                'object_type'           => $objectType,
                'object_remote_id'      => $objectRemoteId,
                'remote_media_url'      => $remoteUrl,
                'local_attachment_id'   => 0,
                'remote_media_hash'     => $this->build_remote_media_hash($remoteUrl),
                'last_operation_status' => 'error',
                'last_error_message'    => $message,
            ]);

            throw new \RuntimeException($message);
        }

        $this->normalize_attachment_title($localAttachmentId, $remoteUrl);

        $this->mediaMapRepository->upsert([
            'object_type'           => $objectType,
            'object_remote_id'      => $objectRemoteId,
            'remote_media_url'      => $remoteUrl,
            'local_attachment_id'   => $localAttachmentId,
            'remote_media_hash'     => $this->build_remote_media_hash($remoteUrl),
            'last_operation_status' => 'success',
            'last_error_message'    => null,
        ]);

        return $localAttachmentId;
    }

    private function assert_upload_storage_writable(): string
    {
        if ($this->uploadProbeChecked) {
            return $this->uploadProbeError;
        }

        $this->uploadProbeChecked = true;
        $this->uploadProbeError = '';

        $uploadDir = wp_upload_dir();
        $path = (string) ($uploadDir['path'] ?? '');

        if ($path === '') {
            $this->uploadProbeError = 'Media sideload failed: upload directory is not resolved.';
            return $this->uploadProbeError;
        }

        $probeFile = trailingslashit($path) . 'crs-write-probe-' . uniqid('', true) . '.tmp';
        $bytes = @file_put_contents($probeFile, 'ok');

        if ($bytes === false) {
            $last = error_get_last();
            $rawMessage = is_array($last) ? (string) ($last['message'] ?? '') : '';
            $rawMessage = sanitize_text_field($rawMessage);
            $lower = strtolower($rawMessage);

            if (strpos($lower, 'quota') !== false || strpos($lower, 'errno=122') !== false || strpos($lower, 'no space left') !== false) {
                $this->uploadProbeError = 'Media sideload failed: disk quota exceeded on regional site uploads.';
            } elseif ($rawMessage !== '') {
                $this->uploadProbeError = 'Media sideload failed: upload storage write probe failed: ' . $rawMessage;
            } else {
                $this->uploadProbeError = 'Media sideload failed: upload storage write probe failed.';
            }

            return $this->uploadProbeError;
        }

        @unlink($probeFile);

        return '';
    }

    private function try_copy_remote_media_locally(string $remoteUrl): string
    {
        if (! $this->is_local_media_copy_enabled()) {
            return '';
        }

        $sourceFile = $this->resolve_source_local_media_path($remoteUrl);

        if ($sourceFile === '' || ! is_file($sourceFile) || ! is_readable($sourceFile)) {
            return '';
        }

        $tmpFile = wp_tempnam(basename($sourceFile));

        if (! is_string($tmpFile) || $tmpFile === '') {
            return '';
        }

        if (! @copy($sourceFile, $tmpFile)) {
            @unlink($tmpFile);
            return '';
        }

        return $tmpFile;
    }

    private function is_local_media_copy_enabled(): bool
    {
        return ! empty($this->settings['use_local_media_copy']);
    }

    private function resolve_source_local_media_path(string $remoteUrl): string
    {
        $remotePath = (string) parse_url($remoteUrl, PHP_URL_PATH);

        if ($remotePath === '' || strpos($remotePath, '/wp-content/uploads/') !== 0) {
            return '';
        }

        $sourceBasePath = $this->detect_source_base_path();

        if ($sourceBasePath === '') {
            return '';
        }

        $candidate = rtrim($sourceBasePath, "/\\") . $remotePath;
        $candidate = str_replace(["\0", "\r", "\n"], '', $candidate);

        return $candidate;
    }

    private function detect_source_base_path(): string
    {
        $configured = trim((string) ($this->settings['source_local_base_path'] ?? ''));

        if ($configured !== '') {
            return rtrim($configured, "/\\");
        }

        $sourceHost = strtolower((string) parse_url((string) ($this->settings['source_url'] ?? ''), PHP_URL_HOST));
        $currentHost = strtolower((string) parse_url((string) home_url('/'), PHP_URL_HOST));
        $currentBase = rtrim((string) ABSPATH, "/\\");

        if ($sourceHost === '' || $currentHost === '' || $currentBase === '') {
            return '';
        }

        $needle = DIRECTORY_SEPARATOR . $currentHost . DIRECTORY_SEPARATOR;

        if (strpos($currentBase, $needle) === false) {
            return '';
        }

        return str_replace($needle, DIRECTORY_SEPARATOR . $sourceHost . DIRECTORY_SEPARATOR, $currentBase);
    }

    private function should_retry_media_error(string $errorCode, string $errorMessage): bool
    {
        $code = strtolower(trim($errorCode));
        $message = strtolower($errorMessage);

        if (in_array($code, ['http_request_failed', 'http_429', 'http_500', 'http_502', 'http_503', 'http_504'], true)) {
            return true;
        }

        $needles = [
            'timed out',
            'timeout',
            'connection reset',
            'connection refused',
            'could not resolve host',
            'temporarily unavailable',
            'too many requests',
            'http 429',
            'http 500',
            'http 502',
            'http 503',
            'http 504',
            'ssl',
            'curl error',
            'operation timed out',
        ];

        foreach ($needles as $needle) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function normalize_attachment_title(int $attachmentId, string $remoteUrl): void
    {
        if ($attachmentId <= 0 || $remoteUrl === '') {
            return;
        }

        $post = get_post($attachmentId);

        if (! $post instanceof \WP_Post || $post->post_type !== 'attachment') {
            return;
        }

        $currentTitle = (string) $post->post_title;
        $shouldRewrite = stripos($currentTitle, 'CRS media sync:') === 0 || $currentTitle === '';

        if (! $shouldRewrite) {
            return;
        }

        $path = (string) parse_url($remoteUrl, PHP_URL_PATH);
        $fileName = sanitize_file_name((string) basename($path));
        $baseTitle = preg_replace('/\.[^.]+$/', '', $fileName);
        $baseTitle = sanitize_text_field((string) $baseTitle);

        if ($baseTitle === '') {
            return;
        }

        wp_update_post([
            'ID'         => $attachmentId,
            'post_title' => $baseTitle,
        ]);
    }

    private function load_media_dependencies(): void
    {
        $includes = [
            ABSPATH . 'wp-admin/includes/file.php',
            ABSPATH . 'wp-admin/includes/media.php',
            ABSPATH . 'wp-admin/includes/image.php',
        ];

        foreach ($includes as $includeFile) {
            if (is_file($includeFile)) {
                require_once $includeFile;
            }
        }
    }

    /**
     * Fast sideload path that avoids expensive metadata generation for non-image files
     * (video/pdf/doc) which can hit host execution limits on shared hosting.
     *
     * @param array{name:string,tmp_name:string} $fileArray
     * @return int|\WP_Error
     */
    private function handle_sideload_attachment(array $fileArray, string $filename)
    {
        $overrides = [
            'test_form' => false,
            'test_size' => true,
        ];

        $moved = wp_handle_sideload($fileArray, $overrides, current_time('mysql'));

        if (! is_array($moved) || isset($moved['error'])) {
            $error = is_array($moved) ? (string) ($moved['error'] ?? 'Unknown upload error.') : 'Unknown upload error.';
            return new \WP_Error('media_sideload_error', $error);
        }

        $filePath = (string) ($moved['file'] ?? '');
        $mimeType = (string) ($moved['type'] ?? '');
        $fileUrl = (string) ($moved['url'] ?? '');

        if ($filePath === '' || $fileUrl === '') {
            return new \WP_Error('media_sideload_error', 'Uploaded file path is empty.');
        }

        $title = sanitize_text_field((string) preg_replace('/\.[^.]+$/', '', sanitize_file_name($filename)));
        $attachment = [
            'post_mime_type' => $mimeType,
            'guid'           => $fileUrl,
            'post_title'     => $title,
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attachmentId = wp_insert_attachment($attachment, $filePath, 0, true);

        if (is_wp_error($attachmentId)) {
            @unlink($filePath);
            return $attachmentId;
        }

        $attachmentId = (int) $attachmentId;

        if ($attachmentId <= 0) {
            @unlink($filePath);
            return new \WP_Error('media_sideload_error', 'Attachment insert failed.');
        }

        if ($this->is_image_mime_type($mimeType)) {
            $metadata = wp_generate_attachment_metadata($attachmentId, $filePath);

            if (is_array($metadata) && $metadata !== []) {
                wp_update_attachment_metadata($attachmentId, $metadata);
            }
        }

        return $attachmentId;
    }

    private function sanitize_remote_media_url(string $remoteUrl): string
    {
        $normalized = trim($remoteUrl);

        if ($normalized === '') {
            return '';
        }

        if (strpos($normalized, '//') === 0) {
            $normalized = 'https:' . $normalized;
        }

        $normalized = esc_url_raw($normalized);

        if ($normalized === '') {
            return '';
        }

        $normalized = $this->normalize_media_url($normalized);

        if ($normalized === '') {
            return '';
        }

        $normalized = $this->normalize_media_host_for_source($normalized);

        $scheme = strtolower((string) parse_url($normalized, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        return $normalized;
    }

    private function normalize_media_host_for_source(string $url): string
    {
        if (! $this->is_local_media_copy_enabled()) {
            return $url;
        }

        $parts = wp_parse_url($url);

        if (! is_array($parts)) {
            return $url;
        }

        $sourceHost = strtolower((string) parse_url((string) ($this->settings['source_url'] ?? ''), PHP_URL_HOST));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if ($sourceHost === '' || $host === '' || $host === $sourceHost) {
            return $url;
        }

        if (strpos($path, '/wp-content/uploads/') !== 0) {
            return $url;
        }

        $scheme = (string) ($parts['scheme'] ?? 'https');
        $port = isset($parts['port']) ? (int) $parts['port'] : 0;
        $rebuilt = strtolower($scheme) . '://' . $sourceHost;

        if ($port > 0 && $port !== 80 && $port !== 443) {
            $rebuilt .= ':' . $port;
        }

        $rebuilt .= $path;

        return $rebuilt;
    }

    private function normalize_media_url(string $url): string
    {
        $parts = wp_parse_url($url);

        if (! is_array($parts)) {
            return $url;
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host = isset($parts['host']) ? (string) $parts['host'] : '';
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        $port = isset($parts['port']) ? (int) $parts['port'] : 0;

        if ($scheme === '' || $host === '' || $path === '') {
            return $url;
        }

        $base = $scheme . '://' . $host;

        if ($port > 0 && $port !== 80 && $port !== 443) {
            $base .= ':' . $port;
        }

        // Для media sync query/hash не нужны и приводят к дублям одного файла.
        return $base . $path;
    }

    private function build_filename_from_url(string $remoteUrl): string
    {
        $path = (string) parse_url($remoteUrl, PHP_URL_PATH);
        $basename = basename($path);
        $basename = sanitize_file_name($basename);

        if ($basename !== '') {
            return $basename;
        }

        return 'crs-media-' . substr(sha1($remoteUrl), 0, 12) . '.bin';
    }

    private function build_remote_media_hash(string $remoteUrl): string
    {
        return hash('sha256', $remoteUrl);
    }

    private function extract_media_urls_from_content(string $content): array
    {
        if ($content === '') {
            return [];
        }

        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = str_replace('\\/', '/', $content);

        $urls = [];
        $attributeMatches = [];
        preg_match_all('/<(img|source|video|a)[^>]+(?:src|href|data-alt_link|data-src|data-url)=["\']([^"\']+)["\']/i', $content, $attributeMatches);

        if (isset($attributeMatches[2]) && is_array($attributeMatches[2])) {
            foreach ($attributeMatches[2] as $rawUrl) {
                $url = $this->sanitize_remote_media_url((string) $rawUrl);

                if ($url !== '' && $this->is_supported_media_url($url)) {
                    $urls[] = $url;
                }
            }
        }

        $textMatches = [];
        preg_match_all('/https?:\/\/[^\s"\'<>]+/i', $content, $textMatches);

        if (isset($textMatches[0]) && is_array($textMatches[0])) {
            foreach ($textMatches[0] as $rawUrl) {
                $rawUrl = rtrim((string) $rawUrl, ".,);]");
                $url = $this->sanitize_remote_media_url($rawUrl);

                if ($url !== '' && $this->is_supported_media_url($url)) {
                    $urls[] = $url;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    private function is_supported_media_url(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        if ($path === '') {
            return false;
        }

        if (strpos($path, '/wp-content/uploads/') !== false) {
            return true;
        }

        $extensions = [
            'jpg',
            'jpeg',
            'png',
            'gif',
            'webp',
            'bmp',
            'svg',
            'mp4',
            'webm',
            'ogg',
            'mov',
            'm4v',
            'avi',
        ];

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, $extensions, true);
    }

    private function is_video_media_url(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['mp4', 'webm', 'ogg', 'mov', 'm4v', 'avi'], true);
    }

    private function is_image_mime_type(string $mimeType): bool
    {
        $mimeType = strtolower(trim($mimeType));

        return strpos($mimeType, 'image/') === 0;
    }
}
