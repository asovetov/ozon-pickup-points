<?php
/**
 * Ошибка Ozon Delivery API.
 *
 * @package WooOzonDelivery
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Тексты исключений попадают в лог и в заметки заказа, в HTML не выводятся,
// поэтому экранирование вывода к ним не применимо.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Ошибка Ozon.
 */
class Woo_Ozon_Error extends Exception {

	/**
	 * Код ответа HTTP.
	 *
	 * @var int
	 */
	public int $status_code;

	/**
	 * Тело ответа.
	 *
	 * @var mixed
	 */
	public $details;

	/**
	 * Конструктор.
	 *
	 * @param string $message Текст ошибки.
	 * @param int    $status_code Код HTTP.
	 * @param mixed  $details Тело ответа.
	 */
	public function __construct( string $message, int $status_code = 0, $details = null ) {
		parent::__construct( $message );
		$this->status_code = $status_code;
		$this->details     = $details;
	}
}
