<?php
/**
 * Finds tracking numbers in order meta.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Order;

/**
 * Reads tracking details stored by common shipment tracking plugins and by
 * custom meta keys.
 *
 * - `_wc_shipment_tracking_items`: list of items with tracking_number,
 *   tracking_provider / custom_tracking_provider and custom_tracking_link
 *   (WooCommerce Shipment Tracking and compatible plugins). The newest item wins.
 * - Plain keys: `_tracking_number`, `tracking_number`, `_tracking_code`,
 *   with `_tracking_provider` / `_tracking_url` next to them.
 * - Extra keys configured in the settings, checked first.
 *
 * {tracking_url} templates such as "https://courier.example/track/{tracking}"
 * can be configured per courier to build links when none is stored.
 */
final class TrackingResolver {

	public const DEFAULT_NUMBER_KEYS = array( '_tracking_number', 'tracking_number', '_tracking_code', 'tracking_code' );

	/**
	 * Resolve tracking details.
	 *
	 * @param callable(string):mixed $get_meta Returns the meta value for a key.
	 * @param string[]               $extra_keys Additional tracking number keys.
	 * @param string                 $url_template Link template with {tracking}, used when no link is stored.
	 */
	public function resolve( callable $get_meta, array $extra_keys = array(), string $url_template = '' ): TrackingInfo {
		$info = new TrackingInfo();

		foreach ( array_merge( $extra_keys, self::DEFAULT_NUMBER_KEYS ) as $key ) {
			$value = self::scalar( $get_meta( $key ) );
			if ( '' !== $value ) {
				$info = new TrackingInfo(
					$value,
					self::scalar( $get_meta( '_tracking_provider' ) ),
					self::scalar( $get_meta( '_tracking_url' ) )
				);
				break;
			}
		}

		if ( ! $info->has_number() ) {
			$items = $get_meta( '_wc_shipment_tracking_items' );
			if ( is_string( $items ) && '' !== $items ) {
				$items = json_decode( $items, true );
			}
			if ( is_array( $items ) && array() !== $items ) {
				$item = end( $items );
				if ( is_array( $item ) && '' !== self::scalar( $item['tracking_number'] ?? '' ) ) {
					$courier = self::scalar( $item['custom_tracking_provider'] ?? '' );
					if ( '' === $courier ) {
						$courier = self::scalar( $item['tracking_provider'] ?? '' );
					}
					$info = new TrackingInfo(
						self::scalar( $item['tracking_number'] ),
						$courier,
						self::scalar( $item['custom_tracking_link'] ?? '' )
					);
				}
			}
		}

		if ( $info->has_number() && '' === $info->url && '' !== $url_template ) {
			$info->url = str_replace( '{tracking}', rawurlencode( $info->number ), $url_template );
		}

		return $info;
	}

	/**
	 * Trimmed string of a scalar meta value.
	 *
	 * @param mixed $value Value.
	 */
	private static function scalar( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
