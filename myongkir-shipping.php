<?php
/**
 * @package myongkir
 * @category Core
 * @author eezhal
 */

require_once 'class/cart-weight.php';

class MyOngkir_Shipping_Method extends WC_Shipping_Method {
	/**
	 * Constructor for shipping class
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {
		$this->id                 = 'myongkir_shipping'; // Id for your shipping method. Should be uunique.
		$this->method_title       = __( 'MyOngkir Method' );  // Title shown in admin
		$this->method_description = __( 'WooCommerce add-on for indonesian local shipping.'); // Description shown in admin

		$this->init();
	}

	/**
	 * Init your settings
	 *
	 * @access public
	 * @return void
	 */
	function init() {
		// Load the settings API
		$this->init_form_fields(); // This is part of the settings API. Override the method to add your own settings
		$this->init_settings(); // This is part of the settings API. Loads settings you previously init.

		$this->title = "MyOngkir Shipping Method"; // This can be added as an setting but for this example its forced.
		$this->enabled = $this->settings['enabled']; // if api key not setted, enabled = false
		$this->api_key = $this->settings['api_key'];

		$this->base_city = $this->settings['base_city'];

		global $woocommerce;
		global $myongkir;
		// Save settings in admin if you have any defined
		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
		// handle frontend cost request
		add_action( 'wp_ajax_get_costs', 'calculate_shipping' );
		add_action( 'woocommerce_review_order_after_shipping', function() {
			echo '<tr class="order-total">
				<th>Weight Total</th>
				<td><strong><span class="amount">'.  WC()->cart->cart_contents_weight . ' ' . get_option('woocommerce_weight_unit') .'</span></strong></td>
			  </tr>';		
		});
	}

	function isset_rajaongkir_api() {
		$rajaongkir_api = get_option('woocommerce_rajaongkir_api_key');

		if( $rajaongkir_api == '' || $rajaongkir_api == null) {
			return false;
		}

		return true;
	}

	function admin_options() {
	?>
		<h2><?php _e('MyOngkir Shipping','woocommerce'); ?></h2>
		<table class="form-table">
			<?php
				$rajaongkir_api = get_option('woocommerce_rajaongkir_api_key');

				if( !$this->isset_rajaongkir_api() ) {
			?>
				<div id="message" class="error fade"><p><strong>Please Set RajaOngkir API Key first, on woocommerce general settings.</strong></p></div>
			<?php
				} else {
					 $this->generate_settings_html();
				}

			?>
		</table> <?php
	}

	function init_form_fields() {
		global $woocommerce;
		global $myongkir;
		// $province_id = $myongkir->convert_to_province_id( $woocommerce->countries->get_base_state() );
		if ( $this->isset_rajaongkir_api() ) {
		    $this->form_fields = array(
		    	'enabled' => array(
				    'title' => 'Enable/Disable',
				    'type' => 'checkbox', // pilih salah satu
				    'default' => 'no',
				    'label' => 'Enable this shipping method' // checkbox only

					),
				'base_city' => array(
			         'title' => __( 'Your Base City', 'woocommerce' ),
			         'type' => 'select',
			         'id' => 'woocommerce_myongkir_base_city', // generate woocommerce_myongkir_base_city
			         'class' => 'chosen_select',
			         'description' => __( 'This is your store location, used for origin package. Require api key is setted, before showing available city.', 'woocommerce' ),
			         'options' => $myongkir->get_cities( $woocommerce->countries->get_base_state() )
			    	),
					'courier' => array(
				         'title' => __( 'Your couriers', 'woocommerce' ),
				         'type' => 'select',
				         'id' => 'woocommerce_myongkir_couriers', // generate woocommerce_myongkir_base_city
				         'class' => 'chosen_select',
				         'description' => __( 'This is the avaibility of yours couriers', 'woocommerce' ),
				         'options' => array('all'=>'All','jne'=>'JNE', 'pos'=>'POS','tiki'=>'Tiki')
				    	)
		    );
		}
	}	

	/**
	 * calculate_shipping function.
	 *
	 * @access public
	 * @param mixed $package
	 * @return void
	 */
	public function calculate_shipping( $package ) {
		global $woocommerce;
		global $myongkir;

		$current_shipping_city = $woocommerce->customer->get_shipping_city();		
		$current_cart_weight = $woocommerce->cart->cart_contents_weight;

		$minimum_weight = get_option('woocommerce_myongkir_minimum_weight');

		// not setted yet,woocommerce_myongkir_minimum_weight
		if (! $minimum_weight) {
			$minimum_weight = 1;
		}

		if ($current_cart_weight < $minimum_weight) {
			$current_cart_weight = $minimum_weight;
		}

		$weight_unit = get_option('woocommerce_weight_unit');			
		// convert to gram
		$current_cart_weight = CartWeight::toGram($current_cart_weight, $weight_unit);

		// $current_currency = get_woocommerce_currency();

		$courier = $this->settings['courier'];

		// echo '<pre>';
		// var_dump(array(
		// 	'minimum_weight'      => $minimum_weight,
		// 	'current_cart_weight' => $current_cart_weight,
		// 	'weight_unit'         => $weight_unit,
		// 	'shipping_city'       => $current_shipping_city,
		// ));
		// echo '</pre>';
		
		$shipping_couriers = array(); // results		

		switch ($courier) {
			case 'jne':				
			case 'tiki':				
			case 'pos':
				$shipping_couriers = $this->get_available_shippings(
					$current_shipping_city, 
					$current_cart_weight, 
					$courier
				);

				break;
			default:
				$couriers = ['jne', 'tiki', 'pos'];				

				foreach ($couriers as $courier) {
					$result = $this->get_available_shippings(
						$current_shipping_city, 
						$current_cart_weight, $courier
					);

					$shipping_couriers[] = $result[0];
				}

				break;
		}		
		
		if( $shipping_couriers ) {
			foreach( $shipping_couriers as $courier ) {
				foreach ( $courier['costs'] as $item ) {
					foreach ( $item['cost'] as $cost ) {
						$this->add_rate(
							array(
								'id' => $this->id . "_" . $courier['name'] . "_" . $item['service'] ,
								'label' => $courier['name'] . " " . $item['service'],
								'cost' => $cost['value'],
								'calc_tax' => 'per_item'
							)
						);
					}
				}
			}
		}

	}

	private function get_available_shippings( $shipping_city, $cart_weight, $courier ) {
		if( $origin_city = $this->settings['base_city'] ) {
			global $myongkir;				

			return $myongkir->get_costs(
				$origin_city, 
				$shipping_city,
				$cart_weight, 
				$courier 
			);
		}
		echo $this->settings['base_city'];
		// break shipping calculation if origin city not (base_city) set
		echo "DEBUG: Break shipping calculation if origin city not (base_city) set <br>";
		var_dump($this->settings['base_city']);

	}
}


?>
