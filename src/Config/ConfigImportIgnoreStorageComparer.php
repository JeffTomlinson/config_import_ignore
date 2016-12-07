<?php

namespace Drupal\config_import_ignore\Config;

use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;

/**
 * Defines a config storage comparer.
 */
class ConfigImportIgnoreStorageComparer extends StorageComparer {

  /**
   * Core extensions.
   *
   * @var array
   */
  protected static $coreExtensions;

  /**
   * Theme data.
   *
   * @var array
   */
  protected static $themeData;

  /**
   * Module data.
   *
   * @var array
   */
  protected static $moduleData;

  /**
   * Existing dependencies.
   *
   * @var array
   */
  protected static $existingConfiguration;

  /**
   * Existing dependencies.
   *
   * @var array
   */
  protected static $allConfigDependencies;

  /**
   * Configuration data for an import operation.
   *
   * @var array
   */
  protected static $importConfigData = [];

  /**
   * The theme handler.
   *
   * @var \Drupal\Core\Extension\ThemeHandlerInterface
   */
  protected $themeHandler;

  /**
   * {@inheritdoc}
   */
  public function __construct(StorageInterface $source_storage, StorageInterface $target_storage, ConfigManagerInterface $config_manager, ThemeHandlerInterface $theme_handler) {
    parent::__construct($source_storage, $target_storage, $config_manager);
    $this->themeHandler = $theme_handler;
  }

  /**
   * {@inheritdoc}
   */
  public function getEmptyChangelist() {
    $emptyChangelist = parent::getEmptyChangelist();
    $emptyChangelist['ignore'] = [];
    return $emptyChangelist;
  }

  /**
   * {@inheritdoc}
   */
  public function createChangelist() {
    parent::createChangeList();

    foreach ($this->getAllCollectionNames() as $collection) {
      // Remove ignored changes from the list.
      $this->removeChangeListIgnored($collection);
    }

    return $this;
  }

  /**
   * Removes ignored changes from the change list.
   *
   * @param string $collection
   *   The storage collection to check for ignored changes.
   */
  protected function removeChangeListIgnored($collection) {
    $ignores = [];
    $ignored = $this->getChangeListIgnored($collection, TRUE);

    foreach ($ignored as $op => $names) {
      foreach ($names as $name) {
        $this->removeFromChangelist($collection, $op, $name);
        $ignores[$name] = $name;
      }
    }

    $this->addChangeList($collection, 'ignore', $ignores);
  }

  /**
   * Gets ignored configurations and optionally renders messages for exceptions.
   *
   * @param string $collection
   *   The storage collection to check for ignored changes.
   * @param bool $renderExceptions
   *   Whether to render config import dependency exceptions as warning
   *   messages.
   *
   * @return array
   *   An array of ignored configuration names keyed by import operation.
   */
  public function getChangeListIgnored($collection, $renderExceptions = FALSE) {
    $changeList = $this->getChangeList();
    $ignored = [];
    $exceptions = [];

    // Iterate change list operations.
    foreach ($changeList as $op => $names) {
      if (!empty($names)) {
        // Iterate configuration changes for the operation.
        foreach ($names as $name) {
          // Make sure this the entity's import ignore setting
          // for this operation is set to ignore.
          if ($this->opIsIgnored($collection, $op, $name)) {
            // If there are any dependency exceptions, make note of the
            // configuration and continue without altering the change list.
            if ($this->ignoredOpHasExceptions($collection, $op, $name)) {
              $exceptions[$op][] = $name;
              continue;
            }

            $ignored[$op][] = $name;
          }
        }
      }
    }

    if ($renderExceptions && !empty($exceptions)) {
      $this->renderExceptionMessages($exceptions);
    }

    return $ignored;
  }

  /**
   * Determine if an import operation should be ignored for a configuration.
   *
   * @param string $collection
   *   The storage collection to operate on.
   * @param string $op
   *   The config import operation. Either delete, create, rename or update.
   * @param string $name
   *   The name of the configuration to check.
   *
   * @return bool
   *   Returns TRUE if the operation should be ignored, FALSE if not.
   */
  public function ignoreImport($collection, $op, $name) {
    // Make sure this the entity's import ignore setting
    // for this operation is set to ignore and there are no dependency
    // exceptions.
    if ($this->opIsIgnored($collection, $op, $name)
      && !$this->ignoredOpHasExceptions($collection, $op, $name)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Checks if an import operation is set to be ignored for config entity.
   *
   * @param string $collection
   *   The storage collection to operate on.
   * @param string $op
   *   The config import operation. Either delete, create, rename or update.
   * @param string $name
   *   The name of the configuration to check.
   *
   * @return bool
   *   Returns TRUE if the operation should be ignored, FALSE if not.
   */
  protected function opIsIgnored($collection, $op, $name) {
    $data = $this->getImportConfigData($collection, $op, $name);

    // Make sure we should even be testing this op for this config entity.
    if (isset($data['third_party_settings']['config_import_ignore']['import_ignore'][$op]) && $data['third_party_settings']['config_import_ignore']['import_ignore'][$op] === 1) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Validates if a config entity can be ignored during import for a given op.
   *
   * @param string $collection
   *   The storage collection to operate on.
   * @param string $op
   *   The config import operation. Either delete, create, rename or update.
   * @param string $name
   *   The name of the configuration to validate.
   *
   * @return bool
   *   Returns TRUE if the config entity can be ignored, FALSE if not.
   */
  public function ignoredOpHasExceptions($collection, $op, $name) {
    // Make sure this is a config entity and the entity's import ignore setting
    // for this operation is set to ignore.
    if ($this->isConfigEntity($collection, $op, $name) && $this->opIsIgnored($collection, $op, $name)) {
      // If the create op is set to be ignored but other config is dependent
      // upon it or if rename op is set to be ignored but other config is
      // dependent upon the renamed config, create it anyway.
      if (in_array($op, ['create', 'rename'])
        && $this->hasDependentConfig($collection, $op, $name)) {
        return TRUE;
      }

      // If update op is set to be ignored but it will have unmet dependencies,
      // update it anyway.
      if ($op === 'update' && $this->willHaveUnmetDependencies($collection, $name)) {
        return TRUE;
      }

      // If the delete op is set to be ignored but it's owner will no longer be
      // enabled, delete it anyway.
      if ($op === 'delete' && $this->willBeOrphaned($name)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Validates if there are other configurations dependent upon this config.
   *
   * Runs on create and rename operations.
   *
   * @param string $name
   *   The name of the configuration to validate.
   *
   * @return bool
   *   Returns TRUE if it has dependent configurations, FALSE if not.
   */
  public function hasDependentConfig($collection, $op, $name) {
    // Get all dependencies from all configurations.
    $allConfigDependencies = $this->getAllConfigDependencies($collection, $op);

    // If there is a dependency on this config then we should go ahead and
    // create it.
    if (isset($allConfigDependencies[$name])) {
      // TODO: Is the config that has the dependency getting deleted? If so
      // maybe this is okay?
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Validates if there will be unmet dependencies for the config after import.
   *
   * Runs on update operations.
   *
   * @param string $collection
   *   The storage collection to operate on.
   * @param string $name
   *   The name of the configuration to validate.
   *
   * @return bool
   *   Returns TRUE if there will be unmet dependencies, FALSE if not.
   */
  public function willHaveUnmetDependencies($collection, $name) {
    $sourceData = $this->getSourceStorage($collection)->read($name);
    $targetData = $this->getTargetStorage($collection)->read($name);
    $coreExtensions = $this->getCoreExtensions();

    // If a module or theme dependency is being removed and the module or theme
    // is being disabled, we should perform the update.
    foreach (['module', 'theme'] as $type) {
      if (isset($targetData['dependencies'][$type]) && isset($sourceData['dependencies'][$type])) {
        $dependenciesRemoved = array_diff($targetData['dependencies'][$type], $sourceData['dependencies'][$type]);

        if (!empty($dependenciesRemoved)) {
          foreach ($dependenciesRemoved as $dependency) {
            if (!in_array($dependency, $coreExtensions[$type])) {
              return TRUE;
            }
          }
        }
      }
    }

    // If a config dependency is being removed that will no longer be present
    // after import, then we should perform the update.
    if (isset($targetData['dependencies']['config']) && isset($sourceData['dependencies']['config'])) {
      $config = $this->getSourceStorage()->listAll();
      $configDependenciesRemoved = array_diff($targetData['dependencies']['config'], $sourceData['dependencies']['config']);

      if (!empty($configDependenciesRemoved)) {
        foreach ($configDependenciesRemoved as $dependency) {
          if (!in_array($dependency, $config)) {
            return TRUE;
          }
        }
      }
    }

    return FALSE;
  }

  /**
   * Validates if this configuration will be orphaned after import.
   *
   * Runs on delete operations.
   *
   * @param string $name
   *   The name of the configuration to validate.
   *
   * @return bool
   *   Returns TRUE if it will be orphaned by its owner, FALSE if not.
   */
  public function willBeOrphaned($name) {
    $coreExtensions = $this->getCoreExtensions();
    $themeData = $this->getThemeData();
    $moduleData = $this->getModuleData();
    list($owner,) = explode('.', $name, 2);

    if ($owner !== 'core') {
      if ((!isset($coreExtensions['module'][$owner]) && isset($moduleData[$owner]))
        || (!isset($coreExtensions['theme'][$owner]) && isset($themeData[$owner]))
        || (!isset($coreExtensions['module'][$owner]) && !isset($coreExtensions['theme'][$owner]))) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks if the configuration is a configuration entity.
   *
   * @param string $collection
   *   The storage collection to operate on.
   * @param string $op
   *   The config import operation. Either delete, create, rename or update.
   * @param string $name
   *   The name of the configuration to check.
   *
   * @return bool
   *   Returns TRUE if the configuration is a config entity, FALSE if not.
   */
  protected function isConfigEntity($collection, $op, $name) {
    $data = $this->getImportConfigData($collection, $op, $name);

    // Configuration entities can be identified by having 'dependencies' and
    // 'uuid' keys.
    if (isset($data['dependencies']) && isset($data['uuid'])) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Configures import ignore exceptions for rendering as messages.
   *
   * @param array $exceptions
   *   An array of configuration import ignore exceptions.
   *
   * @see ConfigImportIgnoreStorageComparer::changeListIgnore()
   */
  protected function renderExceptionMessages($exceptions) {
    // Render dependency exception messages.
    $this->renderExceptionMessage($exceptions, 'create', t('These new items in your source configuration are set to be ignored but other configurations are dependent upon them. They will be created.'));
    $this->renderExceptionMessage($exceptions, 'rename', t('These renamed items in your source configuration are set to be ignored but other configurations are dependent upon their new names. They will be renamed.'));
    $this->renderExceptionMessage($exceptions, 'update', t('These updated items in your source configuration are set to be ignored but they would have unmet dependencies after import. They will be updated.'));
    $this->renderExceptionMessage($exceptions, 'delete', t('These deleted items in your source configuration are set to be ignored but their owners will no longer be enabled after import. They will be deleted.'));
  }

  /**
   * Renders configuration import ignore exception messages.
   *
   * @param array $exceptions
   *   An array of configuration names that have exceptions.
   * @param string $op
   *   The config import operation. Either delete, create, rename or update.
   * @param string $message
   *   The rendered exception message.
   */
  protected function renderExceptionMessage($exceptions, $op, $message) {
    if (count($exceptions[$op])) {
      sort($exceptions[$op]);
      $message = [
        [
          '#markup' => $message,
        ],
        [
          '#theme' => 'item_list',
          '#items' => $exceptions[$op],
        ],
      ];
      drupal_set_message(\Drupal::service('renderer')
        ->renderPlain($message), 'warning');
    }
  }

  /**
   * Gets core extensions.
   *
   * @return array
   *   An array of core extension names.
   */
  protected function getCoreExtensions() {
    if (!isset($this->coreExtensions)) {
      $this->coreExtensions = $this->getSourceStorage()->read('core.extension');
    }

    return $this->coreExtensions;
  }

  /**
   * Gets theme data.
   *
   * @return array
   *   An array of theme data.
   */
  protected function getThemeData() {
    if (!isset($this->themeData)) {
      $this->themeData = $this->themeHandler->rebuildThemeData();
    }

    return $this->themeData;
  }

  /**
   * Gets module data.
   *
   * @return array
   *   An array of module data.
   */
  protected function getModuleData() {
    if (!isset($this->moduleData)) {
      $this->moduleData = system_rebuild_module_data();
    }

    return $this->moduleData;
  }

  /**
   * Returns configuration data for a specific config operation and name.
   *
   * @param string $collection
   *   The storage collection to operate on.
   * @param string $op
   *   The config import operation. Either delete, create, rename or update.
   * @param string $name
   *   The name of the configuration from which to get data.
   *
   * @return array
   *   An array of configuration data.
   */
  protected function getImportConfigData($collection, $op, $name) {
    if (!isset(self::$importConfigData[$op][$name])) {
      switch ($op) {
        // If this is a delete operation, get data from the target storage.
        case 'delete':
          $data = $this->getTargetStorage($collection)->read($name);
          break;

        // Otherwise get it from the source storage.
        default:
          $data = $this->getSourceStorage($collection)->read($name);
      }

      self::$importConfigData[$op][$name] = $data;
    }

    return self::$importConfigData[$op][$name];
  }

  /**
   * Gets existing dependencies.
   *
   * @return array
   *   An array of existing dependencies.
   */
  protected function getExistingConfiguration() {
    if (!isset($this->existingConfiguration)) {
      $coreExtensions = $this->getCoreExtensions();
      $this->existingConfiguration = [
        'config' => $this->getSourceStorage()->listAll(),
        'module' => array_keys($coreExtensions['module']),
        'theme' => array_keys($coreExtensions['theme']),
      ];
    }

    return $this->existingConfiguration;
  }

  /**
   * Gets an array of all dependencies that exist in all configurations.
   *
   * @param string $collection
   *   The storage collection to operate on.
   * @param string $op
   *   The config import operation. Either delete, create, rename or update.
   *
   * @return array
   *   An array of all configuration dependencies with names as keys.
   */
  protected function getAllConfigDependencies($collection, $op) {
    if (!isset($this->allConfigDependencies)) {
      $dependencies = [];

      // Iterate all configurations.
      foreach ($this->getSourceStorage()->listAll() as $name) {
        $data = $this->getImportConfigData($collection, $op, $name);
        // Get config dependencies from the configuration and add it to our
        // dependencies array.
        $config = isset($data['dependencies']['config']) ? $data['dependencies']['config'] : [];

        if (is_array($config) && !empty($config)) {
          foreach ($config as $dependency) {
            $dependencies[] = $dependency;
          }
        }
      }

      // Get unique dependencies.
      $this->allConfigDependencies = array_flip($dependencies);
    }

    return $this->allConfigDependencies;
  }

}
