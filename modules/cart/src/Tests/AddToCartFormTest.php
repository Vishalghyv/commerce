<?php

/**
 * @file
 * Contains \Drupal\commerce_cart\Tests\AddToCartFormTest.
 */

namespace Drupal\commerce_cart\Tests;

use Drupal\commerce_order\Entity\LineItemInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Tests\OrderTestBase;
use Drupal\commerce_product\Entity\ProductAttribute;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\commerce_product\Entity\ProductVariationTypeInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Tests the add to cart form.
 *
 * @group commerce
 */
class AddToCartFormTest extends OrderTestBase {

  /**
   * The cart order to test against.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  protected $cart;

  /**
   * The cart manager.
   *
   * @var \Drupal\commerce_cart\CartManagerInterface
   */
  protected $cartManager;

  /**
   * The attribute field manager.
   *
   * @var \Drupal\commerce_product\ProductAttributeFieldManagerInterface
   */
  protected $attributeFieldManager;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [
    'commerce_cart',
    'node',
    'taxonomy',
  ];

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return array_merge([
      'administer products',
      'access content',
    ], parent::getAdministratorPermissions());
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->cart = \Drupal::service('commerce_cart.cart_provider')->createCart('default', $this->store);
    $this->cartManager = \Drupal::service('commerce_cart.cart_manager');
    $this->attributeFieldManager = \Drupal::service('commerce_product.attribute_field_manager');
  }

  /**
   * Test adding a product to the cart.
   */
  public function testProductAddToCartForm() {
    // Confirm that the initial add to cart submit works.
    $this->postAddToCart($this->variation->getProduct());
    $this->cart = Order::load($this->cart->id());
    $line_items = $this->cart->getLineItems();
    $this->assertLineItemInOrder($this->variation, $line_items[0]);

    // Confirm that the second add to cart submit increments the quantity
    // of the first line item..
    $this->postAddToCart($this->variation->getProduct());
    \Drupal::entityTypeManager()->getStorage('commerce_order')->resetCache();
    \Drupal::entityTypeManager()->getStorage('commerce_line_item')->resetCache();
    $this->cart = Order::load($this->cart->id());
    $line_items = $this->cart->getLineItems();
    $this->assertTrue(count($line_items) == 1, 'No additional line items were created');
    $this->assertLineItemInOrder($this->variation, $line_items[0], 2);
  }

  /**
   * Tests ability to expose line item fields on the add to cart form.
   */
  public function testExposedLineItemFields() {
    /** @var \Drupal\Core\Entity\Entity\EntityFormDisplay $line_item_form_display */
    $line_item_form_display = EntityFormDisplay::load('commerce_line_item.product_variation.add_to_cart');
    $line_item_form_display->setComponent('quantity', [
      'type' => 'number',
    ]);
    $line_item_form_display->save();
    // Get the existing product page and submit Add to cart form.
    $this->postAddToCart($this->variation->getProduct(), [
      'quantity[0][value]' => 3,
    ]);
    // Check if the quantity was increased for the existing line item.
    $this->cart = Order::load($this->cart->id());
    $line_items = $this->cart->getLineItems();
    $this->assertLineItemInOrder($this->variation, $line_items[0], 3);
  }

  /**
   * Tests that an attribute field is disabled if there's only one value.
   */
  public function testProductAttributeDisabledIfOne() {
    /** @var \Drupal\commerce_product\Entity\ProductVariationTypeInterface $variation_type */
    $variation_type = ProductVariationType::load($this->variation->bundle());

    $size_attributes = $this->createAttributeSet($variation_type, 'size', [
      'small' => 'Small',
      'medium' => 'Medium',
      'large' => 'Large',
    ]);
    $color_attributes = $this->createAttributeSet($variation_type, 'color', [
      'red' => 'Red',
    ]);

    // Reload the variation since we have new fields.
    $this->variation = ProductVariation::load($this->variation->id());
    $product = $this->variation->getProduct();

    // Update first variation to have the attribute's value.
    $this->variation->attribute_size = $size_attributes['small']->id();
    $this->variation->attribute_color = $color_attributes['red']->id();
    $this->variation->save();

    $attribute_values_matrix = [
      ['medium', 'red'],
      ['large', 'red'],
    ];
    $variations = [
      $this->variation,
    ];
    // Generate variations off of the attributes values matrix.
    foreach ($attribute_values_matrix as $key => $value) {
      $variation = $this->createEntity('commerce_product_variation', [
        'type' => $variation_type->id(),
        'sku' => $this->randomMachineName(),
        'price' => [
          'amount' => 999,
          'currency_code' => 'USD',
        ],
        'attribute_size' => $size_attributes[$value[0]]->id(),
        'attribute_color' => $color_attributes[$value[1]]->id(),
      ]);
      $variations[] = $variation;
      $product->variations->appendItem($variation);
    }
    $product->save();

    $this->drupalGet($product->toUrl());
    $selects = $this->xpath('//select[@data-drupal-selector=:data_drupal_selector and @disabled]', [
      ':data_drupal_selector' => 'edit-purchased-entity-0-attributes-attribute-color',
    ]);
    $this->assertTrue(isset($selects[0]));
  }

  /**
   * Tests adding a product to the cart when there are multiple variations.
   */
  public function testMultipleVariations() {
    /** @var \Drupal\commerce_product\Entity\ProductVariationTypeInterface $variation_type */
    $variation_type = ProductVariationType::load($this->variation->bundle());

    $color_attributes = $this->createAttributeSet($variation_type, 'color', [
      'red' => 'Red',
      'blue' => 'Blue',
    ]);
    $size_attributes = $this->createAttributeSet($variation_type, 'size', [
      'small' => 'Small',
      'medium' => 'Medium',
      'large' => 'Large',
    ]);

    // Reload the variation since we have new fields.
    $this->variation = ProductVariation::load($this->variation->id());
    $product = $this->variation->getProduct();

    // Update first variation to have the attribute's value.
    $this->variation->attribute_color = $color_attributes['red']->id();
    $this->variation->attribute_size = $size_attributes['small']->id();
    $this->variation->save();

    // The matrix is intentionally uneven, blue / large is missing.
    $attribute_values_matrix = [
      ['red', 'small'],
      ['red', 'medium'],
      ['red', 'large'],
      ['blue', 'small'],
      ['blue', 'medium'],
    ];
    $variations = [
      $this->variation,
    ];
    // Generate variations off of the attributes values matrix.
    foreach ($attribute_values_matrix as $key => $value) {
      $variation = $this->createEntity('commerce_product_variation', [
        'type' => $variation_type->id(),
        'sku' => $this->randomMachineName(),
        'price' => [
          'amount' => 999,
          'currency_code' => 'USD',
        ],
        'attribute_color' => $color_attributes[$value[0]],
        'attribute_size' => $size_attributes[$value[1]],
      ]);
      $variations[] = $variation;
      $product->variations->appendItem($variation);
    }
    $product->save();

    $this->drupalGet($product->toUrl());
    $this->assertAttributeSelected('edit-purchased-entity-0-attributes-attribute-color', $color_attributes['red']->id());
    $this->assertAttributeSelected('edit-purchased-entity-0-attributes-attribute-size', $size_attributes['small']->id());
    $this->assertAttributeExists('edit-purchased-entity-0-attributes-attribute-color', $color_attributes['blue']->id());
    $this->assertAttributeExists('edit-purchased-entity-0-attributes-attribute-size', $size_attributes['medium']->id());
    $this->assertAttributeExists('edit-purchased-entity-0-attributes-attribute-size', $size_attributes['large']->id());
    $this->postAddToCart($this->variation->getProduct());

    // Use AJAX to change the size to Medium, keeping the color on Red.
    $this->drupalPostAjaxForm(NULL, [
      'purchased_entity[0][attributes][attribute_size]' => $size_attributes['medium']->id(),
    ], 'purchased_entity[0][attributes][attribute_size]');
    $this->assertAttributeSelected('edit-purchased-entity-0-attributes-attribute-color', $color_attributes['red']->id());
    $this->assertAttributeSelected('edit-purchased-entity-0-attributes-attribute-size', $size_attributes['medium']->id());
    $this->assertAttributeExists('edit-purchased-entity-0-attributes-attribute-color', $color_attributes['blue']->id());
    $this->assertAttributeExists('edit-purchased-entity-0-attributes-attribute-size', $size_attributes['small']->id());
    $this->assertAttributeExists('edit-purchased-entity-0-attributes-attribute-size', $size_attributes['large']->id());

    // Use AJAX to change the color to Blue, keeping the size on Medium.
    $this->drupalPostAjaxForm(NULL, [
      'purchased_entity[0][attributes][attribute_color]' => $color_attributes['blue']->id(),
    ], 'purchased_entity[0][attributes][attribute_color]');
    $this->assertAttributeSelected('edit-purchased-entity-0-attributes-attribute-color', $color_attributes['blue']->id());
    $this->assertAttributeSelected('edit-purchased-entity-0-attributes-attribute-size', $size_attributes['medium']->id());
    $this->assertAttributeExists('edit-purchased-entity-0-attributes-attribute-color', $color_attributes['red']->id());
    $this->assertAttributeExists('edit-purchased-entity-0-attributes-attribute-size', $size_attributes['small']->id());
    $this->assertAttributeDoesNotExist('edit-purchased-entity-0-attributes-attribute-size', $size_attributes['large']->id());

    $this->postAddToCart($product, [
      'purchased_entity[0][attributes][attribute_color]' => $color_attributes['blue']->id(),
      'purchased_entity[0][attributes][attribute_size]' => $size_attributes['medium']->id(),
    ]);
    $this->cart = Order::load($this->cart->id());
    $line_items = $this->cart->getLineItems();
    $this->assertLineItemInOrder($variations[0], $line_items[0]);
    $this->assertLineItemInOrder($variations[5], $line_items[1]);
  }

  /**
   * Creates an attribute field and set of attribute values.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationTypeInterface $variation_type
   *   The variation type.
   * @param string $name
   *   The attribute field name.
   * @param array $options
   *   Associative array of key name values. [red => Red].
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Array of attribute entities.
   */
  protected function createAttributeSet(ProductVariationTypeInterface $variation_type, $name, array $options) {
    $attribute = ProductAttribute::create([
      'id' => $name,
      'label' => ucfirst($name),
    ]);
    $attribute->save();
    $this->attributeFieldManager->createField($attribute, $variation_type->id());

    $attribute_set = [];
    foreach ($options as $key => $value) {
      $attribute_set[$key] = $this->createAttributeValue($name, $value);
    }

    return $attribute_set;
  }

  /**
   * Creates an attribute value.
   *
   * @param string $attribute
   *   The attribute id.
   * @param string $name
   *   The attribute value name.
   *
   * @return \Drupal\commerce_product\Entity\ProductAttributeValueInterface
   *   The attribute value entity.
   */
  protected function createAttributeValue($attribute, $name) {
    $attribute_value = $this->createEntity('commerce_product_attribute_value', [
      'attribute' => $attribute,
      'name' => $name,
    ]);
    $attribute_value->save();

    return $attribute_value;
  }

  /**
   * Posts the add to cart form for a product.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product.
   * @param array $edit
   *   The form array.
   *
   * @throws \Exception
   */
  protected function postAddToCart(ProductInterface $product, array $edit = []) {
    $this->drupalGet('product/' . $product->id());
    $this->assertField('edit-submit', t('Add to cart button exists.'));
    $this->drupalPostForm(NULL, $edit, t('Add to cart'));
  }

  /**
   * Asserts that an attribute option is selected.
   *
   * @param string $selector
   *   The element selector.
   * @param string $option
   *   The option.
   */
  protected function assertAttributeSelected($selector, $option) {
    $options = $this->xpath('//select[@data-drupal-selector=:data_drupal_selector]//option[@selected="selected" and @value=:option]', [
      ':data_drupal_selector' => $selector,
      ':option' => $option,
    ]);
    $this->assertFalse(empty($options), 'The attribute is selected');
  }

  /**
   * Asserts that an attribute option does exist.
   *
   * @param string $selector
   *   The element selector.
   * @param string $option
   *   The option.
   */
  protected function assertAttributeExists($selector, $option) {
    $options = $this->xpath('//select[@data-drupal-selector=:data_drupal_selector]//option[@value=:option]', [
      ':data_drupal_selector' => $selector,
      ':option' => $option,
    ]);
    $this->assertFalse(empty($options), 'The attribute is not available');
  }

  /**
   * Asserts that an attribute option does not exist.
   *
   * @param string $selector
   *   The element selector.
   * @param string $option
   *   The option.
   */
  protected function assertAttributeDoesNotExist($selector, $option) {
    $options = $this->xpath('//select[@data-drupal-selector=:data_drupal_selector]//option[@value=:option]', [
      ':data_drupal_selector' => $selector,
      ':option' => $option,
    ]);
    $this->assertTrue(empty($options), 'The attribute is not available');
  }

  /**
   * Assert the line item in the order is correct.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The purchased product variation.
   * @param \Drupal\commerce_order\Entity\LineItemInterface $line_item
   *   The line item.
   * @param int $quantity
   *   The quantity.
   */
  protected function assertLineItemInOrder(ProductVariationInterface $variation, LineItemInterface $line_item, $quantity = 1) {
    $this->assertEqual($line_item->getTitle(), $variation->getLineItemTitle());
    $this->assertTrue(($line_item->getQuantity() == $quantity), t('The product @product has been added to cart with quantity of @quantity.', [
      '@product' => $line_item->getTitle(),
      '@quantity' => $line_item->getQuantity(),
    ]));
  }

}