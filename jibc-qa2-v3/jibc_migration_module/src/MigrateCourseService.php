<?php
/**
 * @file
 * Contains \Drupal\jibc_api_migration\MigrateCourseService
 */

namespace Drupal\jibc_api_migration;

use Drupal\jibc_api_migration\JIBCMigrateExecutable;
use Drupal\migrate\MigrateMessage;
use Drupal\migrate\Plugin\MigrationInterface;

/**
 * Class MigrateCourseService.
 * Handles course migration using Workato API
 */
class MigrateCourseService {

  /**
   * Unpublishes a missing course.
   *
   * @param string $migration_name
   *   The migration name plugin.
   *
   * @throws \Drupal\migrate\MigrateException
   */
  public function unpublishCourse($migration_name) {
    $manager = \Drupal::service('plugin.manager.migration');
    $migration = $manager->createInstance($migration_name);
    $message = new JIBCMigrateMessage();
    
    // Add options to prevent timeout
    $options = [
      'limit' => 100,  // Limit rollback batch size
      'feedback' => 50,  // Show progress every 50 items
    ];
    
    $executable = new JIBCMigrateExecutable($migration, $message, $options);
    drush_op([$executable, 'rollbackMissingItems']);
  }

  /**
   * Refresh all courses from Workato API with batch processing
   */
  public function refreshAllCourses() {
    // Validate Workato API configuration
    if (!$this->validateWorkatoConfig()) {
      return;
    }

    // Test API connectivity before proceeding
    if (!$this->testWorkatoConnectivity()) {
      $error_msg = "Cannot connect to Workato API. Please check your configuration and network connectivity.";
      \Drupal::messenger()->addError($error_msg);
      \Drupal::logger('Course Refresh')->error($error_msg);
      return;
    }

    \Drupal::logger('Course Refresh')->notice('Starting course refresh using Workato API');

    // First, rollback missing courses
    $msg0 = $this->rollBackCourses();
    
    $migration_id = 'new_courses';
    $migration = \Drupal::service('plugin.manager.migration')->createInstance($migration_id);

    // To force every course to refresh (and not just updated ones), uncomment the line below
    //$migration->getIdMap()->prepareUpdate();
    
    // Add options for batch processing to prevent timeouts
    $options = [
      'limit' => 0,  // No limit for manual refresh, but use feedback
      'feedback' => 25,  // Show progress every 25 items
      'update' => TRUE,
      'force' => FALSE,
    ];
    
    $executable = new JIBCMigrateExecutable(
      $migration, 
      new MigrateMessage(),
      $options
    );

    // Use Drupal's batch API for better progress tracking and timeout prevention
    if (PHP_SAPI !== 'cli') {
      // Running via web UI - use batch API
      $this->runMigrationInBatches($migration);
      return;
    }
    
    // Running via drush/cli - run directly
    $msg1 = $executable->import();
    
    if($msg0 == MigrationInterface::RESULT_COMPLETED && $msg1 == MigrationInterface::RESULT_COMPLETED) {
      \Drupal::messenger()->addStatus(t('Course refresh completed successfully using Workato API!'));
      \Drupal::logger('Course Refresh')->notice('Course refresh completed successfully');
    } else {
      $err_msg = "Course refresh failed! Please check the logs and contact your administrator.";
      \Drupal::messenger()->addError($err_msg);
      \Drupal::logger('Course Refresh')->error($err_msg);
    }
  }
  
  /**
   * Run migration in batches to prevent timeouts
   */
  private function runMigrationInBatches($migration) {
    $batch = [
      'title' => t('Refreshing Courses'),
      'operations' => [
        [
          [\Drupal\jibc_api_migration\MigrateCourseService::class, 'batchProcessMigration'],
          [$migration->id()],
        ],
      ],
      'finished' => [\Drupal\jibc_api_migration\MigrateCourseService::class, 'batchFinished'],
      'progress_message' => t('Processing courses... @current of @total.'),
    ];
    
    batch_set($batch);
  }
  
  /**
   * Batch operation callback for migration
   */
  public static function batchProcessMigration($migration_id, &$context) {
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current_id'] = 0;
      $context['sandbox']['max'] = 0;
    }
    
    $migration = \Drupal::service('plugin.manager.migration')->createInstance($migration_id);
    
    // Process 25 items per batch to prevent timeouts
    $options = [
      'limit' => 25,
      'update' => TRUE,
    ];
    
    $executable = new JIBCMigrateExecutable(
      $migration,
      new MigrateMessage(),
      $options
    );
    
    $result = $executable->import();
    
    $context['sandbox']['progress'] += 25;
    $context['message'] = t('Processed @count courses...', ['@count' => $context['sandbox']['progress']]);
    
    // Check if we're done
    if ($migration->getStatus() == MigrationInterface::STATUS_IDLE) {
      $context['finished'] = 1;
    } else {
      $context['finished'] = min(0.99, $context['sandbox']['progress'] / 800); // Estimate 800 total
    }
  }
  
  /**
   * Batch finished callback
   */
  public static function batchFinished($success, $results, $operations) {
    if ($success) {
      \Drupal::messenger()->addStatus(t('Course refresh completed successfully!'));
      \Drupal::logger('Course Refresh')->notice('Batch course refresh completed successfully');
    } else {
      \Drupal::messenger()->addError(t('Course refresh encountered errors. Please check the logs.'));
      \Drupal::logger('Course Refresh')->error('Batch course refresh failed');
    }
  }

  /**
   * Rollback courses (unpublish missing ones)
   */
  public function rollBackCourses(){
    $migration_id = 'new_courses';
    $migration = \Drupal::service('plugin.manager.migration')->createInstance($migration_id);
    
    // Add options for batch processing
    $options = [
      'limit' => 100,  // Process 100 at a time for rollback
      'feedback' => 50,
    ];
    
    $executable = new JIBCMigrateExecutable(
      $migration, 
      new MigrateMessage(),
      $options
    );
    return $executable->rollbackMissingItems();
  }

  /**
   * Validate Workato API configuration
   * 
   * @return bool
   */
  private function validateWorkatoConfig() {
    $settings_config = \Drupal\Core\Site\Settings::get('jibc_api', []);
    
    if (empty($settings_config['api_base_url'])) {
      $error_msg = "Workato API URL not configured in settings.php";
      \Drupal::messenger()->addError($error_msg);
      \Drupal::logger('Course Refresh')->error($error_msg);
      return false;
    }
    
    if (empty($settings_config['workato_auth_token'])) {
      $error_msg = "Workato authentication token not found in settings.php";
      \Drupal::messenger()->addError($error_msg);
      \Drupal::logger('Course Refresh')->error($error_msg);
      return false;
    }
    
    return true;
  }

  /**
   * Test connectivity to the Workato API
   * 
   * @return bool
   */
  private function testWorkatoConnectivity() {
    $settings_config = \Drupal\Core\Site\Settings::get('jibc_api', []);
    $api_url = rtrim($settings_config['api_base_url'] ?? '', '/') . '/courses';
    $auth_token = $settings_config['workato_auth_token'] ?? '';
    
    if (empty($api_url) || empty($auth_token)) {
      return false;
    }
    
    $client = \Drupal::httpClient();
    
    try {
      $response = $client->head($api_url, [
        'headers' => [
          'api-token' => $auth_token,
          'Accept' => 'application/json',
        ],
        'timeout' => 10,
        'http_errors' => false,
      ]);
      
      $success = $response->getStatusCode() < 400;
      
      if ($success) {
        \Drupal::logger('Course Refresh')->notice('Workato API connectivity test successful');
      } else {
        \Drupal::logger('Course Refresh')->error('Workato API returned HTTP @code', [
          '@code' => $response->getStatusCode()
        ]);
      }
      
      return $success;
    }
    catch (\Exception $e) {
      \Drupal::logger('Course Refresh')->error('Workato API connectivity test failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * Attach orphaned course offerings to courses
   * This method finds course offering paragraphs that exist but aren't attached to any course
   * and attempts to attach them based on their course ID
   */
  public function attachOrphanedOfferings() {
    $db = \Drupal::database();
    $logger = \Drupal::logger('jibc_api_migration');
    
    // Check current status
    $orphaned_count = $db->query("
      SELECT COUNT(*) FROM {paragraphs_item} p 
      WHERE p.type = 'course_offering' 
      AND p.id NOT IN (SELECT field_course_offerings_target_id FROM {node__field_course_offerings})
    ")->fetchField();
    
    if ($orphaned_count == 0) {
      \Drupal::messenger()->addStatus('No orphaned offerings found. All offerings are attached.');
      return;
    }
    
    \Drupal::messenger()->addStatus("Found $orphaned_count orphaned course offerings. Attaching them now...");
    
    // Get all orphaned offerings with their section IDs
    $offerings = $db->query("
      SELECT p.id, p.revision_id, pf.field_course_id_value as section_id
      FROM {paragraphs_item} p 
      JOIN {paragraph__field_course_id} pf ON p.id = pf.entity_id
      WHERE p.type = 'course_offering'
      AND p.id NOT IN (SELECT field_course_offerings_target_id FROM {node__field_course_offerings})
      ORDER BY pf.field_course_id_value
    ")->fetchAll();
    
    // Build a mapping of possible course IDs to node IDs for faster lookup
    $course_map = [];
    $course_nodes = $db->query("
      SELECT field_course_id_value, entity_id 
      FROM {node__field_course_id}
    ")->fetchAll();
    
    foreach ($course_nodes as $cn) {
      $course_map[$cn->field_course_id_value] = $cn->entity_id;
    }
    
    $logger->info('Found @count course nodes in system', ['@count' => count($course_map)]);
    
    // Group offerings by their parent course
    $grouped = [];
    $unmapped = [];
    
    foreach ($offerings as $offering) {
      $section_id = $offering->section_id;
      $course_id = null;
      $node_id = null;
      
      // Try different patterns to extract course ID from section ID
      // Pattern 1: Direct match
      if (isset($course_map[$section_id])) {
        $course_id = $section_id;
        $node_id = $course_map[$section_id];
      }
      // Pattern 2: Remove last segment after hyphen
      elseif (preg_match('/^(.+)-\d+$/', $section_id, $matches)) {
        $potential_course = $matches[1];
        if (isset($course_map[$potential_course])) {
          $course_id = $potential_course;
          $node_id = $course_map[$potential_course];
        }
      }
      // Pattern 3: First two segments
      if (!$node_id) {
        $parts = explode('-', $section_id);
        if (count($parts) >= 2) {
          $potential_course = $parts[0] . '-' . $parts[1];
          if (isset($course_map[$potential_course])) {
            $course_id = $potential_course;
            $node_id = $course_map[$potential_course];
          }
        }
      }
      
      if ($node_id) {
        if (!isset($grouped[$node_id])) {
          $grouped[$node_id] = [
            'course_id' => $course_id,
            'offerings' => []
          ];
        }
        $grouped[$node_id]['offerings'][] = [
          'target_id' => $offering->id,
          'target_revision_id' => $offering->revision_id,
        ];
      } else {
        $unmapped[] = $section_id;
      }
    }
    
    if (!empty($unmapped)) {
      $logger->warning('Could not map @count offerings to courses', ['@count' => count($unmapped)]);
    }
    
    // Attach offerings to nodes
    $attached = 0;
    $failed = 0;
    
    foreach ($grouped as $nid => $data) {
      try {
        $node = \Drupal\node\Entity\Node::load($nid);
        if ($node && $node->bundle() === 'course') {
          $existing = $node->get('field_course_offerings')->getValue();
          $existing_ids = array_column($existing, 'target_id');
          
          $new_offerings = [];
          foreach ($data['offerings'] as $offering) {
            if (!in_array($offering['target_id'], $existing_ids)) {
              $new_offerings[] = $offering;
            }
          }
          
          if (!empty($new_offerings)) {
            $all_offerings = array_merge($existing, $new_offerings);
            $node->set('field_course_offerings', $all_offerings);
            $node->save();
            $attached++;
          }
        } else {
          $failed++;
        }
      } catch (\Exception $e) {
        $failed++;
        $logger->error('Failed to attach offerings to node @nid: @error', [
          '@nid' => $nid,
          '@error' => $e->getMessage()
        ]);
      }
    }
    
    // Clear caches
    drupal_flush_all_caches();
    
    \Drupal::messenger()->addStatus("Successfully updated $attached courses with their offerings.");
    $logger->notice('Attachment complete. Updated @attached courses, @failed failed', [
      '@attached' => $attached,
      '@failed' => $failed
    ]);
  }
}