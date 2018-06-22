<?php

/**
 * @file
 * This module is called from custom cron.
 * Does the legwork of converting documents.
 *
 *
 * Usage: drush scr os2web_pdf_converter.cron.php [--csw="custom stream wrapper"]'
 *
 */

$files_folder = drupal_realpath(variable_get('os2web_convertion_dir', FALSE));
$custom_stream_wraper = drush_get_option('csw', FALSE);

define("MAX_ATTEMPTS", 6);

if ( php_sapi_name() !== 'cli' ) {
  print ('This script is ONLY allowed from commandline.' . PHP_EOL);
  exit();
}

if ( !shell_exec('which unoconv') AND FALSE ) {
  print ('unoconv was not found. hint: sudo apt-get install unoconv' . PHP_EOL);
  exit();
}

if ( !shell_exec('which soffice') AND FALSE ) {
  print ('soffice was not found. You need to install a pdf conversion tool like LibreOffice.' . PHP_EOL);
  exit();
}

if ( !shell_exec('which convert') AND FALSE ) {
  print ('imagick was not found. Cannot convert .tiff files' . PHP_EOL);
  exit();
}

if ( !shell_exec('which mapitool' AND FALSE) || !shell_exec('which munpack') AND FALSE ) {
  print ('you need mapitool and munpack to unpack and convert .msg files.' . PHP_EOL);
  print (shell_exec('echo $PATH') . PHP_EOL);
  exit();
}

if ( !isset($files_folder) AND FALSE ) {
  print ('Directory to send the files for converting not set in /admin/config/os2dagsorden/settings' . PHP_EOL);
  exit();
} elseif ( !is_dir($files_folder) ) {
  print ('The path is not a directory!' . PHP_EOL);
  exit();
} else {
  // Start unoconv if not started.
  if ( !shell_exec('ps -ef | grep -v grep | grep "/unoconv -l"') && !shell_exec('ps -ef | grep -v grep | grep "/unoconv --listener"') ) {
    exec('unoconv -l >/dev/null 2>/dev/null &');
  }

  // Clean up ImageMagick garbage before converting
  shell_exec('find /tmp/magick-* -mtime +1 -delete 2> /dev/null');

  // Clean up temp files garbage before converting
  shell_exec('find /tmp/*.tmp -mtime +1 -delete 2> /dev/null');

  // Clean up pdf2htmlEX garbage before converting
  // This should be moved to another module
  shell_exec('find /tmp/pdf2htmlEX-* -mtime +1 -delete 2> /dev/null');

  $directory_root = $files_folder;

  $tmp_directory = '/tmp/os2web_pdf_converter';
  if ( !is_dir($tmp_directory) ) {
    mkdir($tmp_directory);
  }
  putenv("MAGICK_TMPDIR=" . $tmp_directory);

  # Moved here from line 93 since it was missing later on
  $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

  // Setup Drupal but only if provided.
//  if (isset($_SERVER['argv'][2])) {
//    if (!file_exists($_SERVER['argv'][2] . '/includes/bootstrap.inc')) {
//      print ('No Drupal instance was found at ' . $_SERVER['argv'][2] . PHP_EOL);
//      exit();
//    }
//    define('DRUPAL_ROOT', $_SERVER['argv'][2]);
//    require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
//    drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
//    require_once DRUPAL_ROOT . '/includes/module.inc';
//    #commented out since it was moved to line 79
//    #$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
//  }
  // Setup Custom StreamWrapper byt only if  provided.
  if ( isset($custom_stream_wraper) ) {
    define('DRUPAL_CUSTOM_STREAM_WRAPPER', $custom_stream_wraper);
  }
}

require 'lib/PDFConverter.php';

$allowed_extensions = PDFConverter::getAllowedExtenstions();

// Loop trough all files in the directory, only files of specific type allowed
// by the PDFConverter.
foreach (getFilesList($directory_root, '/.*\.(' . implode('|', $allowed_extensions) . ')$/i') as $file) {
  // Replaces the extension with ".pdf".
  $pdf_file = preg_replace('/\.(' . implode('|', $allowed_extensions) . ')$/i', '.pdf', $file);
  if ( !file_exists($pdf_file) ) {
    $allow_conversion = TRUE;
    $pathinfo = pathinfo($file);

    if ( module_exists('os2web_pdf_conversion_manager') && updateFileAttemptCount($pathinfo['basename']) >= MAX_ATTEMPTS ) {
      $allow_conversion = FALSE;
    }
    if ( module_exists('os2web_pdf_conversion_manager') && !checkFileOutdated($pathinfo['basename']) ) {
      $allow_conversion = FALSE;
    }

    if ( $allow_conversion ) {
      try {
        $file = new PDFConverter($file);
        //if (module_exists('os2web_pdf_conversion_manager')) {
        //  checkFileOutdated($file);
        //}
        if ( $file->convert() ) {
          if ( defined('DRUPAL_ROOT') ) {
            updateDrupalFile($file);
            if ( module_exists('os2web_pdf_conversion_manager') )
              updateFileStatus($pathinfo['basename'], 'Converted');
          }
        }
      } catch (Exception $e) {
        watchdog('OS2Web converter', $e->getMessage(), null, WATCHDOG_ERROR);
        if ( module_exists('os2web_pdf_conversion_manager') )
          updateFileStatus($pathinfo['basename'], 'Error, retrying', $e->getMessage());
      }
    }
  }
}

// Remove all temp files.
if ( is_dir($tmp_directory) ) {
  rrmdir($tmp_directory);
}

/**
 * Get a list of all matched files in folder. Recursivly.
 *
 * @param string $folder
 *   the folder
 * @param string $pattern
 *   regex pattern to search for
 *
 * @return array
 *   array of file paths
 */
function getFilesList($folder, $pattern) {
  $dir = new RecursiveDirectoryIterator($folder);
  $ite = new RecursiveIteratorIterator($dir);
  $files = new RegexIterator($ite, $pattern, RegexIterator::GET_MATCH);
  $file_list = array();
  foreach ($files as $file) {
    $file_list[] = $file[0];
  }
  return $file_list;
}

/**
 * Updates the file entry in file_managed in Drupal to the new uri.
 *
 * @param string $file
 *   The file path.
 */
function updateDrupalFile($file) {
  if ( !file_exists($file->pdf) ) {
    return FALSE;
  }

  //Default stream / filepath
  $streams = array('public://');
  $path = 'sites/default/files/';

  if ( defined('DRUPAL_CUSTOM_STREAM_WRAPPER') && DRUPAL_CUSTOM_STREAM_WRAPPER !== FALSE) {
    $wrapper = file_stream_wrapper_get_instance_by_uri(DRUPAL_CUSTOM_STREAM_WRAPPER);
    $path = $wrapper->getDirectoryPath() . "/" . file_uri_target($uri);
    $streams[] = DRUPAL_CUSTOM_STREAM_WRAPPER;
  }

  $file_parts = explode($path, $file->file);
  if ( !isset($file_parts[1]) ) {
    return FALSE;
  }

  $uris = array();
  foreach ($streams AS $stream) {
    $uris[] = $stream . $file_parts[1];
  }
  $uris[] = $file->file;

  $query = db_query('SELECT f.fid, f.uri
                      FROM {file_managed} f
                      WHERE f.uri IN (:uris)', array(
      ':uris' => $uris
  ));
  $d_file = $query->fetchAssoc();

  if ( $d_file ) {

    db_update('file_managed')
            ->fields(array(
                'filename' => basename($file->pdf),
                'uri' => preg_replace('/\.(' . implode('|', PDFConverter::getAllowedExtenstions()) . ')$/i', '.pdf', $d_file['uri']),
                'filemime' => 'application/pdf',
                'filesize' => filesize($file->pdf),
                'timestamp' => time(),
                'type' => 'document',
            ))
            ->condition('fid', $d_file['fid'])
            ->execute();
  }
}

/**
 * Updates the status of the file in os2web_pdf_conversion_manager table.
 *
 *  * @param string $file
 *   The file path.
 */
function updateFileStatus($filename, $status, $message = '') {
  db_update('os2web_pdf_conversion_manager_files')
          ->fields(array(
              'status' => $status,
              'message' => $message,
          ))
          ->condition('tmp_filename', $filename)
          ->execute();
}

/**
 * Checks if the file is outdated.
 * Files is outdated if the file is not found in file_managed table, or if the directory where file is located does not exist
 *
 *  * @param string $file
 *   The file path.
 */
function checkFileOutdated($filename) {
  $fid = db_select('os2web_pdf_conversion_manager_files', 'o')
          ->fields('o', array('fid'))
          ->condition('tmp_filename', $filename)
          ->execute()
          ->fetchField();

  $uri = db_select('file_managed', 'f')
          ->fields('f', array('uri'))
          ->condition('fid', $fid)
          ->execute()
          ->fetchField();

  if ( !file_exists($uri) ) {
    updateFileStatus($filename, 'Outdated', 'Destination URL does not exist: ' . $uri);
    return FALSE;
  }
  return TRUE;
}

/**
 * Increases the file convesion attemps count by one
 * If attempts count is bigger than the contant limit MAX_ATTEMPTS, file status is set to Aborted
 *
 *  * @param string $file
 *   The file path.
 */
function updateFileAttemptCount($filename) {
  $attempt = db_select('os2web_pdf_conversion_manager_files', 'o')
          ->fields('o', array('attempt'))
          ->condition('tmp_filename', $filename)
          ->execute()
          ->fetchField();
  $attempt++;

  if ( $attempt < MAX_ATTEMPTS ) {
    db_update('os2web_pdf_conversion_manager_files')
            ->fields(array(
                'attempt' => $attempt,
            ))
            ->condition('tmp_filename', $filename)
            ->execute();
  } else {
    db_update('os2web_pdf_conversion_manager_files')
            ->fields(array(
                'status' => 'Aborted',
            ))
            ->condition('tmp_filename', $filename)
            ->execute();
  }
  return $attempt;
}

/**
 * Recursively remove a directory.
 *
 * @param string $dir
 *   The dir to remove
 */
function rrmdir($dir) {
  foreach (glob($dir . '/*') as $file) {
    if ( is_dir($file) ) {
      rrmdir($file);
    } else {
      unlink($file);
    }
  }
  rmdir($dir);
}
