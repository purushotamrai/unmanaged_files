<?php

namespace Drupal\unmanaged_files\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class UnmanagedFilesAdminForm.
 */
class UnmanagedFilesAdminForm extends ConfigFormBase {

  /**
   * Drupal\unmanaged_files\UnmanagedFilesManager definition.
   *
   * @var \Drupal\unmanaged_files\UnmanagedFilesManager
   */
  protected $UnmanagedFilesManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->UnmanagedFilesManager = $container->get('unmanaged_files.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'unmanaged_files.admin_settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'unmanaged_files_admin_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('unmanaged_files.admin_settings');
    $publicDirectories = $this->UnmanagedFilesManager->getDirectories();
    $form['exclude_directory'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Exclude Following Directories'),
    ];
    $this->buildNestedDirOptions($form['exclude_directory'], $publicDirectories, $config->get('exclude_directory'));
    $form['files_per_page'] = [
      '#type' => 'number',
      '#title' => $this->t('Files per page'),
      '#default_value' => $config->get('files_per_page'),
      '#description' => $this->t('Recommended value < 2000'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * Build nested checkboxes for directories.
   */
  protected function buildNestedDirOptions(&$form, $dirs, $config, $parentDir = NULL) {
    foreach ($dirs as $dir => $subDir) {
      $form[$dir . '_fieldset'] = [
        '#type' => 'details',
        '#title' => $dir,
        $dir => [
          '#type' => 'checkbox',
          '#title' => $dir,
          '#default_value' => in_array($dir, $config),
        ],
      ];

      if (!empty($parentDir) && !empty($form[$parentDir])) {
        $form[$dir . '_fieldset'][$dir]['#states'] = [
          'checked' => [
            ':input[name="' . $parentDir . '"]' => ['checked' => TRUE],
          ],
          'disabled' => [
            ':input[name="' . $parentDir . '"]' => ['checked' => TRUE],
          ],
        ];
      }

      if (!empty($subDir)) {
        $this->buildNestedDirOptions($form[$dir . '_fieldset'], $subDir, $config, $dir);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $values = $form_state->getValues();
    $exclude_directory = [];
    foreach ($values as $key => $value) {
      if (preg_match('/public:\/\//', $key)) {
        if ($value) {
          $exclude_directory[] = $key;
        }
      }
    }

    $this->config('unmanaged_files.admin_settings')
      ->set('exclude_directory', $exclude_directory)
      ->set('files_per_page', $form_state->getValue('files_per_page'))
      ->save();
  }

}
