<?php

namespace Drupal\material_inventory_totals\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Material Inventory Quick Update' block.
 *
 * @Block(
 *   id = "material_inventory_quick_update",
 *   admin_label = @Translation("Material Inventory Quick Update"),
 *   category = @Translation("MakeHaven"),
 * )
 */
class MaterialInventoryBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new MaterialInventoryBlock.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, FormBuilderInterface $form_builder, RouteMatchInterface $route_match, AccountInterface $current_user) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->formBuilder = $form_builder;
    $this->routeMatch = $route_match;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('form_builder'),
      $container->get('current_route_match'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = $this->routeMatch->getParameter('node');
    
    // If not a node route, check for views argument (like in /store/purchase/%).
    if (!$node instanceof NodeInterface) {
      $node_id = $this->routeMatch->getParameter('arg_0');
      if ($node_id && is_numeric($node_id)) {
        $node = \Drupal\node\Entity\Node::load($node_id);
      }
    }

    // Ensure we have a node and it is a material.
    if (!$node instanceof NodeInterface || $node->bundle() !== 'material') {
      return [];
    }
    
    // Check permission.
    if (!$this->currentUser->hasPermission('update material inventory counts')) {
      return [];
    }

    // Return the form.
    $form = $this->formBuilder->getForm('Drupal\material_inventory_totals\Form\InventoryCountForm', $node);
    
    // Add a wrapper and some styling to make it look like a prominent block.
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['material-inventory-quick-update-block', 'card', 'mb-4', 'border-primary'],
        'style' => 'background-color: #f8f9fa;',
      ],
      'form' => $form,
      '#attached' => [
        'library' => ['core/drupal.ajax'],
      ],
    ];
  }

}
