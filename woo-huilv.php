<?php
/**
 * Plugin Name: WOO 汇率转换
 * Plugin URI: https://example.com/woo-huilv
 * Description: 根据翻译插件的语言切换，自动将 WooCommerce 商品价格转换为对应地区的货币。支持 ExchangeRate API，可配置刷新频率。
 * Version: 0.3
 * Author: 石斑鱼定制
 * Author URI: https://example.com
 * Text Domain: woo-huilv
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 插件常量
define( 'WOO_HUILV_VERSION', '0.3' );
define( 'WOO_HUILV_FILE', __FILE__ );
define( 'WOO_HUILV_PATH', plugin_dir_path( __FILE__ ) );
define( 'WOO_HUILV_URL', plugin_dir_url( __FILE__ ) );
define( 'WOO_HUILV_BASENAME', plugin_basename( __FILE__ ) );

/**
 * 主插件类
 */
final class WOO_Huilv {

    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 获取单例
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数
     */
    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * 加载文件
     */
    private function includes() {
        require_once WOO_HUILV_PATH . 'includes/class-exchange-rate-api.php';
        require_once WOO_HUILV_PATH . 'includes/class-currency-converter.php';
        require_once WOO_HUILV_PATH . 'includes/class-product-fixed-prices.php';
        require_once WOO_HUILV_PATH . 'includes/class-translation-bridge.php';

        if ( is_admin() ) {
            require_once WOO_HUILV_PATH . 'includes/class-admin-settings.php';
        }
    }

    /**
     * 初始化钩子
     */
    private function init_hooks() {
        // 声明 WooCommerce HPOS 兼容性
        add_action( 'before_woocommerce_init', function() {
            if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WOO_HUILV_FILE, true );
            }
        } );

        // 检查 WooCommerce 是否激活
        add_action( 'admin_notices', array( $this, 'check_woocommerce' ) );

        // 初始化插件
        add_action( 'init', array( $this, 'load_textdomain' ) );

        // 定时任务
        add_action( 'woo_huilv_refresh_rates', array( 'WOO_Huilv_Exchange_Rate_API', 'scheduled_refresh' ) );
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        // 前台测试模式工具栏
        add_action( 'wp_footer', array( $this, 'render_test_mode_bar' ) );
        add_action( 'wp_ajax_woo_huilv_switch_test_currency', array( $this, 'ajax_switch_test_currency' ) );
        add_action( 'wp_ajax_nopriv_woo_huilv_switch_test_currency', array( $this, 'ajax_switch_test_currency' ) );

        // AJAX 手动刷新
        add_action( 'wp_ajax_woo_huilv_manual_refresh', array( $this, 'ajax_manual_refresh' ) );

        // AJAX 保存手动汇率
        add_action( 'wp_ajax_woo_huilv_save_manual_rate', array( $this, 'ajax_save_manual_rate' ) );
        add_action( 'wp_ajax_woo_huilv_delete_manual_rate', array( $this, 'ajax_delete_manual_rate' ) );
    }

    /**
     * 加载翻译
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'woo-huilv', false, dirname( WOO_HUILV_BASENAME ) . '/languages/' );
    }

    /**
     * 插件激活
     */
    public function activate() {
        // 设置默认选项
        $defaults = array(
            'api_key'           => '5bf039521917e1c8493624c6',
            'base_currency'     => '',
            'refresh_days'      => 1,
            'auto_refresh'      => 'yes',
            'enabled'           => 'yes',
            'test_mode'         => 'no',
            'test_currency'     => 'JPY',
            'show_rate_notice'  => 'yes',
            'manual_rates'      => array(),
            'language_currency_map' => array(
                'ja' => 'JPY',
                'zh' => 'CNY',
                'ko' => 'KRW',
                'de' => 'EUR',
                'fr' => 'EUR',
                'es' => 'EUR',
                'it' => 'EUR',
                'pt' => 'BRL',
                'ru' => 'RUB',
                'ar' => 'SAR',
                'th' => 'THB',
                'vi' => 'VND',
                'en' => 'USD',
            ),
            'decimal_map' => array(
                'JPY' => 0,
                'KRW' => 0,
                'VND' => 0,
            ),
        );

        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( 'woo_huilv_' . $key ) ) {
                update_option( 'woo_huilv_' . $key, $value );
            }
        }

        // 设置定时任务（仅在自动刷新开启时）
        $auto_refresh = get_option( 'woo_huilv_auto_refresh', 'yes' );
        if ( $auto_refresh === 'yes' ) {
            if ( ! wp_next_scheduled( 'woo_huilv_refresh_rates' ) ) {
                wp_schedule_event( time(), 'woo_huilv_interval', 'woo_huilv_refresh_rates' );
            }
            // 立即获取一次汇率
            WOO_Huilv_Exchange_Rate_API::fetch_rates();
        }
    }

    /**
     * 插件停用
     */
    public function deactivate() {
        wp_clear_scheduled_hook( 'woo_huilv_refresh_rates' );
    }

    /**
     * 检查 WooCommerce
     */
    public function check_woocommerce() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            echo '<div class="error"><p>';
            echo esc_html__( 'WOO 汇率转换插件需要 WooCommerce 才能正常工作，请先安装并激活 WooCommerce。', 'woo-huilv' );
            echo '</p></div>';
        }
    }

    /**
     * AJAX 手动刷新汇率
     */
    public function ajax_manual_refresh() {
        check_ajax_referer( 'woo_huilv_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '权限不足' );
        }

        $result = WOO_Huilv_Exchange_Rate_API::fetch_rates();

        if ( $result ) {
            $rates = get_option( 'woo_huilv_cached_rates', array() );
            $last_update = get_option( 'woo_huilv_last_update', '' );
            wp_send_json_success( array(
                'message'     => '汇率已成功刷新！',
                'last_update' => $last_update,
                'rates_count' => count( $rates ),
            ) );
        } else {
            wp_send_json_error( '刷新失败，请检查 API Key 是否正确。' );
        }
    }

    /**
     * 渲染前台测试模式浮动工具栏
     */
    public function render_test_mode_bar() {
        if ( get_option( 'woo_huilv_test_mode', 'no' ) !== 'yes' ) {
            return;
        }
        if ( get_option( 'woo_huilv_enabled', 'yes' ) !== 'yes' ) {
            return;
        }

        // 获取当前测试货币（优先 cookie）
        $base_currency = WOO_Huilv_Exchange_Rate_API::get_base_currency();
        $test_currency = $base_currency; // 默认无转换
        if ( isset( $_COOKIE['woo_huilv_test_currency'] ) ) {
            $cookie_val = sanitize_text_field( $_COOKIE['woo_huilv_test_currency'] );
            $test_currency = ! empty( $cookie_val ) ? $cookie_val : $base_currency;
        } else {
            $test_currency = get_option( 'woo_huilv_test_currency', 'JPY' );
        }
        $lang_map      = get_option( 'woo_huilv_language_currency_map', array() );
        $cached_rates  = WOO_Huilv_Exchange_Rate_API::get_cached_rates();

        // 收集所有已映射的货币
        $mapped_currencies = array_unique( array_values( $lang_map ) );
        sort( $mapped_currencies );
        ?>
        <div id="woo-huilv-test-bar">
            <div class="woo-huilv-test-bar-inner">
                <span class="woo-huilv-test-badge">🧪 <?php esc_html_e( '汇率测试模式', 'woo-huilv' ); ?></span>
                <span class="woo-huilv-test-label"><?php esc_html_e( '切换货币：', 'woo-huilv' ); ?></span>
                <select id="woo-huilv-test-currency-select">
                    <option value="<?php echo esc_attr( $base_currency ); ?>" <?php selected( $test_currency, $base_currency ); ?>><?php echo esc_html( $base_currency . ' (基础货币 - 无转换)' ); ?></option>
                    <?php foreach ( $mapped_currencies as $mc ) :
                        if ( $mc === $base_currency ) continue;
                        $rate_display = isset( $cached_rates[ $mc ] ) ? ' (1 ' . $base_currency . ' = ' . number_format( $cached_rates[ $mc ], 2 ) . ')' : '';
                    ?>
                        <option value="<?php echo esc_attr( $mc ); ?>" <?php selected( $test_currency, $mc ); ?>>
                            <?php echo esc_html( $mc . $rate_display ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="woo-huilv-test-hint"><?php esc_html_e( '仅管理员可见 · 后台可关闭', 'woo-huilv' ); ?></span>
            </div>
        </div>
        <style>
            #woo-huilv-test-bar {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                z-index: 999999;
                background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
                color: #e2e8f0;
                padding: 10px 20px;
                font-size: 13px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                box-shadow: 0 -4px 20px rgba(0,0,0,0.3);
                border-top: 3px solid #f59e0b;
            }
            .woo-huilv-test-bar-inner {
                display: flex;
                align-items: center;
                gap: 12px;
                max-width: 1200px;
                margin: 0 auto;
                flex-wrap: wrap;
            }
            .woo-huilv-test-badge {
                background: #f59e0b;
                color: #1e293b;
                padding: 3px 10px;
                border-radius: 12px;
                font-weight: 700;
                font-size: 12px;
                white-space: nowrap;
            }
            .woo-huilv-test-label {
                color: #94a3b8;
                white-space: nowrap;
            }
            #woo-huilv-test-currency-select {
                background: #475569;
                color: #f1f5f9;
                border: 1px solid #64748b;
                padding: 4px 10px;
                border-radius: 6px;
                font-size: 13px;
                cursor: pointer;
            }
            #woo-huilv-test-currency-select:focus {
                outline: none;
                border-color: #f59e0b;
            }
            .woo-huilv-test-hint {
                color: #64748b;
                font-size: 11px;
                margin-left: auto;
                white-space: nowrap;
            }
            @media (max-width: 600px) {
                .woo-huilv-test-hint { display: none; }
            }
        </style>
        <script>
            (function() {
                var select = document.getElementById('woo-huilv-test-currency-select');
                if (!select) return;
                select.addEventListener('change', function() {
                    var currency = this.value;
                    // 设置 cookie
                    document.cookie = 'woo_huilv_test_currency=' + currency + ';path=/;max-age=' + (86400 * 30);
                    // 刷新页面
                    location.reload();
                });
            })();
        </script>
        <?php
    }

    /**
     * AJAX 切换测试货币
     */
    public function ajax_switch_test_currency() {
        $currency = strtoupper( sanitize_text_field( $_POST['currency'] ?? '' ) );
        setcookie( 'woo_huilv_test_currency', $currency, time() + 86400 * 30, '/' );
        wp_send_json_success( array( 'currency' => $currency ) );
    }

    /**
     * AJAX 保存手动汇率
     */
    public function ajax_save_manual_rate() {
        check_ajax_referer( 'woo_huilv_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '权限不足' );
        }

        $currency = strtoupper( sanitize_text_field( $_POST['currency'] ?? '' ) );
        $rate     = floatval( $_POST['rate'] ?? 0 );

        if ( empty( $currency ) || $rate <= 0 ) {
            wp_send_json_error( '请输入有效的货币代码和汇率值' );
        }

        $manual_rates = get_option( 'woo_huilv_manual_rates', array() );
        $manual_rates[ $currency ] = $rate;
        update_option( 'woo_huilv_manual_rates', $manual_rates );

        wp_send_json_success( array(
            'message'  => $currency . ' 汇率已设置为 ' . $rate,
            'currency' => $currency,
            'rate'     => $rate,
        ) );
    }

    /**
     * AJAX 删除手动汇率
     */
    public function ajax_delete_manual_rate() {
        check_ajax_referer( 'woo_huilv_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '权限不足' );
        }

        $currency = strtoupper( sanitize_text_field( $_POST['currency'] ?? '' ) );

        if ( empty( $currency ) ) {
            wp_send_json_error( '请指定货币代码' );
        }

        $manual_rates = get_option( 'woo_huilv_manual_rates', array() );
        unset( $manual_rates[ $currency ] );
        update_option( 'woo_huilv_manual_rates', $manual_rates );

        wp_send_json_success( array(
            'message'  => $currency . ' 的手动汇率已删除，将使用 API 汇率',
            'currency' => $currency,
        ) );
    }
}

/**
 * 自定义 cron 间隔
 */
add_filter( 'cron_schedules', function( $schedules ) {
    $days = absint( get_option( 'woo_huilv_refresh_days', 1 ) );
    if ( $days < 1 ) {
        $days = 1;
    }
    $schedules['woo_huilv_interval'] = array(
        'interval' => $days * DAY_IN_SECONDS,
        'display'  => sprintf( __( '每 %d 天', 'woo-huilv' ), $days ),
    );
    return $schedules;
} );

/**
 * 初始化插件
 */
function woo_huilv_init() {
    return WOO_Huilv::instance();
}
add_action( 'plugins_loaded', 'woo_huilv_init' );

/**
 * ===================================================
 * 公共 API 函数 —— 供翻译插件或其他插件调用
 * ===================================================
 */

/**
 * 根据语言代码转换价格
 *
 * @param float  $price    原始价格
 * @param string $lang     语言代码，如 'ja', 'zh', 'ko'
 * @return float 转换后的价格
 *
 * 用法示例:
 *   $converted = woo_huilv_convert_price( 99.99, 'ja' );
 */
function woo_huilv_convert_price( $price, $lang = '' ) {
    if ( ! class_exists( 'WOO_Huilv_Currency_Converter' ) ) {
        return $price;
    }
    return WOO_Huilv_Currency_Converter::convert_by_language( $price, $lang );
}

/**
 * 获取语言对应的货币代码
 *
 * @param string $lang 语言代码
 * @return string 货币代码
 */
function woo_huilv_get_currency_for_language( $lang ) {
    if ( ! class_exists( 'WOO_Huilv_Currency_Converter' ) ) {
        return 'USD';
    }
    return WOO_Huilv_Currency_Converter::get_currency_for_language( $lang );
}

/**
 * 获取当前活跃的目标货币代码（基于当前语言环境）
 *
 * @return string 当前应该显示的货币代码
 */
function woo_huilv_get_current_currency() {
    if ( ! class_exists( 'WOO_Huilv_Translation_Bridge' ) ) {
        return get_option( 'woocommerce_currency', 'USD' );
    }
    return WOO_Huilv_Translation_Bridge::get_current_currency();
}

/**
 * 直接按货币代码转换价格
 *
 * @param float  $price         原始价格
 * @param string $to_currency   目标货币代码 (如 'JPY', 'CNY')
 * @param string $from_currency 源货币代码，默认使用商店基础货币
 * @return float 转换后的价格
 */
function woo_huilv_convert_price_by_currency( $price, $to_currency, $from_currency = '' ) {
    if ( ! class_exists( 'WOO_Huilv_Currency_Converter' ) ) {
        return $price;
    }
    return WOO_Huilv_Currency_Converter::convert( $price, $to_currency, $from_currency );
}
