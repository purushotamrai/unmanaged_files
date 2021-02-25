<?php

namespace Drupal\unmanaged_files;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Driver\mysql\Connection;
use Drupal\Core\File\FileSystemInterface;

/**
 * Class UnmanagedFilesManager.
 */
class UnmanagedFilesManager {

  /**
   * Mandatory excluded directories.
   */
  const mandatoryExDirs = [
    'public://private',
    'public://styles',
    'public://css',
    'public://php',
    'public://js',
    'public://sitemaps',
    'public://xmlsitemap',
    'public://google_tag',
  ];

  /**
   * Drupal\Core\File\FileSystemInterface definition.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Drupal\Core\Database\Driver\mysql\Connection definition.
   *
   * @var \Drupal\Core\Database\Driver\mysql\Connection
   */
  protected $database;

  /**
   * Drupal\Core\Config\ConfigFactoryInterface definition.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new UnmanagedFilesManager object.
   */
  public function __construct(Connection $database,
                              FileSystemInterface $fileSystem,
                              ConfigFactoryInterface $configFactory) {
    $this->database = $database;
    $this->fileSystem = $fileSystem;
    $this->configFactory = $configFactory;
  }

  /**
   * Return unmanaged files in public directory.
   *
   * @return array
   *   List of unmanaged files.
   */
  public function getUnmanagedFiles() {
    $files = $this->getAllEligibleFiles();
    $managedFiles = $this->getAllManagedFiles();
    return array_diff_key($files, $managedFiles);
  }

  /**
   * Fetch all eligible files in files folder recursively.
   *
   * @return array
   *   List of files.
   */
  protected function getAllEligibleFiles() {
    $excludeDir = $this->configFactory->get('unmanaged_files.admin_settings')->get('exclude_directory');
    $excludeDir = !empty($excludeDir) ? array_merge($excludeDir, UnmanagedFilesManager::mandatoryExDirs) : UnmanagedFilesManager::mandatoryExDirs;
    $mandatoryExDirs = implode('|', $excludeDir);
    $mandatoryExDirs = str_replace('/', '\/', $mandatoryExDirs);

    return $this->fileSystem->scanDirectory(
      'public://',
      '/.*\..*/',
      [
        'nomask_dir' => "/$mandatoryExDirs/",
        'recurse' => TRUE
      ]
    );
  }

  /**
   * Return unique managed files list.
   *
   * @return mixed
   *   Array of unique managed files.
   */
  protected function getAllManagedFiles() {
    $query = $this->database->select('file_managed', 'fm')
      ->fields('fm', ['uri']);
    return $query->distinct()->execute()->fetchAllAssoc('uri');
  }

  /**
   * Fetch directories in files folder (non-recursive).
   *
   * @return array
   *   List of directories in files folder.
   */
  public function getDirectories() {
    $mandatoryExDirs = UnmanagedFilesManager::mandatoryExDirs;
    $mandatoryExDirs = implode('|', $mandatoryExDirs);
    $mandatoryExDirs = str_replace('/', '\/', $mandatoryExDirs);

    return $this->fileSystem->getDirectoryList(
      'public://',
      [
        'nomask_dir' => "/$mandatoryExDirs/",
        'recurse' => TRUE,
      ]
    );
  }

  /**
   * Batch processing function to delete files.
   *
   * @param $fileURIs
   *   List of file URIs.
   * @param $validate
   *   Validate flag.
   * @param $dry_run
   *   Dry run flag.
   * @param $context
   *   Batch context.
   */
  public static function processFilesDelete($fileURIs, $validate, $dry_run, &$context) {
    if (empty($context['sandbox'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current_id'] = 0;
      $context['sandbox']['max'] = count($fileURIs);
    }

    $limit = 10;
    $starting = $context['sandbox']['progress'];
    for ($i = $starting; $i < $starting + $limit; $i++) {
      if (isset($fileURIs[$i]) && !empty($fileURIs[$i])) {
        // Validate file is not used in wysiwyg fields.
        $delete = TRUE;
        $fileUri = $fileURIs[$i];
        if ($validate && UnmanagedFilesManager::validateFileUsage($fileUri)) {
          $delete = FALSE;
        }

        // Delete the files upon successful validations.
        if ($delete) {
          if (UnmanagedFilesManager::deleteFile($fileUri, $dry_run)) {
            $context['results'][] = $fileUri;
          }
        }
        $context['sandbox']['current_id'] = $context['sandbox']['progress'];
        $context['sandbox']['progress']++;
        $context['message'] = $fileUri;
      }
    }

    if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Deletes file (Batch fxn) based on fileUri after proper validation.
   *
   * @param $fileUri
   *   File URI.
   * @param $validate
   *   Validate flag.
   * @param $dry_run
   *   Dry run flag.
   * @param $context
   *   Batch context.
   */
  public static function processFileDelete($fileUri, $validate, $dry_run, &$context) {
    // Validate file is not used in wysiwyg fields.
    $delete = TRUE;
    if ($validate && UnmanagedFilesManager::validateFileUsage($fileUri)) {
      $delete = FALSE;
    }

    // Delete the files upon successful validations.
    if ($delete) {
      if (UnmanagedFilesManager::deleteFile($fileUri, $dry_run)) {
        $context['results'][] = $fileUri;
      }
    }
    $context['message'] = 'Processing file : ' . $fileUri;
  }

  /**
   * Validates file is being used in Drupal text fields.
   *
   * @param $fileUri
   *   File URI.
   *
   * @return bool
   *   File is being used or not.
   */
  protected static function validateFileUsage($fileUri) {
    $presence = FALSE;
    $textFields = UnmanagedFilesManager::getWysiwygFields();

    foreach ($textFields as $entity_type => $fields) {
      $query = \Drupal::entityQuery($entity_type);
      $parsedFileUri = str_replace('public://', 'files/', $fileUri);
      if ($query) {
        $query->orConditionGroup();
        foreach ($fields as $field) {
          $query->condition($field, $parsedFileUri, 'CONTAINS');
        }

        $result = $query->execute();

        if (!empty($result)) {
          $tempstore = \Drupal::service('tempstore.shared')->get('unmanaged_files');
          $nonDeletedFiles = $tempstore->get('unmanaged_files_being_used');
          $nonDeletedFiles = empty($nonDeletedFiles) ? [] : $nonDeletedFiles;
          $nonDeletedFiles[$fileUri][$entity_type] = $result;
          $tempstore->set('unmanaged_files_being_used', $nonDeletedFiles);
          $presence = TRUE;
          break;
        }
      }
    }

    return $presence;
  }

  /**
   * Get all wysiwyg fields.
   *
   * @return array|mixed|null
   *   List of fields.
   */
  protected static function getWysiwygFields() {
    $eligibleFields = &drupal_static(__FUNCTION__);
    if (!isset($eligibleFields)) {
      $textLong = \Drupal::service('entity_field.manager')->getFieldMapByFieldType('text_long');
      $textWithSummary = \Drupal::service('entity_field.manager')->getFieldMapByFieldType('text_with_summary');

      if (!empty($textLong['taxonomy_term']['description'])) {
        $description = $textLong['taxonomy_term']['description'];
        $textLong['taxonomy_term']['description__value'] = $description;
        unset($textLong['taxonomy_term']['description']);
      }

      foreach ($textLong as $entity_type => $fields) {
        $eligibleFields[$entity_type] = array_keys($fields);
      }
      foreach ($textWithSummary as $entity_type => $fields) {
        if (!empty($eligibleFields[$entity_type])) {
          $eligibleFields[$entity_type] = array_merge(array_keys($fields), $eligibleFields[$entity_type]);
        }
        else {
          $eligibleFields[$entity_type] = array_keys($fields);
        }
      }
    }

    return $eligibleFields;
  }

  /**
   * Delete file.
   *
   * @param $fileUri
   *   File URI.
   * @param $dry_run
   *   Dry Run flag.
   *
   * @return bool
   *   Deletion success.
   */
  protected static function deleteFile($fileUri, $dry_run) {
    $deleted = FALSE;
    try {
      $filePath = \Drupal::service('file_system')->realpath($fileUri);
      if (empty($dry_run)) {
        \Drupal::service('file_system')->delete($filePath);
      }
      $deleted = TRUE;
    }
    catch (\Exception $exception) {
      watchdog_exception('unmanaged_files', $exception);
    }

    return $deleted;
  }

  /**
   * Finish callback for batch process.
   *
   * @param mixed $success
   *   Success Flag.
   * @param array|mixed $results
   *   Result list.
   * @param array|mixed $operations
   *   List of incomplete operations.
   */
  public static function processFileDeleteFinishCallback($success, $results, $operations) {
    if ($success) {
      $message = \Drupal::translation()->formatPlural(
        count($results),
        'One file deleted.', '@count files deleted.'
      );
    }
    else {
      $message = t('Finished with an error.');
    }
    \Drupal::messenger()->addMessage($message);
  }

}
