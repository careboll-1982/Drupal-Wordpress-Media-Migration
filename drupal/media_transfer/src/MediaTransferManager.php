<?php

declare(strict_types=1);

namespace Drupal\media_transfer;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\Exception\InvalidStreamWrapperException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use GuzzleHttp\ClientInterface;

/**
 * Exports and imports the shared media migration CSV manifest.
 */
final class MediaTransferManager {

  public const CSV_COLUMNS = [
    'media_uuid', 'bundle', 'name', 'status', 'langcode', 'created',
    'source_field', 'file_uuid', 'filename', 'mime_type', 'size', 'sha256',
    'download_url', 'alt', 'title', 'description',
  ];

  public const DEFAULT_MAPPINGS = [
    'image' => ['bundle' => 'image', 'field' => 'field_media_image'],
    'document' => ['bundle' => 'document', 'field' => 'field_media_document'],
    'audio' => ['bundle' => 'audio', 'field' => 'field_media_audio_file'],
    'video' => ['bundle' => 'video', 'field' => 'field_media_video_file'],
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly FileSystemInterface $fileSystem,
    private readonly ClientInterface $httpClient,
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly LoggerChannelInterface $logger,
  ) {}

  public function exportCsv(string $output, ?string $baseUrl = NULL, array $bundles = []): array {
    $this->initializeCsv($output);
    $query = $this->entityTypeManager->getStorage('media')->getQuery()->accessCheck(FALSE)->sort('mid');
    if ($bundles) {
      $query->condition('bundle', $bundles, 'IN');
    }
    $counts = ['exported' => 0, 'skipped' => 0];
    foreach (array_chunk(array_values($query->execute()), 100) as $ids) {
      $chunk = $this->appendCsv($output, $ids, $baseUrl ?? '');
      $counts['exported'] += $chunk['exported'];
      $counts['skipped'] += $chunk['skipped'];
    }
    return $counts;
  }

  public function initializeCsv(string $output): void {
    $handle = fopen($output, 'wb');
    if ($handle === FALSE) {
      throw new \RuntimeException("Cannot open CSV for writing: {$output}");
    }
    fputcsv($handle, self::CSV_COLUMNS, ',', '"', '');
    fclose($handle);
  }

  public function appendCsv(string $output, array $mediaIds, string $baseUrl): array {
    $handle = fopen($output, 'ab');
    if ($handle === FALSE) {
      throw new \RuntimeException("Cannot open CSV for appending: {$output}");
    }
    $counts = ['exported' => 0, 'skipped' => 0];
    $storage = $this->entityTypeManager->getStorage('media');
    foreach ($storage->loadMultiple($mediaIds) as $media) {
      $row = $this->buildExportRow($media, $baseUrl);
      if ($row === NULL) {
        $counts['skipped']++;
        continue;
      }
      fputcsv($handle, array_map(static fn (string $column): mixed => $row[$column] ?? '', self::CSV_COLUMNS), ',', '"', '');
      $counts['exported']++;
    }
    fclose($handle);
    $storage->resetCache($mediaIds);
    return $counts;
  }

  public function importCsv(
    string $manifest,
    array $mappings = self::DEFAULT_MAPPINGS,
    string $destination = 'public://media-migration',
    int $offset = 0,
    int $limit = 0,
    bool $dryRun = FALSE,
    bool $allowInsecureSsl = FALSE,
  ): array {
    $handle = fopen($manifest, 'rb');
    if ($handle === FALSE) {
      throw new \RuntimeException("Cannot open CSV: {$manifest}");
    }
    $headers = fgetcsv($handle, NULL, ',', '"', '');
    $required = ['media_uuid', 'bundle', 'name', 'filename', 'download_url'];
    if (!$headers || array_diff($required, $headers)) {
      fclose($handle);
      throw new \UnexpectedValueException('CSV does not contain the required media migration columns.');
    }
    $counts = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    $dataRow = 0;
    $processed = 0;
    while (($values = fgetcsv($handle, NULL, ',', '"', '')) !== FALSE) {
      $dataRow++;
      if ($dataRow <= $offset) {
        continue;
      }
      if ($limit > 0 && $processed >= $limit) {
        break;
      }
      $processed++;
      unset($row);
      try {
        if (count($headers) !== count($values)) {
          throw new \UnexpectedValueException('Column count does not match the CSV header.');
        }
        $row = array_combine($headers, $values);
        $sourceType = strtolower((string) ($row['bundle'] ?? ''));
        if (!isset($mappings[$sourceType])) {
          $counts['skipped']++;
          continue;
        }
        $this->validateMapping($mappings[$sourceType]);
        if ($this->alreadyImported($row)) {
          $counts['skipped']++;
          continue;
        }
        if (!$dryRun) {
          $this->importRow($row, $mappings, rtrim($destination, '/'), $allowInsecureSsl);
        }
        $counts['created']++;
      }
      catch (\Throwable $e) {
        $detail = [
          'row' => $dataRow + 1,
          'name' => isset($row) ? (string) ($row['name'] ?? '') : '',
          'url' => isset($row) ? (string) ($row['download_url'] ?? '') : '',
          'message' => $e->getMessage(),
        ];
        $counts['failed']++;
        $counts['errors'][] = $detail;
        $this->logger->error('CSV row @row (@name) failed: @message; URL: @url', [
          '@row' => $detail['row'], '@name' => $detail['name'],
          '@message' => $detail['message'], '@url' => $detail['url'],
        ]);
      }
    }
    fclose($handle);
    return $counts;
  }

  private function buildExportRow(MediaInterface $media, string $baseUrl): ?array {
    $sourceField = $media->getSource()->getConfiguration()['source_field'] ?? NULL;
    if (!$sourceField || !$media->hasField($sourceField) || $media->get($sourceField)->isEmpty()) {
      return NULL;
    }
    $item = $media->get($sourceField)->first();
    $file = $item?->entity;
    if (!$file instanceof FileInterface) {
      return NULL;
    }
    $uri = $file->getFileUri();
    try {
      $generatedUrl = $this->fileUrlGenerator->generateString($uri);
    }
    catch (InvalidStreamWrapperException $e) {
      if ($baseUrl !== '' && parse_url($uri, PHP_URL_SCHEME) === NULL) {
        $generatedUrl = '/' . ltrim($uri, '/');
      }
      else {
        $this->logger->warning('Skipped media @media because @uri is unavailable: @message', [
          '@media' => $media->id(), '@uri' => $uri, '@message' => $e->getMessage(),
        ]);
        return NULL;
      }
    }
    if (preg_match('#^https?://#i', $generatedUrl)) {
      $downloadUrl = $generatedUrl;
    }
    elseif ($baseUrl !== '') {
      $downloadUrl = rtrim($baseUrl, '/') . '/' . ltrim($generatedUrl, '/');
    }
    else {
      $downloadUrl = $this->fileUrlGenerator->generateAbsoluteString($uri);
    }
    $realpath = $this->fileSystem->realpath($uri);
    $properties = $item->getProperties(TRUE);
    return [
      'media_uuid' => $media->uuid(), 'bundle' => $media->bundle(),
      'name' => $media->label(), 'status' => (int) $media->isPublished(),
      'langcode' => $media->language()->getId(), 'created' => (int) $media->getCreatedTime(),
      'source_field' => $sourceField, 'file_uuid' => $file->uuid(),
      'filename' => $file->getFilename(), 'mime_type' => $file->getMimeType(),
      'size' => (int) $file->getSize(),
      'sha256' => $realpath && is_readable($realpath) ? hash_file('sha256', $realpath) : '',
      'download_url' => $downloadUrl,
      'alt' => isset($properties['alt']) ? $properties['alt']->getValue() : '',
      'title' => isset($properties['title']) ? $properties['title']->getValue() : '',
      'description' => isset($properties['description']) ? $properties['description']->getValue() : '',
    ];
  }

  private function alreadyImported(array $row): bool {
    $sourceId = (string) ($row['media_uuid'] ?? '');
    if ($sourceId === '') {
      return FALSE;
    }
    if (Uuid::isValid($sourceId)) {
      $ids = $this->entityTypeManager->getStorage('media')->getQuery()->accessCheck(FALSE)
        ->condition('uuid', $sourceId)->range(0, 1)->execute();
      if ($ids) {
        return TRUE;
      }
    }
    return $this->keyValueFactory->get('media_transfer.sources')->has(hash('sha256', $sourceId));
  }

  private function importRow(array $row, array $mappings, string $destination, bool $allowInsecureSsl): void {
    foreach (['media_uuid', 'bundle', 'name', 'filename', 'download_url'] as $required) {
      if (empty($row[$required])) {
        throw new \UnexpectedValueException("Missing required value: {$required}");
      }
    }
    $sourceType = strtolower((string) $row['bundle']);
    $mapping = $mappings[$sourceType] ?? NULL;
    if (empty($mapping['bundle']) || empty($mapping['field'])) {
      throw new \UnexpectedValueException("No Drupal mapping configured for source type: {$sourceType}");
    }
    $this->validateMapping($mapping);
    if (!in_array(parse_url((string) $row['download_url'], PHP_URL_SCHEME), ['http', 'https'], TRUE)) {
      throw new \UnexpectedValueException('download_url must be an absolute HTTP(S) URL.');
    }
    $directory = $destination . '/' . preg_replace('/[^a-z0-9_-]+/i', '-', $mapping['bundle']);
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException("Cannot prepare destination directory: {$directory}");
    }

    $file = $this->loadImportedFile($row);
    $temporary = '';
    try {
      if (!$file) {
        $temporary = $this->fileSystem->tempnam('temporary://', 'media-migration-');
        if ($temporary === FALSE) {
          throw new \RuntimeException('Could not create a temporary file.');
        }
        $this->httpClient->request('GET', $row['download_url'], [
          'sink' => $temporary, 'connect_timeout' => 20, 'timeout' => 300,
          'http_errors' => TRUE, 'verify' => !$allowInsecureSsl,
        ]);
        if (!empty($row['sha256']) && !hash_equals(strtolower((string) $row['sha256']), hash_file('sha256', $temporary))) {
          throw new \RuntimeException('Downloaded file checksum does not match the CSV.');
        }
        $safeName = $this->fileSystem->basename((string) $row['filename']);
        $target = $this->fileSystem->getDestinationFilename($directory . '/' . $safeName, FileExists::Rename);
        if ($target === FALSE) {
          throw new \RuntimeException('Could not choose a destination filename.');
        }
        $moved = $this->fileSystem->move($temporary, $target, FileExists::Error);
        $temporary = '';
        $values = [
          'uri' => $moved, 'filename' => $safeName,
          'filemime' => $row['mime_type'] ?: 'application/octet-stream', 'status' => 1,
        ];
        if (!empty($row['file_uuid']) && Uuid::isValid((string) $row['file_uuid'])) {
          $values['uuid'] = $row['file_uuid'];
        }
        $file = $this->entityTypeManager->getStorage('file')->create($values);
        $file->save();
        if (!empty($row['sha256'])) {
          $this->keyValueFactory->get('media_transfer.checksums')
            ->set(strtolower((string) $row['sha256']), $file->id());
        }
      }

      $mediaValues = [
        'bundle' => $mapping['bundle'], 'name' => $row['name'],
        'status' => filter_var($row['status'] ?? TRUE, FILTER_VALIDATE_BOOL),
        'langcode' => $row['langcode'] ?: 'en',
        'created' => is_numeric($row['created'] ?? NULL) ? (int) $row['created'] : time(),
      ];
      if (Uuid::isValid((string) $row['media_uuid'])) {
        $mediaValues['uuid'] = $row['media_uuid'];
      }
      $media = $this->entityTypeManager->getStorage('media')->create($mediaValues);
      $fieldValue = ['target_id' => $file->id()];
      $definitions = $media->get($mapping['field'])->getFieldDefinition()
        ->getFieldStorageDefinition()->getPropertyDefinitions();
      foreach (['alt', 'title', 'description'] as $property) {
        if (isset($definitions[$property]) && ($row[$property] ?? '') !== '') {
          $fieldValue[$property] = $row[$property];
        }
      }
      $media->set($mapping['field'], $fieldValue);
      $media->save();
      $this->keyValueFactory->get('media_transfer.sources')
        ->set(hash('sha256', (string) $row['media_uuid']), $media->id());
    }
    finally {
      if ($temporary && file_exists($temporary)) {
        @unlink($temporary);
      }
    }
  }

  private function loadImportedFile(array $row): ?FileInterface {
    $storage = $this->entityTypeManager->getStorage('file');
    if (!empty($row['file_uuid']) && Uuid::isValid((string) $row['file_uuid'])) {
      $ids = $storage->getQuery()->accessCheck(FALSE)
        ->condition('uuid', $row['file_uuid'])->range(0, 1)->execute();
      if ($ids) {
        $file = $storage->load(reset($ids));
        return $file instanceof FileInterface ? $file : NULL;
      }
    }
    if (!empty($row['sha256'])) {
      $fileId = $this->keyValueFactory->get('media_transfer.checksums')
        ->get(strtolower((string) $row['sha256']));
      $file = $fileId ? $storage->load($fileId) : NULL;
      return $file instanceof FileInterface ? $file : NULL;
    }
    return NULL;
  }

  private function validateMapping(array $mapping): void {
    if (empty($mapping['bundle']) || empty($mapping['field'])) {
      throw new \UnexpectedValueException('A destination mapping is incomplete.');
    }
    $mediaType = $this->entityTypeManager->getStorage('media_type')->load($mapping['bundle']);
    if (!$mediaType) {
      throw new \UnexpectedValueException("Drupal media type does not exist: {$mapping['bundle']}");
    }
    $expectedField = $mediaType->getSource()->getConfiguration()['source_field'] ?? '';
    if ($expectedField !== $mapping['field']) {
      throw new \UnexpectedValueException("{$mapping['field']} is not the source field for media type {$mapping['bundle']}");
    }
  }

}
