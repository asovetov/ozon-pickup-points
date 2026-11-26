<?php
/**
 * Метод доставки «Ozon».
 *
 * Цену считает только Ozon. Пока покупатель не выбрал пункт выдачи и не указал телефон,
 * стоимость неизвестна: способ доставки показывается без суммы с пометкой «уточним после
 * выбора пункта». Своих тарифов у плагина нет.
 *
 * @package WooOzonDelivery
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Класс метода доставки.
 */
class Woo_Ozon_Shipping_Method extends WC_Shipping_Method {

	/**
	 * Конструктор.
	 *
	 * @param int $instance_id Идентификатор экземпляра метода.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'woo_ozon';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Ozon', 'sovetov-ozon-pickup-points' );
		$this->method_description = __( 'Доставка в пункты выдачи Ozon. Стоимость считает Ozon после выбора пункта выдачи.', 'sovetov-ozon-pickup-points' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );

		$this->init();
	}

	/**
	 * Настройки метода.
	 */
	public function init(): void {
		$this->instance_form_fields = array(
			'title'       => array(
				'title'       => __( 'Название', 'sovetov-ozon-pickup-points' ),
				'type'        => 'text',
				'description' => __( 'Как метод называется в оформлении заказа.', 'sovetov-ozon-pickup-points' ),
				'default'     => __( 'Ozon, пункт выдачи', 'sovetov-ozon-pickup-points' ),
				'desc_tip'    => true,
			),
			'tax_status'  => array(
				'title'   => __( 'Налог', 'sovetov-ozon-pickup-points' ),
				'type'    => 'select',
				'default' => 'taxable',
				'options' => array(
					'taxable' => __( 'Облагается', 'sovetov-ozon-pickup-points' ),
					'none'    => __( 'Не облагается', 'sovetov-ozon-pickup-points' ),
				),
			),
			'free_min'    => array(
				'title'       => __( 'Бесплатно от суммы, ₽', 'sovetov-ozon-pickup-points' ),
				'type'        => 'number',
				'default'     => '0',
				'description' => __( '0: бесплатной доставки нет.', 'sovetov-ozon-pickup-points' ),
				'desc_tip'    => true,
			),
			'markup'      => array(
				'title'       => __( 'Наценка, ₽', 'sovetov-ozon-pickup-points' ),
				'type'        => 'number',
				'default'     => '0',
				'description' => __( 'Прибавляется к тарифу: упаковка, обработка.', 'sovetov-ozon-pickup-points' ),
				'desc_tip'    => true,
			),
		);

		$this->init_settings();

		$this->enabled    = 'yes';
		$this->title      = (string) ( $this->get_option( 'title' ) ?: __( 'Ozon, пункт выдачи', 'sovetov-ozon-pickup-points' ) );
		$this->tax_status = (string) ( $this->get_option( 'tax_status' ) ?: 'taxable' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Вес корзины для отметки в способе доставки.
	 *
	 * @param array<string, mixed> $package Пакет доставки.
	 */
	private function package_weight_kg( array $package ): float {
		$weight = 0.0;

		foreach ( $package['contents'] as $item ) {
			$product = $item['data'] ?? null;

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$item_weight = (float) $product->get_weight();
			if ( $item_weight <= 0 ) {
				$item_weight = 0.3;
			}

			$weight += $item_weight * (int) $item['quantity'];
		}

		return $weight;
	}

	/**
	 * Расчёт стоимости для корзины.
	 *
	 * @param array<string, mixed> $package Пакет доставки.
	 */
	public function calculate_shipping( $package = array() ): void {
		$weight = $this->package_weight_kg( $package );
		$quote  = class_exists( 'Woo_Ozon_Quote' ) ? Woo_Ozon_Quote::current() : null;

		if ( null === $quote ) {
			// Цены ещё нет: пункт не выбран или Ozon не ответил.
			$this->add_rate(
				array(
					'id'        => $this->get_rate_id(),
					'label'     => sprintf(
						/* translators: %s: название способа доставки */
						__( '%s (стоимость уточним после выбора пункта)', 'sovetov-ozon-pickup-points' ),
						$this->title
					),
					'cost'      => 0,
					'package'   => $package,
					'meta_data' => array(
						__( 'Вес заказа', 'sovetov-ozon-pickup-points' ) => wc_format_weight( $weight ),
					),
				)
			);

			return;
		}

		$cost = $quote + (float) $this->get_option( 'markup', 0 );

		$free_min = (float) $this->get_option( 'free_min', 0 );
		if ( $free_min > 0 && (float) ( $package['contents_cost'] ?? 0 ) >= $free_min ) {
			$cost = 0.0;
		}

		$this->add_rate(
			array(
				'id'        => $this->get_rate_id(),
				'label'     => $this->title,
				'cost'      => max( 0, $cost ),
				'package'   => $package,
				'meta_data' => array(
					__( 'Расчёт Ozon', 'sovetov-ozon-pickup-points' ) => wc_format_weight( $weight ),
				),
			)
		);
	}
}
