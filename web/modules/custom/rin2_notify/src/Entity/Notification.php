<?php

declare(strict_types=1);

namespace Drupal\rin2_notify\Entity;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\rin2_notify\Form\NotificationForm;
use Drupal\rin2_notify\NotificationAccessControlHandler;
use Drupal\rin2_notify\NotificationInterface;
use Drupal\rin2_notify\NotificationManagerInterface;
use Drupal\rin2_notify\NotificationViewBuilder;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the notification entity.
 *
 * One row in {rin2_notification} per posted notification. Recipients are in
 * {rin2_notification__target_uid} (one row per user); no recipient rows means
 * the notification is addressed to all users.
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
  handlers: [
    'access' => NotificationAccessControlHandler::class,
    'view_builder' => NotificationViewBuilder::class,
    'form' => [
      'add' => NotificationForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  base_table: 'rin2_notification',
  admin_permission: 'administer rin2 notifications',
  // Write-once: no edit-form or delete-form (see the access handler).
  links: [
    'canonical' => '/admin/content/notifications/{rin2_notification}',
    'add-form' => '/admin/content/notifications/add',
    // Route defined in rin2_notify.routing.yml (custom controller).
    'collection' => '/admin/content/notifications',
  ],
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
      ->setDescription(new TranslatableMarkup('After this time the notification is no longer shown, even if unread.'))
      ->setRequired(TRUE)
      ->addConstraint('Rin2ExpiresInFuture')
      ->setDisplayOptions('form', ['type' => 'datetime_timestamp', 'weight' => 2])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'timestamp', 'weight' => 2])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Empty (NULL) means the notification is for all users.
    // Unlimited: one notification can go to several users. Stored in its own
    // table; empty means the notification is for all users.
    $fields['target_uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Recipients'))
      ->setDescription(new TranslatableMarkup('The users who receive the notification. Empty means all users.'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 3])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'entity_reference_label', 'weight' => 3])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid']
      ->setLabel(new TranslatableMarkup('Author'))
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'author', 'weight' => 4])
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Posted'))
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'timestamp', 'weight' => 5])
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    // Drupal does not enforce the foreign keys declared in hook_schema().
    \Drupal::database()->delete('rin2_notification_read')
      ->condition('notification_id', array_keys($entities), 'IN')
      ->execute();
    // Per-user output is covered by the rin2_notification_list tag that core
    // invalidates on delete; admin read counts use the global read tag.
    Cache::invalidateTags([NotificationManagerInterface::READ_TAG]);
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
  public function getTargetUsers(): array {
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $items */
    $items = $this->get('target_uid');
    return $items->referencedEntities();
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetUserIds(): array {
    return array_map('intval', array_column($this->get('target_uid')->getValue(), 'target_id'));
  }

  /**
   * {@inheritdoc}
   */
  public function isTargeting(int|string $uid): bool {
    return in_array((int) $uid, $this->getTargetUserIds(), TRUE);
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
