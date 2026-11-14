<?php
/**
 * Result of counting SMS segments.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Sms;

/**
 * How a message will be encoded and split by the network.
 */
final class SegmentInfo {

	/**
	 * GSM-7 or UCS-2.
	 *
	 * @var string
	 */
	private $encoding;

	/**
	 * Length in encoding units (septets for GSM-7, UTF-16 code units for UCS-2).
	 *
	 * @var int
	 */
	private $units;

	/**
	 * Number of characters as a person would count them.
	 *
	 * @var int
	 */
	private $characters;

	/**
	 * Segments, each as a list of characters.
	 *
	 * @var array<int,string>
	 */
	private $parts;

	/**
	 * Capacity of each segment in units.
	 *
	 * @var int
	 */
	private $per_segment;

	/**
	 * Constructor.
	 *
	 * @param string            $encoding Encoding constant.
	 * @param int               $units Length in units.
	 * @param int               $characters Character count.
	 * @param array<int,string> $parts Segment texts.
	 * @param int               $per_segment Capacity of each segment.
	 */
	public function __construct( string $encoding, int $units, int $characters, array $parts, int $per_segment ) {
		$this->encoding    = $encoding;
		$this->units       = $units;
		$this->characters  = $characters;
		$this->parts       = $parts;
		$this->per_segment = $per_segment;
	}

	/**
	 * GSM-7 or UCS-2.
	 */
	public function encoding(): string {
		return $this->encoding;
	}

	/**
	 * Whether the message is sent as Unicode (UCS-2).
	 */
	public function is_unicode(): bool {
		return Encoding::UCS2 === $this->encoding;
	}

	/**
	 * Length in encoding units.
	 */
	public function units(): int {
		return $this->units;
	}

	/**
	 * Number of characters.
	 */
	public function characters(): int {
		return $this->characters;
	}

	/**
	 * Number of segments (billed SMS). An empty message counts as 0.
	 */
	public function segments(): int {
		return count( $this->parts );
	}

	/**
	 * The text of each segment.
	 *
	 * @return array<int,string>
	 */
	public function parts(): array {
		return $this->parts;
	}

	/**
	 * Capacity of each segment: 160/70 for a single SMS, 153/67 when concatenated.
	 */
	public function per_segment(): int {
		return $this->per_segment;
	}

	/**
	 * Units left before another segment is needed.
	 */
	public function remaining(): int {
		$used = $this->units;
		if ( $this->segments() > 1 ) {
			// Segment boundaries never split a character, so measure the last part itself.
			$used = SegmentCounter::units_of( (string) end( $this->parts ), $this->encoding );
		}

		return max( 0, $this->per_segment - $used );
	}

	/**
	 * Array form for JSON, CLI tables and the admin preview.
	 *
	 * @return array{encoding:string,characters:int,units:int,segments:int,per_segment:int,remaining:int}
	 */
	public function to_array(): array {
		return array(
			'encoding'    => $this->encoding,
			'characters'  => $this->characters,
			'units'       => $this->units,
			'segments'    => $this->segments(),
			'per_segment' => $this->per_segment,
			'remaining'   => $this->remaining(),
		);
	}
}
