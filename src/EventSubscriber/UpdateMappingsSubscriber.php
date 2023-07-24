<?php

declare(strict_types = 1);

namespace Drupal\feeds_ex_xml_mapping\EventSubscriber;

use Drupal\feeds\Event\FeedsEvents;
use Drupal\feeds\Event\InitEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event listener that update mappings from feed before actual parsing.
 */
class UpdateMappingsSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Go first to update the mappings before import. Use the ones from feed
    // instead of feed type entity. It's enough to go before actual parsing in
    // Drupal\feeds\EventSubscriber\LazySubscriber, but better go as very first.
    $events[FeedsEvents::INIT_IMPORT][] = ['updateMappings', 1024];
    return $events;
  }

  /**
   * Update mappings.
   *
   * Loads mappings stored per feed and inject them into feed type so they are
   * used during parsing.
   *
   * @param \Drupal\feeds\Event\InitEvent $event
   *   The init import event.
   */
  public function updateMappings(InitEvent $event): void {
    $feed = $event->getFeed();
    $feed_type = $feed->getType();
    $parser_id = $feed_type->getParser()->getPluginId();
    if ($parser_id == 'xml') {
      // Get mappings saved in feed.
      $mappings = $feed->get('config')->getValue()[0]['xml_parser'] ?? [];
      if (empty($mappings)) {
        return;
      }
      // Update mappings.
      $feed_type->setMappings($mappings['mappings']);
      // Update custom sources.
      $feed_type->set('custom_sources', $mappings['custom_sources']);
      // Update sources.
      $feed_type->set('sources', $mappings['sources']);
      // Update parser configuration.
      $parser_configuration = $feed_type->get('parser_configuration');
      $parser_configuration['context']['value'] = $mappings['context'];
      $parser_configuration['sources'] = $mappings['sources'];
      $feed_type->set('parser_configuration', $parser_configuration);
    }
  }

}
