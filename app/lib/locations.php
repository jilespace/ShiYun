<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* 兼容垫片：历史代码仍可调用 blob_location_* 全局函数。
   新代码请直接依赖 Shiyun\Lib\Locations（由 app/autoload.php 自动加载）。 */
require_once __DIR__ . '/Locations.php';

function blob_location_record(int $blobId, int $sourceId, string $objectKey, int $size): int
{
    return \Shiyun\Lib\Locations::record($blobId, $sourceId, $objectKey, $size);
}

function blob_location_mark_ok(int $blobId, int $sourceId, string $objectKey): void
{
    \Shiyun\Lib\Locations::markOk($blobId, $sourceId, $objectKey);
}

function blob_location_mark_failed(int $blobId, int $sourceId, string $error): void
{
    \Shiyun\Lib\Locations::markFailed($blobId, $sourceId, $error);
}

function blob_location_mark_stale(int $locationId, string $error): void
{
    \Shiyun\Lib\Locations::markStale($locationId, $error);
}

function blob_location_find(int $blobId, int $sourceId): ?array
{
    return \Shiyun\Lib\Locations::find($blobId, $sourceId);
}

function blob_locations_of_blob(int $blobId): array
{
    return \Shiyun\Lib\Locations::ofBlob($blobId);
}

function blob_location_forget(int $locationId): void
{
    \Shiyun\Lib\Locations::forget($locationId);
}

function blob_location_direct(int $userId, int $blobId): ?array
{
    return \Shiyun\Lib\Locations::direct($userId, $blobId);
}
