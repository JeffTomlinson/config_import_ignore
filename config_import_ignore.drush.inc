<?php

/**
 * @file
 * Provides Configuration Management commands.
 */

use Drupal\config\StorageReplaceDataWrapper;
use Drush\Log\LogLevel;
use Drupal\Core\Config\ConfigException;
use Drupal\Core\Config\FileStorage;
use Drush\Config\StorageWrapper;
use Drupal\config_import_ignore\Config\ConfigImportIgnoreConfigImporter;
use Drupal\config_import_ignore\Config\ConfigImportIgnoreStorageComparer;

/**
 * Implements hook_drush_command_alter().
 */
function config_import_ignore_drush_command_alter(&$command) {
  // Override config-import to use config-import-ignore.
  if (($command['command'] === 'config-import') && (drush_drupal_major_version() >= 8)) {
    $command['command'] = 'config-import-ignore';
    $command['command-hook'] = 'config-import-ignore';
    $command['primary function'] = FALSE;
    drush_set_command($command);
  }
}

/**
 * Implements hook_drush_command().
 */
function config_import_ignore_drush_command() {
  $items['config-import-ignore'] = array(
    'description' => 'Import config from a config directory respecting configuration import ignore settings.',
    'arguments' => array(
      'label' => "A config directory label (i.e. a key in \$config_directories array in settings.php). Defaults to 'sync'",
    ),
    'options' => array(
      'preview' => array(
        'description' => 'Format for displaying proposed changes. Recognized values: list, diff. Defaults to list.',
        'example-value' => 'list',
      ),
      'source' => array(
        'description' => 'An arbitrary directory that holds the configuration files. An alternative to label argument',
      ),
      'partial' => array(
        'description' => 'Allows for partial config imports from the source directory. Only updates and new configs will be processed with this flag (missing configs will not be deleted).',
      ),
      'skip-modules' => 'A list of modules to ignore during import (e.g. to avoid disabling dev-only modules that are not enabled in the imported configuration).',
    ),
    'core' => array('8+'),
    'examples' => array(
      'drush config-import --skip-modules=devel' => 'Import configuration; do not enable or disable the devel module, regardless of whether or not it appears in the imported list of enabled modules.',
    ),
    'aliases' => array('cimi'),
  );

  return $items;
}

/**
 * Command callback. Import from specified config directory (defaults to sync).
 */
function drush_config_import_ignore($source = NULL) {
  global $config_directories;
  // Determine source directory.
  if ($target = drush_get_option('source')) {
    $source_dir = $target;
  }
  else {
    $choices = drush_map_assoc(array_keys($config_directories));
    unset($choices[CONFIG_ACTIVE_DIRECTORY]);
    if (!isset($source) && count($choices) >= 2) {
      $source = drush_choice($choices, 'Choose a source.');
      if (empty($source)) {
        return drush_user_abort();
      }
    }
    elseif (!isset($source)) {
      $source = CONFIG_SYNC_DIRECTORY;
    }
    $source_dir = config_get_config_directory($source);
  }
  // Determine $source_storage in partial and non-partial cases.
  /** @var \Drupal\Core\Config\StorageInterface $active_storage */
  $active_storage = \Drupal::service('config.storage');
  if (drush_get_option('partial')) {
    $source_storage = new StorageReplaceDataWrapper($active_storage);
    $file_storage = new FileStorage($source_dir);
    foreach ($file_storage->listAll() as $name) {
      $data = $file_storage->read($name);
      $source_storage->replaceData($name, $data);
    }
  }
  else {
    $source_storage = new FileStorage($source_dir);
  }
  // If our configuration storage is being filtered, then attach all filters
  // to the source storage object.  We will use the filtered values uniformly
  // for comparison, full imports, and partial imports.
  $storage_filters = drush_config_get_storage_filters();
  if (!empty($storage_filters)) {
    $source_storage = new StorageWrapper($source_storage, $storage_filters);
  }
  /** @var \Drupal\Core\Config\ConfigManagerInterface $config_manager */
  $config_manager = \Drupal::service('config.manager');
  $theme_handler = \Drupal::service('theme_handler');
  $storage_comparer = new ConfigImportIgnoreStorageComparer($source_storage, $active_storage, $config_manager, $theme_handler);
  if (!$storage_comparer->createChangelist()->hasChanges()) {
    return drush_log(dt('There are no changes to import.'), LogLevel::OK);
  }
  if (drush_get_option('preview', 'list') == 'list') {
    $change_list = array();
    foreach ($storage_comparer->getAllCollectionNames() as $collection) {
      $change_list[$collection] = $storage_comparer->getChangelist(NULL, $collection);
    }
    _drush_print_config_changes_table($change_list);
  }
  else {
    // Copy active storage to the temporary directory.
    $temp_dir = drush_tempdir();
    $temp_storage = new FileStorage($temp_dir);
    $source_dir_storage = new FileStorage($source_dir);
    foreach ($source_dir_storage->listAll() as $name) {
      if ($data = $active_storage->read($name)) {
        $temp_storage->write($name, $data);
      }
    }
    drush_shell_exec('diff -x %s -u %s %s', '*.git', $temp_dir, $source_dir);
    $output = drush_shell_exec_output();
    drush_print(implode("\n", $output));
  }
  if (drush_confirm(dt('Import the listed configuration changes?'))) {
    return drush_op('_drush_config_import_ignore', $storage_comparer);
  }
}

/**
 * Copied from submitForm() at /core/modules/config/src/Form/ConfigSync.php.
 *
 * @param ConfigImportIgnoreStorageComparer $storage_comparer
 *   An instance of the StorageComparer ConfigImportIgnoreStorageComparer class.
 *
 * @return mixed
 *   Drush error upon import failure.
 */
function _drush_config_import_ignore(ConfigImportIgnoreStorageComparer $storage_comparer) {
  $config_importer = new ConfigImportIgnoreConfigImporter(
    $storage_comparer,
    \Drupal::service('event_dispatcher'),
    \Drupal::service('config.manager'),
    \Drupal::lock(),
    \Drupal::service('config.typed'),
    \Drupal::moduleHandler(),
    \Drupal::service('module_installer'),
    \Drupal::service('theme_handler'),
    \Drupal::service('string_translation')
  );
  if ($config_importer->alreadyImporting()) {
    drush_log('Another request may be synchronizing configuration already.', LogLevel::WARNING);
  }
  else {
    try {
      $config_importer->import();
      drush_log('The configuration was imported successfully.', LogLevel::SUCCESS);
    }
    catch (ConfigException $e) {
      // Return a negative result for UI purposes. We do not differentiate
      // between an actual synchronization error and a failed lock, because
      // concurrent synchronizations are an edge-case happening only when
      // multiple developers or site builders attempt to do it without
      // coordinating.
      $message = 'The import failed due for the following reasons:' . "\n";
      $message .= implode("\n", $config_importer->getErrors());
      watchdog_exception('config_import', $e);
      return drush_set_error('config_import_fail', $message);
    }
  }
}
