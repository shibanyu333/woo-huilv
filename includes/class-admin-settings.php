<?php
/**
 * 后台管理设置页面
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_Huilv_Admin_Settings {

    /**
     * 初始化
     */
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    /**
     * 添加菜单
     */
    public static function add_menu() {
        add_submenu_page(
            'woocommerce',
            __( '汇率转换设置', 'woo-huilv' ),
            __( '汇率转换', 'woo-huilv' ),
            'manage_options',
            'woo-huilv-settings',
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * 注册设置
     */
    public static function register_settings() {
        register_setting( 'woo_huilv_settings', 'woo_huilv_enabled' );
        register_setting( 'woo_huilv_settings', 'woo_huilv_test_mode' );
        register_setting( 'woo_huilv_settings', 'woo_huilv_test_currency', array(
            'sanitize_callback' => 'sanitize_text_field',
        ) );
        register_setting( 'woo_huilv_settings', 'woo_huilv_show_rate_notice' );
        register_setting( 'woo_huilv_settings', 'woo_huilv_api_key', array(
            'sanitize_callback' => 'sanitize_text_field',
        ) );
        register_setting( 'woo_huilv_settings', 'woo_huilv_base_currency', array(
            'sanitize_callback' => 'sanitize_text_field',
        ) );
        register_setting( 'woo_huilv_settings', 'woo_huilv_auto_refresh' );
        register_setting( 'woo_huilv_settings', 'woo_huilv_refresh_days', array(
            'sanitize_callback' => 'absint',
        ) );
        register_setting( 'woo_huilv_settings', 'woo_huilv_manual_rates', array(
            'sanitize_callback' => array( __CLASS__, 'sanitize_manual_rates' ),
        ) );
        register_setting( 'woo_huilv_settings', 'woo_huilv_language_currency_map', array(
            'sanitize_callback' => array( __CLASS__, 'sanitize_map' ),
        ) );
        register_setting( 'woo_huilv_settings', 'woo_huilv_decimal_map', array(
            'sanitize_callback' => array( __CLASS__, 'sanitize_decimal_map' ),
        ) );
    }

    /**
     * 清理语言货币映射
     */
    public static function sanitize_map( $input ) {
        if ( ! is_array( $input ) ) {
            return array();
        }
        $clean = array();
        foreach ( $input as $lang => $currency ) {
            $lang     = sanitize_text_field( $lang );
            $currency = strtoupper( sanitize_text_field( $currency ) );
            if ( ! empty( $lang ) && ! empty( $currency ) ) {
                $clean[ $lang ] = $currency;
            }
        }
        return $clean;
    }

    /**
     * 清理手动汇率
     */
    public static function sanitize_manual_rates( $input ) {
        if ( ! is_array( $input ) ) {
            return array();
        }
        $clean = array();
        foreach ( $input as $currency => $rate ) {
            $currency = strtoupper( sanitize_text_field( $currency ) );
            $rate     = floatval( $rate );
            if ( ! empty( $currency ) && $rate > 0 ) {
                $clean[ $currency ] = $rate;
            }
        }
        return $clean;
    }

    /**
     * 清理小数映射
     */
    public static function sanitize_decimal_map( $input ) {
        if ( ! is_array( $input ) ) {
            return array();
        }
        $clean = array();
        foreach ( $input as $currency => $decimals ) {
            $currency = strtoupper( sanitize_text_field( $currency ) );
            $decimals = absint( $decimals );
            if ( ! empty( $currency ) ) {
                $clean[ $currency ] = $decimals;
            }
        }
        return $clean;
    }

    /**
     * 加载资源
     */
    public static function enqueue_assets( $hook ) {
        if ( 'woocommerce_page_woo-huilv-settings' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'woo-huilv-admin',
            WOO_HUILV_URL . 'assets/css/admin.css',
            array(),
            WOO_HUILV_VERSION
        );

        wp_enqueue_script(
            'woo-huilv-admin',
            WOO_HUILV_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            WOO_HUILV_VERSION,
            true
        );

        wp_localize_script( 'woo-huilv-admin', 'wooHuilv', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'woo_huilv_nonce' ),
            'i18n'    => array(
                'refreshing'    => __( '正在刷新汇率...', 'woo-huilv' ),
                'refreshed'     => __( '汇率已刷新！', 'woo-huilv' ),
                'refresh_error' => __( '刷新失败，请重试', 'woo-huilv' ),
                'confirm_delete'=> __( '确定要删除这条映射吗？', 'woo-huilv' ),
            ),
        ) );
    }

    /**
     * 渲染设置页面
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $enabled        = get_option( 'woo_huilv_enabled', 'yes' );
        $test_mode      = get_option( 'woo_huilv_test_mode', 'no' );
        $test_currency  = get_option( 'woo_huilv_test_currency', 'JPY' );
        $show_notice    = get_option( 'woo_huilv_show_rate_notice', 'yes' );
        $api_key        = get_option( 'woo_huilv_api_key', '' );
        $base_currency  = WOO_Huilv_Exchange_Rate_API::get_base_currency();
        $auto_refresh  = get_option( 'woo_huilv_auto_refresh', 'yes' );
        $refresh_days  = get_option( 'woo_huilv_refresh_days', 1 );
        $last_update   = WOO_Huilv_Exchange_Rate_API::get_last_update();
        $cached_rates  = WOO_Huilv_Exchange_Rate_API::get_cached_rates();
        $api_rates     = WOO_Huilv_Exchange_Rate_API::get_api_rates();
        $manual_rates  = WOO_Huilv_Exchange_Rate_API::get_manual_rates();
        $lang_map      = get_option( 'woo_huilv_language_currency_map', array() );
        $decimal_map   = get_option( 'woo_huilv_decimal_map', array() );

        // 常用货币列表
        $common_currencies = self::get_common_currencies();
        $common_languages  = self::get_common_languages();

        ?>
        <div class="wrap woo-huilv-wrap">
            <h1>
                <span class="dashicons dashicons-money-alt"></span>
                <?php esc_html_e( 'WOO 汇率转换设置', 'woo-huilv' ); ?>
            </h1>

            <!-- 状态卡片 -->
            <div class="woo-huilv-status-cards">
                <div class="status-card">
                    <div class="status-card-icon dashicons dashicons-clock"></div>
                    <div class="status-card-content">
                        <span class="status-card-label"><?php esc_html_e( '上次更新', 'woo-huilv' ); ?></span>
                        <span class="status-card-value" id="last-update-time"><?php echo esc_html( $last_update ); ?></span>
                    </div>
                </div>
                <div class="status-card">
                    <div class="status-card-icon dashicons dashicons-chart-bar"></div>
                    <div class="status-card-content">
                        <span class="status-card-label"><?php esc_html_e( '已缓存汇率', 'woo-huilv' ); ?></span>
                        <span class="status-card-value" id="rates-count"><?php echo count( $cached_rates ); ?> <?php esc_html_e( '种货币', 'woo-huilv' ); ?></span>
                    </div>
                </div>
                <div class="status-card">
                    <div class="status-card-icon dashicons dashicons-money-alt"></div>
                    <div class="status-card-content">
                        <span class="status-card-label"><?php esc_html_e( '基础货币', 'woo-huilv' ); ?></span>
                        <span class="status-card-value"><?php echo esc_html( $base_currency ); ?></span>
                    </div>
                </div>
                <div class="status-card">
                    <div class="status-card-content">
                        <button type="button" id="btn-refresh-rates" class="button button-primary">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e( '手动刷新汇率', 'woo-huilv' ); ?>
                        </button>
                    </div>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'woo_huilv_settings' ); ?>

                <!-- 基础设置 -->
                <div class="woo-huilv-section">
                    <h2><?php esc_html_e( '基础设置', 'woo-huilv' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( '启用插件', 'woo-huilv' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="woo_huilv_enabled" value="yes" <?php checked( $enabled, 'yes' ); ?> />
                                    <?php esc_html_e( '启用货币自动转换', 'woo-huilv' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( '关闭后所有价格将显示为商店原始货币', 'woo-huilv' ); ?></p>
                            </td>
                        </tr>
                        <!-- 测试模式 -->
                        <tr>
                            <th scope="row"><?php esc_html_e( '🧪 测试模式', 'woo-huilv' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="woo_huilv_test_mode" name="woo_huilv_test_mode" value="yes" <?php checked( $test_mode, 'yes' ); ?> />
                                    <?php esc_html_e( '启用测试模式（无须翻译插件即可切换货币）', 'woo-huilv' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( '开启后前台底部会出现货币切换工具栏，可直接选择要测试的目标货币。无须安装任何翻译插件。', 'woo-huilv' ); ?></p>
                            </td>
                        </tr>
                        <tr class="test-mode-row" <?php echo $test_mode !== 'yes' ? 'style="display:none;"' : ''; ?>>
                            <th scope="row">
                                <label for="woo_huilv_test_currency"><?php esc_html_e( '默认测试货币', 'woo-huilv' ); ?></label>
                            </th>
                            <td>
                                <select id="woo_huilv_test_currency" name="woo_huilv_test_currency">
                                    <?php foreach ( $common_currencies as $code => $name ) : ?>
                                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $test_currency, $code ); ?>>
                                            <?php echo esc_html( $code . ' - ' . $name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e( '前台工具栏的默认货币。也可以在前台工具栏实时切换。', 'woo-huilv' ); ?></p>
                            </td>
                        </tr>
                        <!-- 汇率参考提示 -->
                        <tr>
                            <th scope="row"><?php esc_html_e( '汇率参考提示', 'woo-huilv' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="woo_huilv_show_rate_notice" value="yes" <?php checked( $show_notice, 'yes' ); ?> />
                                    <?php esc_html_e( '在购物车和结账页显示"价格仅供参考"提示', 'woo-huilv' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( '当发生货币转换时，在购物车/结账页顶部显示一条提示，告知用户实际结算币种。提示语言会自动匹配当前语言。', 'woo-huilv' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="woo_huilv_api_key"><?php esc_html_e( 'API Key', 'woo-huilv' ); ?></label>
                            </th>
                            <td>
                                <input type="text" id="woo_huilv_api_key" name="woo_huilv_api_key"
                                       value="<?php echo esc_attr( $api_key ); ?>"
                                       class="regular-text" placeholder="<?php esc_attr_e( '输入 ExchangeRate-API Key', 'woo-huilv' ); ?>" />
                                <p class="description">
                                    <?php echo wp_kses(
                                        __( '从 <a href="https://www.exchangerate-api.com/" target="_blank">ExchangeRate-API.com</a> 获取免费 API Key', 'woo-huilv' ),
                                        array( 'a' => array( 'href' => array(), 'target' => array() ) )
                                    ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="woo_huilv_base_currency"><?php esc_html_e( '基础货币', 'woo-huilv' ); ?></label>
                            </th>
                            <td>
                                <?php
                                $wc_currency = WOO_Huilv_Exchange_Rate_API::get_wc_currency();
                                $saved_base  = get_option( 'woo_huilv_base_currency', '' );
                                ?>
                                <select id="woo_huilv_base_currency" name="woo_huilv_base_currency">
                                    <option value="" <?php selected( $saved_base, '' ); ?>>
                                        <?php echo esc_html( sprintf( __( '自动检测 (当前: %s)', 'woo-huilv' ), $wc_currency ) ); ?>
                                    </option>
                                    <?php foreach ( $common_currencies as $code => $name ) : ?>
                                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $saved_base, $code ); ?>>
                                            <?php echo esc_html( $code . ' - ' . $name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e( '选择"自动检测"将使用 WooCommerce 商店设置的货币作为基础货币。仅在需要不同基础货币时手动选择。', 'woo-huilv' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( '自动刷新汇率', 'woo-huilv' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="woo_huilv_auto_refresh" name="woo_huilv_auto_refresh" value="yes" <?php checked( $auto_refresh, 'yes' ); ?> />
                                    <?php esc_html_e( '启用自动刷新汇率', 'woo-huilv' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( '关闭后将不再自动从 API 获取汇率，仅使用手动设置的汇率或已缓存的数据', 'woo-huilv' ); ?></p>
                            </td>
                        </tr>
                        <tr class="auto-refresh-row" <?php echo $auto_refresh !== 'yes' ? 'style="display:none;"' : ''; ?>>
                            <th scope="row">
                                <label for="woo_huilv_refresh_days"><?php esc_html_e( '刷新频率（天）', 'woo-huilv' ); ?></label>
                            </th>
                            <td>
                                <input type="number" id="woo_huilv_refresh_days" name="woo_huilv_refresh_days"
                                       value="<?php echo esc_attr( $refresh_days ); ?>"
                                       min="1" max="30" step="1" class="small-text" />
                                <p class="description"><?php esc_html_e( '每隔多少天自动从 API 获取最新汇率（默认1天）', 'woo-huilv' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- 手动汇率设置 -->
                <div class="woo-huilv-section">
                    <h2><?php esc_html_e( '手动汇率设置', 'woo-huilv' ); ?></h2>
                    <p class="description"><?php esc_html_e( '手动设置的汇率将覆盖 API 自动获取的汇率。适用于需要固定汇率或关闭自动刷新的场景。', 'woo-huilv' ); ?></p>

                    <table class="widefat woo-huilv-map-table" id="manual-rates-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( '货币代码', 'woo-huilv' ); ?></th>
                                <th><?php esc_html_e( '手动汇率', 'woo-huilv' ); ?></th>
                                <th><?php esc_html_e( 'API 汇率', 'woo-huilv' ); ?></th>
                                <th><?php esc_html_e( '状态', 'woo-huilv' ); ?></th>
                                <th style="width: 60px;"><?php esc_html_e( '操作', 'woo-huilv' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( ! empty( $manual_rates ) ) : ?>
                                <?php foreach ( $manual_rates as $m_currency => $m_rate ) : ?>
                                    <tr class="map-row" data-currency="<?php echo esc_attr( $m_currency ); ?>">
                                        <td>
                                            <strong><?php echo esc_html( $m_currency ); ?></strong>
                                            <?php
                                            $currencies_list = self::get_common_currencies();
                                            if ( isset( $currencies_list[ $m_currency ] ) ) {
                                                echo '<br><small class="description">' . esc_html( $currencies_list[ $m_currency ] ) . '</small>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <input type="number" class="manual-rate-input" name="woo_huilv_manual_rates[<?php echo esc_attr( $m_currency ); ?>]"
                                                   value="<?php echo esc_attr( $m_rate ); ?>"
                                                   step="any" min="0" style="width: 120px;" />
                                        </td>
                                        <td class="rate-display">
                                            <?php echo isset( $api_rates[ $m_currency ] ) ? esc_html( number_format( $api_rates[ $m_currency ], 4 ) ) : '-'; ?>
                                        </td>
                                        <td>
                                            <span class="manual-rate-badge"><?php esc_html_e( '手动', 'woo-huilv' ); ?></span>
                                        </td>
                                        <td>
                                            <button type="button" class="button btn-remove-manual-rate" data-currency="<?php echo esc_attr( $m_currency ); ?>" title="<?php esc_attr_e( '删除', 'woo-huilv' ); ?>">
                                                <span class="dashicons dashicons-trash"></span>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5">
                                    <div class="woo-huilv-add-manual-rate">
                                        <select id="new-manual-currency" class="currency-select">
                                            <option value=""><?php esc_html_e( '-- 选择货币 --', 'woo-huilv' ); ?></option>
                                            <?php foreach ( $common_currencies as $cc => $cn ) : ?>
                                                <option value="<?php echo esc_attr( $cc ); ?>">
                                                    <?php echo esc_html( $cc . ' - ' . $cn ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="number" id="new-manual-rate" step="any" min="0" placeholder="<?php esc_attr_e( '汇率值', 'woo-huilv' ); ?>" style="width: 120px;" />
                                        <button type="button" id="btn-add-manual-rate" class="button">
                                            <span class="dashicons dashicons-plus-alt2"></span>
                                            <?php esc_html_e( '添加手动汇率', 'woo-huilv' ); ?>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- 语言-货币映射 -->
                <div class="woo-huilv-section">
                    <h2><?php esc_html_e( '语言 → 货币映射', 'woo-huilv' ); ?></h2>
                    <p class="description"><?php esc_html_e( '当翻译插件切换到对应语言时，商品价格将自动转换为该货币。', 'woo-huilv' ); ?></p>

                    <table class="widefat woo-huilv-map-table" id="lang-currency-map-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( '语言代码', 'woo-huilv' ); ?></th>
                                <th><?php esc_html_e( '目标货币', 'woo-huilv' ); ?></th>
                                <th><?php esc_html_e( '当前汇率', 'woo-huilv' ); ?></th>
                                <th style="width: 60px;"><?php esc_html_e( '操作', 'woo-huilv' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( ! empty( $lang_map ) ) : ?>
                                <?php foreach ( $lang_map as $lang_code => $currency_code ) : ?>
                                    <tr class="map-row">
                                        <td>
                                            <select name="woo_huilv_language_currency_map_lang[]" class="lang-select">
                                                <?php foreach ( $common_languages as $lc => $ln ) : ?>
                                                    <option value="<?php echo esc_attr( $lc ); ?>" <?php selected( $lang_code, $lc ); ?>>
                                                        <?php echo esc_html( $lc . ' - ' . $ln ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                                <?php if ( ! isset( $common_languages[ $lang_code ] ) ) : ?>
                                                    <option value="<?php echo esc_attr( $lang_code ); ?>" selected>
                                                        <?php echo esc_html( $lang_code ); ?>
                                                    </option>
                                                <?php endif; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="woo_huilv_language_currency_map_currency[]" class="currency-select">
                                                <?php foreach ( $common_currencies as $cc => $cn ) : ?>
                                                    <option value="<?php echo esc_attr( $cc ); ?>" <?php selected( $currency_code, $cc ); ?>>
                                                        <?php echo esc_html( $cc . ' - ' . $cn ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="rate-display">
                                            <?php
                                            $rate = isset( $cached_rates[ $currency_code ] ) ? $cached_rates[ $currency_code ] : '-';
                                            echo esc_html( $rate );
                                            ?>
                                        </td>
                                        <td>
                                            <button type="button" class="button btn-remove-row" title="<?php esc_attr_e( '删除', 'woo-huilv' ); ?>">
                                                <span class="dashicons dashicons-trash"></span>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4">
                                    <button type="button" id="btn-add-mapping" class="button">
                                        <span class="dashicons dashicons-plus-alt2"></span>
                                        <?php esc_html_e( '添加映射', 'woo-huilv' ); ?>
                                    </button>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- 小数位设置 -->
                <div class="woo-huilv-section">
                    <h2><?php esc_html_e( '小数位数设置', 'woo-huilv' ); ?></h2>
                    <p class="description"><?php esc_html_e( '为特定货币设置价格显示的小数位数（如日元、韩元通常为0位小数）', 'woo-huilv' ); ?></p>

                    <table class="widefat woo-huilv-map-table" id="decimal-map-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( '货币代码', 'woo-huilv' ); ?></th>
                                <th><?php esc_html_e( '小数位数', 'woo-huilv' ); ?></th>
                                <th style="width: 60px;"><?php esc_html_e( '操作', 'woo-huilv' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( ! empty( $decimal_map ) ) : ?>
                                <?php foreach ( $decimal_map as $dec_currency => $decimals ) : ?>
                                    <tr class="map-row">
                                        <td>
                                            <select name="woo_huilv_decimal_map_currency[]" class="currency-select">
                                                <?php foreach ( $common_currencies as $cc => $cn ) : ?>
                                                    <option value="<?php echo esc_attr( $cc ); ?>" <?php selected( $dec_currency, $cc ); ?>>
                                                        <?php echo esc_html( $cc . ' - ' . $cn ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" name="woo_huilv_decimal_map_decimals[]"
                                                   value="<?php echo esc_attr( $decimals ); ?>"
                                                   min="0" max="4" step="1" class="small-text" />
                                        </td>
                                        <td>
                                            <button type="button" class="button btn-remove-row" title="<?php esc_attr_e( '删除', 'woo-huilv' ); ?>">
                                                <span class="dashicons dashicons-trash"></span>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3">
                                    <button type="button" id="btn-add-decimal" class="button">
                                        <span class="dashicons dashicons-plus-alt2"></span>
                                        <?php esc_html_e( '添加设置', 'woo-huilv' ); ?>
                                    </button>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- 当前汇率一览 -->
                <?php if ( ! empty( $cached_rates ) ) : ?>
                <div class="woo-huilv-section">
                    <h2><?php esc_html_e( '当前生效汇率一览', 'woo-huilv' ); ?>
                        <small>(<?php esc_html_e( '基准', 'woo-huilv' ); ?>: 1 <?php echo esc_html( $base_currency ); ?>)</small>
                    </h2>
                    <p class="description"><?php esc_html_e( '紫色边框 = 已映射语言，橙色标记 = 手动覆盖汇率', 'woo-huilv' ); ?></p>
                    <div class="woo-huilv-rates-grid">
                        <?php
                        // 只显示已映射的货币以及一些常用货币
                        $mapped_currencies = array_values( $lang_map );
                        $show_currencies = array_unique( array_merge( $mapped_currencies, array( 'USD', 'EUR', 'GBP', 'JPY', 'CNY', 'KRW' ) ) );
                        sort( $show_currencies );
                        foreach ( $show_currencies as $sc ) :
                            if ( isset( $cached_rates[ $sc ] ) ) :
                                $is_manual = isset( $manual_rates[ $sc ] );
                                $is_mapped = in_array( $sc, $mapped_currencies );
                        ?>
                            <div class="rate-item <?php echo $is_mapped ? 'rate-item-active' : ''; ?> <?php echo $is_manual ? 'rate-item-manual' : ''; ?>">
                                <span class="rate-currency">
                                    <?php echo esc_html( $sc ); ?>
                                    <?php if ( $is_manual ) : ?>
                                        <small class="manual-tag"><?php esc_html_e( '手动', 'woo-huilv' ); ?></small>
                                    <?php endif; ?>
                                </span>
                                <span class="rate-value"><?php echo esc_html( number_format( $cached_rates[ $sc ], 4 ) ); ?></span>
                            </div>
                        <?php
                            endif;
                        endforeach;
                        ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 集成说明 -->
                <div class="woo-huilv-section">
                    <h2><?php esc_html_e( '翻译插件集成', 'woo-huilv' ); ?></h2>
                    <div class="woo-huilv-integration-info">
                        <h4><?php esc_html_e( '自动支持的翻译插件：', 'woo-huilv' ); ?></h4>
                        <ul>
                            <li>✅ WPML</li>
                            <li>✅ Polylang</li>
                            <li>✅ TranslatePress</li>
                            <li>✅ GTranslate</li>
                            <li>✅ Weglot</li>
                            <li>✅ ConveyThis</li>
                        </ul>

                        <h4><?php esc_html_e( '自定义集成接口（开发者）：', 'woo-huilv' ); ?></h4>
                        <div class="woo-huilv-code-block">
                            <p><strong>Filter 1:</strong> <?php esc_html_e( '传入语言代码', 'woo-huilv' ); ?></p>
                            <code>add_filter( 'woo_huilv_current_language', function( $lang ) { return 'ja'; } );</code>

                            <p><strong>Filter 2:</strong> <?php esc_html_e( '直接指定目标货币', 'woo-huilv' ); ?></p>
                            <code>add_filter( 'woo_huilv_target_currency', function( $c ) { return 'JPY'; } );</code>

                            <p><strong><?php esc_html_e( '公共函数', 'woo-huilv' ); ?>:</strong></p>
                            <code>$price = woo_huilv_convert_price( 99.99, 'ja' );</code>
                            <br><code>$price = woo_huilv_convert_price_by_currency( 99.99, 'JPY' );</code>
                            <br><code>$currency = woo_huilv_get_current_currency();</code>
                        </div>
                    </div>
                </div>

                <?php submit_button( __( '保存设置', 'woo-huilv' ) ); ?>
            </form>

            <!-- 隐藏模板用于 JS 动态添加行 -->
            <script type="text/html" id="tmpl-lang-currency-row">
                <tr class="map-row">
                    <td>
                        <select name="woo_huilv_language_currency_map_lang[]" class="lang-select">
                            <?php foreach ( $common_languages as $lc => $ln ) : ?>
                                <option value="<?php echo esc_attr( $lc ); ?>">
                                    <?php echo esc_html( $lc . ' - ' . $ln ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="woo_huilv_language_currency_map_currency[]" class="currency-select">
                            <?php foreach ( $common_currencies as $cc => $cn ) : ?>
                                <option value="<?php echo esc_attr( $cc ); ?>">
                                    <?php echo esc_html( $cc . ' - ' . $cn ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="rate-display">-</td>
                    <td>
                        <button type="button" class="button btn-remove-row" title="<?php esc_attr_e( '删除', 'woo-huilv' ); ?>">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </td>
                </tr>
            </script>

            <script type="text/html" id="tmpl-decimal-row">
                <tr class="map-row">
                    <td>
                        <select name="woo_huilv_decimal_map_currency[]" class="currency-select">
                            <?php foreach ( $common_currencies as $cc => $cn ) : ?>
                                <option value="<?php echo esc_attr( $cc ); ?>">
                                    <?php echo esc_html( $cc . ' - ' . $cn ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="number" name="woo_huilv_decimal_map_decimals[]"
                               value="2" min="0" max="4" step="1" class="small-text" />
                    </td>
                    <td>
                        <button type="button" class="button btn-remove-row" title="<?php esc_attr_e( '删除', 'woo-huilv' ); ?>">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </td>
                </tr>
            </script>
        </div>
        <?php
    }

    /**
     * 常用货币列表
     */
    public static function get_common_currencies() {
        return array(
            'USD' => '美元 (US Dollar)',
            'EUR' => '欧元 (Euro)',
            'GBP' => '英镑 (British Pound)',
            'JPY' => '日元 (Japanese Yen)',
            'CNY' => '人民币 (Chinese Yuan)',
            'KRW' => '韩元 (Korean Won)',
            'HKD' => '港币 (Hong Kong Dollar)',
            'TWD' => '新台币 (Taiwan Dollar)',
            'SGD' => '新加坡元 (Singapore Dollar)',
            'AUD' => '澳元 (Australian Dollar)',
            'CAD' => '加元 (Canadian Dollar)',
            'CHF' => '瑞士法郎 (Swiss Franc)',
            'NZD' => '纽西兰元 (New Zealand Dollar)',
            'THB' => '泰铢 (Thai Baht)',
            'INR' => '印度卢比 (Indian Rupee)',
            'MYR' => '马来西亚令吉 (Malaysian Ringgit)',
            'PHP' => '菲律宾比索 (Philippine Peso)',
            'IDR' => '印尼盾 (Indonesian Rupiah)',
            'VND' => '越南盾 (Vietnamese Dong)',
            'BRL' => '巴西雷亚尔 (Brazilian Real)',
            'RUB' => '俄罗斯卢布 (Russian Ruble)',
            'SAR' => '沙特里亚尔 (Saudi Riyal)',
            'AED' => '迪拉姆 (UAE Dirham)',
            'MXN' => '墨西哥比索 (Mexican Peso)',
            'ZAR' => '南非兰特 (South African Rand)',
            'SEK' => '瑞典克朗 (Swedish Krona)',
            'NOK' => '挪威克朗 (Norwegian Krone)',
            'DKK' => '丹麦克朗 (Danish Krone)',
            'PLN' => '波兰兹罗提 (Polish Zloty)',
            'TRY' => '土耳其里拉 (Turkish Lira)',
        );
    }

    /**
     * 常用语言列表
     */
    public static function get_common_languages() {
        return array(
            'en' => 'English 英语',
            'en-gb' => 'English (United Kingdom) 英语（英国）',
            'ja' => '日本語 日语',
            'zh' => '中文 中文',
            'hk' => '繁體中文（香港）',
            'tw' => '繁體中文（台灣）',
            'ko' => '한국어 韩语',
            'fr' => 'Français 法语',
            'de' => 'Deutsch 德语',
            'es' => 'Español 西班牙语',
            'it' => 'Italiano 意大利语',
            'pt' => 'Português 葡萄牙语',
            'ru' => 'Русский 俄语',
            'ar' => 'العربية 阿拉伯语',
            'th' => 'ไทย 泰语',
            'vi' => 'Tiếng Việt 越南语',
            'id' => 'Bahasa Indonesia 印尼语',
            'ms' => 'Bahasa Melayu 马来语',
            'hi' => 'हिन्दी 印地语',
            'nl' => 'Nederlands 荷兰语',
            'sv' => 'Svenska 瑞典语',
            'no' => 'Norsk 挪威语',
            'da' => 'Dansk 丹麦语',
            'pl' => 'Polski 波兰语',
            'tr' => 'Türkçe 土耳其语',
        );
    }
}

// 初始化管理页面
WOO_Huilv_Admin_Settings::init();

/**
 * 拦截表单保存，将分开的数组重组为关联数组
 */
add_action( 'admin_init', function() {
    if ( isset( $_POST['option_page'] ) && $_POST['option_page'] === 'woo_huilv_settings' ) {
        // 重组语言-货币映射
        if ( isset( $_POST['woo_huilv_language_currency_map_lang'] ) && isset( $_POST['woo_huilv_language_currency_map_currency'] ) ) {
            $langs      = $_POST['woo_huilv_language_currency_map_lang'];
            $currencies = $_POST['woo_huilv_language_currency_map_currency'];
            $map        = array();

            for ( $i = 0; $i < count( $langs ); $i++ ) {
                $lang     = sanitize_text_field( $langs[ $i ] );
                $currency = strtoupper( sanitize_text_field( $currencies[ $i ] ) );
                if ( ! empty( $lang ) && ! empty( $currency ) ) {
                    $map[ $lang ] = $currency;
                }
            }

            $_POST['woo_huilv_language_currency_map'] = $map;
        } else {
            $_POST['woo_huilv_language_currency_map'] = array();
        }

        // 重组手动汇率（从表单提交时的数组格式）
        if ( isset( $_POST['woo_huilv_manual_rates'] ) && is_array( $_POST['woo_huilv_manual_rates'] ) ) {
            $manual = array();
            foreach ( $_POST['woo_huilv_manual_rates'] as $currency => $rate ) {
                $currency = strtoupper( sanitize_text_field( $currency ) );
                $rate     = floatval( $rate );
                if ( ! empty( $currency ) && $rate > 0 ) {
                    $manual[ $currency ] = $rate;
                }
            }
            $_POST['woo_huilv_manual_rates'] = $manual;
        }

        // 重组小数映射
        if ( isset( $_POST['woo_huilv_decimal_map_currency'] ) && isset( $_POST['woo_huilv_decimal_map_decimals'] ) ) {
            $dec_currencies = $_POST['woo_huilv_decimal_map_currency'];
            $dec_decimals   = $_POST['woo_huilv_decimal_map_decimals'];
            $dec_map        = array();

            for ( $i = 0; $i < count( $dec_currencies ); $i++ ) {
                $currency = strtoupper( sanitize_text_field( $dec_currencies[ $i ] ) );
                $decimals = absint( $dec_decimals[ $i ] );
                if ( ! empty( $currency ) ) {
                    $dec_map[ $currency ] = $decimals;
                }
            }

            $_POST['woo_huilv_decimal_map'] = $dec_map;
        } else {
            $_POST['woo_huilv_decimal_map'] = array();
        }
    }
}, 5 );
