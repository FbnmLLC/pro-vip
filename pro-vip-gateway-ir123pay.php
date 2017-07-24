<?php
/**
 * Plugin Name: 123PAY.IR - Pro VIP
 * Description: پلاگین پرداخت، سامانه پرداخت یک دو سه پی برای Pro VIP
 * Plugin URI: https://123pay.ir
 * Author: تیم فنی یک دو سه پی
 * Author URI: http://123pay.ir
 * Version: 1.0
 **/

if ( ! defined( 'ABSPATH' ) ) {

	die( 'This file cannot be accessed directly' );
}

if ( ! function_exists( 'init_ir123pay_gateway_pv_class' ) ) {

	add_action( 'plugins_loaded', 'init_ir123pay_gateway_pv_class' );

	function init_ir123pay_gateway_pv_class() {
		add_filter( 'pro_vip_currencies_list', 'currencies_check' );

		function currencies_check( $list ) {
			if ( ! in_array( 'IRT', $list ) ) {

				$list['IRT'] = array(

					'name'   => 'تومان ایران',
					'symbol' => 'تومان',
				);
			}

			if ( ! in_array( 'IRR', $list ) ) {

				$list['IRR'] = array(

					'name'   => 'ریال ایران',
					'symbol' => 'ریال',
				);
			}

			return $list;
		}

		if ( class_exists( 'Pro_VIP_Payment_Gateway' ) && ! class_exists( 'Pro_VIP_Ir123pay_Gateway' ) ) {

			class Pro_VIP_Ir123pay_Gateway extends Pro_VIP_Payment_Gateway {
				public

					$id = 'Ir123pay',
					$settings = array(),
					$frontendLabel = 'سامانه پرداخت یک دو سه پی',
					$adminLabel = 'سامانه پرداخت یک دو سه پی';

				public function __construct() {
					parent::__construct();
				}

				public function beforePayment( Pro_VIP_Payment $payment ) {
					if ( extension_loaded( 'curl' ) ) {

						$merchant_id  = $this->settings['merchant_id'];
						$order_id     = $payment->paymentId;
						$callback_url = add_query_arg( 'order', $order_id, $this->getReturnUrl() );
						$amount       = intval( $payment->price );

						$amount = ( pvGetOption( 'currency' ) == 'IRT' ) ? $amount * 10 : $amount;

						$params = array(

							'merchant_id' => $merchant_id,
							'amount'      => $amount,
							'redirect'    => urlencode( $callback_url )
						);

						$response = $this->common( 'https://123pay.ir/api/v1/create/payment', $params );
						$result   = json_decode( $response );
						if ( $result->status ) {

							$payment->key  = $order_id;
							$payment->user = get_current_user_id();
							$payment->save();

							$message = 'شماره تراکنش ' . $result->RefNum;

							pvAddNotice( $message, 'error' );

							wp_redirect( $result->payment_url );

							exit();

						} else {

							$message = 'در ارتباط با وب سرویس یک دو سه پی خطایی رخ داده است';
							$message = isset( $result->message ) ? $result->message : $message;

							pvAddNotice( $message, 'error' );

							$payment->status = 'trash';
							$payment->save();

							wp_die( $message );
							exit();
						}

					} else {

						$message = 'تابع cURL در سرور فعال نمی باشد';

						pvAddNotice( $message, 'error' );

						$payment->status = 'trash';
						$payment->save();

						wp_die( $message );
						exit();
					}
				}

				public function afterPayment() {
					if ( isset( $_GET['order'] ) ) {

						$order_id = sanitize_text_field( $_GET['order'] );

					} else {

						$order_id = null;
					}

					if ( $order_id ) {

						$payment = new Pro_VIP_Payment( $order_id );

						if ( isset( $_REQUEST['State'] ) && isset( $_REQUEST['RefNum'] ) ) {

							$State  = sanitize_text_field( $_REQUEST['State'] );
							$RefNum = sanitize_text_field( $_REQUEST['RefNum'] );

							if ( $State == 'OK' ) {

								$merchant_id = $this->settings['merchant_id'];

								$params = array(

									'merchant_id' => $merchant_id,
									'RefNum'      => $RefNum
								);

								$response = $this->common( 'https://123pay.ir/api/v1/verify/payment', $params );
								$result   = json_decode( $response );
								if ( $result->status ) {
									$amount = intval( $payment->price );
									$amount = ( pvGetOption( 'currency' ) == 'IRT' ) ? $amount * 10 : $amount;

									if ( $amount == $result->amount ) {

										$message = 'تراکنش شماره ' . $RefNum . ' با موفقیت انجام شد';

										pvAddNotice( $message, 'success' );

										$payment->status = 'publish';
										$payment->save();

										$this->paymentComplete( $payment );

									} else {

										$message = 'رقم تراكنش با رقم پرداخت شده مطابقت ندارد';

										pvAddNotice( $message, 'error' );

										$payment->status = 'trash';
										$payment->save();

										$this->paymentFailed( $payment );
									}

								} else {

									$message = 'در ارتباط با وب سرویس یک دو سه پی و بررسی تراکنش خطایی رخ داده است';
									$message = isset( $result->message ) ? $result->message : $message;

									pvAddNotice( $message, 'error' );

									$payment->status = 'trash';
									$payment->save();

									$this->paymentFailed( $payment );
								}

							} else {

								$message = isset( $message ) ? $message : 'تراكنش با خطا مواجه شد و یا توسط پرداخت کننده کنسل شده است';

								pvAddNotice( $message, 'error' );

								$payment->status = 'trash';
								$payment->save();

								$this->paymentFailed( $payment );
							}

						} else {

							$message = 'اطلاعات ارسال شده مربوط به تایید تراکنش ناقص و یا غیر معتبر است';

							pvAddNotice( $message, 'error' );

							$payment->status = 'trash';
							$payment->save();

							$this->paymentFailed( $payment );
						}

					} else {

						$message = 'شماره سفارش ارسال شده غیر معتبر است';

						pvAddNotice( $message, 'error' );
					}
				}

				public
				function adminSettings(
					PV_Framework_Form_Builder $form
				) {
					$form->textfield( 'merchant_id' )->label( 'کد پذیرنده' );
				}

				private
				static function common(
					$url, $params
				) {
					$ch = curl_init();

					curl_setopt( $ch, CURLOPT_URL, $url );
					curl_setopt( $ch, CURLOPT_POST, true );
					curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
					curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
					curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
					curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $params ) );

					$response = curl_exec( $ch );
					$error    = curl_errno( $ch );

					curl_close( $ch );

					$output = $error ? false : $response;

					return $output;
				}
			}

			Pro_VIP_Payment_Gateway::registerGateway( 'Pro_VIP_Ir123pay_Gateway' );
		}
	}
}
