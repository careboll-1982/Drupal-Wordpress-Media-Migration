<?php

declare(strict_types=1);

namespace Drupal\media_transfer\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\media_transfer\MediaTransferManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the browser-based WordPress CSV export form.
 */
final class MediaExportForm extends FormBase {

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('file_system'),
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'media_transfer_export_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();
    $defaultOrigin = $request->getSchemeAndHttpHost();
    $mediaTypes = $this->entityTypeManager->getStorage('media_type')->loadMultiple();
    $options = [];
    foreach ($mediaTypes as $id => $mediaType) {
      $options[$id] = $mediaType->label();
    }

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Generate a CSV that the Drupal WordPress Media Migration plugin can fetch into the WordPress Media Library. Only media backed by files will be included.') . '</p>',
    ];
    $form['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Public Drupal site URL'),
      '#description' => $this->t('WordPress must be able to reach this address while importing the files.'),
      '#default_value' => $defaultOrigin,
      '#required' => TRUE,
    ];
    $form['bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Media types'),
      '#options' => $options,
      '#default_value' => array_keys($options),
      '#required' => TRUE,
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Generate CSV'),
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $directory = 'temporary://media-migration';
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      $this->messenger()->addError($this->t('Drupal could not prepare the temporary export directory.'));
      return;
    }
    $token = bin2hex(random_bytes(32));
    $uri = $directory . '/media-' . $token . '.csv';
    $bundles = array_values(array_filter($form_state->getValue('bundles')));

    $builder = (new BatchBuilder())
      ->setTitle($this->t('Exporting media'))
      ->setInitMessage($this->t('Preparing the CSV export…'))
      ->setProgressMessage($this->t('Processed @current of @total batches.'))
      ->setErrorMessage($this->t('The export encountered an error.'))
      ->addOperation([self::class, 'processBatch'], [[
        'uri' => $uri,
        'token' => $token,
        'base_url' => rtrim((string) $form_state->getValue('base_url'), '/'),
        'bundles' => $bundles,
        'uid' => (int) $this->currentUser()->id(),
      ]])
      ->setFinishCallback([self::class, 'finishBatch']);
    batch_set($builder->toArray());
  }

  public static function processBatch(array $settings, array &$context): void {
    /** @var \Drupal\media_transfer\MediaTransferManager $manager */
    $manager = \Drupal::service('media_transfer.manager');
    if (empty($context['sandbox']['initialized'])) {
      $manager->initializeCsv($settings['uri']);
      $query = \Drupal::entityQuery('media')->accessCheck(FALSE);
      if ($settings['bundles']) {
        $query->condition('bundle', $settings['bundles'], 'IN');
      }
      $context['sandbox']['ids'] = array_values($query->sort('mid')->execute());
      $context['sandbox']['total'] = count($context['sandbox']['ids']);
      $context['sandbox']['offset'] = 0;
      $context['sandbox']['exported'] = 0;
      $context['sandbox']['skipped'] = 0;
      $context['sandbox']['initialized'] = TRUE;
    }

    $ids = array_slice($context['sandbox']['ids'], $context['sandbox']['offset'], 50);
    if ($ids) {
      $counts = $manager->appendCsv($settings['uri'], $ids, $settings['base_url']);
      $context['sandbox']['offset'] += count($ids);
      $context['sandbox']['exported'] += $counts['exported'];
      $context['sandbox']['skipped'] += $counts['skipped'];
    }
    $total = max(1, $context['sandbox']['total']);
    $context['finished'] = min(1, $context['sandbox']['offset'] / $total);
    $context['message'] = t('Exported @count downloadable media items.', ['@count' => $context['sandbox']['exported']]);
    if ($context['finished'] >= 1 || $context['sandbox']['total'] === 0) {
      $context['results'] = [
        'uri' => $settings['uri'],
        'token' => $settings['token'],
        'uid' => $settings['uid'],
        'exported' => $context['sandbox']['exported'],
        'skipped' => $context['sandbox']['skipped'],
      ];
      $context['finished'] = 1;
    }
  }

  public static function finishBatch(bool $success, array $results, array $operations): void {
    if (!$success || empty($results['token'])) {
      \Drupal::messenger()->addError(t('The media export did not complete.'));
      return;
    }
    \Drupal::keyValue('media_transfer.downloads')->set($results['token'], [
      'uri' => $results['uri'],
      'uid' => $results['uid'],
      'expires' => \Drupal::time()->getRequestTime() + 3600,
    ]);
    $url = Url::fromRoute('media_transfer.download', ['token' => $results['token']])->toString();
    \Drupal::messenger()->addStatus(t('Exported @exported media items; skipped @skipped. <a href=":url">Download the CSV</a>. The link expires in one hour.', [
      '@exported' => $results['exported'],
      '@skipped' => $results['skipped'],
      ':url' => $url,
    ]));
  }

}
