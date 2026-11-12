<?php

declare(strict_types=1);

namespace Drupal\rin2_notify;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\user\UserStorageSchema;

/**
 * Adds a NOT NULL constraint to the notifications_enabled user column.
 *
 * Core only marks entity keys as NOT NULL in shared tables and ignores
 * isStorageRequired() there (https://www.drupal.org/node/2841291). Core's
 * UserStorageSchema works around this the same way for the "name" column.
 */
class Rin2UserStorageSchema extends UserStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getSharedTableFieldSchema(FieldStorageDefinitionInterface $storage_definition, $table_name, array $column_mapping) {
    $schema = parent::getSharedTableFieldSchema($storage_definition, $table_name, $column_mapping);

    if ($table_name === 'users_field_data' && $storage_definition->getName() === 'notifications_enabled') {
      $schema['fields']['notifications_enabled']['not null'] = TRUE;
    }

    return $schema;
  }

}
