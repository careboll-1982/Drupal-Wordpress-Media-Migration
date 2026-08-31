<?php

declare(strict_types=1);

namespace Drupal\media_transfer\Controller;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves protected, short-lived CSV export downloads.
 */
final class MediaExportDownloadController {

  public function __construct(
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  public function download(string $token): BinaryFileResponse {
    $store = $this->keyValueFactory->get('media_transfer.downloads');
    $record = $store->get($token);
    if (!$record || (int) $record['uid'] !== (int) $this->currentUser->id() || (int) $record['expires'] < time()) {
      throw new NotFoundHttpException();
    }
    $path = $this->fileSystem->realpath($record['uri']);
    if (!$path || !is_readable($path)) {
      throw new NotFoundHttpException();
    }
    $store->delete($token);
    $response = new BinaryFileResponse($path);
    $response->setContentDisposition('attachment', 'drupal-media-' . date('Y-m-d-His') . '.csv');
    $response->deleteFileAfterSend(TRUE);
    return $response;
  }

}
