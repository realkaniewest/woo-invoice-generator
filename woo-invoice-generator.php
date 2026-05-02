<?php
/**
 * Plugin Name: WooCommerce Invoice Generator
 * Description: Auto-generate invoice PDF for legal entities (VAT payers). Sends to customer email on order.
 * Version: 1.0.0
 * Author: realkaniewest
 */

if (!defined('ABSPATH')) exit;

class WC_Invoice_Generator {

    private $settings;

    public function __construct() {
        $this->settings = get_option('wig_settings', []);
        add_action('woocommerce_order_status_processing', [$this, 'generate_and_send']);
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('woocommerce_after_order_notes', [$this, 'add_checkout_fields']);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_checkout_fields']);
    }

    public function add_checkout_fields($checkout) {
        echo '<div id="wig-legal-fields"><h3>Реквизиты для счёта (Юрлица с НДС)</h3>';
        woocommerce_form_field('wig_company_name', ['type' => 'text', 'label' => 'Название организации', 'required' => false], $checkout->get_value('wig_company_name'));
        woocommerce_form_field('wig_inn',  ['type' => 'text', 'label' => 'ИНН',  'required' => false], $checkout->get_value('wig_inn'));
        woocommerce_form_field('wig_kpp',  ['type' => 'text', 'label' => 'КПП',  'required' => false], $checkout->get_value('wig_kpp'));
        woocommerce_form_field('wig_legal_address', ['type' => 'text', 'label' => 'Юридический адрес', 'required' => false], $checkout->get_value('wig_legal_address'));
        woocommerce_form_field('wig_product_name_override', ['type' => 'text', 'label' => 'Название товара в счёте (если отличается)', 'required' => false], $checkout->get_value('wig_product_name_override'));
        echo '</div>';
    }

    public function save_checkout_fields($order_id) {
        foreach (['wig_company_name','wig_inn','wig_kpp','wig_legal_address','wig_product_name_override'] as $f) {
            if (isset($_POST[$f])) update_post_meta($order_id, $f, sanitize_text_field($_POST[$f]));
        }
    }

    public function generate_and_send($order_id) {
        $order   = wc_get_order($order_id);
        $company = get_post_meta($order_id, 'wig_company_name', true);
        if (!$order || empty($company)) return;
        $pdf = $this->generate_pdf($order);
        if ($pdf) $this->send_email($order, $pdf);
    }

    private function generate_pdf($order) {
        $dir = wp_upload_dir()['basedir'] . '/invoices/';
        if (!file_exists($dir)) wp_mkdir_p($dir);
        $file = $dir . 'invoice-' . $order->get_id() . '.html';
        file_put_contents($file, $this->render_html($order));
        return $file;
    }

    private function render_html($order) {
        $s        = $this->settings;
        $oid      = $order->get_id();
        $company  = get_post_meta($oid, 'wig_company_name', true);
        $inn      = get_post_meta($oid, 'wig_inn', true);
        $kpp      = get_post_meta($oid, 'wig_kpp', true);
        $addr     = get_post_meta($oid, 'wig_legal_address', true);
        $name_ovr = get_post_meta($oid, 'wig_product_name_override', true);
        $rows = ''; $i = 1;
        foreach ($order->get_items() as $item) {
            $name  = $name_ovr ?: $item->get_name();
            $qty   = $item->get_quantity();
            $total = $item->get_total();
            $vat   = round($total * 0.2, 2);
            $rows .= "<tr><td>$i</td><td>$name</td><td>$qty</td><td>" . number_format($total,2,'.',' ') . "</td><td>" . number_format($vat,2,'.',' ') . "</td></tr>";
            $i++;
        }
        $total = $order->get_total();
        $vat_t = round($total * 0.2 / 1.2, 2);
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:Arial,sans-serif;font-size:12px}table{width:100%;border-collapse:collapse}td,th{border:1px solid #000;padding:4px}.nb{border:none}</style></head><body>
<h2 style="text-align:center">СЧЁТ № '.$oid.' от '.date('d.m.Y').'</h2>
<table><tr><td class="nb"><b>Поставщик:</b><br>'.esc_html($s['supplier_name']??'').'<br>ИНН: '.esc_html($s['supplier_inn']??'').' КПП: '.esc_html($s['supplier_kpp']??'').'<br>'.esc_html($s['supplier_bank']??'').'</td>
<td class="nb"><b>Покупатель:</b><br>'.esc_html($company).'<br>ИНН: '.esc_html($inn).' КПП: '.esc_html($kpp).'<br>'.esc_html($addr).'</td></tr></table><br>
<table><thead><tr><th>#</th><th>Наименование</th><th>Кол-во</th><th>Сумма</th><th>НДС 20%</th></tr></thead><tbody>'.$rows.'</tbody>
<tfoot><tr><td colspan="3"><b>Итого:</b></td><td colspan="2"><b>'.number_format($total,2,'.',' ').' руб.</b></td></tr>
<tr><td colspan="3">В т.ч. НДС 20%:</td><td colspan="2">'.number_format($vat_t,2,'.',' ').' руб.</td></tr></tfoot></table>
<br><p><b>Р/с:</b> '.esc_html($s['supplier_account']??'').'</p><p><b>Банк:</b> '.esc_html($s['supplier_bank_name']??'').'</p></body></html>';
    }

    private function send_email($order, $path) {
        wp_mail($order->get_billing_email(), 'Счёт №'.$order->get_id().' от '.get_bloginfo('name'), 'Во вложении счёт для вашего заказа.', ['Content-Type: text/plain; charset=UTF-8'], [$path]);
    }

    public function add_menu() {
        add_submenu_page('woocommerce','Invoice Generator','Invoice Generator','manage_options','wig-settings',[$this,'settings_page']);
    }

    public function register_settings() {
        register_setting('wig_group','wig_settings');
    }

    public function settings_page() {
        $s = $this->settings; ?>
        <div class="wrap"><h1>Invoice Generator — Реквизиты поставщика</h1>
        <form method="post" action="options.php"><?php settings_fields('wig_group'); ?>
        <table class="form-table">
            <tr><th>Название организации</th><td><input name="wig_settings[supplier_name]" value="<?= esc_attr($s['supplier_name']??'') ?>" class="regular-text"></td></tr>
            <tr><th>ИНН</th><td><input name="wig_settings[supplier_inn]" value="<?= esc_attr($s['supplier_inn']??'') ?>" class="regular-text"></td></tr>
            <tr><th>КПП</th><td><input name="wig_settings[supplier_kpp]" value="<?= esc_attr($s['supplier_kpp']??'') ?>" class="regular-text"></td></tr>
            <tr><th>Расчётный счёт</th><td><input name="wig_settings[supplier_account]" value="<?= esc_attr($s['supplier_account']??'') ?>" class="regular-text"></td></tr>
            <tr><th>Название банка</th><td><input name="wig_settings[supplier_bank_name]" value="<?= esc_attr($s['supplier_bank_name']??'') ?>" class="regular-text"></td></tr>
            <tr><th>БИК / Доп. реквизиты</th><td><input name="wig_settings[supplier_bank]" value="<?= esc_attr($s['supplier_bank']??'') ?>" class="regular-text"></td></tr>
        </table><?php submit_button(); ?>
        </form></div><?php
    }
}

new WC_Invoice_Generator();