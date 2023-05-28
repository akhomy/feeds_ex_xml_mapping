<?php

declare(strict_types = 1);

namespace Drupal\feeds_ex_xml_mapping\Util;

use Drupal\feeds\FeedTypeInterface;

/**
 * Util class that contains various helper functions to deal with XML mappings.
 */
class XmlMappingHelper {

  /**
   * Gets mappings from the feed type.
   *
   * Mappings in the custom format as saved on submit so default values can be
   * preset.
   *
   * @param \Drupal\feeds\FeedTypeInterface $feed_type
   *   Feed type.
   *
   * @return array
   *   Mappings in the custom format as saved on submit.
   */
  public static function getMappingsFromFeedType(FeedTypeInterface $feed_type): array {
    $parser_configuration = $feed_type->getParser()->getConfiguration();

    $mappings = $sources = [];
    $feed_type_mappings = $feed_type->getMappings();
    $feed_type_sources = $parser_configuration['sources'] ?? [];
    foreach ($feed_type_mappings as $feed_type_mapping) {
      $mapping = $feed_type_mapping;
      // Adjust xpath field mappings.
      foreach ($mapping['map'] as $field_name => $source) {
        // Ignore none XPath source such as "parent:*".
        if (isset($feed_type_sources[$source])) {
          $xpath_plugin_id = 'xpath_' . $mapping['target'] . '_' . $field_name;
          $mapping['map'][$field_name] = $xpath_plugin_id;
          $sources[$xpath_plugin_id]['value'] = $feed_type_sources[$source]['value'];
        }
      }
      $mappings[] = $mapping;
    }
    return [
      'context' => $parser_configuration['context']['value'],
      'mappings' => $mappings,
      'sources' => $sources,
    ];
  }

  /**
   * Gets field mappings of particular field.
   *
   * @param string $field_name
   *   Field name to get mappings for.
   * @param array $mappings
   *   The stored mappings.
   *
   * @return array
   *   Field mappings of particular field.
   */
  public static function getFieldMappings(string $field_name, array $mappings): array {
    $values = $mappings['mappings'] ?? [];
    foreach ($values as $field_mappings) {
      if ($field_mappings['target'] == $field_name) {
        return $field_mappings;
      }
    }
    return [];
  }

}
