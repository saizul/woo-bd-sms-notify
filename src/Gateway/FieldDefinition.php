<?php
/**
 * Settings field of a gateway.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Gateway;

/**
 * Describes one configuration value of a gateway for the settings screen.
 */
final class FieldDefinition {

	public const TEXT     = 'text';
	public const SECRET   = 'secret';
	public const URL      = 'url';
	public const TEXTAREA = 'textarea';
	public const SELECT   = 'select';

	/**
	 * Key.
	 *
	 * @var string
	 */
	public $key;

	/**
	 * Label (English, translated by the admin screen).
	 *
	 * @var string
	 */
	public $label;

	/**
	 * Field type.
	 *
	 * @var string
	 */
	public $type;

	/**
	 * Required to send.
	 *
	 * @var bool
	 */
	public $required;

	/**
	 * Default value.
	 *
	 * @var string
	 */
	public $default;

	/**
	 * Options for select fields.
	 *
	 * @var array<string,string>
	 */
	public $options;

	/**
	 * Help text.
	 *
	 * @var string
	 */
	public $help;

	/**
	 * Constructor.
	 *
	 * @param string               $key Key.
	 * @param string               $label Label.
	 * @param string               $type Type.
	 * @param bool                 $required Required.
	 * @param string               $default_value Default.
	 * @param array<string,string> $options Select options.
	 * @param string               $help Help text.
	 */
	public function __construct( string $key, string $label, string $type = self::TEXT, bool $required = false, string $default_value = '', array $options = array(), string $help = '' ) {
		$this->key      = $key;
		$this->label    = $label;
		$this->type     = $type;
		$this->required = $required;
		$this->default  = $default_value;
		$this->options  = $options;
		$this->help     = $help;
	}

	/**
	 * Whether the value is a secret (never shown again, may be set by a constant).
	 */
	public function is_secret(): bool {
		return self::SECRET === $this->type;
	}
}
