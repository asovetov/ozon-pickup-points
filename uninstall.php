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
$woo_ozon_timestamp = wp_next_scheduled( 'woo_ozon_sync_statuses' );
while ( $woo_ozon_timestamp ) {
	wp_unschedule_event( $woo_ozon_timestamp, 'woo_ozon_sync_statuses' );
	$woo_ozon_timestamp = wp_next_scheduled( 'woo_ozon_sync_statuses' );
}

// Своя таблица кэша пунктов: имя собирается из $wpdb->prefix.
$woo_ozon_table = $wpdb->prefix . 'woo_ozon_points';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- удаление своей таблицы при деинсталляции.
$wpdb->query( "DROP TABLE IF EXISTS {$woo_ozon_table}" );

// Заметки об ошибках в карточках заказов.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_woo_ozon_error_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_woo_ozon_error_' ) . '%'
	)
);
