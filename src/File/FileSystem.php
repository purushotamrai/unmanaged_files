<?php

namespace Drupal\unmanaged_files\File;

use Drupal\Core\File\Exception\NotRegularDirectoryException;
use Drupal\Core\File\FileSystem as CoreFileSystem;

/**
 * Provides helpers to operate on files and stream wrappers.
 */
class FileSystem extends CoreFileSystem {

  /**
   * Internal function to handle directory scanning with recursion.
   *
   * @param string $dir
   *   The base directory or URI to scan, without trailing slash.
   * @param string $mask
   *   The preg_match() regular expression for files to be included.
   * @param array $options
   *   The options as per ::scanDirectory(), Except an additional key
   *   - 'nomask_dir': The preg_match() regular expression for directories to
   *     be excluded.
   * @param int $depth
   *   The current depth of recursion.
   *
   * @return array
   *   An associative array as per ::scanDirectory().
   *
   * @throws \Drupal\Core\File\Exception\NotRegularDirectoryException
   *   If the directory does not exist.
   *
   * @see \Drupal\Core\File\FileSystemInterface::scanDirectory()
   */
  protected function doScanDirectory($dir, $mask, array $options = [], $depth = 0) {
    $files = [];
    // Avoid warnings when opendir does not have the permissions to open a
    // directory.
    if ($handle = @opendir($dir)) {
      while (FALSE !== ($filename = readdir($handle))) {
        // Skip this file if it matches the nomask or starts with a dot.
        if ($filename[0] != '.' && !(preg_match($options['nomask'], $filename))) {
          if (substr($dir, -1) == '/') {
            $uri = "$dir$filename";
          }
          else {
            $uri = "$dir/$filename";
          }
          if ($options['recurse'] && is_dir($uri)) {
            // Give priority to files in this folder by merging them in after
            // any subdirectory files.
            if (!empty($options['nomask_dir'])) {
              if (!preg_match($options['nomask_dir'], $uri)) {
                $files = array_merge($this->doScanDirectory($uri, $mask, $options, $depth + 1), $files);
              }
            }
            else {
              $files = array_merge($this->doScanDirectory($uri, $mask, $options, $depth + 1), $files);
            }
          }
          elseif ($depth >= $options['min_depth'] && preg_match($mask, $filename)) {
            // Always use this match over anything already set in $files with
            // the same $options['key'].
            $file = new \stdClass();
            $file->uri = $uri;
            $file->filename = $filename;
            $file->name = pathinfo($filename, PATHINFO_FILENAME);
            $key = $options['key'];
            $files[$file->$key] = $file;
            if ($options['callback']) {
              $options['callback']($uri);
            }
          }
        }
      }
      closedir($handle);
    }
    else {
      $this->logger->error('@dir can not be opened', ['@dir' => $dir]);
    }

    return $files;
  }

  /**
   * Fetch Directory list.
   *
   * @param string $dir
   *   The base directory or URI to scan, without trailing slash.
   * @param array $options
   *   - 'nomask_dir' : The preg_match() regular expression for directories to
   *     be excluded.
   *   - 'recurse' : Scan recursively.
   *
   * @return array
   *   List of directories.
   */
  public function getDirectoryList($dir, $options) {
    // Merge in defaults.
    $options += [
      'recurse' => TRUE,
    ];

    $dir = $this->streamWrapperManager->normalizeUri($dir);
    if (!is_dir($dir)) {
      throw new NotRegularDirectoryException("$dir is not a directory.");
    }
    // Allow directories specified in settings.php to be ignored. You can use
    // this to not check for files in common special-purpose directories. For
    // example, node_modules and bower_components. Ignoring irrelevant
    // directories is a performance boost.
    if (!isset($options['nomask'])) {
      $ignore_directories = $this->settings->get('file_scan_ignore_directories', []);
      array_walk($ignore_directories, function (&$value) {
        $value = preg_quote($value, '/');
      });
      $options['nomask'] = '/^' . implode('|', $ignore_directories) . '$/';
    }
    $directories = [];
    $this->doGetDirectoryList($dir, $options, $directories);
    return $directories;
  }

  /**
   * Fetch Directory list.
   *
   * @param string $dir
   *   The base directory or URI to scan, without trailing slash.
   * @param array $options
   *   - 'nomask_dir' : The preg_match() regular expression for directories to
   *     be excluded.
   *   - 'recurse' : Scan recursively.
   * @param array $directories
   *   Resultant Directory array.
   *
   * @return array
   *   List of directories.
   */
  protected function doGetDirectoryList($dir, $options, &$directories) {
    // Avoid warnings when opendir does not have the permissions to open a
    // directory.
    if ($handle = @opendir($dir)) {
      while (FALSE !== ($filename = readdir($handle))) {
        // Skip this file if it matches the nomask or starts with a dot.
        if ($filename[0] != '.' && !(preg_match($options['nomask'], $filename))) {
          if (substr($dir, -1) == '/') {
            $uri = "$dir$filename";
          }
          else {
            $uri = "$dir/$filename";
          }
          if (is_dir($uri)) {
            $nomask_dir = FALSE;
            if (!empty($options['nomask_dir']) && preg_match($options['nomask_dir'], $uri)) {
              $nomask_dir = TRUE;
            }

            if (!$nomask_dir) {
              $directories[$uri] = [];
              if ($options['recurse']) {
                $this->doGetDirectoryList($uri, $options, $directories[$uri]);
              }
            }
          }
        }
      }
      closedir($handle);
    }
    else {
      $this->logger->error('@dir can not be opened', ['@dir' => $dir]);
    }

    return $directories;
  }

}
