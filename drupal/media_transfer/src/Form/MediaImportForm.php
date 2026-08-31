<?php

declare(strict_types=1);

namespace Drupal\media_transfer\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the browser-based WordPress-to-Drupal import form.
 */
final class MediaImportForm extends FormBase {

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('entity_type.manager'));
  }

  public function getFormId(): string {
    return 'media_transfer_import_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $mediaTypes = $this->entityTypeManager->getStorage('media_type')->loadMultiple();
    $options = [];
    foreach ($mediaTypes as $id => $mediaType) {
      $sourceField = $mediaType->getSource()->getConfiguration()['source_field'] ?? '';
      if ($sourceField !== '') {
        $options[$id] = $mediaType->label() . ' (' . $id . ' / ' . $sourceField . ')';
      }
    }
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Upload a CSV exported by the WordPress plugin. Imports are restart-safe and errors are written to the Drupal log.') . '</p>',
    ];
    $form['manifest'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('WordPress media CSV'),
      '#upload_location' => 'temporary://media-migration-imports',
      '#upload_validators' => ['FileExtension' => ['extensions' => 'csv']],
      '#required' => TRUE,
    ];
    foreach (['image', 'document', 'audio', 'video'] as $type) {
      $form['mapping_' . $type] = [
        '#type' => 'select',
        '#title' => $this->t('@type destination media type', ['@type' => ucfirst($type)]),
        '#options' => ['' => $this->t('- Skip this type -')] + $options,
        '#default_value' => isset($options[$type]) ? $type : '',
      ];
    }
    $form['destination'] = [
      '#type' => 'textfield',
      '#title' => $this->t('File destination'),
      '#default_value' => 'public://media-migration',
      '#required' => TRUE,
    ];
    $form['allow_insecure_ssl'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow invalid SSL certificates for source downloads'),
      '#description' => $this->t('Use only for a trusted source. SSL verification is enabled by default.'),
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit', '#button_type' => 'primary',
      '#value' => $this->t('Start import'),
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $fileIds = $form_state->getValue('manifest');
    $fid = (int) reset($fileIds);
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if (!$file instanceof FileInterface) {
      $this->messenger()->addError($this->t('The uploaded CSV could not be loaded.'));
      return;
    }
    $file->setPermanent()->save();
    $mappings = [];
    $mediaTypes = $this->entityTypeManager->getStorage('media_type');
    foreach (['image', 'document', 'audio', 'video'] as $type) {
      $bundle = (string) $form_state->getValue('mapping_' . $type);
      $mediaType = $bundle !== '' ? $mediaTypes->load($bundle) : NULL;
      if ($mediaType) {
        $mappings[$type] = [
          'bundle' => $bundle,
          'field' => $mediaType->getSource()->getConfiguration()['source_field'],
        ];
      }
    }
    $total = $this->countRows($file->getFileUri());
    $builder = (new BatchBuilder())
      ->setTitle($this->t('Importing WordPress media'))
      ->setProgressMessage($this->t('Processed @current of @total batches.'))
      ->setErrorMessage($this->t('The media import encountered an error.'))
      ->addOperation([self::class, 'processBatch'], [[
        'uri' => $file->getFileUri(), 'fid' => $fid, 'total' => $total,
        'mappings' => $mappings,
        'destination' => (string) $form_state->getValue('destination'),
        'insecure' => (bool) $form_state->getValue('allow_insecure_ssl'),
      ]])
      ->setFinishCallback([self::class, 'finishBatch']);
    batch_set($builder->toArray());
  }

  public static function processBatch(array $settings, array &$context): void {
    $context['sandbox'] = ($context['sandbox'] ?? []) + [
      'offset' => 0, 'created' => 0, 'skipped' => 0,
      'failed' => 0, 'errors' => [],
    ];
    /** @var \Drupal\media_transfer\MediaTransferManager $manager */
    $manager = \Drupal::service('media_transfer.manager');
    $batchSize = 20;
    $counts = $manager->importCsv(
      $settings['uri'], $settings['mappings'], $settings['destination'],
      $context['sandbox']['offset'], $batchSize, FALSE, $settings['insecure'],
    );
    $context['sandbox']['offset'] = min($settings['total'], $context['sandbox']['offset'] + $batchSize);
    foreach (['created', 'skipped', 'failed'] as $counter) {
      $context['sandbox'][$counter] += $counts[$counter];
    }
    $context['sandbox']['errors'] = array_slice(array_merge($counts['errors'], $context['sandbox']['errors']), 0, 20);
    $context['message'] = t('Created @created; skipped @skipped; failed @failed.', [
      '@created' => $context['sandbox']['created'], '@skipped' => $context['sandbox']['skipped'],
      '@failed' => $context['sandbox']['failed'],
    ]);
    $allFailed = $counts['failed'] === $batchSize && $counts['created'] === 0 && $counts['skipped'] === 0;
    $context['finished'] = $settings['total'] > 0
      ? min(1, $context['sandbox']['offset'] / $settings['total']) : 1;
    if ($allFailed || $context['finished'] >= 1) {
      $context['results'] = $context['sandbox'] + ['fid' => $settings['fid'], 'all_failed' => $allFailed];
      $context['finished'] = 1;
    }
  }

  public static function finishBatch(bool $success, array $results, array $operations): void {
    if (!empty($results['fid'])) {
      $file = \Drupal::entityTypeManager()->getStorage('file')->load($results['fid']);
      if ($file) {
        $file->delete();
      }
    }
    if (!$success) {
      \Drupal::messenger()->addError(t('The media import did not complete.'));
      return;
    }
    \Drupal::messenger()->addStatus(t('Created @created; skipped @skipped; failed @failed.', [
      '@created' => $results['created'] ?? 0, '@skipped' => $results['skipped'] ?? 0,
      '@failed' => $results['failed'] ?? 0,
    ]));
    if (!empty($results['all_failed'])) {
      \Drupal::messenger()->addError(t('Import stopped because an entire batch failed. Review Recent log messages before retrying.'));
    }
    foreach ($results['errors'] ?? [] as $error) {
      \Drupal::messenger()->addWarning(t('CSV row @row (@name): @message', [
        '@row' => $error['row'], '@name' => $error['name'], '@message' => $error['message'],
      ]));
    }
  }

  private function countRows(string $uri): int {
    $handle = fopen($uri, 'rb');
    if ($handle === FALSE) {
      return 0;
    }
    fgetcsv($handle, NULL, ',', '"', '');
    $count = 0;
    while (fgetcsv($handle, NULL, ',', '"', '') !== FALSE) {
      $count++;
    }
    fclose($handle);
    return $count;
  }

}
