<?php

namespace Drupal\unmanaged_files\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class DeleteUnmanagedFilesForm.
 */
class DeleteUnmanagedFilesForm extends FormBase {

  /**
   * Drupal\unmanaged_files\UnmanagedFilesManager definition.
   *
   * @var \Drupal\unmanaged_files\UnmanagedFilesManager
   */
  protected $UnmanagedFilesManager;

  /**
   * Drupal\Core\Messenger\MessengerInterface definition.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Drupal\Core\TempStore\SharedTempStore definition.
   *
   * @var \Drupal\Core\TempStore\SharedTempStore
   */
  protected $tempStore;

  /**
   * Drupal\Core\Config\ConfigFactoryInterface definition.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Drupal\Core\Pager\PagerManager definition.
   *
   * @var \Drupal\Core\Pager\PagerManager
   */
  protected $pagerManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->UnmanagedFilesManager = $container->get('unmanaged_files.manager');
    $instance->messenger = $container->get('messenger');
    $instance->tempStore = $container->get('tempstore.shared')->get('unmanaged_files');
    $instance->configFactory = $container->get('config.factory');
    $instance->pagerManager = $container->get('pager.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'delete_unmanaged_files_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $this->buildResult($form);

    // Implement pager.
    $unmanagedFiles = $this->UnmanagedFilesManager->getUnmanagedFiles();
    $totalFiles = count($unmanagedFiles);
    $limit = $this->configFactory->get('unmanaged_files.admin_settings')->get('files_per_page');
    $limit = !empty($limit) ? $limit : 2000;
    $pager = $this->pagerManager->createPager($totalFiles, $limit);
    $currentPage = $pager->getCurrentPage();
    $unmanagedFiles = array_slice($unmanagedFiles, $currentPage * $limit, $limit);

    $form['files'] = [
      '#type' => 'fieldset',
      '#tree' => TRUE,
      '#title' => $this->t(
        'Showing @start - @end of @total Unmanaged Files',
        [
          '@start' => ($currentPage * $limit) + 1,
          '@end' => ($currentPage * $limit) + $limit,
          '@total' => $totalFiles,
        ]
      ),
    ];
    $this->buildFilesList($form, $unmanagedFiles);

    $form['pager'] = [
      '#type' => 'pager',
    ];
    $form['validate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Validate file being used in wysiwyg text fields site-wide.'),
      '#default_value' => TRUE,
    ];
    $form['dry_run'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Dry Run'),
      '#default_value' => TRUE,
      '#description' => $this->t('Uncheck this to actually delete the files.'),
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete'),
      '#attributes' => [
        'onclick' => "return confirm('Kindly confirm deletion of the files or Cancel to revisit the selected files. It\'s recommended to take backup before deletion.');",
      ]
    ];
    return $form;
  }

  /**
   * Build results using tempstore from previous execution.
   *
   * @param array $form
   *   Form array.
   *
   * @throws \Drupal\Core\TempStore\TempStoreException
   */
  protected function buildResult(&$form) {
    $nonDeletedFiles = $this->tempStore->get('unmanaged_files_being_used');
    if (!empty($nonDeletedFiles)) {
      $this->messenger->addWarning('Following files were NOT deleted as these files are being used directly.');
      $form['unmanaged_files_being_used'] = [
        '#type' => 'details',
        '#title' => $this->t('List of unmanaged files being used and hence not deleted'),
      ];

      $form['unmanaged_files_being_used']['table'] = [
        '#type' => 'table',
        '#header' => [
          'file_uri' => $this->t('File Uri'),
          'host_entity_type' => $this->t('Host Entity Type'),
          'host_entity_id' => $this->t('Host Entity ID'),
        ],
      ];

      $key = 0;
      foreach ($nonDeletedFiles as $file => $details) {
        foreach ($details as $entity_type => $ids) {
          $id_links = [];
          foreach ($ids as $id) {
            switch ($entity_type) {
              case 'block_content':
                $id_links[] = '<a href=/block/' . $id . ' target="_blank" > ' . $id . '</a>';
                break;

              default:
                $id_links[] = '<a href=/' . $entity_type . '/' . $id . ' target="_blank" > ' . $id . '</a>';
            }
          }
          $form['unmanaged_files_being_used']['table'][$key] = [
            'file_uri' => ['#markup' => '<a href="' . file_create_url($file) . '" target=_blank>' . $file . '</a>'],
            'host_entity_type' => ['#markup' => $entity_type],
            'host_entity_id' => ['#markup' => implode(', ', $id_links)],
          ];
          $key++;
        }
      }

      // Reset tempstore.
      $this->tempStore->set('unmanaged_files_being_used', []);
    }
  }

  /**
   * Build Files list in form.
   *
   * @param array $form
   *   Form array.
   */
  protected function buildFilesList(&$form, $unmanagedFiles) {
    $header = [
      'uri' => $this->t('File Uri'),
    ];
    foreach ($unmanagedFiles as $uri => $info) {
      if (preg_match('/^public:\/\/(.*)\//', $uri, $matches)) {
        // File is in directory.
        $directory = explode('/', $matches[1]);
        $directory = reset($directory);
      }
      else {
        $directory = '/files';
      }

      if (!empty($form['files'][$directory])) {
        $form['files'][$directory]['files']['#options'][$uri] = ['uri' => $uri];
        $form['files'][$directory]['files']['#default_value'][$uri] = 1;
      }
      else {
        $form['files'][$directory] = [
          '#type' => 'details',
          '#title' => $directory,
        ];
        $form['files'][$directory]['files'] = [
          '#type' => 'tableselect',
          '#title' => $this->t('Select Files'),
          '#description' => $this->t('Select Unmanaged Files to be deleted'),
          '#weight' => '0',
          '#header' => $header,
          '#default_value' => [],
        ];
        $form['files'][$directory]['files']['#options'][$uri] = ['uri' => $uri];
        $form['files'][$directory]['files']['#default_value'][$uri] = 1;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $validate = $values['validate'];
    $dry_run = $values['dry_run'];

    $batch = [
      'init_message' => t('Starting files processing and deletion operation...'),
      'operations' => [],
      'finished' => '\Drupal\unmanaged_files\UnmanagedFilesManager::processFileDeleteFinishCallback',
    ];

    foreach ($values['files'] as $dir => $files) {
      foreach ($files['files'] as $file) {
        if ($file) {
          $batch['operations'][] = [
            '\Drupal\unmanaged_files\UnmanagedFilesManager::processFileDelete',
            [$file, $validate, $dry_run],
          ];
        }
      }
    }

    if (!empty($batch['operations'])) {
      batch_set($batch);
    }
    else {
      $this->messenger->addError($this->t('No files found/selected for deletion.'));
    }

    if ($dry_run) {
      $this->messenger->addError($this->t('As it was a dry run, files have NOT been deleted.'));
    }
  }

}
