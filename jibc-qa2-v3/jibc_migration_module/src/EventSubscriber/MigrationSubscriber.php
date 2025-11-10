<?php

namespace Drupal\jibc_api_migration\EventSubscriber;

use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Drupal\migrate\Event\MigratePreRowSaveEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MigrationSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents() {
    return [
      MigrateEvents::PRE_ROW_SAVE => ['onPreRowSave'],
      MigrateEvents::POST_ROW_SAVE => ['onPostRowSave'],
    ];
  }

  public function onPreRowSave(MigratePreRowSaveEvent $event) {
    $migration = $event->getMigration();
    $row = $event->getRow();
    
    if ($migration->id() == 'new_courses') {
      // Debug: Log what we're receiving for Course_Desc
      $course_desc = $row->getSourceProperty('Course_Desc');
      if (!empty($course_desc)) {
        \Drupal::logger('jibc_api_migration')->debug('Pre-save Course_Desc for @id: Length @length, Has HTML: @html', [
          '@id' => $row->getSourceProperty('Course_ID'),
          '@length' => strlen($course_desc),
          '@html' => (strip_tags($course_desc) != $course_desc ? 'Yes' : 'No'),
        ]);
        
        // Ensure the description is properly set with format
        $row->setDestinationProperty('field_course_details', [
          'value' => $course_desc,
          'format' => 'full_html',
        ]);
      } else {
        \Drupal::logger('jibc_api_migration')->warning('Empty Course_Desc for @id', [
          '@id' => $row->getSourceProperty('Course_ID'),
        ]);
      }
    }
  }

  public function onPostRowSave(MigratePostRowSaveEvent $event) {
    $migration = $event->getMigration();
    
    if ($migration->id() == 'new_courses') {
      $destination_ids = $event->getDestinationIdValues();
      if (!empty($destination_ids[0])) {
        $node = \Drupal::entityTypeManager()->getStorage('node')->load($destination_ids[0]);
        if ($node && $node->hasField('field_course_details')) {
          $row = $event->getRow();
          $course_desc = $row->getSourceProperty('Course_Desc');
          
          // Check if the field is empty but we have source data
          if (!empty($course_desc) && $node->get('field_course_details')->isEmpty()) {
            \Drupal::logger('jibc_api_migration')->error('Course details not saved for @id despite having source data', [
              '@id' => $row->getSourceProperty('Course_ID'),
            ]);
            
            // Try to force save it
            $node->set('field_course_details', [
              'value' => $course_desc,
              'format' => 'full_html',
            ]);
            $node->save();
            
            \Drupal::logger('jibc_api_migration')->notice('Force-saved course details for @id', [
              '@id' => $row->getSourceProperty('Course_ID'),
            ]);
          }
        }
      }
    }
  }
}