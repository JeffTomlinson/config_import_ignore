<?php

namespace Drupal\config_import_ignore\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines a config entity class with settings for ignoring config imports.
 *
 * @ingroup entity_api
 */
abstract class ConfigImportIgnoreConfigEntity extends ConfigEntityBase {

  /**
   * Configuration import ignore settings.
   *
   * An array of configuration import operations to ignore keyed by operation.
   *
   * @var array
   */
  protected $import_ignore = array(
    'create' => 0,
    'update' => 0,
    'rename' => 0,
    'delete' => 0,
  );

  /**
   * {@inheritdoc}
   */
  public function __construct(array $values, $entity_type) {
    parent::__construct($values, $entity_type);
  }

  /**
   * Sets flags for ignoring this entity during configuration imports.
   *
   * @param bool $ignore
   *   Set TRUE to ignore this entity during configuration imports.
   * @param array $ops
   *   An array of configuration entity import operations to ignore during
   *   import.
   *
   * @return $this
   *   This ConfigEntityBase.
   */
  public function setImportIgnore($ignore = TRUE, array $ops = []) {
    if (empty($ops)) {
      $ops = [
        'create',
        'update',
        'rename',
        'delete',
      ];
    }

    if (!is_bool($ignore)) {
      return $this;
    }

    $import_ignore = $this->getThirdPartySetting('config_import_ignore', 'import_ignore', $this->import_ignore);

    foreach ($ops as $op) {
      $import_ignore[$op] = $ignore;
    }

    $this->setThirdPartySetting('config_import_ignore', 'import_ignore', $import_ignore);
    return $this;
  }

  /**
   * Gets configuration import ignore settings for this entity.
   *
   * @return array
   *   An array of configuration import ignore settings.
   */
  public function getImportIgnore() {
    return $this->getThirdPartySetting('config_import_ignore', 'import_ignore', $this->import_ignore);
  }

}
