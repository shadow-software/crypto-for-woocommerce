<?php
/**
 * Raised when a JSON-RPC call cannot be completed or returns an error.
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * A transport- or protocol-level RPC failure. Carries a customer-safe message;
 * technical detail goes to the log, not the buyer.
 */
final class RpcException extends \Exception {
}
