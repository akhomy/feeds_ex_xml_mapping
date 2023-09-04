<?php

declare(strict_types = 1);

namespace Drupal\feeds_ex_xml_mapping\Feeds\Parser\Form;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\Plugin\Type\ExternalPluginFormBase;
use Drupal\feeds\Plugin\Type\FeedsPluginManager;
use Drupal\feeds\Plugin\Type\MappingPluginFormInterface;
use Drupal\feeds\Plugin\Type\Target\ConfigurableTargetInterface;
use Drupal\feeds_ex_xml_mapping\Util\XmlMappingHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form on the feed edit page for the XmlParser.
 */
class XmlParserFeedForm extends ExternalPluginFormBase implements ContainerInjectionInterface {


  /**
   * The module name used in the 3rd-party settings.
   */
  const MODULE_NAME = 'feeds_ex_xml_mapping';

  /**
   * The feeds target plugin manager.
   */
  protected FeedsPluginManager $targetManager;

  /**
   * XmlParserFeedForm constructor.
   *
   * @param \Drupal\feeds\Plugin\Type\FeedsPluginManager $target_manager
   *   The feeds target plugin manager.
   */
  public function __construct(FeedsPluginManager $target_manager) {
    $this->targetManager = $target_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('plugin.manager.feeds.target')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, ?FeedInterface $feed = NULL): array {
    $feed_type = $feed->getType();
    // If no configuration was enabled - skip build mappping.
    if (empty($feed_type->getThirdPartySetting(self::MODULE_NAME, 'source', FALSE))) {
      return [];
    }
    $mappings = $feed->get('config')->getValue()[0]['xml_parser'] ?? [];
    if ($feed->isNew()) {
      $mappings = XmlMappingHelper::getMappingsFromFeedType($feed_type);
    }
    $targets = $feed_type->getMappingTargets();
    $form['context'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Context'),
      '#description' => $this->t('The base query to run.'),
      '#size' => 50,
      '#default_value' => $mappings['context'] ?? '',
      '#required' => TRUE,
      '#maxlength' => 1024,
      '#weight' => -50,
    ];

    $form['mappings-wrapper'] = [
      '#type' => 'details',
      '#title' => $this->t('XPath Parser Settings'),
    ];
    $headers = [
      $this->t('Source'),
    ];
    if ($feed_type->getThirdPartySetting(self::MODULE_NAME, 'source_configuration', FALSE)) {
      $headers = [
        $this->t('Source'),
        $this->t('Configure'),
        $this->t('Unique'),
      ];
    }
    $table = [
      '#type' => 'table',
      '#header' => $headers,
      '#sticky' => TRUE,
    ];
    foreach ($targets as $field_name => $target) {
      $field_settings = XmlMappingHelper::getFieldMappings($field_name, $mappings);
      $properties = $target->getProperties();
      /** @var \Drupal\feeds\FieldTargetDefinition $target */
      $row = [];
      foreach ($properties as $index => $property) {
        $label = (string) $target->getLabel();
        $title = count($properties) > 1 ? $label . ' : ' . $property : $label;
        // Ignore non-xpath properties, such as parent:*.
        if (isset($field_settings['map'][$property]) && strpos($field_settings['map'][$property], 'xpath_') !== 0) {
          $row['xpath'][$property]['value'] = [
            '#type' => 'hidden',
            '#parents' => [
              'plugin',
              'parser',
              'mappings',
              $field_name,
              'xpath',
              $property,
            ],
            '#value' => $field_settings['map'][$property],
          ];
          $row['xpath'][$property]['skip'] = [
            '#type' => 'hidden',
            '#parents' => [
              'plugin',
              'parser',
              'mappings',
              $field_name,
              'skip',
              $property,
            ],
            '#value' => 1,
          ];
          $row['xpath'][$property]['title'] = [
            '#type' => 'item',
            '#title' => $title,
            '#markup' => $field_settings['map'][$property],
          ];
        }
        else {
          // XPath for parsing.
          $row['xpath'][$property] = [
            '#type' => 'textfield',
            '#title' => $title,
            '#parents' => [
              'plugin',
              'parser',
              'mappings',
              $field_name,
              'xpath',
              $property,
            ],
            '#array_parents' => ['mappings', $field_name, 'xpath', $property],
            '#description' => $this->t('The XPath query to run.'),
            '#default_value' => $mappings['custom_sources']['xpath_' . $field_name . '_' . $property]['value'] ?? '',
          ];
          // Add map key to satisfy validation in parser base.
          $row['map'][$property] = [
            '#type' => 'value',
            '#title' => $title,
            '#parents' => [
              'plugin',
              'parser',
              'mappings',
              $field_name,
              'map',
              $property,
              'select',
            ],
            '#array_parents' => [
              'mappings',
              $field_name,
              'map',
              $property,
              'select',
            ],
            '#value' => $field_settings['map'][$property] ?? NULL,
          ];
        }
        // Settings subform for particular target. Only one per field.
        if ($index == 0 && $feed_type->getThirdPartySetting(self::MODULE_NAME, 'source_configuration', FALSE)) {
          $target_plugin_id = $target->getPluginId();
          $target_config = $field_settings['settings'] ?? [];
          $target_config['feed_type'] = $feed_type;
          $target_config['target_definition'] = $target;
          /** @var \Drupal\feeds\Plugin\Type\Target\TargetBase $target_plugin */
          $target_plugin = $this->targetManager->createInstance($target_plugin_id, $target_config);
          if ($target_plugin instanceof ConfigurableTargetInterface) {
            // Set target settings so the form is build without redundant
            // feeds_item drop down. See eg
            // \Drupal\feeds\Feeds\Target\EntityReference::buildConfigurationForm()
            $subform_state = new FormState();
            $subform_state->setValue('target-settings-' . $field_name, $field_name);
            $settings = $target_plugin->buildConfigurationForm([], $subform_state);
            // States does not work as the target plugin name start with
            // "mappings", but in current scope the form element name must start
            // with "plugin", "parser". Thus get rid off feeds item element
            // manually.
            if (isset($settings['feeds_item'])) {
              $input_name = ':input[name="plugin[parser][mappings][' . $field_name . '][settings][reference_by]"]';
              $settings['feeds_item']['#states']['visible'][$input_name]['value'] = 'feeds_item';
            }
            $row['settings'] = $settings;
            $row['settings']['#parents'] = [
              'plugin',
              'parser',
              'mappings',
              $field_name,
              'settings',
            ];
          }
          else {
            $row['settings']['#markup'] = '';
          }
          $row['settings']['#attributes'] = ['rowspan' => count($properties)];
        }
        // Unique property.
        if ($target->isUnique($property) && $feed_type->getThirdPartySetting(self::MODULE_NAME, 'source_configuration', FALSE)) {
          $row['unique'][$property] = [
            '#title' => $this->t('Unique'),
            '#type' => 'checkbox',
            '#parents' => [
              'plugin',
              'parser',
              'mappings',
              $field_name,
              'unique',
              $property,
            ],
            '#default_value' => !empty($field_settings['unique'][$property]),
            '#title_display' => 'invisible',
          ];
        }
        else {
          $row['unique'][$property]['#markup'] = '';
        }
      }
      $table[$field_name] = $row;
    }

    $form['mappings-wrapper']['mappings'] = $table;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state, ?FeedInterface $feed = NULL): void {
    $values = $form_state->getValues();
    // If no mappings override enabled - skip validation.
    if (empty($values['mappings'])) {
      return;
    }
    // Allow plugins to validate the mapping form.
    foreach ($feed->getType()->getPlugins() as $plugin) {
      if ($plugin instanceof MappingPluginFormInterface) {
        $plugin->mappingFormValidate($form, $form_state);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state, ?FeedInterface $feed = NULL): void {
    $values = $form_state->getValues();
    // If no mappings override enabled - skip submit.
    if (empty($values['mappings'])) {
      return;
    }
    // Build values in the same format as feed_type entity is using, so they
    // can be easily injected during actual import.
    $sources = $custom_sources = $mappings = [];
    foreach ($values['mappings'] as $field_name => $field_values) {
      $mapping = [];
      $mapping['target'] = $field_name;
      foreach ($field_values['xpath'] as $property => $value) {
        // Keep original value as this is not xpath plugin.
        if (!empty($field_values['skip'][$property])) {
          $mapping['map'][$property] = $value;
          continue;
        }
        if ($value) {
          $xpath_name = 'xpath_' . $field_name . '_' . $property;
          $mapping['map'][$property] = $xpath_name;
          if (!empty($field_values['unique'][$property])) {
            $mapping['unique'][$property] = 1;
          }
          // Add to sources.
          $source = [
            'label' => $field_name . ':' . $property,
            'value' => $value,
          ];
          $sources[$xpath_name] = $source;
          // Add to custom sources which additionally contains machine name.
          $source['machine_name'] = $xpath_name;
          $custom_sources[$xpath_name] = $source;
        }
      }
      // Add settings.
      if (!empty($field_values['settings'])) {
        $mapping['settings'] = $field_values['settings'];
      }
      // Add mapping if not empty.
      if (!empty($mapping['map'])) {
        $mappings[] = $mapping;
      }
    }
    $config = $feed->get('config')->getValue();
    $config[0]['xml_parser'] = [
      'context' => $values['context'],
      'mappings' => $mappings,
      'custom_sources' => $custom_sources,
    ];
    $feed->get('config')->setValue($config);
  }

}
