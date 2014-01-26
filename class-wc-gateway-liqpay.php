<?php
/*
Plugin Name: WooCommerce Liqpay 
Plugin URI: http://woothemes.com/woocommerce
Description: Extends WooCommerce with an Liqpay gateway.
Version: 2.01
Author: Denys Kanunnikov
Author URI: http://freelancehunt.com/freelancer/dargentstore.html
*/
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('plugins_loaded', 'woocommerce_init', 0);

function woocommerce_init() {

	if ( !class_exists( 'WC_Payment_Gateway' ) ) return;
    
	class WC_Gateway_Liqpay extends WC_Payment_Gateway{

        public function __construct(){
			
			global $woocommerce;
			
            $this->id = 'liqpay';
            $this->has_fields         = false;
            $this->method_title 	  = __( 'Liqpay', 'woocommerce' );
            $this->method_description = __( 'Liqpay', 'woocommerce' );
			$this->init_form_fields();
            $this->init_settings();
            $this->title 			  =  $this->get_option( 'title' );
            $this->description        =  $this->get_option('description');
            $this->merchant_id        =  $this->get_option('merchant_id');
            $this->merchant_sig       =  $this->get_option('merchant_sig');
			$this->phone              =  $this->get_option('phone');
			
            // Actions
            add_action( 'woocommerce_receipt_liqpay', array( $this, 'receipt_page' ) );
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			
			// Payment listener/API hook
			add_action( 'woocommerce_api_wc_gateway_liqpay', array( $this, 'check_ipn_response' ) );
            
            if (!$this->is_valid_for_use()){
                $this->enabled = false;
            }
        }
			
		public function admin_options() {

		?>
		<h3><?php _e( 'Liqpay', 'woocommerce' ); ?></h3>
        
        <?php if ( $this->is_valid_for_use() ) : ?>
        
			<table class="form-table">
			<?php
    			
    			$this->generate_settings_html();
			?>

			</table>
            
		<?php else : ?>
		<div class="inline error"><p><strong><?php _e('Шлюз отключен', 'woocommerce'); ?></strong>: <?php _e('Liqpay не поддерживает валюты Вашего магазина.', 'woocommerce' ); ?></p></div>
		<?php
			endif;
		}
		
        public function init_form_fields(){

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __( 'Включить/Отключить', 'woocommerce' ),
                    'type' => 'checkbox',
                    'label' => __( 'Включить', 'woocommerce' ),
                    'default' => 'yes'
                                ),
                'title' => array(
                    'title' => __( 'Заголовок', 'woocommerce' ),
                    'type' => 'text',
                    'description' => __( 'Заголовок, который отображается на странице оформления заказа', 'woocommerce' ),
                    'default' => 'Оплата картой',
                    'desc_tip' => true,
                                ),
                'description' => array(
                    'title' => __( 'Описание', 'woocommerce' ),
                    'type' => 'textarea',
                    'description' => __( 'Описание, которое отображается в процессе выбора формы оплаты', 'woocommerce' ),
                    'default' => __( 'Оплатить через электронную платежную систему Liqpay', 'woocommerce' ),
                ),
                'merchant_id' => array(
                    'title' => __( 'Merchant ID', 'woocommerce' ),
                    'type' => 'text',
                    'description' => __( 'Уникальный идентификатор магазина в системе Единная Касса.', 'woocommerce' ),
                ),
                'merchant_sig' => array(
                    'title' => __( 'Подпись', 'woocommerce' ),
                    'type' => 'text',
                    'description' => __( 'Подпись для остальных операций', 'woocommerce' ),
                ),
                'phone' => array(
                    'title' => __( 'Tелефон', 'woocommerce' ),
                    'type' => 'text',
                    'description' => __( 'Телефон получателя', 'woocommerce' ),
                ),                
            );
        }
        
        
        function is_valid_for_use(){
            if (!in_array(get_option('woocommerce_currency'), array('RUB', 'UAH', 'USD'))){
                return false;
            }
		return true;
	}

        function process_payment($order_id){
                $order = new WC_Order($order_id);
				return array(
        			'result' => 'success',
        			'redirect'	=> add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
        		);
                
         }

        public function receipt_page($order){
            echo '<p>'.__('Спасибо за Ваш заказ, пожалуйста, нажмите кнопку ниже, чтобы заплатить.', 'woocommerce').'</p>';
            echo $this->generate_form($order);
        }

        public function generate_form($order_id){
			global $woocommerce;
			
            $order = new WC_Order( $order_id );
            $action_adr = "https://www.liqpay.com/?do=clickNbuy";
            $result_url = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'wc_gateway_liqpay', home_url( '/' ) ) );
			
            switch (get_woocommerce_currency()) {
				case 'UAH':
					$currency = 'UAH';
					break;
				case 'USD':
					$currency = 'USD';
					break;
				case 'RUB':
					$currency = 'RUB';
					break;    
			}

			$xml="<request>
					  <version>1.2</version>
					  <merchant_id>".esc_attr($this->merchant_id)."</merchant_id>
					  <result_url>".esc_url($result_url)."</result_url>
					  <server_url></server_url>
					  <order_id>".esc_attr($order_id)."</order_id>
					  <amount>".esc_attr($order->order_total)."</amount>
					  <currency>".esc_attr($currency)."</currency>
					  <description>Оплата за заказ - ".$order_id."</description>
					  <to>".esc_attr($this->phone)."</to>
					  <pay_way>card,liqpay</pay_way>
					  <goods_id></goods_id>
				</request>";


            $sign = base64_encode(sha1($this->merchant_sig.$xml.$this->merchant_sig,1));
            $xml_encoded=base64_encode($xml);

            return
                    '<form action="'.esc_url($action_adr).'" method="POST">'.
					'<input type="hidden" name="operation_xml" value="'.esc_attr($xml_encoded).'" />'.
					'<input type="hidden" name="signature" value="'.esc_attr($sign).'" />'.
                    '<input type="submit" class="button alt" id="submit_liqpay_button" value="'.__('Оплатить', 'woocommerce').'" /> <a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Отказаться от оплаты & вернуться в корзину', 'woocommerce').'</a>'."\n".
                    '</form>';

        }

        function check_ipn_response(){
			
            global $woocommerce;
            

            if (isset($_POST['operation_xml'])  )
            {	
				$xml_decoded = base64_decode($_POST['operation_xml']);
                $hash = base64_encode(sha1($this->merchant_sig.$xml_decoded.$this->merchant_sig,1));
				$result = new SimpleXMLElement($xml_decoded);

              if ($hash == $_POST['signature'])
              {
                  $order_id = (string)$result->order_id;
                  $order = new WC_Order($order_id );
		  if( (string)$result->status == 'success')
                   	$order->update_status('on-hold', __('Платеж успешно оплачен', 'woocommerce'));
				  if((string) $status == 'wait_secure')
					$order->update_status('on-hold', __('Ожидание оплаты', 'woocommerce'));
                                  if((string) $status == 'failure')
                                   $order->update_status('failed', __('Платеж не оплачен', 'woocommerce'));
                  $woocommerce->cart->empty_cart();
                  wp_redirect(add_query_arg('key', $order->order_key, add_query_arg('order', $order_id , get_permalink(get_option('woocommerce_thanks_page_id')))));
                  exit;
              }
              else
              {
                  $order_id = $result->order_id;
                  $order = new WC_Order($order_id );
                  $order->update_status('failed', __('Платеж не оплачен', 'woocommerce'));
                  wp_redirect($order->get_cancel_order_url());
                  exit;
              }
            }
            else
            {
                wp_die('IPN Request Failure');

            }

        }

    }

	
	function woocommerce_add_liqpay_gateway($methods) {
		$methods[] = 'WC_Gateway_Liqpay';
		return $methods;
	}
	
	add_filter('woocommerce_payment_gateways', 'woocommerce_add_liqpay_gateway' );
	
}


?>
