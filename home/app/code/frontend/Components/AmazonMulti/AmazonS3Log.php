<?php
namespace App\Frontend\Components\AmazonMulti;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

use App\Core\Components\Base;
use App\Core\Models\User;

class AmazonS3Log extends Base
{
    /**
    * List S3 objects by a simple prefix like "2025-02-09/" or "2025-02-09/7erty".
    *
    * @param string      $bucket   S3 bucket name
    * @param string      $prefix   e.g. "2025-02-09/" or "2025-02-09/7erty"
    * @param int         $limit    1..1000 (MaxKeys per call)
    * @param string|null $token    ContinuationToken for pagination
    * @param bool        $presign  Include short-lived download URLs
    * @param int         $ttl      Presigned URL TTL in seconds (default 300)
    * @return array {
    *   items: [{ key, size, lastModified, url? }, ...],
    *   isTruncated: bool,
    *   nextToken: ?string
    * }
    */
    function s3ListByPrefix(
        string $prefix,
        int $limit = 1000,
        ?string $token = null,
        bool $presign = false,
        int $ttl = 300
    ): array {
        $bucket = $this->di->getConfig()->get('amazon_s3_log_bucket') ?? 'amazon-prod-env-log';
        $prefix = ltrim($prefix, '/');                 // normalize
        $limit  = max(1, min(1000, $limit));

        $s3 = new S3Client(include BP . '/app/etc/aws.php');

        $prefix = $prefix ? ( 'logs/' . $prefix )  : 'logs';

        try {
            $resp = $s3->listObjectsV2([
                'Bucket'            => $bucket,
                'Prefix'            => $prefix === '' ? null : $prefix,
                'MaxKeys'           => $limit,
                'ContinuationToken' => $token ?: null,
                // 'Delimiter' => '/', // <-- key to grouping by "folders"
            ]);

            $folders = [];
            foreach ($resp['CommonPrefixes'] ?? [] as $cp) {
                $folders[] = rtrim($cp['Prefix'], '/');
            }


            $items = [];
            foreach (($resp['Contents'] ?? []) as $o) {
                $item = [
                    'key'          => $o['Key'],
                    'size_human'         => $this->formatSize((int)$o['Size']),
                    'StorageClass' => $o['StorageClass'],
                    'lastModified' => $o['LastModified'] instanceof DateTimeInterface
                        ? $o['LastModified']->format(DateTimeInterface::ATOM)
                        : null,
                ];

                if ($presign) {
                    $cmd = $s3->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $o['Key']]);
                    $req = $s3->createPresignedRequest($cmd, "+{$ttl} seconds");
                    $item['downloadUrl'] = (string)$req->getUri();
                }

                $items[] = $item;
            }

            return [
                'files'       => $items,
                'isTruncated' => (bool)($resp['IsTruncated'] ?? false),
                'nextToken'   => $resp['NextContinuationToken'] ?? null,
                'prefixUsed'  => $prefix,
                'folder' => $folders
            ];
        } catch (AwsException $e) {
            error_log("S3 list error: " . $e->getMessage());
            return [
                'items'       => [],
                'isTruncated' => false,
                'nextToken'   => null,
                'error'       => $e->getMessage(),
            ];
        }
    }
    /**
     * Format bytes into KB, MB, GB with 2 decimals.
     */
    function formatSize(int $bytes): string {
        if ($bytes >= 1073741824) { // 1 GB
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) { // 1 MB
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) { // 1 KB
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' B';
        }
    }
}
