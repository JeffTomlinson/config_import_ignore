<?php

namespace Drupal\config_import_ignore\Config;

use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\StorageComparerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\Entity\ImportableEntityStorageInterface;
use Drupal\Core\Entity\EntityStorageException;

/**
 * Defines a configuration importer that allows entities to be ignored.
 *
 * A config importer imports the changes into the configuration system. To
 * determine which changes to import a StorageComparer in used.
 *
 * @see \Drupal\Core\Config\StorageComparerInterface
 *
 * The ConfigImporter has a identifier which is used to construct event names.
 * The events fired during an import are:
 * - ConfigEvents::IMPORT_VALIDATE: Events listening can throw a
 *   \Drupal\Core\Config\ConfigImporterException to prevent an import from
 *   occurring.
 *   @see \Drupal\Core\EventSubscriber\ConfigImportSubscriber
 * - ConfigEvents::IMPORT: Events listening can react to a successful import.
 *   @see \Drupal\Core\EventSubscriber\ConfigSnapshotSubscriber
 *
 * @see \Drupal\Core\Config\ConfigImporterEvent
 */
class ConfigImportIgnoreConfigImporter extends ConfigImporter {

  /**
   * {@inheritdoc}
   */
  public function __construct(StorageComparerInterface $storage_comparer, EventDispatcherInterface $event_dispatcher, ConfigManagerInterface $config_manager, LockBackendInterface $lock, TypedConfigManagerInterface $typed_config, ModuleHandlerInterface $module_handler, ModuleInstallerInterface $module_installer, ThemeHandlerInterface $theme_handler, TranslationInterface $string_translation) {
    parent::__construct($storage_comparer, $event_dispatcher, $config_manager, $lock, $typed_config, $module_handler, $module_installer, $theme_handler, $string_translation);
  }

  /**
   * {@inheritdoc}
   */
  protected function importInvokeOwner($collection, $op, $name) {
    // Renames are handled separately.
    if ($op == 'rename') {
      return $this->importInvokeRename($collection, $name);
    }
    // Validate the configuration object name before importing it.
    // Config::validateName($name);
    if ($entity_type = $this->configManager->getEntityTypeIdByName($name)) {
      $old_config = new Config($name, $this->storageComparer->getTargetStorage($collection), $this->eventDispatcher, $this->typedConfigManager);
      if ($old_data = $this->storageComparer->getTargetStorage($collection)->read($name)) {
        $old_config->initWithData($old_data);
      }

      $data = $this->storageComparer->getSourceStorage($collection)->read($name);
      $new_config = new Config($name, $this->storageComparer->getTargetStorage($collection), $this->eventDispatcher, $this->typedConfigManager);
      if ($data !== FALSE) {
        $new_config->setData($data);
      }

      $method = 'import' . ucfirst($op);
      $entity_storage = $this->configManager->getEntityManager()->getStorage($entity_type);
      // Call to the configuration entity's storage to handle the configuration
      // change.
      if (!($entity_storage instanceof ImportableEntityStorageInterface)) {
        throw new EntityStorageException(sprintf('The entity storage "%s" for the "%s" entity type does not support imports', get_class($entity_storage), $entity_type));
      }

      // Invoke the import method if the config import operation is not set to
      // be ignored for the entity and there are no dependency exceptions.
      if (!$this->storageComparer->ignoreImport($collection, $op, $name)) {
        $entity_storage->$method($name, $new_config, $old_config);
      }

      $this->setProcessedConfiguration($collection, $op, $name);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function importInvokeRename($collection, $rename_name) {
    $names = $this->storageComparer->extractRenameNames($rename_name);
    $entity_type_id = $this->configManager->getEntityTypeIdByName($names['old_name']);
    $old_config = new Config($names['old_name'], $this->storageComparer->getTargetStorage($collection), $this->eventDispatcher, $this->typedConfigManager);
    if ($old_data = $this->storageComparer->getTargetStorage($collection)->read($names['old_name'])) {
      $old_config->initWithData($old_data);
    }

    $data = $this->storageComparer->getSourceStorage($collection)->read($names['new_name']);
    $new_config = new Config($names['new_name'], $this->storageComparer->getTargetStorage($collection), $this->eventDispatcher, $this->typedConfigManager);
    if ($data !== FALSE) {
      $new_config->setData($data);
    }

    $entity_storage = $this->configManager->getEntityManager()->getStorage($entity_type_id);
    // Call to the configuration entity's storage to handle the configuration
    // change.
    if (!($entity_storage instanceof ImportableEntityStorageInterface)) {
      throw new EntityStorageException(sprintf("The entity storage '%s' for the '%s' entity type does not support imports", get_class($entity_storage), $entity_type_id));
    }

    // Invoke the import method if the config import operation is not set to
    // be ignored for the entity and there are no dependency exceptions.
    if (!$this->storageComparer->ignoreImport($collection, 'rename', $rename_name)) {
      $entity_storage->importRename($names['old_name'], $new_config, $old_config);
    }

    $this->setProcessedConfiguration($collection, 'rename', $rename_name);
    return TRUE;
  }

  /**
   * Processes ignored configurations as a batch operation.
   *
   * Even if a config entity is ignored during config import, we still want to
   * ensure it's ignore settings get synced.
   *
   * @param array $context
   *   The batch context.
   */
  protected function processIgnored(&$context) {
    if (!isset($context['sandbox']['progress'])) {
      $ignoredToProcess = [];

      foreach ($this->storageComparer->getAllCollectionNames() as $collection) {
        $this->storageComparer->reset();
        $ignored = $this->storageComparer->getChangelist('ignore', $collection);

        if (!empty($ignored)) {
          foreach ($ignored as $name) {
            $ignoredToProcess[$collection . '-' . $name] = [
              'collection' => $collection,
              'name' => $name,
            ];
          }
        }
      }

      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = count($ignoredToProcess);
      $context['sandbox']['ignored'] = array_values($ignoredToProcess);
    }

    if ($context['sandbox']['progress'] < $context['sandbox']['max']) {
      $data = $context['sandbox']['ignored'][$context['sandbox']['progress']];
      $collection = $data['collection'];
      $name = $data['name'];
      $sourceData = $this->storageComparer->getSourceStorage($collection)->read($name);

      // Make sure we have both source and target configurations.
      if ($sourceData !== FALSE) {
        $targetData = $this->storageComparer->getTargetStorage($collection)->read($name);

        if ($targetData !== FALSE) {
          // Update target ignore settings with the source ignore settings and
          // save the target config.
          $targetData['third_party_settings']['config_import_ignore']['import_ignore'] = $sourceData['third_party_settings']['config_import_ignore']['import_ignore'];
          $config = new Config($name, $this->storageComparer->getTargetStorage($collection), $this->eventDispatcher, $this->typedConfigManager);
          $config->setData($targetData);
          $config->save();
        }
      }

      $context['sandbox']['progress']++;
    }

    if ($context['sandbox']['progress'] < $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
    else {
      $context['finished'] = 1;
    }
  }

}
