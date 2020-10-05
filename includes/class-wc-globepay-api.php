<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GlobePay_API{

    private static $partner_code = '';

    private static $credential_code =  '';

    private static $transport_protocols = '';

	/**
	 * Set partner_code.
	 * @param string
	 */
	public static function set_partner_code( $partner_code ) {
		self::$partner_code = $partner_code;
	}

	/**
	 * Get partner_code.
	 * @return string
	 */
	public static function get_partner_code() {
		if ( ! self::$partner_code ) {
            $options = get_option( 'woocommerce_globepay_settings' );


			if ( isset( $options['partner_code']) ) {
				self::set_partner_code( $options['partner_code'] );
			}
		}
		return self::$partner_code;
    }

    /**
	 * Set credential_code.
	 * @param string
	 */
	public static function set_credential_code( $credential_code ) {
		self::$credential_code = $credential_code;
	}

	/**
	 * Get credential_code.
	 * @return string
	 */
	public static function get_credential_code() {
		if ( ! self::$credential_code ) {
			$options = get_option( 'woocommerce_globepay_settings' );

			if ( isset( $options['credential_code']) ) {
				self::set_credential_code( $options['credential_code'] );
			}
		}
		return self::$credential_code;
	}


     /**
	 * Set transport_protocols.
	 * @param string
	 */
	public static function set_transport_protocols( $transport_protocols ) {
		self::$transport_protocols = $transport_protocols;
	}

	/**
	 * Get transport_protocols.
	 * @return string
	 */
	public static function get_transport_protocols() {
		if ( ! self::$transport_protocols ) {
			$options = get_option( 'woocommerce_globepay_settings' );

			if ( isset( $options['transport_protocols']) ) {
				self::set_transport_protocols( $options['transport_protocols'] );
			}
		}
		return self::$transport_protocols;
	}


    public static function generate_globepay_order($order,$channel,$api_uri){

		$currency =method_exists($order, 'get_currency') ?$order->get_currency():$order->currency;

		if($currency != 'CNY' && $currency != 'GBP'){
			throw new Exception('Current Payment Method Only Accept Currency: CNY & GBP');
		}


        $order_id = method_exists($order, 'get_id')? $order->get_id():$order->id;

        $partner_code = self::get_partner_code();

        $time=time().'000';

	    $nonce_str = substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0,10);
        $credential_code = self::get_credential_code();


	    $valid_string="$partner_code&$time&$nonce_str&$credential_code";
	    $sign=strtolower(hash('sha256',$valid_string));

	    $new_order_id = date_i18n("ymdHis").$order_id;
		update_post_meta($order_id, 'globepay_order_id', $new_order_id);
        update_post_meta( $order_id, 'channel', $channel );



	    $url = sprintf($api_uri,$partner_code,$new_order_id);

	    $url.="?time=$time&nonce_str=$nonce_str&sign=$sign";
	    $head_arr = array();
	    $head_arr[] = 'Content-Type: application/json';
	    $head_arr[] = 'Accept: application/json';
	    $head_arr[] = 'Accept-Language: '.get_locale();

	    $data =new stdClass();
	    $data->description = self::get_order_title($order);
		$data->price = (int)($order->get_total()*100);

		$data->channel = $channel;


        $data->currency =$currency;


		// if choose currency GBP
	    if($data->price < 1 && $currency == 'GBP'){
	        throw new Exception('The payment amount is too little!');
		}

		// if choose currency CNY
		if($data->price < 6 && $currency == 'CNY'){
	        throw new Exception('The payment amount is too little!');
	    }


		$data->notify_url=  get_site_url().'/?wc-api=wc_globepay_notify';


        $transport_protocols = self::get_transport_protocols();


	    if(!$transport_protocols){
	        $transport_protocols='none';
	    }

	    switch ($transport_protocols){
	        case 'http':
	            if(strpos($data->notify_url, 'https')===0){
	                $data->notify_url = str_replace('https', 'http', $data->notify_url);
	            }
	            break;
	        case 'https':
	            if(strpos($data->notify_url, 'https')!==0){
	                $data->notify_url = str_replace('http', 'https', $data->notify_url);
	            }
	            break;
	    }


        $data =json_encode($data);




        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $head_arr);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt( $ch, CURLOPT_CAINFO, ABSPATH . WPINC . '/certificates/ca-bundle.crt');

        $temp = tmpfile();
        fwrite($temp, $data);
        fseek($temp, 0);

        curl_setopt($ch, CURLOPT_INFILE, $temp);
        curl_setopt($ch, CURLOPT_INFILESIZE, strlen($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        $response = wp_remote_get();
        $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error=curl_error($ch);
        curl_close($ch);
        if($httpStatusCode!=200){
            throw new Exception("invalid httpstatus:{$httpStatusCode} ,response:$response,detail_error:".$error,$httpStatusCode);
        }

        $result =$response;

        if($temp){
            fclose($temp);
            unset($temp);
        }

        $resArr = json_decode($result,false);
        if(!$resArr){
            throw new Exception('This request has been rejected by the GlobePay service!');
        }

        if(!isset($resArr->result_code)||$resArr->result_code!='SUCCESS'){
            $errcode =empty($resArr->result_code)?$resArr->return_code:$resArr->result_code;
            throw new Exception(sprintf('ERROR CODE:%s;ERROR MSG:%s.',$errcode,$resArr->return_msg));
		}


       return $resArr;
    }

    public static function globepay_refund($amount,$ooid,$refund_id){

        $partner_code = self::get_partner_code();
		$time=time().'000';
		$nonce_str = substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0,10);
		$credential_code = self::get_credential_code();
		$valid_string="$partner_code&$time&$nonce_str&$credential_code";
		$sign=strtolower(hash('sha256',$valid_string));



		$url ="https://pay.globepay.co/api/v1.0/gateway/partners/$partner_code/orders/$ooid/refunds/$refund_id";
		$url.="?time=$time&nonce_str=$nonce_str&sign=$sign";

		$head_arr = array();
		$head_arr[] = 'Content-Type: application/json';
		$head_arr[] = 'Accept: application/json';
		$head_arr[] = 'Accept-Language: '.get_locale();

        $data =new stdClass();
        $data->fee = $amount;
        $data=json_encode($data);
        $args = array(
            'body'        => $data,
            'timeout'     => '5',
            'redirection' => '5',
            'httpversion' => '1.0',
            'blocking'    => true,
            'cookies'     => array(),
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( YOUR_USERNAME . ':' . YOUR_PASSWORD )
            )
        );
		$result = $response = wp_remote_post($url,$args);
        $resArr = json_decode($result,false);
        if(!$resArr){
            return new WP_Error( 'refuse_error', $result);
        }
        return $resArr;
    }

    public function query_order_status($globepay_order_id){


		$partner_code = self::get_partner_code();
		$time=time().'000';
		$nonce_str = substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0,10);
		$credential_code = self::get_credential_code();
		$valid_string="$partner_code&$time&$nonce_str&$credential_code";
		$sign=strtolower(hash('sha256',$valid_string));

		$head_arr = array();
		$head_arr[] = 'Accept: application/json';
		$head_arr[] = 'Accept-Language: '.get_locale();


		$url ="https://pay.globepay.co/api/v1.0/gateway/partners/$partner_code/orders/$globepay_order_id";
		$url.="?time=$time&nonce_str=$nonce_str&sign=$sign";
        $result = wp_remote_get( $url);
		$resArr = json_decode($result,false);

		if(!$resArr){
			return new WP_Error( 'refuse_error', $result);
		}
		return $resArr;

    }

		public static function query_refund_status($globepay_order_id, $globepay_refund_id){


		$partner_code = self::get_partner_code();
		$time=time().'000';
		$nonce_str = substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0,10);
		$credential_code = self::get_credential_code();
		$valid_string="$partner_code&$time&$nonce_str&$credential_code";
		$sign=strtolower(hash('sha256',$valid_string));

		$head_arr = array();
		$head_arr[] = 'Accept: application/json';
		$head_arr[] = 'Accept-Language: '.get_locale();


		$url ="https://pay.globepay.co/api/v1.0/gateway/partners/$partner_code/orders/$globepay_order_id/refunds/$globepay_refund_id";
		$url.="?time=$time&nonce_str=$nonce_str&sign=$sign";
		$result = wp_remote_get( $url);
		$resArr = json_decode($result,false);
		if(!$resArr){
			return new WP_Error( 'refuse_error', $result);
		}

		return $resArr;

    }

    public static function get_order_title($order,$limit=32,$trimmarker='...'){
	    $title ="";
		$order_items = $order->get_items();
		if($order_items){
		    $qty = count($order_items);
		    foreach ($order_items as $item_id =>$item){
		        $title.="{$item['name']}";
		        break;
		    }
		    if($qty>1){
		        $title.='...';
		    }
		}

		$title = mb_strimwidth($title, 0, $limit,'utf-8');
		return apply_filters('payment-get-order-title', $title,$order);
	}

	public function wc_globepay_notify(){


		$json =isset($GLOBALS['HTTP_RAW_POST_DATA'])?$GLOBALS['HTTP_RAW_POST_DATA']:'';

		if(empty($json)){
			$json = file_get_contents("php://input");
		}



		if(empty($json)){
			print json_encode(array('return_code'=>'FAIL'));
			exit;
		}


		$response = json_decode($json,false);
		if(!$response){
			print json_encode(array('return_code'=>'FAIL'));
			exit;
		}

		$credential_code = self::get_credential_code();
		$partner_code = self::get_partner_code();
		$time=$response->time;
		$nonce_str=$response->nonce_str;

		$valid_string="$partner_code&$time&$nonce_str&$credential_code";
		$sign=strtolower(hash('sha256',$valid_string));
		if($sign!=$response->sign){
			print json_encode(array('return_code'=>'FAIL'));
			exit;
		}
		$order_id=substr($response->partner_order_id, 12);

		$order = new WC_Order($order_id);
		if(!$order||!$order->needs_payment()){
			print json_encode(array('return_code'=>'SUCCESS'));
			exit;
		}

		if(get_post_meta($order_id, 'globepay_order_id',true)!=$response->partner_order_id){
			update_post_meta($order_id, 'globepay_order_id', $response->partner_order_id);
		}

		$resArr = GlobePay_API::query_order_status($response->partner_order_id);

		if(!$resArr){
			print json_encode(array('return_code'=>'FAIL'));
			exit;
		}

		if($resArr->result_code!='PAY_SUCCESS'){
			print json_encode(array('return_code'=>'SUCCESS'));
			exit;
		}

		try {
			$order->payment_complete ($response->order_id);
		} catch (Exception $e) {
			print json_encode(array('return_code'=>'FAIL'));
			exit;
		}

		print json_encode(array('return_code'=>'SUCCESS'));
		exit;
	}


}
