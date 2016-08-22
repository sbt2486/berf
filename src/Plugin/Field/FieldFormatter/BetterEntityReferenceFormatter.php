<?php

namespace Drupal\berf\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceEntityFormatter;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'configurable entity reference' field formatter.
 *
 * @FieldFormatter(
 *   id = "better_entity_reference_view",
 *   label = @Translation("Advanced Rendered Entity"),
 *   description = @Translation("Display a configured set of referenced entities using entity_view()."),
 *   field_types = {
 *     "entity_reference",
 *     "file",
 *     "image"
 *   }
 * )
 */
class BetterEntityReferenceFormatter extends EntityReferenceEntityFormatter {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('logger.factory'),
      $container->get('entity_type.manager'),
      $container->get('entity_display.repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return array(
      'selection_mode' => 'all',
      'amount' => 1,
      'offset' => 0,
    ) + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $elements['selection_mode'] = [
      '#type' => 'select',
      '#options' => $this->getSelectionModes(),
      '#title' => t('Selection mode'),
      '#default_value' => $this->getSetting('selection_mode'),
      '#required' => TRUE,
    ];

    $elements['amount'] = [
      '#type' => 'number',
      '#step' => 1,
      '#min' => 1,
      '#title' => t('Amount of displayed entities'),
      '#default_value' => $this->getSetting('amount'),
      '#states' => [
        'visible' => [
          ':input[name="fields[field_images][settings_edit_form][settings][selection_mode]"]' => ['value' => 'advanced'],
        ],
      ],
    ];

    $elements['offset'] = [
      '#type' => 'number',
      '#step' => 1,
      '#min' => 0,
      '#title' => t('Offset'),
      '#default_value' => $this->getSetting('offset'),
      '#states' => [
        'visible' => [
          ':input[name="fields[field_images][settings_edit_form][settings][selection_mode]"]' => ['value' => 'advanced'],
        ],
      ],
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    $summary[] = t(
      'Selection mode: @mode',
      ['@mode' => $this->getSelectionModes()[$this->getSetting('selection_mode')]]
    );
    if ($this->getSetting('selection_mode') == 'advanced') {
      $amount = $this->getSetting('amount') ? $this->getSetting('amount') : 1;
      $summary[] = \Drupal::translation()->formatPlural(
        $amount,
        'Showing @amount entity starting at @offset',
        'Showing @amount entities starting at @offset', [
        '@amount' => $amount,
        '@offset' => $this->getSetting('offset') ? $this->getSetting('offset') : 0,
      ]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    switch ($this->getSetting('selection_mode')) {
      case 'advanced':
        $elements = $this->getAdvancedSelection(
          $items,
          $langcode,
          $this->getSetting('amount'),
          $this->getSetting('offset')
        );
        break;

      case 'first':
        $elements = $this->getAdvancedSelection(
          $items,
          $langcode,
          1,
          0
        );
        break;

      case 'last':
        $elements = $this->getAdvancedSelection(
          $items,
          $langcode,
          1,
          $items->count() - 1
        );
        break;

      default;
        $elements = parent::viewElements($items, $langcode);
        break;

    }

    return $elements;
  }

  /**
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   * @param $langcode
   * @return array
   */
  protected function getAdvancedSelection(FieldItemListInterface $items, $langcode, $amount, $offset) {
    $elements = [];
    $count = 0;

    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $entity) {

      // Show entities if offset was reached and amount limit isn't reached yet.
      if ($delta >= $offset && $count < $amount) {
        // Due to render caching and delayed calls, the viewElements() method
        // will be called later in the rendering process through a '#pre_render'
        // callback, so we need to generate a counter that takes into account
        // all the relevant information about this field and the referenced
        // entity that is being rendered.
        $recursive_render_id = $items->getFieldDefinition()
            ->getTargetEntityTypeId()
          . $items->getFieldDefinition()->getTargetBundle()
          . $items->getName()
          . $entity->id();

        if (isset(static::$recursiveRenderDepth[$recursive_render_id])) {
          static::$recursiveRenderDepth[$recursive_render_id]++;
        }
        else {
          static::$recursiveRenderDepth[$recursive_render_id] = 1;
        }

        // Protect ourselves from recursive rendering.
        if (static::$recursiveRenderDepth[$recursive_render_id] > static::RECURSIVE_RENDER_LIMIT) {
          $this->loggerFactory->get('entity')
            ->error('Recursive rendering detected when rendering entity %entity_type: %entity_id, using the %field_name field on the %bundle_name bundle. Aborting rendering.', [
              '%entity_type' => $entity->getEntityTypeId(),
              '%entity_id' => $entity->id(),
              '%field_name' => $items->getName(),
              '%bundle_name' => $items->getFieldDefinition()->getTargetBundle(),
            ]);
          return $elements;
        }

        $view_builder = $this->entityTypeManager->getViewBuilder($entity->getEntityTypeId());
        $elements[$delta] = $view_builder->view(
          $entity,
          $this->getSetting('view_mode'),
          $entity->language()->getId()
        );

        // Add a resource attribute to set the mapping property's value to the
        // entity's url. Since we don't know what the markup of the entity will
        // be, we shouldn't rely on it for structured data such as RDFa.
        if (!empty($items[$delta]->_attributes) && !$entity->isNew() && $entity->hasLinkTemplate('canonical')) {
          $items[$delta]->_attributes += array('resource' => $entity->toUrl()->toString());
        }

        $count++;
      }
    }

    return $elements;
  }

  /**
   * @param $items
   * @param $langcode
   */
  protected function getSelectionModes() {
    return [
      'all' => t('All'),
      'first' => t('First entity'),
      'last' => t('Last entity'),
      'advanced' => t('Advanced'),
    ];
  }

}
