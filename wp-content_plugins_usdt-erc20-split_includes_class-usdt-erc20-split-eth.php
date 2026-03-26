<?php
if (!defined('ABSPATH')) exit;

class USDT_ERC20_Split_Eth {

  private string $rpc;

  public function __construct(string $rpc_url) {
    $this->rpc = $rpc_url;
  }

  /**
   * Best-effort search for two USDT transfers:
   * - to merchant_address with merchant_amount
   * - to fee_address with fee_amount
   *
   * Approach:
   * - Query latest block number
   * - Pull logs for USDT Transfer events to merchant and fee addresses in a recent range
   * - Verify confirmations
   *
   * NOTE: This is a pragmatic implementation for shared hosting.
   * It searches a limited recent block window based on created_at (approx).
   */
  public function find_usdt_split_transfers(array $args): array {
    try {
      $usdt = strtolower($args['usdt_contract']);
      $merchant = strtolower($args['merchant_address']);
      $fee = strtolower($args['fee_address']);

      $merchant_amount_str = (string)$args['merchant_amount'];
      $fee_amount_str = (string)$args['fee_amount'];
      $created_at = (int)$args['created_at'];
      $min_conf = (int)$args['min_confirmations'];

      // Convert amount string (6 decimals) -> integer base units
      $merchant_units = $this->usdt_to_units($merchant_amount_str);
      $fee_units = $this->usdt_to_units($fee_amount_str);

      $latest = $this->rpc_call('eth_blockNumber', []);
      if (!isset($latest['result'])) return ['ok' => false, 'error' => 'No blockNumber result'];

      $latest_block = hexdec($latest['result']);

      // Estimate starting block by timestamp heuristics:
      // 12s per block; search back at most ~24 hours by default to avoid huge ranges
      $seconds_back = min(24 * 3600, max(900, time() - $created_at + 600)); // at least 15min, add buffer
      $blocks_back = (int)ceil($seconds_back / 12);
      $from_block = max(0, $latest_block - $blocks_back);

      // Transfer event signature: keccak256("Transfer(address,address,uint256)")
      $topic0 = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

      // topic2 is indexed "to" address (32 bytes). We'll filter by "to" for each recipient.
      $merchant_topic2 = $this->topic_address($merchant);
      $fee_topic2 = $this->topic_address($fee);

      $merchant_log = $this->find_first_matching_transfer($usdt, $topic0, $merchant_topic2, $merchant_units, $from_block, $latest_block);
      $fee_log = $this->find_first_matching_transfer($usdt, $topic0, $fee_topic2, $fee_units, $from_block, $latest_block);

      // Confirmations check
      if ($merchant_log && !$this->has_confirmations($merchant_log['blockNumber'], $latest_block, $min_conf)) {
        $merchant_log = null;
      }
      if ($fee_log && !$this->has_confirmations($fee_log['blockNumber'], $latest_block, $min_conf)) {
        $fee_log = null;
      }

      return [
        'ok' => true,
        'merchant_tx' => $merchant_log ? $merchant_log['transactionHash'] : '',
        'fee_tx'      => $fee_log ? $fee_log['transactionHash'] : '',
      ];
    } catch (Throwable $e) {
      return ['ok' => false, 'error' => $e->getMessage()];
    }
  }

  private function find_first_matching_transfer(string $contract, string $topic0, string $topic2_to, string $expected_units_hex, int $from, int $to): ?array {
    $params = [[
      'fromBlock' => $this->to_hex($from),
      'toBlock'   => $this->to_hex($to),
      'address'   => $contract,
      'topics'    => [$topic0, null, $topic2_to],
    ]];

    $logs = $this->rpc_call('eth_getLogs', $params);
    if (empty($logs['result']) || !is_array($logs['result'])) return null;

    // Pick first log whose data equals expected amount
    foreach ($logs['result'] as $log) {
      if (!isset($log['data'], $log['transactionHash'], $log['blockNumber'])) continue;
      // data is uint256 value
      $data = strtolower($log['data']);
      if ($this->strip_0x($data) === $this->strip_0x(strtolower($expected_units_hex))) {
        return [
          'transactionHash' => $log['transactionHash'],
          'blockNumber' => hexdec($log['blockNumber']),
        ];
      }
    }
    return null;
  }

  private function has_confirmations(int $tx_block, int $latest_block, int $min_conf): bool {
    if ($min_conf <= 0) return true;
    $conf = ($latest_block - $tx_block) + 1;
    return $conf >= $min_conf;
  }

  private function usdt_to_units(string $amount): string {
    // amount like "12.340000" -> integer * 1e6 -> hex
    $amount = trim($amount);
    if ($amount === '') $amount = '0';

    // Normalize to 6 decimals
    if (strpos($amount, '.') === false) {
      $whole = $amount;
      $frac = '0';
    } else {
      [$whole, $frac] = explode('.', $amount, 2);
    }
    $whole = preg_replace('/\D/', '', $whole);
    $frac = preg_replace('/\D/', '', $frac);
    $frac = str_pad(substr($frac, 0, 6), 6, '0');

    $int_str = ltrim($whole . $frac, '0');
    if ($int_str === '') $int_str = '0';

    // Convert decimal string to hex (BCMath preferred; fallback limited)
    if (function_exists('bcadd')) {
      $hex = $this->decstr_to_hex_bc($int_str);
    } else {
      // Fallback: may overflow for very large values
      $hex = dechex((int)$int_str);
    }
    return '0x' . str_pad($hex, 64, '0', STR_PAD_LEFT);
  }

  private function decstr_to_hex_bc(string $dec): string {
    $hex = '';
    while (bccomp($dec, '0') > 0) {
      $rem = bcmod($dec, '16');
      $dec = bcdiv($dec, '16', 0);
      $hex = dechex((int)$rem) . $hex;
    }
    return $hex === '' ? '0' : $hex;
  }

  private function topic_address(string $addr): string {
    // addr must be 0x + 40 hex
    $addr = strtolower($addr);
    $addr = $this->strip_0x($addr);
    return '0x' . str_pad($addr, 64, '0', STR_PAD_LEFT);
  }

  private function to_hex(int $n): string {
    return '0x' . dechex($n);
  }

  private function strip_0x(string $h): string {
    return (strpos($h, '0x') === 0) ? substr($h, 2) : $h;
  }

  private function rpc_call(string $method, array $params): array {
    $body = wp_json_encode([
      'jsonrpc' => '2.0',
      'id'      => 1,
      'method'  => $method,
      'params'  => $params,
    ]);

    $resp = wp_remote_post($this->rpc, [
      'timeout' => 20,
      'headers' => ['Content-Type' => 'application/json'],
      'body'    => $body,
    ]);

    if (is_wp_error($resp)) {
      return ['error' => $resp->get_error_message()];
    }
    $code = wp_remote_retrieve_response_code($resp);
    $raw  = wp_remote_retrieve_body($resp);
    if ($code < 200 || $code >= 300) {
      return ['error' => 'HTTP ' . $code . ' ' . $raw];
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) return ['error' => 'Invalid JSON'];
    if (isset($json['error'])) return ['error' => is_array($json['error']) ? wp_json_encode($json['error']) : (string)$json['error']];
    return $json;
  }
}