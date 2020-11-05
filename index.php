<?php

add_action( 'rcl_payments_gateway_init', 'rcl_add_payeer_gateway' );
function rcl_add_payeer_gateway() {
	rcl_gateway_register( 'payeer', 'Rcl_Payeer_Payment' );
}

class Rcl_Payeer_Payment extends Rcl_Gateway_Core {
	function __construct() {
		parent::__construct( array(
			'request'	 => 'm_operation_id',
			'name'		 => 'Payeer',
			'submit'	 => __( 'Оплатить через Payeer' ),
			'image'		 => rcl_addon_url( 'icon.jpg', __FILE__ )
		) );
	}

	function get_options() {

		if ( false !== array_search( rcl_get_commerce_option( 'primary_cur' ), array( 'RUB', 'USD', 'EUR' ) ) ) {
			$options = array(
				array(
					'type'	 => 'text',
					'slug'	 => 'm_shop',
					'title'	 => __( 'Идентификатор магазина' )
				),
				array(
					'type'	 => 'text',
					'slug'	 => 'm_key',
					'title'	 => __( 'Секретный ключ' )
				)
			);
		} else {
			$options = array(
				array(
					'type'		 => 'custom',
					'slug'		 => 'notice',
					'content'	 => rcl_get_notice( [
						'type'	 => 'error',
						'text'	 => 'Данное подключение не поддерживает действующую валюту сайта.<br>'
						. 'Поддерживается работа с RUB, USD, EUR'
					] )
				)
			);
		}

		return $options;
	}

	function get_form( $data ) {

		$fields = array(
			'm_shop'	 => rcl_get_commerce_option( 'm_shop' ),
			'm_orderid'	 => $data->pay_id . ':' . $data->user_id,
			'm_amount'	 => number_format( $data->pay_summ, 2, '.', '' ),
			'm_curr'	 => rcl_get_commerce_option( 'primary_cur' ),
			'm_desc'	 => base64_encode( $data->description )
		);

		// Формируем массив дополнительных параметров
		$m_params = array(
			'reference' => array(
				'user_id'		 => $data->user_id,
				'pay_type'		 => $data->pay_type,
				'baggage_data'	 => $data->baggage_data
			),
		);

		// Формируем ключ для шифрования
		$key = md5( rcl_get_commerce_option( 'm_key' ) . $data->pay_id . ':' . $data->user_id );

		// Шифруем дополнительные параметры
		//$m_params = urlencode(base64_encode(mcrypt_encrypt(MCR YPT_RIJNDAEL_256, $key, json_encode($arParams), MCRYPT_MODE_ECB)));
		// Шифруем дополнительные параметры с помощью AES-256-CBC (для >= PHP 7)
		$m_params			 = urlencode( base64_encode( openssl_encrypt( json_encode( $m_params ), 'AES-256-CBC', $key, OPENSSL_RAW_DATA ) ) );
		// Добавляем параметры в массив для формирования подписи
		$fields['m_params']	 = $m_params;

		$hash_data = array_merge( $fields, array( rcl_get_commerce_option( 'm_key' ) ) );

		$fields['m_sign'] = strtoupper( hash( 'sha256', implode( ':', $hash_data ) ) );

		$fields['m_cipher_method'] = 'AES-256-CBC';

		return parent::construct_form( [
				'action' => 'https://payeer.com/merchant/',
				'fields' => $fields
			] );
	}

	function result( $data ) {

		$whitelist = array(
			'185.71.65.189',
			'185.71.65.92',
			'149.202.17.210'
		);

		if ( ! in_array( $_SERVER['REMOTE_ADDR'], $whitelist ) )
			return;

		if ( isset( $_REQUEST['m_operation_id'] ) && isset( $_REQUEST['m_sign'] ) ) {

			$m_key		 = rcl_get_commerce_option( 'm_key' );
			$m_params	 = wp_unslash( $_REQUEST["m_params"] );

			$arHash = array(
				$_REQUEST['m_operation_id'],
				$_REQUEST['m_operation_ps'],
				$_REQUEST['m_operation_date'],
				$_REQUEST['m_operation_pay_date'],
				$_REQUEST['m_shop'],
				$_REQUEST['m_orderid'],
				$_REQUEST['m_amount'],
				$_REQUEST['m_curr'],
				$_REQUEST['m_desc'],
				$_REQUEST['m_status'],
				$m_params,
				$m_key
			);

			$sign_hash = strtoupper( hash( 'sha256', implode( ':', $arHash ) ) );

			if ( $_REQUEST['m_sign'] == $sign_hash && $_REQUEST['m_status'] == 'success' ) {

				$params		 = json_decode( $m_params );
				$order_id	 = explode( ':', $_REQUEST["m_orderid"] );

				if ( ! parent::get_payment( $order_id[0] ) ) {
					parent::insert_payment( array(
						'pay_id'		 => $order_id[0],
						'pay_summ'		 => $_REQUEST["m_amount"],
						'user_id'		 => $order_id[1],
						'pay_type'		 => $params->reference->pay_type,
						'baggage_data'	 => $params->reference->baggage_data
					) );
					echo $_REQUEST['m_orderid'] . '|success';
					exit;
				}
			}
		}

		rcl_mail_payment_error( $sign_hash );
		echo $_REQUEST['m_orderid'] . '|error';
		exit;
	}

	function success( $process ) {

		$order_id = explode( ':', $_REQUEST["m_orderid"] );

		if ( parent::get_payment( $order_id[0] ) ) {
			wp_redirect( get_permalink( $process->page_successfully ) );
			exit;
		} else {
			wp_die( 'Платеж не найден в базе данных!' );
		}
	}

}
