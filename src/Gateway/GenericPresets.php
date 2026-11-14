<?php
/**
 * Starting points for the generic gateway.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Gateway;

/**
 * Presets fill in the generic gateway form for common API shapes.
 *
 * Presets describe request shapes, not specific providers: the plugin only
 * ships provider adapters whose API is publicly documented. Add presets for
 * other providers with the `woo_bd_sms_generic_presets` filter.
 */
final class GenericPresets {

	/**
	 * Preset id => [label, configuration].
	 *
	 * @return array<string,array{label:string,config:array<string,string>}>
	 */
	public static function all(): array {
		return array(
			'get_query'   => array(
				'label'  => 'GET with query string',
				'config' => array(
					'method'          => 'GET',
					'body_format'     => 'form',
					'params'          => "api_key={api_key}\nsenderid={sender_id}\nnumber={to}\nmessage={message}",
					'success_type'    => SuccessMatcher::HTTP_2XX,
					'success_value'   => '',
					'message_id_path' => '',
					'error_path'      => '',
				),
			),
			'json_bearer' => array(
				'label'  => 'JSON POST with bearer token',
				'config' => array(
					'method'          => 'POST',
					'body_format'     => 'json',
					'params'          => "from={sender_id}\nto={to_e164}\ntext={message}\nreference={reference}",
					'headers'         => 'Authorization: Bearer {api_key}',
					'success_type'    => SuccessMatcher::HTTP_2XX,
					'success_value'   => '',
					'message_id_path' => 'id',
					'error_path'      => 'message',
				),
			),
			'form_status' => array(
				'label'  => 'Form POST with JSON status field',
				'config' => array(
					'method'          => 'POST',
					'body_format'     => 'form',
					'params'          => "api_key={api_key}\nsender_id={sender_id}\nto={to}\nmessage={message}\ntype={type}",
					'success_type'    => SuccessMatcher::JSON,
					'success_value'   => 'status=success',
					'message_id_path' => 'message_id',
					'error_path'      => 'message',
				),
			),
		);
	}

	/**
	 * Merge a preset into an existing configuration (credentials are kept).
	 *
	 * @param string               $preset Preset id.
	 * @param array<string,string> $config Current configuration.
	 * @return array<string,string>
	 */
	public static function apply( string $preset, array $config ): array {
		$presets = self::all();
		if ( ! isset( $presets[ $preset ] ) ) {
			return $config;
		}

		return array_merge( $config, $presets[ $preset ]['config'] );
	}
}
