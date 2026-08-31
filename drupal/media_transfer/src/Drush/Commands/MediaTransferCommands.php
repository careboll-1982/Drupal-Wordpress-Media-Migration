<?php

declare(strict_types=1);

namespace Drupal\media_transfer\Drush\Commands;

use Drupal\media_transfer\MediaTransferManager;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for bidirectional Drupal/WordPress media migration.
 */
final class MediaTransferCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'media_transfer.manager')]
    private readonly MediaTransferManager $manager,
  ) {
    parent::__construct();
  }

  #[CLI\Command(name: 'media-migration:export', aliases: ['mme'])]
  #[CLI\Argument(name: 'output', description: 'Path of the CSV manifest to create.')]
  #[CLI\Option(name: 'base-url', description: 'Public origin override, for example https://source.example.com. Otherwise Drush --uri is used.')]
  #[CLI\Option(name: 'bundles', description: 'Comma-separated media bundle IDs. All local-file bundles by default.')]
  #[CLI\Usage(name: 'drush mme /tmp/media.csv --base-url=https://drupal.example.com', description: 'Export Drupal media for WordPress.')]
  public function exportCsv(string $output, array $options = ['base-url' => NULL, 'bundles' => NULL]): int {
    $bundles = array_values(array_filter(array_map('trim', explode(',', (string) ($options['bundles'] ?? '')))));
    $counts = $this->manager->exportCsv($output, $options['base-url'] ?: NULL, $bundles);
    $this->logger()->success(sprintf('Exported %d CSV rows; skipped %d non-file/empty items.', $counts['exported'], $counts['skipped']));
    return self::EXIT_SUCCESS;
  }

  #[CLI\Command(name: 'media-migration:import', aliases: ['mmi'])]
  #[CLI\Argument(name: 'manifest', description: 'Path of the CSV manifest exported by WordPress.')]
  #[CLI\Option(name: 'destination', description: 'Drupal stream-wrapper directory for downloaded files.')]
  #[CLI\Option(name: 'offset', description: 'Number of manifest rows to skip.')]
  #[CLI\Option(name: 'limit', description: 'Maximum rows to process; zero means all.')]
  #[CLI\Option(name: 'dry-run', description: 'Validate rows without downloading or saving entities.')]
  #[CLI\Option(name: 'insecure', description: 'Allow invalid SSL certificates for source downloads.')]
  #[CLI\Option(name: 'mapping', description: 'Type mappings: image=bundle:field,document=bundle:field,audio=bundle:field,video=bundle:field.')]
  #[CLI\Usage(name: 'drush mmi /tmp/media.csv --limit=100', description: 'Import the first WordPress batch.')]
  public function import(string $manifest, array $options = ['destination' => 'public://media-migration', 'offset' => 0, 'limit' => 0, 'dry-run' => FALSE, 'insecure' => FALSE, 'mapping' => NULL]): int {
    $mappings = MediaTransferManager::DEFAULT_MAPPINGS;
    foreach (array_filter(explode(',', (string) ($options['mapping'] ?? ''))) as $definition) {
      [$type, $target] = array_pad(explode('=', $definition, 2), 2, '');
      [$bundle, $field] = array_pad(explode(':', $target, 2), 2, '');
      if ($type !== '' && $bundle !== '' && $field !== '') {
        $mappings[$type] = ['bundle' => $bundle, 'field' => $field];
      }
    }
    $counts = $this->manager->importCsv(
      $manifest,
      $mappings,
      (string) $options['destination'],
      max(0, (int) $options['offset']),
      max(0, (int) $options['limit']),
      (bool) $options['dry-run'],
      (bool) $options['insecure'],
    );
    foreach ($counts['errors'] as $error) {
      $this->logger()->warning(sprintf('CSV row %d (%s): %s', $error['row'], $error['name'], $error['message']));
    }
    $this->logger()->success(sprintf('Created %d, skipped %d, failed %d.', $counts['created'], $counts['skipped'], $counts['failed']));
    return $counts['failed'] > 0 ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

}
