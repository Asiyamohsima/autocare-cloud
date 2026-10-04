<?php
/**
 * AutoCare Cloud - Amazon S3 connection and file functions (AWS SDK for PHP v3).
 *
 * NO AWS keys are used anywhere in this project. On EC2 the SDK automatically
 * obtains temporary credentials from the IAM role attached to the instance.
 * Only the bucket name and region are read from config/config.local.php.
 *
 * Functions:
 *   s3_put_file($key, $localPath, $contentType)  upload a file
 *   s3_get_object($key)                          download (returns a stream)
 *   s3_delete_object($key)                       delete
 *   s3_object_exists($key)                       check that an object exists
 *
 * Every S3 failure is logged privately and thrown as S3OperationException,
 * whose message is safe to show to the user.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

/** An S3 problem. getMessage() is a friendly text that is safe to show to users. */
class S3OperationException extends RuntimeException
{
}

/** Reads and validates the bucket name and region from config.local.php. */
function aws_settings(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $file = __DIR__ . '/config.local.php';
    if (!is_readable($file)) {
        throw new RuntimeException('Configuration file is missing or unreadable.');
    }

    $all    = require $file;
    $bucket = is_array($all) ? ($all['s3_bucket'] ?? '') : '';
    $region = is_array($all) ? ($all['s3_region'] ?? '') : '';

    if (!is_string($bucket) || preg_match('/^[a-z0-9][a-z0-9\-]{1,61}[a-z0-9]$/', $bucket) !== 1) {
        throw new RuntimeException('s3_bucket is missing or invalid in config.local.php (use lowercase letters, numbers and hyphens).');
    }
    if (!is_string($region) || preg_match('/^[a-z]{2}(-[a-z]+)+-\d+$/', $region) !== 1) {
        throw new RuntimeException('s3_region is missing or invalid in config.local.php (for example ap-south-1).');
    }

    return $cfg = ['bucket' => $bucket, 'region' => $region];
}

function s3_bucket(): string
{
    return aws_settings()['bucket'];
}

/**
 * Returns the S3 client (created once per request).
 * No 'credentials' option is given on purpose, so the SDK uses its default
 * provider chain, which on EC2 ends with the instance's IAM role.
 */
function s3_client(): S3Client
{
    static $client = null;
    if ($client instanceof S3Client) {
        return $client;
    }

    $settings = aws_settings();
    $client = new S3Client([
        'region'  => $settings['region'],
        'version' => '2006-03-01',
        'http'    => ['connect_timeout' => 5, 'timeout' => 30],
    ]);
    return $client;
}

/**
 * Safety check for object keys. Only keys under vehicles/ (real data) or
 * test/ (Phase 11 test) are accepted. This mirrors the IAM policy, and blocks
 * odd keys such as "../" before they ever reach S3.
 */
function s3_validate_key(string $key): void
{
    $ok = strlen($key) <= 500
        && preg_match('#^vehicles/[A-Za-z0-9][A-Za-z0-9._/\-]*$#', $key) === 1
        && strpos($key, '..') === false
        && strpos($key, '//') === false
        && substr($key, -1) !== '/';

    if (!$ok) {
        throw new InvalidArgumentException('Invalid storage key.');
    }
}

/** Logs the technical details privately, then throws a user-safe exception. */
function s3_fail(string $userMessage, Throwable $e, string $operation, string $key = ''): never
{
    error_log('[AutoCare][S3] ' . $operation . ' failed for key "' . $key . '": '
        . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 500));
    throw new S3OperationException($userMessage, 0, $e);
}

/** Uploads a local file to S3. */
function s3_put_file(string $key, string $localPath, string $contentType): void
{
    s3_validate_key($key);

    if (!is_file($localPath) || !is_readable($localPath)) {
        throw new S3OperationException('The file to upload could not be read.');
    }

    try {
        s3_client()->putObject([
            'Bucket'               => s3_bucket(),
            'Key'                  => $key,
            'SourceFile'           => $localPath,
            'ContentType'          => $contentType,
            'ServerSideEncryption' => 'AES256',      // encrypted at rest (SSE-S3)
        ]);
    } catch (Throwable $e) {
        s3_fail('The file could not be uploaded to storage. Please try again later.', $e, 'PUT', $key);
    }
}

/**
 * Downloads an object. Returns ['body' => stream, 'size' => int, 'type' => string].
 * Throws S3OperationException (code 404) if the object does not exist.
 */
function s3_get_object(string $key): array
{
    s3_validate_key($key);

    try {
        $result = s3_client()->getObject(['Bucket' => s3_bucket(), 'Key' => $key]);
    } catch (S3Exception $e) {
        if ($e->getStatusCode() === 404) {
            throw new S3OperationException('The file was not found in storage.', 404, $e);
        }
        s3_fail('The file could not be retrieved from storage. Please try again later.', $e, 'GET', $key);
    } catch (Throwable $e) {
        s3_fail('The file could not be retrieved from storage. Please try again later.', $e, 'GET', $key);
    }

    return [
        'body' => $result['Body'],
        'size' => (int)($result['ContentLength'] ?? 0),
        'type' => (string)($result['ContentType'] ?? 'application/octet-stream'),
    ];
}

/** Deletes an object. Throws S3OperationException if S3 reports a problem. */
function s3_delete_object(string $key): void
{
    s3_validate_key($key);

    try {
        s3_client()->deleteObject(['Bucket' => s3_bucket(), 'Key' => $key]);
    } catch (Throwable $e) {
        s3_fail('The file could not be deleted from storage. Please try again later.', $e, 'DELETE', $key);
    }
}

/** True if the object exists, false if it does not. Throws on any other problem. */
function s3_object_exists(string $key): bool
{
    s3_validate_key($key);

    try {
        s3_client()->headObject(['Bucket' => s3_bucket(), 'Key' => $key]);
        return true;
    } catch (S3Exception $e) {
        if ($e->getStatusCode() === 404) {
            return false;
        }
        s3_fail('Could not check the file in storage. Please try again later.', $e, 'HEAD', $key);
    } catch (Throwable $e) {
        s3_fail('Could not check the file in storage. Please try again later.', $e, 'HEAD', $key);
    }
}
