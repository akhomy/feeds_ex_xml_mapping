<?php

declare(strict_types = 1);

namespace Drupal\Tests\feeds_ex_xml_mapping\Functional\Feeds\Parser;

use Drupal\Component\Utility\Xss;
use Drupal\node\Entity\Node;
use Drupal\Tests\feeds_ex\Functional\Feeds\Parser\ContextTestTrait;
use Drupal\Tests\feeds_ex\Functional\Feeds\Parser\ParserTestBase;

/**
 * Tests XML parser mapping override.
 *
 * @group feeds_ex
 */
class XmlParserMappingOverrideTest extends ParserTestBase {

  use ContextTestTrait;

  /**
   * {@inheritdoc}
   *
   * It is declared this way in the parent modules. Let's replace it later
   * with protected.
   */
  protected static $modules = [
    'feeds',
    'feeds_ex',
    'feeds_ex_xml_mapping',
    'node',
    'user',
    'file',
  ];

  /**
   * {@inheritdoc}
   */
  protected $parserId = 'xml';

  /**
   * {@inheritdoc}
   */
  protected $customSourceType = 'xml';

  /**
   * {@inheritdoc}
   */
  public function dataProviderValidContext(): array {
    return [
      ['/items/item'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function dataProviderInvalidContext(): array {
    return [
      ['!! ', 'Invalid expression'],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Override with own test path.
   */
  protected function resourcesUrl(): string {
    return \Drupal::request()->getSchemeAndHttpHost() . '/' . \Drupal::service('extension.list.module')->getPath('feeds_ex_xml_mapping') . '/tests/resources';
  }

  /**
   * {@inheritdoc}
   */
  public function testMapping() {
    $expected_sources = [
      'name' => [
        'label' => 'Name',
        'value' => 'name',
        'machine_name' => 'name',
        'type' => $this->customSourceType,
        'raw' => FALSE,
        'inner' => FALSE,
      ],
    ];
    $custom_source = [
      'label' => 'Name',
      'value' => 'name',
      'machine_name' => 'name',
    ];

    $this->setupContext();
    $this->doMappingTest($expected_sources, $custom_source);

    // Assert that custom sources are displayed.
    $this->drupalGet('admin/structure/feeds/manage/' . $this->feedType->id() . '/sources');
    $session = $this->assertSession();
    $session->pageTextContains('Custom XML Xpath sources');
    $session->pageTextContains('Name');
    $session->pageTextContains('Raw value');
    $session->pageTextContains('Inner XML');
    // Both options are disabled.
    $session->pageTextNotContains('Enabled');
    $session->pageTextContains('Disabled');
    $session->linkByHrefExists('/admin/structure/feeds/manage/' . $this->feedType->id() . '/sources/name');
    $session->linkByHrefExists('/admin/structure/feeds/manage/' . $this->feedType->id() . '/sources/name/delete');
  }

  /**
   * Tests enabling mapping override.
   */
  public function testEnableOverrideMapping(): void {
    $this->drupalGet('/admin/structure/feeds/manage/' . $this->feedType->id() . '/mapping');

    // Assert override settings are on the page.
    $this->assertSession()->pageTextContains('Override mapping per feed');

    // First, set context.
    $edit = [
      'context' => '/items/item',
    ];

    $this->submitForm($edit, 'Save');

    // Now setup mapping override.
    $edit = [
      'override_mapping_per_feed[source]' => TRUE,
      'override_mapping_per_feed[source_configuration]' => TRUE,
    ];
    $this->submitForm($edit, 'Save');

    // Assert mapping override source is checked.
    $this->assertSession()->checkboxChecked('override_mapping_per_feed[source]');
    // Assert mapping override source configuration is checked.
    $this->assertSession()->checkboxChecked('override_mapping_per_feed[source_configuration]');

    // Now check the parser configuration.
    /** @var \Drupal\feeds\Entity\FeedType $feed_type */
    $feed_type = $this->reloadEntity($this->feedType);
    $this->assertTrue($feed_type->getThirdPartySetting('feed_ex_xml_mapping', 'source'), 'Override mapping per feed source is enabled');
    $this->assertTrue($feed_type->getThirdPartySetting('feed_ex_xml_mapping', 'source_configuration'), 'Override mapping per feed source configuration is enabled');
  }

  /**
   * Tests override mapping form on feed.
   */
  public function testOverrideMappingFormOnFeed(): void {
    $this->createFieldWithStorage('field_alpha');

    // Create and configure feed type.
    $feed_type = $this->createFeedType([
      'parser' => 'xml',
      'custom_sources' => [
        'guid' => [
          'label' => 'guid',
          'value' => 'guid',
          'machine_name' => 'guid',
        ],
        'title' => [
          'label' => 'title',
          'value' => 'title',
          'machine_name' => 'title',
        ],
        'alpha' => [
          'label' => 'alpha',
          'value' => 'alpha',
          'machine_name' => 'alpha',
        ],
      ],
      'mappings' => array_merge($this->getDefaultMappings(), [
        [
          'target' => 'field_alpha',
          'map' => ['value' => 'alpha'],
          'unique' => ['alpha' => TRUE],
        ],
      ]),
      'processor_configuration' => [
        'authorize' => FALSE,
        'values' => [
          'type' => 'article',
        ],
      ],
    ]);
    $feed_type->setThirdPartySetting('feed_ex_xml_mapping', 'source', TRUE);
    $feed_type->setThirdPartySetting('feed_ex_xml_mapping', 'source_configuration', TRUE);
    $feed_type->save();

    // Checks form elements.
    $feed = $this->createFeed($feed_type->id(), [
      'source' => $this->resourcesUrl() . '/content.xml',
    ]);
    $this->drupalGet('/feed/' . $feed->id() . '/edit');
    // Assert override settings are on the page.
    $this->assertSession()->pageTextContains('XPath Parser Settings');

    // Assert form default values.
    $this->assertSession()->fieldValueEquals('plugin[parser][context]', '');
    $this->assertSession()->fieldValueEquals('plugin[parser][mappings][field_alpha][xpath][value]', '');
    $this->assertSession()->fieldValueEquals('plugin[parser][mappings][nid][xpath][value]', '');
    $this->assertSession()->fieldValueEquals('plugin[parser][mappings][feeds_item][xpath][guid]', '');
    $this->assertSession()->fieldValueEquals('plugin[parser][mappings][title][xpath][value]', '');
  }

  /**
   * Tests override mapping on feed.
   */
  public function testOverrideMappingOnFeed(): void {
    $this->createFieldWithStorage('field_alpha');

    // Create and configure feed type.
    $feed_type = $this->createFeedType([
      'parser' => 'xml',
      'parser_configuration' => [
        'context' => [
          'value' => '/items/item',
        ],
      ],
      'custom_sources' => [
        'guid' => [
          'label' => 'guid',
          'value' => 'guid',
          'machine_name' => 'guid',
        ],
        'title' => [
          'label' => 'title',
          'value' => 'title',
          'machine_name' => 'title',
        ],
        'alpha' => [
          'label' => 'alpha',
          'value' => 'alpha',
          'machine_name' => 'alpha',
        ],
      ],
      'mappings' => [
        [
          'target' => 'field_alpha',
          'map' => ['value' => 'alpha'],
        ],
        [
          'target' => 'feeds_item',
          'map' => ['guid' => 'guid'],
          'unique' => ['guid' => TRUE],
          'settings' => [],
        ],
        [
          'target' => 'title',
          'map' => ['value' => 'title'],
          'unique' => ['value' => TRUE],
          'settings' => [
            'language' => NULL,
          ],
        ],
      ],
      'processor_configuration' => [
        'authorize' => FALSE,
        'values' => [
          'type' => 'article',
        ],
      ],
    ]);
    $feed_type->setThirdPartySetting('feed_ex_xml_mapping', 'source', TRUE);
    $feed_type->setThirdPartySetting('feed_ex_xml_mapping', 'source_configuration', TRUE);
    $feed_type->save();

    // Import XML file.
    // Override mapping as in content.xml structure and make title not unique.
    $feed = $this->createFeed($feed_type->id(), [
      'source' => $this->resourcesUrl() . '/content.xml',
    ]);
    $config = $feed->get('config')->getValue();
    $config[0]['xml_parser'] = [
      'context' => '/elements/element',
      'mappings' => [
        [
          'target' => 'field_alpha',
          'map' => [
            'value' => 'xpath_field_alpha_value',
          ],
        ],
        [
          'target' => 'nid',
          'map' => [
            'value' => 'xpath_nid_value',
          ],
        ],
        [
          'target' => 'feeds_item',
          'map' => [
            'value' => 'xpath_feeds_item_guid',
          ],
        ],
        [
          'target' => 'title',
          'map' => [
            'value' => 'xpath_title_value',
          ],
        ],
      ],
      'sources' => [
        'xpath_field_alpha_value' => [
          'label' => 'field_alpha:value',
          'value' => 'alpha',
        ],
        'xpath_nid_value' => [
          'label' => 'nid:value',
          'value' => 'id',
        ],
        'xpath_feeds_item_guid' => [
          'label' => 'feeds_item:guid',
          'value' => 'id',
        ],
        'xpath_title_value' => [
          'label' => 'title:value',
          'value' => 'label',
        ],
      ],
      'custom_sources' => [
        'xpath_field_alpha_value' => [
          'label' => 'field_alpha:value',
          'value' => 'alpha',
          'machine_name' => 'xpath_field_alpha_value',
        ],
        'xpath_nid_value' => [
          'label' => 'nid:value',
          'value' => 'id',
          'machine_name' => 'xpath_nid_value',
        ],
        'xpath_feeds_item_guid' => [
          'label' => 'feeds_item:guid',
          'value' => 'id',
          'machine_name' => 'xpath_feeds_item_guid',
        ],
        'xpath_title_value' => [
          'label' => 'title:value',
          'value' => 'label',
          'machine_name' => 'xpath_title_value',
        ],
      ],
    ];
    $feed->get('config')->setValue($config);
    $feed->save();
    $this->batchImport($feed);

    // Check that 2 items were created.
    $page_text = Xss::filter($this->getSession()->getPage()->getContent(), []);
    $this->assertStringContainsString('Created 2 Article items.', $page_text);
    // Assert node values.
    $node1 = Node::load(1);
    $this->assertEquals('Lorem ipsum', $node1->getTitle());
    // Node 2 title is not unique as it is set in the feed type.
    $node2 = Node::load(2);
    $this->assertEquals('Lorem ipsum', $node2->getTitle());
  }

}
