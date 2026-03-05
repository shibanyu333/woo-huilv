<?php
/**
 * 汇率 API 服务类
 *
 * 负责从 ExchangeRate-API 获取汇率数据并缓存
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_Huilv_Exchange_Rate_API {

    /**
     * API 基础 URL
     */
    const API_BASE_URL = 'https://v6.exchangerate-api.com/v6/';

    /**
     * 获取汇率数据
     *
     * @param string $base_currency 基础货币代码，默认从设置读取
     * @return bool 是否成功
     */
    public static function fetch_rates( $base_currency = '' ) {
        $api_key = get_option( 'woo_huilv_api_key', '' );

        if ( empty( $api_key ) ) {
            self::log( '未设置 API Key' );
            return false;
        }

        if ( empty( $base_currency ) ) {
            $base_currency = self::get_base_currency();
        }

        $url = self::API_BASE_URL . $api_key . '/latest/' . $base_currency;

        $response = wp_remote_get( $url, array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            self::log( 'API 请求失败: ' . $response->get_error_message() );
            return false;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            self::log( 'API 返回异常状态码: ' . $status_code );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( empty( $data ) || $data['result'] !== 'success' ) {
            $error_type = isset( $data['error-type'] ) ? $data['error-type'] : '未知错误';
            self::log( 'API 返回错误: ' . $error_type );
            return false;
        }

        if ( ! isset( $data['conversion_rates'] ) || ! is_array( $data['conversion_rates'] ) ) {
            self::log( 'API 返回数据格式异常' );
            return false;
        }

        // 保存汇率数据
        update_option( 'woo_huilv_cached_rates', $data['conversion_rates'] );
        update_option( 'woo_huilv_rates_base', $base_currency );
        update_option( 'woo_huilv_last_update', current_time( 'mysql' ) );
        update_option( 'woo_huilv_last_update_timestamp', time() );

        self::log( '汇率数据已更新，基础货币: ' . $base_currency . '，共 ' . count( $data['conversion_rates'] ) . ' 种货币' );

        // 触发更新完成的动作钩子
        do_action( 'woo_huilv_rates_updated', $data['conversion_rates'], $base_currency );

        return true;
    }

    /**
     * 定时任务回调
     */
    public static function scheduled_refresh() {
        // 检查自动刷新是否开启
        $auto_refresh = get_option( 'woo_huilv_auto_refresh', 'yes' );
        if ( $auto_refresh !== 'yes' ) {
            // 自动刷新已关闭，清除定时任务
            wp_clear_scheduled_hook( 'woo_huilv_refresh_rates' );
            return;
        }
        self::fetch_rates();
    }

    /**
     * 获取缓存的汇率（合并手动汇率覆盖）
     *
     * @return array 汇率数组
     */
    public static function get_cached_rates() {
        $rates = get_option( 'woo_huilv_cached_rates', array() );

        // 手动汇率覆盖 API 汇率
        $manual_rates = get_option( 'woo_huilv_manual_rates', array() );
        if ( ! empty( $manual_rates ) && is_array( $manual_rates ) ) {
            foreach ( $manual_rates as $currency => $rate ) {
                $rates[ $currency ] = floatval( $rate );
            }
        }

        return $rates;
    }

    /**
     * 获取原始 API 缓存汇率（不含手动覆盖）
     *
     * @return array
     */
    public static function get_api_rates() {
        return get_option( 'woo_huilv_cached_rates', array() );
    }

    /**
     * 获取手动设置的汇率
     *
     * @return array
     */
    public static function get_manual_rates() {
        return get_option( 'woo_huilv_manual_rates', array() );
    }

    /**
     * 获取特定货币的汇率
     *
     * @param string $currency 货币代码
     * @return float|false 汇率值或 false
     */
    public static function get_rate( $currency ) {
        // 手动汇率优先
        $manual_rates = get_option( 'woo_huilv_manual_rates', array() );
        if ( isset( $manual_rates[ $currency ] ) ) {
            return floatval( $manual_rates[ $currency ] );
        }

        $rates = get_option( 'woo_huilv_cached_rates', array() );
        if ( isset( $rates[ $currency ] ) ) {
            return floatval( $rates[ $currency ] );
        }

        return false;
    }

    /**
     * 获取基础货币
     *
     * @return string
     */
    public static function get_base_currency() {
        $base = get_option( 'woo_huilv_base_currency', '' );
        // 为空时自动检测 WooCommerce 商店货币（使用安全方法避免过滤器递归）
        if ( empty( $base ) ) {
            $base = self::get_wc_currency();
        }
        return $base ? $base : 'USD';
    }

    /**
     * 获取 WooCommerce 商店原始货币（不受任何过滤器影响）
     *
     * 直接读取数据库选项，完全绕过 woocommerce_currency 过滤器链，
     * 避免在 filter_currency → get_base_currency → get_wc_currency 调用链中产生递归或状态污染。
     *
     * @return string
     */
    public static function get_wc_currency() {
        return get_option( 'woocommerce_currency', 'USD' );
    }

    /**
     * 检查是否需要刷新
     *
     * @return bool
     */
    public static function needs_refresh() {
        // 如果自动刷新已关闭，则不需要刷新
        $auto_refresh = get_option( 'woo_huilv_auto_refresh', 'yes' );
        if ( $auto_refresh !== 'yes' ) {
            return false;
        }

        $last_timestamp = get_option( 'woo_huilv_last_update_timestamp', 0 );

        if ( empty( $last_timestamp ) ) {
            return true;
        }

        $refresh_days = absint( get_option( 'woo_huilv_refresh_days', 1 ) );
        if ( $refresh_days < 1 ) {
            $refresh_days = 1;
        }

        $refresh_seconds = $refresh_days * DAY_IN_SECONDS;

        return ( time() - $last_timestamp ) >= $refresh_seconds;
    }

    /**
     * 如果需要则自动刷新
     */
    public static function maybe_refresh() {
        if ( self::needs_refresh() ) {
            self::fetch_rates();
        }
    }

    /**
     * 获取上次更新时间
     *
     * @return string
     */
    public static function get_last_update() {
        return get_option( 'woo_huilv_last_update', __( '从未更新', 'woo-huilv' ) );
    }

    /**
     * 获取所有支持的货币列表
     *
     * @return array
     */
    public static function get_supported_currencies() {
        $rates = self::get_cached_rates();
        return array_keys( $rates );
    }

    /**
     * 日志记录
     *
     * @param string $message
     */
    private static function log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WOO-HUILV] ' . $message );
        }
    }
}
