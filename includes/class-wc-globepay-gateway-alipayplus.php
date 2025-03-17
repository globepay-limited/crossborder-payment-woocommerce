<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Setup our Gateway's id, description and other values
#[AllowDynamicProperties]
class WC_GlobePay_Alipayplus extends WC_Payment_Gateway{


	public function __construct(){
		// The global ID for this Payment method
		$this->id = "globepay_alipayplus";

		// The Title shown on the top of the Payment Gateways Page next to all the other Payment Gateways
		$this->method_title='GlobePay - AlipayPlus';

		// The description for this Payment Gateway, shown on the actual Payment options page on the backend
		$this->method_description=sprintf( __( 'All other general GlobePay settings can be adjusted <a href="%s">here</a>.', 'woocommerce-gateway-globepay' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=globepay' ) );

		// If you want to show an image next to the gateway's name on the frontend, enter a URL to an image.
		// $this->icon = GLOBEPAY_URL. '/assets/images/alipay-logo.png';

        $this->has_fields = false;

        $main_settings              = get_option( 'woocommerce_globepay_settings' );

        $this->partner_code             = ! empty( $main_settings['partner_code'] ) ? $main_settings['partner_code'] : '';
        $this->credential_code          = ! empty( $main_settings['credential_code'] ) ? $main_settings['credential_code'] : '';
        $this->instructions             = ! empty( $main_settings['instructions'] ) ? $main_settings['instructions'] : '';
        $this->transport_protocols      = ! empty( $main_settings['transport_protocols'] ) ? $main_settings['transport_protocols'] : '';

		// Supports
		$this->supports[]='refunds';

		// This basically defines your settings which are then loaded with init_settings()
		$this->init_form_fields ();

		// After init_settings() is called, you can get the settings and load them into variables, e.g:
		$this->init_settings ();

		// Turn these settings into variables we can use
		foreach ( $this->settings as $setting_key => $value ) {
			$this->$setting_key = $value;
		}

		if ( is_admin() ) {
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		add_action( 'woocommerce_api_wc_globepay_notify', array( 'GlobePay_API', 'wc_globepay_notify' ) );
		add_action ( 'wp_enqueue_scripts', array($this, 'my_admin_scripts' ) );
		add_action( 'woocommerce_thankyou_globepay', array($this, 'thankyou_page') );
	}

	public function my_admin_scripts() {
		wp_register_style( 'globepay-style', plugins_url( 'assets/css/globepay-style.css', GLOBEPAY_FILE ), array());
		wp_enqueue_style( 'globepay-style' );
	}

	public function get_icon() {

		$icons_str = '<img src="' . GLOBEPAY_URL . '/assets/images/alipayplus-logo.svg" class="right-float" alt="AlipayPlus" style="height: 20px;padding: 10px" />';

		return apply_filters( 'woocommerce_gateway_icon', $icons_str, $this->id );
	}


	public function is_available() {

		if(!$this->partner_code || !$this->credential_code ){
			return false;
		}

		if($this->enabled == 'no'){
			return false;
		}

		return $this->enabled;
	}


	function init_form_fields() {
		$this->form_fields = array (
				'enabled' => array (
						'title' => 'Enable/Disable',
						'type' => 'checkbox',
						'label' =>'Enable GlobePay - AlipayPlus',
						'default' => 'no',
						'section'=>'default'
				),
				'title' => array (
						'title' => 'Title',
						'type' => 'text',
						'default' => 'AlipayPlus',
						'desc_tip' => true,
						'css' => 'width:400px',
						'section'=>'default'
				),
				'description' => array (
						'title' => 'Description',
						'type' => 'textarea',
						'default' => 'Use Alipay to scan QR Code to complete payment',
						'desc_tip' => true,
						'css' => 'width:400px',
						'section'=>'default'
				),
		        'qrcode_redirect'=>array(
					'title' => 'Qrcode Location',
					'type' => 'select',
					'css' => 'width:400px',
					'options'=>array(
						'0'=>'GlobePay.co',
						'1'=>'AlipayPlus Official Page'
					)
				)


			);
	}


	/**
	 * Output for the order received page.
	 */
	public function thankyou_page() {
		if ( $this->instructions ) {
			echo wpautop( wptexturize( $this->instructions ) );
		}
	}

	/**
	 * Add content to the WC emails.
	 *
	 * @access public
	 * @param WC_Order $order
	 * @param bool $sent_to_admin
	 * @param bool $plain_text
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
	    $method = method_exists($order ,'get_payment_method')?$order->get_payment_method():$order->payment_method;
		if ( $this->instructions && ! $sent_to_admin && $this->id === $method ) {
			echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
		}
	}



	public function process_payment($order_id){
		$order = new WC_Order($order_id);
		if(!$order||!$order->needs_payment()){
			return array(
	             'result'   => 'success',
	             'redirect' => $this->get_return_url($order)
	         );
		}

		$partner_code = $this->partner_code;
		$credential_code = $this->credential_code;


		try {
            $result = GlobePay_API::generate_globepay_order($order,'AlipayPlus',"https://pay.globepay.co/api/v1.0/h5_payment/partners/%s/orders/%s");
            $time=time().'000';
			$nonce_str = substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0,10);
			$valid_string="$partner_code&$time&$nonce_str&$credential_code";
			$sign=strtolower(hash('sha256',$valid_string));


			return array(
				'result'   => 'success',
				'redirect' =>$result->pay_url.(strpos($result->pay_url, '?')==false?'?':'&')."time=$time&nonce_str=$nonce_str&sign=$sign&redirect=".urlencode($this->get_return_url($order))
			);

		} catch (Exception $e) {
			throw $e;
		}

	}


	public function process_refund( $order_id, $amount = null, $reason = ''){
		$order = new WC_Order ($order_id );
		if(!$order){
			return new WP_Error( 'invalid_order', 'Wrong Order' );
		}

		$total = ( int ) ($order->get_total () * 100);
		$amount = ( int ) ($amount * 100);
		if($amount<=0||$amount>$total){
			return new WP_Error( 'invalid_order','Invalid Amount ');
		}

		$ooid = get_post_meta($order_id, 'globepay_order_id',true);
		$refund_id=time();


		if($amount == $total){
			// check real fee of order (include service charge)
			$queryresult = GlobePay_API::query_order_status($ooid);
			$amount = $queryresult->real_fee;
		}

		$resArr = GlobePay_API::globepay_refund($amount,$ooid,$refund_id);

		$partner_refund_id = "";
		$partner_refund_id = $resArr->partner_refund_id;


		if(!$resArr){
			return new WP_Error( 'refuse_error', $result);
		}

		//Check if refund status is waiting, if yes, check again until status changes
    if($resArr->result_code == 'WAITING') {

      do{
        $refundResult = GlobePay_API::query_refund_status($ooid, $partner_refund_id);

        if($refundResult->result_code == 'FINISHED') {
          return true;
        }

        sleep(5); // Make it sleep 5 seconds so as to not spam the server
      }
      while($refundResult->result_code == 'WAITING');
    }

		if($resArr->result_code!='SUCCESS' && $resArr->result_code!='FINISHED'){
			return new WP_Error( 'refuse_error', sprintf('ERROR CODE:%s',empty($resArr->result_code)?$resArr->return_code:$resArr->result_code));
		}
		return true;
	}


}
