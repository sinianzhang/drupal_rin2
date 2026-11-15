<?php

declare(strict_types=1);

namespace Drupal\rin2_notify\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\rin2_notify\NotificationInterface;
use Drupal\user\EntityOwnerTrait;
use Drupal\user\UserInterface;

/**
 * Defines the notification entity.
 *
 * One row in {rin2_notification} per posted notification. A NULL target_uid
 * means the notification is addressed to all users.
 */
#[ContentEntityType(
  id: 'rin2_notification',
  label: new TranslatableMarkup('Notification'),
  label_collection: new TranslatableMarkup('Notifications'),
  label_singular: new TranslatableMarkup('notification'),
  label_plural: new TranslatableMarkup('notifications'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'message',
    'owner' => 'uid',
  ],
  base_table: 'rin2_notification',
  admin_permission: 'administer rin2 notifications',
  label_count: [
    'singular' => '@count notification',
    'plural' => '@count notifications',
  ],
)]
final class Notification extends ContentEntityBase implements NotificationInterface {

  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    /** @var \Drupal\Core\Field\BaseFieldDefinition[] $fields */
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Type'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', NotificationInterface::TYPES)
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 0])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'list_default', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['message'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Message'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 1])
      ->setDisplayOptions('view', ['label' => 'hidden', 'type' => 'string', 'weight' => 1])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Stored as a Unix timestamp so "not expired" is an integer comparison.
    $fields['expires'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Expires'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', ['type' => 'datetime_timestamp', 'weight' => 2])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'timestamp', 'weight' => 2])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Empty (NULL) means the notification is for all users.
    $fields['target_uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Recipient'))
      ->setDescription(new TranslatableMarkup('Leave empty to send the notification to all users.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 3])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'entity_reference_label', 'weight' => 3])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid']
      ->setLabel(new TranslatableMarkup('Author'))
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    return (string) $this->get('type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getMessage(): string {
    return (string) $this->get('message')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getExpires(): int {
    return (int) $this->get('expires')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isExpired(int $now): bool {
    return $this->getExpires() <= $now;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetUser(): ?UserInterface {
    return $this->get('target_uid')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function isForAllUsers(): bool {
    return $this->get('target_uid')->isEmpty();
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

}
