<?php
/**
 * Удаление плагина: убираем настройки, кэш и расписание.
 *
 * @package WooOzonDelivery
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'woo_ozon_settings' );
delete_option( 'woo_ozon_schema_version' );
delete_transient( 'woo_ozon_access_token' );

// Снимаем крон, если он ещё висит.
$timestamp = wp_next_scheduled( 'woo_ozon_sync_statuses' );
while ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'woo_ozon_sync_statuses' );
	$timestamp = wp_next_scheduled( 'woo_ozon_sync_statuses' );
}

// Своя таблица кэша пунктов.
$table = $wpdb->prefix . 'woo_ozon_points';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Заметки об ошибках в карточках заказов.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_woo_ozon_error_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_woo_ozon_error_' ) . '%'
	)
);
