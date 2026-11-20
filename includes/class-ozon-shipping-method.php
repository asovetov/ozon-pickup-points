<?php
/**
 * Метод доставки «Ozon».
 *
 * В корзине точную цену Ozon не отдаёт: расчёт требует конкретный пункт выдачи и телефон
 * получателя. Поэтому в корзине работает тариф по весу, а точная стоимость приходит при
 * создании заявки в админке — там же её видно рядом с заказом.
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
		$this->method_title       = __( 'Ozon', 'woo-ozon-delivery' );
		$this->method_description = __( 'Доставка в пункты выдачи Ozon. Тариф по весу в корзине, точный расчёт — при создании заявки.', 'woo-ozon-delivery' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );

		$this->init();
	}

	/**
	 * Настройки метода.
	 */
	public function init(): void {
		$this->instance_form_fields = array(
			'title'       => array(
				'title'       => __( 'Название', 'woo-ozon-delivery' ),
				'type'        => 'text',
				'description' => __( 'Как метод называется в оформлении заказа.', 'woo-ozon-delivery' ),
				'default'     => __( 'Ozon, пункт выдачи', 'woo-ozon-delivery' ),
				'desc_tip'    => true,
			),
			'tax_status'  => array(
				'title'   => __( 'Налог', 'woo-ozon-delivery' ),
				'type'    => 'select',
				'default' => 'taxable',
				'options' => array(
					'taxable' => __( 'Облагается', 'woo-ozon-delivery' ),
					'none'    => __( 'Не облагается', 'woo-ozon-delivery' ),
				),
			),
			'base_cost'   => array(
				'title'       => __( 'Базовая стоимость, ₽', 'woo-ozon-delivery' ),
				'type'        => 'number',
				'default'     => '350',
				'description' => __( 'Применяется, если вес не попал ни в одну полосу.', 'woo-ozon-delivery' ),
				'desc_tip'    => true,
			),
			'weight_bands' => array(
				'title'       => __( 'Тарифы по весу', 'woo-ozon-delivery' ),
				'type'        => 'textarea',
				'default'     => "1:350\n3:450\n5:600\n10:900",
				'description' => __( 'По одной строке: «до_кг:цена». Например «1:350» — до 1 кг стоит 350 ₽.', 'woo-ozon-delivery' ),
				'desc_tip'    => true,
			),
			'free_min'    => array(
				'title'       => __( 'Бесплатно от суммы, ₽', 'woo-ozon-delivery' ),
				'type'        => 'number',
				'default'     => '0',
				'description' => __( '0 — бесплатной доставки нет.', 'woo-ozon-delivery' ),
				'desc_tip'    => true,
			),
			'markup'      => array(
				'title'       => __( 'Наценка, ₽', 'woo-ozon-delivery' ),
				'type'        => 'number',
				'default'     => '0',
				'description' => __( 'Прибавляется к тарифу: упаковка, обработка.', 'woo-ozon-delivery' ),
				'desc_tip'    => true,
			),
		);

		$this->init_settings();

		$this->enabled    = 'yes';
		$this->title      = (string) ( $this->get_option( 'title' ) ?: __( 'Ozon, пункт выдачи', 'woo-ozon-delivery' ) );
		$this->tax_status = (string) ( $this->get_option( 'tax_status' ) ?: 'taxable' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Вес корзины в килограммах.
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
				// Товар без веса считаем как 0.3 кг, иначе тариф уедет в минимум.
				$item_weight = 0.3;
			}

			$weight += $item_weight * (int) $item['quantity'];
		}

		return $weight;
	}

	/**
	 * Стоимость по полосам веса.
	 *
	 * @param float $weight_kg Вес в килограммах.
	 */
	private function band_cost( float $weight_kg ): float {
		$raw   = (string) $this->get_option( 'weight_bands', '' );
		$bands = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}

			list( $max, $price ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$max                 = (float) str_replace( ',', '.', $max );
			$price               = (float) str_replace( ',', '.', $price );

			if ( $max > 0 && $price >= 0 ) {
				$bands[ $max ] = $price;
			}
		}

		ksort( $bands );

		foreach ( $bands as $max => $price ) {
			if ( $weight_kg <= $max ) {
				return $price;
			}
		}

		return (float) $this->get_option( 'base_cost', 0 );
	}

	/**
	 * Расчёт стоимости для корзины.
	 *
	 * @param array<string, mixed> $package Пакет доставки.
	 */
	public function calculate_shipping( $package = array() ): void {
		$weight = $this->package_weight_kg( $package );
		$cost   = $this->band_cost( $weight );
		$cost  += (float) $this->get_option( 'markup', 0 );

		$free_min = (float) $this->get_option( 'free_min', 0 );
		if ( $free_min > 0 ) {
			$subtotal = (float) ( $package['contents_cost'] ?? 0 );
			if ( $subtotal >= $free_min ) {
				$cost = 0.0;
			}
		}

		$this->add_rate(
			array(
				'id'      => $this->get_rate_id(),
				'label'   => $this->title,
				'cost'    => max( 0, $cost ),
				'package' => $package,
				'meta_data' => array(
					__( 'Вес заказа', 'woo-ozon-delivery' ) => wc_format_weight( $weight ),
				),
			)
		);
	}
}
