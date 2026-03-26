<?php
if (!defined('ABSPATH')) exit;

class WC_Gateway_USDT_ERC20_Split extends WC_Payment_Gateway {

  // ثابت: عنوان عمولتك
  const FEE_ADDRESS = '0xf9f9a4bd0bb5624ee6e170e8278cc507e8af2062';
  const FEE_BPS = 100; // 1% = 100 basis points
  const MERCHANT_BPS = 9900; // 99%
  const USDT_DECIMALS = 6;

  // USDT ERC20 contract on Ethereum mainnet
  const USDT_CONTRACT = '0xdAC17F958D2ee523a2206206994597C13D831ec7';

  public function __construct() {
    $this->id                 = 'usdt_erc20_split';
    $this->method_title       = __('USDT (ERC20) Split', 'usdt-erc20-split');
    $this->method_description = __('Pay with USDT on Ethereum mainnet. Customer sends two transfers: 99% to merchant, 1% fee to platform.', 'usdt-erc20-split');
    $this->has_fields         = false;

    $this->init_form_fields();
    $this->init_settings();

    $this->title        = $this->get_option('title');
    $this->description  = $this->get_option('description');
    $this->enabled      = $this->get_option('enabled');

    add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
    add_action('woocommerce_view_order', [$this, 'maybe_render_view_order_pay_instructions'], 20);

    // AJAX check endpoint for customer
    add_action('wp_ajax_usdt_erc20_split_check_payment', [$this, 'ajax_check_payment']);
    add_action('wp_ajax_nopriv_usdt_erc20_split_check_payment', [$this, 'ajax_check_payment']);
  }

  public function init_form_fields() {
    $this->form_fields = [
      'enabled' => [
        'title'   => __('Enable/Disable', 'usdt-erc20-split'),
        'type'    => 'checkbox',
        'label'   => __('Enable USDT (ERC20) Split payments', 'usdt-erc20-split'),
        'default' => 'no',
      ],
      'title' => [
        'title'       => __('Title', 'usdt-erc20-split'),
        'type'        => 'text',
        'default'     => __('USDT (ERC20)', 'usdt-erc20-split'),
        'desc_tip'    => true,
      ],
      'description' => [
        'title'       => __('Description', 'usdt-erc20-split'),
        'type'        => 'textarea',
        'default'     => __('Pay with USDT on Ethereum mainnet. You will be asked to send two USDT transfers: 99% to the merchant and 1% platform fee.', 'usdt-erc20-split'),
      ],
      'merchant_address' => [
        'title'       => __('Merchant USDT (ERC20) Receiving Address', 'usdt-erc20-split'),
        'type'        => 'text',
        'description' => __('Ethereum address (0x...) where merchant receives 99%. Changing it affects new orders only.', 'usdt-erc20-split'),
        'default'     => '',
      ],
      'rpc_url' => [
        'title'       => __('Ethereum RPC HTTPS URL', 'usdt-erc20-split'),
        'type'        => 'text',
        'description' => __('Example: https://eth-mainnet.g.alchemy.com/v2/KEY or https://mainnet.infura.io/v3/KEY', 'usdt-erc20-split'),
        'default'     => '',
      ],
      'confirmations' => [
        'title'       => __('Confirmations (Blocks)', 'usdt-erc20-split'),
        'type'        => 'number',
        'description' => __('Minimum block confirmations before marking paid. 2-5 recommended.', 'usdt-erc20-split'),
        'default'     => 2,
        'custom_attributes' => ['min' => 0, 'step' => 1],
      ],
      'invoice_ttl_minutes' => [
        'title'       => __('Invoice TTL (minutes)', 'usdt-erc20-split'),
        'type'        => 'number',
        'default'     => 30,
        'custom_attributes' => ['min' => 5, 'step' => 1],
      ],
      'enable_cron_checks' => [
        'title'   => __('Cron Auto-Checks', 'usdt-erc20-split'),
        'type'    => 'checkbox',
        'label'   => __('Enable WP-Cron checks every 5 minutes (best effort)', 'usdt-erc20-split'),
        'default' => 'yes',
      ],
      'amount_mode' => [
        'title'       => __('Amount Mode', 'usdt-erc20-split'),
        'type'        => 'select',
        'description' => __('As requested: treat store totals as USD even if currency is SAR.', 'usdt-erc20-split'),
        'default'     => 'treat_as_usd',
        'options'     => [
          'treat_as_usd' => __('Treat order total numeric value as USD', 'usdt-erc20-split'),
        ],
      ],
    ];
  }

  public function is_available() {
    if ('yes' !== $this->enabled) return false;
    if (!$this->get_option('merchant_address')) return false;
    return parent::is_available();
  }

  public function validate_fields() { return true; }

  public function process_payment($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return ['result' => 'fail'];

    $merchant = $this->sanitize_eth_address($this->get_option('merchant_address'));
    if (!$merchant) {
      wc_add_notice(__('Merchant address is not configured.', 'usdt-erc20-split'), 'error');
      return ['result' => 'fail'];
    }

    // "Treat as USD" numeric value. (No FX conversion.)
    $total = (float) $order->get_total();

    $merchant_amount = $this->round_usdt($total * (self::MERCHANT_BPS / 10000));
    $fee_amount      = $this->round_usdt($total * (self::FEE_BPS / 10000));

    $created_at = time();
    $ttl = (int) $this->get_option('invoice_ttl_minutes', 30) * 60;

    $invoice = [
      'version' => USDT_ERC20_SPLIT_VERSION,
      'network' => 'ethereum-mainnet',
      'token'   => 'USDT',
      'contract'=> self::USDT_CONTRACT,
      'merchant_address' => $merchant,
      'fee_address'      => self::FEE_ADDRESS,
      'merchant_amount'  => $merchant_amount,
      'fee_amount'       => $fee_amount,
      'created_at'       => $created_at,
      'expires_at'       => $created_at + $ttl,
      'paid'             => false,
      'merchant_tx'      => '',
      'fee_tx'           => '',
    ];

    $order->update_meta_data('_usdt_erc20_split_invoice', $invoice);
    $order->save();

    $order->update_status('on-hold', __('Awaiting USDT transfers (split payment).', 'usdt-erc20-split'));

    // Reduce stock etc.
    wc_reduce_stock_levels($order_id);

    return [
      'result'   => 'success',
      'redirect' => $this->get_return_url($order),
    ];
  }

  public function thankyou_page($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $invoice = $order->get_meta('_usdt_erc20_split_invoice', true);
    if (empty($invoice) || !is_array($invoice)) {
      echo '<p>' . esc_html__('Invoice not found.', 'usdt-erc20-split') . '</p>';
      return;
    }

    $this->render_payment_instructions($order, $invoice);
  }

  public function maybe_render_view_order_pay_instructions($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;
    if ($order->get_payment_method() !== $this->id) return;

    $invoice = $order->get_meta('_usdt_erc20_split_invoice', true);
    if (empty($invoice) || !is_array($invoice)) return;

    if (in_array($order->get_status(), ['on-hold', 'pending'], true)) {
      echo '<h2>' . esc_html__('USDT Payment Instructions', 'usdt-erc20-split') . '</h2>';
      $this->render_payment_instructions($order, $invoice);
    }
  }

  private function render_payment_instructions(WC_Order $order, array $invoice) {
    $order_id = $order->get_id();
    $expires_at = (int) ($invoice['expires_at'] ?? 0);

    $merchant_address = esc_html($invoice['merchant_address']);
    $fee_address      = esc_html($invoice['fee_address']);
    $merchant_amount  = esc_html($invoice['merchant_amount']);
    $fee_amount       = esc_html($invoice['fee_amount']);

    echo '<p><strong>' . esc_html__('Network:', 'usdt-erc20-split') . '</strong> Ethereum mainnet</p>';
    echo '<p><strong>' . esc_html__('Token:', 'usdt-erc20-split') . '</strong> USDT (ERC20)</p>';

    if ($expires_at) {
      echo '<p><strong>' . esc_html__('Invoice expires:', 'usdt-erc20-split') . '</strong> ' . esc_html(date_i18n('Y-m-d H:i:s', $expires_at)) . '</p>';
    }

    echo '<hr/>';

    echo '<h3>' . esc_html__('Step 1: Send 99% to Merchant', 'usdt-erc20-split') . '</h3>';
    echo '<p><strong>' . esc_html__('Amount (USDT):', 'usdt-erc20-split') . '</strong> ' . $merchant_amount . '</p>';
    echo '<p><strong>' . esc_html__('Address:', 'usdt-erc20-split') . '</strong> <code>' . $merchant_address . '</code></p>';

    echo '<h3>' . esc_html__('Step 2: Send 1% Platform Fee', 'usdt-erc20-split') . '</h3>';
    echo '<p><strong>' . esc_html__('Amount (USDT):', 'usdt-erc20-split') . '</strong> ' . $fee_amount . '</p>';
    echo '<p><strong>' . esc_html__('Fee Address:', 'usdt-erc20-split') . '</strong> <code>' . $fee_address . '</code></p>';

    echo '<hr/>';

    $check_url = esc_url(admin_url('admin-ajax.php'));
    $nonce = wp_create_nonce('usdt_erc20_split_check_' . $order_id);

    echo '<button class="button" id="usdt-erc20-split-check-btn" type="button">' . esc_html__('I have paid — Check payment', 'usdt-erc20-split') . '</button>';
    echo '<p id="usdt-erc20-split-check-result"></p>';

    // Minimal inline script
    ?>
    <script>
      (function(){
        const btn = document.getElementById('usdt-erc20-split-check-btn');
        const out = document.getElementById('usdt-erc20-split-check-result');
        if(!btn) return;

        btn.addEventListener('click', async function(){
          btn.disabled = true;
          out.textContent = 'Checking on-chain payment...';

          const form = new FormData();
          form.append('action', 'usdt_erc20_split_check_payment');
          form.append('order_id', '<?php echo esc_js((string)$order_id); ?>');
          form.append('nonce', '<?php echo esc_js($nonce); ?>');

          try{
            const res = await fetch('<?php echo $check_url; ?>', { method: 'POST', body: form, credentials: 'same-origin' });
            const data = await res.json();
            if(data && data.success){
              out.textContent = data.data.message || 'Payment verified.';
              if(data.data.reload){ window.location.reload(); }
            }else{
              out.textContent = (data && data.data && data.data.message) ? data.data.message : 'Not paid yet.';
            }
          }catch(e){
            out.textContent = 'Error while checking payment.';
          }finally{
            btn.disabled = false;
          }
        });
      })();
    </script>
    <?php
  }

  public function ajax_check_payment() {
    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

    if (!$order_id || !wp_verify_nonce($nonce, 'usdt_erc20_split_check_' . $order_id)) {
      wp_send_json_error(['message' => 'Invalid request.']);
    }

    $order = wc_get_order($order_id);
    if (!$order) wp_send_json_error(['message' => 'Order not found.']);

    $updated = self::maybe_check_and_mark_paid($order);

    if ($updated) {
      wp_send_json_success(['message' => 'Payment verified. Order updated.', 'reload' => true]);
    } else {
      wp_send_json_error(['message' => 'Payment not found yet. Please wait and try again.']);
    }
  }

  public static function maybe_check_and_mark_paid(WC_Order $order): bool {
    if ($order->get_payment_method() !== 'usdt_erc20_split') return false;
    if (!in_array($order->get_status(), ['on-hold', 'pending'], true)) return false;

    $settings = get_option('woocommerce_usdt_erc20_split_settings', []);
    $rpc_url = isset($settings['rpc_url']) ? trim($settings['rpc_url']) : '';
    if (!$rpc_url) return false;

    $invoice = $order->get_meta('_usdt_erc20_split_invoice', true);
    if (empty($invoice) || !is_array($invoice)) return false;

    $now = time();
    if (!empty($invoice['expires_at']) && $now > (int)$invoice['expires_at']) {
      // Expired: keep on-hold but note it.
      $order->add_order_note(__('USDT invoice expired; payment not verified.', 'usdt-erc20-split'));
      return false;
    }

    $merchant = $invoice['merchant_address'] ?? '';
    $fee_addr = $invoice['fee_address'] ?? '';
    $merchant_amount = $invoice['merchant_amount'] ?? '';
    $fee_amount = $invoice['fee_amount'] ?? '';
    $created_at = (int)($invoice['created_at'] ?? 0);

    if (!$merchant || !$fee_addr || $merchant_amount === '' || $fee_amount === '' || !$created_at) return false;

    $confirmations = isset($settings['confirmations']) ? (int)$settings['confirmations'] : 2;

    $eth = new USDT_ERC20_Split_Eth($rpc_url);

    // Find payment transfers for both addresses after invoice created time.
    $result = $eth->find_usdt_split_transfers([
      'usdt_contract'    => self::USDT_CONTRACT,
      'merchant_address' => $merchant,
      'fee_address'      => self::FEE_ADDRESS, // enforce fixed fee addr
      'merchant_amount'  => $merchant_amount,
      'fee_amount'       => $fee_amount,
      'created_at'       => $created_at,
      'min_confirmations'=> $confirmations,
    ]);

    if (!$result['ok']) {
      // Optional debug note
      if (!empty($result['error'])) {
        $order->add_order_note(sprintf(__('USDT check error: %s', 'usdt-erc20-split'), $result['error']));
      }
      return false;
    }

    if (empty($result['merchant_tx']) || empty($result['fee_tx'])) {
      return false;
    }

    // Mark paid
    $order->payment_complete($result['merchant_tx']);
    $order->add_order_note(sprintf(
      __('USDT split verified. Merchant tx: %1$s | Fee tx: %2$s', 'usdt-erc20-split'),
      $result['merchant_tx'],
      $result['fee_tx']
    ));

    // Store tx hashes
    $invoice['paid'] = true;
    $invoice['merchant_tx'] = $result['merchant_tx'];
    $invoice['fee_tx'] = $result['fee_tx'];
    $order->update_meta_data('_usdt_erc20_split_invoice', $invoice);

    // Record platform fee as "due" (ledger)
    $fee_due = (float)$fee_amount;
    $ledger = (array) get_option('usdt_erc20_split_fee_ledger', []);
    $ledger[] = [
      'order_id' => $order->get_id(),
      'fee_address' => self::FEE_ADDRESS,
      'fee_usdt' => $fee_due,
      'created_at' => gmdate('c'),
      'merchant_address' => $merchant,
      'merchant_usdt' => (float)$merchant_amount,
      'merchant_tx' => $result['merchant_tx'],
      'fee_tx' => $result['fee_tx'],
    ];
    update_option('usdt_erc20_split_fee_ledger', $ledger, false);

    $order->save();
    return true;
  }

  private function round_usdt($amount): string {
    // USDT 6 decimals: round to 6
    $rounded = round((float)$amount, self::USDT_DECIMALS);
    // Avoid scientific notation
    return number_format($rounded, self::USDT_DECIMALS, '.', '');
  }

  private function sanitize_eth_address($addr): ?string {
    $addr = trim((string)$addr);
    if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $addr)) return null;
    return strtolower($addr);
  }
}