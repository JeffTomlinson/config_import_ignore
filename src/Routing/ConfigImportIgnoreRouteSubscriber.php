<?php

namespace Drupal\config_import_ignore\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Class RouteSubscriber.
 *
 * @package Drupal\config_import_ignore\Routing
 * Listens to the dynamic route events.
 */
class ConfigImportIgnoreRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    // Change the config sync form to our extension of it.
    if ($route = $collection->get('config.sync')) {
      $route->setDefaults(array(
        '_form' => '\Drupal\config_import_ignore\Form\ConfigImportIgnoreConfigSync',
      ));
    }
  }

}
