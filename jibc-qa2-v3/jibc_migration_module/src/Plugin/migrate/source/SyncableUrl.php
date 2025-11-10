<?php

namespace Drupal\jibc_api_migration\Plugin\migrate\source;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\migrate_plus\Plugin\migrate\source\Url;
use Drupal\migrate_tools\Plugin\migrate\source\SyncableSourceTrait;
use Drupal\migrate_tools\SyncableSourceInterface;
use Drupal\Core\Site\Settings;

/**
 * A syncable url source using SyncableSourceTrait with dynamic URL support.
 *
 * @see Drupal\migrate_plus\Plugin\migrate\source\Url
 *
 * @MigrateSource(
 *   id = "syncable_url",
 *   source_module = "jibc_api_migration"
 * )
 */
class SyncableUrl extends Url implements SyncableSourceInterface {

  use SyncableSourceTrait;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration) {
    // Set dynamic URL based on environment
    $settings_config = Settings::get('jibc_api', []);
    
    if (!empty($settings_config['api_base_url'])) {
      // Override the URL with the dynamic one from settings
      $configuration['urls'] = rtrim($settings_config['api_base_url'], '/') . '/courses';
      
      // Log the URL being used (only in non-production)
      if (empty($settings_config['log_errors_only'])) {
        \Drupal::logger('jibc_api_migration')->info('SyncableUrl using dynamic URL: @url', [
          '@url' => $configuration['urls']
        ]);
      }
    } elseif (empty($configuration['urls'])) {
      // No URL in configuration and no settings, throw error
      throw new \Exception('No API URL configured. Please configure jibc_api in settings.php');
    }
    
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
    $this->setAllRowsFromConfiguration();
  }
}