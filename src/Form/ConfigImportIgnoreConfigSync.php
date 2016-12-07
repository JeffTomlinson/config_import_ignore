<?php

namespace Drupal\config_import_ignore\Form;

use Drupal\config\Form\ConfigSync;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\config_import_ignore\Config\ConfigImportIgnoreConfigImporter;
use Drupal\config_import_ignore\Config\ConfigImportIgnoreStorageComparer;

/**
 * Construct the storage changes in a configuration synchronization form.
 *
 * We're extending ConfigSync here for the sole purpose of using our extensions
 * of StorageComparer and ConfigImporter. Outside of that the methods are
 * identical to those in core.
 */
class ConfigImportIgnoreConfigSync extends ConfigSync {

  /**
   * {@inheritdoc}
   */
  public function __construct(StorageInterface $sync_storage, StorageInterface $active_storage, StorageInterface $snapshot_storage, LockBackendInterface $lock, EventDispatcherInterface $event_dispatcher, ConfigManagerInterface $config_manager, TypedConfigManagerInterface $typed_config, ModuleHandlerInterface $module_handler, ModuleInstallerInterface $module_installer, ThemeHandlerInterface $theme_handler, RendererInterface $renderer) {
    parent::__construct($sync_storage, $active_storage, $snapshot_storage, $lock, $event_dispatcher, $config_manager, $typed_config, $module_handler, $module_installer, $theme_handler, $renderer);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['actions'] = array('#type' => 'actions');
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Import all'),
    );
    $source_list = $this->syncStorage->listAll();
    $storage_comparer = new ConfigImportIgnoreStorageComparer($this->syncStorage, $this->activeStorage, $this->configManager, $this->themeHandler);
    if (empty($source_list) || !$storage_comparer->createChangelist()->hasChanges()) {
      $form['no_changes'] = array(
        '#type' => 'table',
        '#header' => array($this->t('Name'), $this->t('Operations')),
        '#rows' => array(),
        '#empty' => $this->t('There are no configuration changes to import.'),
      );
      $form['actions']['#access'] = FALSE;
      return $form;
    }
    elseif (!$storage_comparer->validateSiteUuid()) {
      drupal_set_message($this->t('The staged configuration cannot be imported, because it originates from a different site than this site. You can only synchronize configuration between cloned instances of this site.'), 'error');
      $form['actions']['#access'] = FALSE;
      return $form;
    }
    // A list of changes will be displayed, so check if the user should be
    // warned of potential losses to configuration.
    if ($this->snapshotStorage->exists('core.extension')) {
      $snapshot_comparer = new ConfigImportIgnoreStorageComparer($this->activeStorage, $this->snapshotStorage, $this->configManager, $this->themeHandler);
      if (!$form_state->getUserInput() && $snapshot_comparer->createChangelist()->hasChanges()) {
        $change_list = array();
        foreach ($snapshot_comparer->getAllCollectionNames() as $collection) {
          foreach ($snapshot_comparer->getChangelist(NULL, $collection) as $config_names) {
            if (empty($config_names)) {
              continue;
            }
            foreach ($config_names as $config_name) {
              $change_list[] = $config_name;
            }
          }
        }
        sort($change_list);
        $message = [
          [
            '#markup' => $this->t('The following items in your active configuration have changes since the last import that may be lost on the next import.'),
          ],
          [
            '#theme' => 'item_list',
            '#items' => $change_list,
          ],
        ];
        drupal_set_message($this->renderer->renderPlain($message), 'warning');
      }
    }

    // Store the comparer for use in the submit.
    $form_state->set('storage_comparer', $storage_comparer);

    // Add the AJAX library to the form for dialog support.
    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';

    foreach ($storage_comparer->getAllCollectionNames() as $collection) {
      if ($collection != StorageInterface::DEFAULT_COLLECTION) {
        $form[$collection]['collection_heading'] = array(
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('@collection configuration collection', array('@collection' => $collection)),
        );
      }
      foreach ($storage_comparer->getChangelist(NULL, $collection) as $config_change_type => $config_names) {
        if (empty($config_names)) {
          continue;
        }

        // @todo A table caption would be more appropriate, but does not have
        // the visual importance of a heading.
        $form[$collection][$config_change_type]['heading'] = array(
          '#type' => 'html_tag',
          '#tag' => 'h3',
        );
        switch ($config_change_type) {
          case 'create':
            $form[$collection][$config_change_type]['heading']['#value'] = $this->formatPlural(count($config_names), '@count new', '@count new');
            break;

          case 'update':
            $form[$collection][$config_change_type]['heading']['#value'] = $this->formatPlural(count($config_names), '@count changed', '@count changed');
            break;

          case 'delete':
            $form[$collection][$config_change_type]['heading']['#value'] = $this->formatPlural(count($config_names), '@count removed', '@count removed');
            break;

          case 'rename':
            $form[$collection][$config_change_type]['heading']['#value'] = $this->formatPlural(count($config_names), '@count renamed', '@count renamed');
            break;

          case 'ignore':
            $form[$collection][$config_change_type]['heading']['#value'] = $this->formatPlural(count($config_names), '@count ignored', '@count ignored');
            break;
        }
        $form[$collection][$config_change_type]['list'] = array(
          '#type' => 'table',
          '#header' => array($this->t('Name'), $this->t('Operations')),
        );

        foreach ($config_names as $config_name) {
          if ($config_change_type == 'rename') {
            $names = $storage_comparer->extractRenameNames($config_name);
            $route_options = array('source_name' => $names['old_name'], 'target_name' => $names['new_name']);
            $config_name = $this->t('@source_name to @target_name', array('@source_name' => $names['old_name'], '@target_name' => $names['new_name']));
          }
          else {
            $route_options = array('source_name' => $config_name);
          }
          if ($collection != StorageInterface::DEFAULT_COLLECTION) {
            $route_name = 'config.diff_collection';
            $route_options['collection'] = $collection;
          }
          else {
            $route_name = 'config.diff';
          }
          $links['view_diff'] = array(
            'title' => $this->t('View differences'),
            'url' => Url::fromRoute($route_name, $route_options),
            'attributes' => array(
              'class' => array('use-ajax'),
              'data-dialog-type' => 'modal',
              'data-dialog-options' => json_encode(array(
                'width' => 700,
              )),
            ),
          );
          $form[$collection][$config_change_type]['list']['#rows'][] = array(
            'name' => $config_name,
            'operations' => array(
              'data' => array(
                '#type' => 'operations',
                '#links' => $links,
              ),
            ),
          );
        }
      }
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config_importer = new ConfigImportIgnoreConfigImporter(
      $form_state->get('storage_comparer'),
      $this->eventDispatcher,
      $this->configManager,
      $this->lock,
      $this->typedConfigManager,
      $this->moduleHandler,
      $this->moduleInstaller,
      $this->themeHandler,
      $this->getStringTranslation()
    );
    if ($config_importer->alreadyImporting()) {
      drupal_set_message($this->t('Another request may be synchronizing configuration already.'));
    }
    else {
      try {
        $sync_steps = $config_importer->initialize();
        $batch = array(
          'operations' => array(),
          'finished' => array(get_class($this), 'finishBatch'),
          'title' => t('Synchronizing configuration'),
          'init_message' => t('Starting configuration synchronization.'),
          'progress_message' => t('Completed step @current of @total.'),
          'error_message' => t('Configuration synchronization has encountered an error.'),
          'file' => __DIR__ . '/../../config.admin.inc',
        );
        foreach ($sync_steps as $sync_step) {
          $batch['operations'][] = array(
            array(
              get_class($this),
              'processBatch',
            ),
            array(
              $config_importer,
              $sync_step,
            ),
          );
        }

        batch_set($batch);
      }
      catch (ConfigImporterException $e) {
        // There are validation errors.
        drupal_set_message($this->t('The configuration cannot be imported because it failed validation for the following reasons:'), 'error');
        foreach ($config_importer->getErrors() as $message) {
          drupal_set_message($message, 'error');
        }
      }
    }
  }

}
