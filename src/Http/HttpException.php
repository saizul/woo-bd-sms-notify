<?php
/**
 * Transport failure.
 *
 * @package Saizul\BdSms
 */

declare(strict_types=1);

namespace Saizul\BdSms\Http;

use RuntimeException;

/**
 * Raised when no HTTP response was received.
 */
final class HttpException extends RuntimeException {
}
