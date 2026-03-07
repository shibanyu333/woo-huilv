<?php
/**
 * 货币转换核心类
 *
 * 负责价格的实际转换逻辑，并挂钩 WooCommerce 的价格过滤器
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_Huilv_Currency_Converter {

    /**
     * 当前目标货币
     */
    private static $current_target_currency = '';

    /**
     * 是否正在转换（防止递归）
     */
    private static $is_converting = false;

    /**
     * 初始化
     */
    public static function init() {
        if ( get_option( 'woo_huilv_enabled', 'yes' ) !== 'yes' ) {
            return;
        }

        // 在前台页面执行价格转换
        if ( ! is_admin() || wp_doing_ajax() ) {
            // WooCommerce 产品价格过滤器
            add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'convert_product_price' ), 9999, 2 );
            add_filter( 'woocommerce_product_get_regular_price', array( __CLASS__, 'convert_product_price' ), 9999, 2 );
            add_filter( 'woocommerce_product_get_sale_price', array( __CLASS__, 'convert_product_price' ), 9999, 2 );

            // 变体产品
            add_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'convert_product_price' ), 9999, 2 );
            add_filter( 'woocommerce_product_variation_get_regular_price', array( __CLASS__, 'convert_product_price' ), 9999, 2 );
            add_filter( 'woocommerce_product_variation_get_sale_price', array( __CLASS__, 'convert_product_price' ), 9999, 2 );

            // 变体价格范围
            add_filter( 'woocommerce_variation_prices_price', array( __CLASS__, 'convert_variation_price' ), 9999, 3 );
            add_filter( 'woocommerce_variation_prices_regular_price', array( __CLASS__, 'convert_variation_price' ), 9999, 3 );
            add_filter( 'woocommerce_variation_prices_sale_price', array( __CLASS__, 'convert_variation_price' ), 9999, 3 );

            // 变体价格 hash（使缓存基于货币失效）
            add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'variation_prices_hash' ), 9999, 1 );

            // 货币符号
            add_filter( 'woocommerce_currency', array( __CLASS__, 'filter_currency' ), 9999 );

            // 小数位数
            add_filter( 'wc_get_price_decimals', array( __CLASS__, 'filter_price_decimals' ), 9999 );

            // 购物车小计
            add_filter( 'woocommerce_cart_subtotal', array( __CLASS__, 'maybe_convert_cart_display' ), 9999, 3 );

            // 运费
            add_filter( 'woocommerce_package_rates', array( __CLASS__, 'convert_shipping_rates' ), 9999, 2 );

            // 汇率参考提示（购物车 & 结账页 — 兼容经典短代码和 Block 页面）
            add_action( 'woocommerce_before_cart', array( __CLASS__, 'render_exchange_rate_notice' ), 5 );
            add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'render_exchange_rate_notice' ), 5 );
            add_action( 'wp_footer', array( __CLASS__, 'render_exchange_rate_notice_footer' ), 5 );
        }
    }

    /**
     * 标记经典钩子是否已输出提示（避免重复）
     */
    private static $notice_rendered = false;

    /**
     * 获取汇率参考提示的多语言文本
     *
     * @param string $base_currency 实际结算货币代码
     * @return string 对应语言的提示文本
     */
    private static function get_rate_notice_text( $base_currency ) {
        // 根据当前目标货币反查对应语言
        $target_currency = self::get_target_currency();
        $lang = '';

        if ( ! empty( $target_currency ) ) {
            // 从语言→货币映射中反查：目标货币对应哪个语言
            $lang_map = get_option( 'woo_huilv_language_currency_map', array() );
            foreach ( $lang_map as $lang_code => $currency_code ) {
                if ( strtoupper( trim( $currency_code ) ) === strtoupper( trim( $target_currency ) ) ) {
                    $lang = $lang_code;
                    break;
                }
            }
        }

        // 多语言提示 —— 简洁风格，按目标货币对应的语言显示
        $messages = array(
            'ja' => '※表示価格は参考値です。実際のお支払いは%sでの決済となります。',
            'zh' => '※ 显示价格仅供参考，实际以%s结算。',
            'hk' => '※ 顯示價格僅供參考，實際以%s結算。',
            'tw' => '※ 顯示價格僅供參考，實際以%s結算。',
            'ko' => '※ 표시 가격은 참고용이며, 실제 결제는 %s 기준입니다.',
            'de' => '※ Angezeigte Preise dienen als Referenz. Die Zahlung erfolgt in %s.',
            'fr' => '※ Prix affichés à titre indicatif. Le paiement sera effectué en %s.',
            'es' => '※ Precios mostrados como referencia. El pago se realizará en %s.',
            'it' => '※ Prezzi indicativi. Il pagamento sarà effettuato in %s.',
            'pt' => '※ Preços exibidos como referência. O pagamento será em %s.',
            'ru' => '※ Цены указаны для справки. Оплата производится в %s.',
            'ar' => '※ الأسعار المعروضة للاستدلال فقط. سيتم الدفع بعملة %s.',
            'th' => '※ ราคาที่แสดงเป็นเพียงการอ้างอิง การชำระเงินจริงเป็น %s',
            'vi' => '※ Giá hiển thị chỉ mang tính tham khảo. Thanh toán thực tế bằng %s.',
            'en' => '※ Prices shown are for reference only. Actual payment will be charged in %s.',
            'en-gb' => '※ Prices shown are for reference only. Actual payment will be charged in %s.',
        );

        $messages = apply_filters( 'woo_huilv_rate_notice_messages', $messages );

        $template = isset( $messages[ $lang ] ) ? $messages[ $lang ] : $messages['en'];

        return sprintf( $template, $base_currency );
    }

    /**
     * 在购物车/结账页面显示汇率仅供参考的提示（经典短代码模式）
     */
    public static function render_exchange_rate_notice() {
        // 检查后台是否开启了提示显示
        if ( get_option( 'woo_huilv_show_rate_notice', 'yes' ) !== 'yes' ) {
            return;
        }

        $target_currency = self::get_target_currency();
        if ( empty( $target_currency ) ) {
            return; // 没有转换，不显示提示
        }

        self::$notice_rendered = true;
        self::output_notice_html();
    }

    /**
     * 在 wp_footer 中为 Block 版购物车/结账页注入提示
     * 仅当经典钩子未触发时才输出，通过 JS 将提示插入页面顶部
     */
    public static function render_exchange_rate_notice_footer() {
        // 经典钩子已经输出了，不重复
        if ( self::$notice_rendered ) {
            return;
        }

        // 检查后台是否开启了提示显示
        if ( get_option( 'woo_huilv_show_rate_notice', 'yes' ) !== 'yes' ) {
            return;
        }

        // 仅在购物车或结账页面
        if ( ! function_exists( 'is_cart' ) || ( ! is_cart() && ! is_checkout() ) ) {
            return;
        }

        $target_currency = self::get_target_currency();
        if ( empty( $target_currency ) ) {
            return;
        }

        $base_currency = WOO_Huilv_Exchange_Rate_API::get_base_currency();
        $notice_text   = self::get_rate_notice_text( $base_currency );
        ?>
        <script>
        (function() {
            var notice = document.createElement('div');
            notice.className = 'woo-huilv-rate-notice';
            notice.style.cssText = 'background:linear-gradient(135deg,#fffbeb 0%,#fef3c7 100%);border:1px solid #f59e0b;border-left:4px solid #f59e0b;border-radius:6px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#92400e;line-height:1.5;';
            notice.textContent = <?php echo wp_json_encode( $notice_text ); ?>;
            // 尝试插入到 Block 版购物车/结账容器前面
            var targets = [
                '.wp-block-woocommerce-cart',
                '.wp-block-woocommerce-checkout',
                '.woocommerce-cart .entry-content',
                '.woocommerce-checkout .entry-content',
                '.woocommerce-cart .woocommerce',
                '.woocommerce-checkout .woocommerce'
            ];
            for (var i = 0; i < targets.length; i++) {
                var el = document.querySelector(targets[i]);
                if (el) {
                    el.parentNode.insertBefore(notice, el);
                    return;
                }
            }
        })();
        </script>
        <?php
    }

    /**
     * 输出提示 HTML
     */
    private static function output_notice_html() {
        $base_currency = WOO_Huilv_Exchange_Rate_API::get_base_currency();
        $notice_text   = self::get_rate_notice_text( $base_currency );
        ?>
        <div class="woo-huilv-rate-notice" style="
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            border: 1px solid #f59e0b;
            border-left: 4px solid #f59e0b;
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #92400e;
            line-height: 1.5;
        ">
            <?php echo esc_html( $notice_text ); ?>
        </div>
        <?php
    }

    /**
     * 转换产品价格
     */
    public static function convert_product_price( $price, $product ) {
        if ( self::$is_converting || empty( $price ) || ! is_numeric( $price ) ) {
            return $price;
        }

        $target_currency = self::get_target_currency();
        if ( empty( $target_currency ) ) {
            return $price;
        }

        return self::convert( floatval( $price ), $target_currency );
    }

    /**
     * 转换变体价格
     */
    public static function convert_variation_price( $price, $variation, $product ) {
        if ( self::$is_converting || empty( $price ) || ! is_numeric( $price ) ) {
            return $price;
        }

        $target_currency = self::get_target_currency();
        if ( empty( $target_currency ) ) {
            return $price;
        }

        return self::convert( floatval( $price ), $target_currency );
    }

    /**
     * 变体价格缓存哈希
     */
    public static function variation_prices_hash( $hash ) {
        $target_currency = self::get_target_currency();
        if ( ! empty( $target_currency ) ) {
            $hash[] = 'woo_huilv_' . $target_currency;
        }
        return $hash;
    }

    /**
     * 过滤当前货币代码
     */
    public static function filter_currency( $currency ) {
        if ( self::$is_converting ) {
            return $currency;
        }

        $target_currency = self::get_target_currency();
        if ( ! empty( $target_currency ) ) {
            // 安全校验：确保返回的是 WooCommerce 认可的合法货币代码
            $target_currency = strtoupper( trim( $target_currency ) );
            if ( self::is_valid_wc_currency( $target_currency ) ) {
                return $target_currency;
            }
        }

        return $currency;
    }

    /**
     * 检查货币代码是否是 WooCommerce 合法的货币
     *
     * @param string $code 货币代码
     * @return bool
     */
    private static function is_valid_wc_currency( $code ) {
        if ( empty( $code ) || strlen( $code ) !== 3 ) {
            return false;
        }
        if ( function_exists( 'get_woocommerce_currencies' ) ) {
            return array_key_exists( $code, get_woocommerce_currencies() );
        }
        // WooCommerce 未加载时使用基本格式校验（3位大写字母）
        return (bool) preg_match( '/^[A-Z]{3}$/', $code );
    }

    /**
     * 过滤价格小数位数
     */
    public static function filter_price_decimals( $decimals ) {
        $target_currency = self::get_target_currency();
        if ( empty( $target_currency ) ) {
            return $decimals;
        }

        $decimal_map = get_option( 'woo_huilv_decimal_map', array() );
        if ( isset( $decimal_map[ $target_currency ] ) ) {
            return absint( $decimal_map[ $target_currency ] );
        }

        return $decimals;
    }

    /**
     * 转换运费
     */
    public static function convert_shipping_rates( $rates, $package ) {
        if ( self::$is_converting ) {
            return $rates;
        }

        $target_currency = self::get_target_currency();
        if ( empty( $target_currency ) ) {
            return $rates;
        }

        self::$is_converting = true;

        foreach ( $rates as $rate_key => $rate ) {
            $original_cost = $rate->get_cost();
            if ( is_numeric( $original_cost ) && $original_cost > 0 ) {
                $converted = self::do_convert( floatval( $original_cost ), $target_currency );
                $rate->set_cost( $converted );
            }

            // 转换税费
            $taxes = $rate->get_taxes();
            if ( ! empty( $taxes ) ) {
                $converted_taxes = array();
                foreach ( $taxes as $tax_key => $tax ) {
                    if ( is_numeric( $tax ) && $tax > 0 ) {
                        $converted_taxes[ $tax_key ] = self::do_convert( floatval( $tax ), $target_currency );
                    } else {
                        $converted_taxes[ $tax_key ] = $tax;
                    }
                }
                $rate->set_taxes( $converted_taxes );
            }
        }

        self::$is_converting = false;

        return $rates;
    }

    /**
     * 购物车显示转换
     */
    public static function maybe_convert_cart_display( $cart_subtotal, $compound, $cart ) {
        // 这里价格已经通过产品过滤器转换了，不需要二次转换
        return $cart_subtotal;
    }

    /**
     * 核心转换方法
     *
     * @param float  $price         原始价格
     * @param string $to_currency   目标货币代码
     * @param string $from_currency 源货币代码
     * @return float 转换后的价格
     */
    public static function convert( $price, $to_currency, $from_currency = '' ) {
        if ( empty( $from_currency ) ) {
            $from_currency = WOO_Huilv_Exchange_Rate_API::get_base_currency();
        }

        // 相同货币不转换
        if ( $from_currency === $to_currency ) {
            return $price;
        }

        return self::do_convert( $price, $to_currency, $from_currency );
    }

    /**
     * 内部执行转换
     */
    private static function do_convert( $price, $to_currency, $from_currency = '' ) {
        if ( empty( $from_currency ) ) {
            $from_currency = WOO_Huilv_Exchange_Rate_API::get_base_currency();
        }

        $rates_base = get_option( 'woo_huilv_rates_base', 'USD' );
        $rates = WOO_Huilv_Exchange_Rate_API::get_cached_rates();

        if ( empty( $rates ) ) {
            // 尝试按需刷新
            WOO_Huilv_Exchange_Rate_API::maybe_refresh();
            $rates = WOO_Huilv_Exchange_Rate_API::get_cached_rates();
        }

        if ( empty( $rates ) ) {
            return $price;
        }

        // 如果基础货币和汇率基准货币相同
        if ( $from_currency === $rates_base ) {
            if ( isset( $rates[ $to_currency ] ) ) {
                $converted = $price * floatval( $rates[ $to_currency ] );
                return self::round_price( $converted, $to_currency );
            }
        }

        // 如果基础货币和汇率基准货币不同，通过交叉汇率转换
        if ( isset( $rates[ $from_currency ] ) && isset( $rates[ $to_currency ] ) ) {
            $from_rate = floatval( $rates[ $from_currency ] );
            $to_rate   = floatval( $rates[ $to_currency ] );

            if ( $from_rate > 0 ) {
                $converted = $price * ( $to_rate / $from_rate );
                return self::round_price( $converted, $to_currency );
            }
        }

        return $price;
    }

    /**
     * 根据货币精度四舍五入
     */
    private static function round_price( $price, $currency ) {
        $decimal_map = get_option( 'woo_huilv_decimal_map', array() );

        if ( isset( $decimal_map[ $currency ] ) ) {
            $decimals = absint( $decimal_map[ $currency ] );
        } else {
            // 默认保留两位小数
            $decimals = 2;
        }

        return round( $price, $decimals );
    }

    /**
     * 根据语言代码转换价格
     *
     * @param float  $price 原始价格
     * @param string $lang  语言代码
     * @return float
     */
    public static function convert_by_language( $price, $lang = '' ) {
        if ( empty( $lang ) ) {
            $lang = self::detect_current_language();
        }

        $currency = self::get_currency_for_language( $lang );

        if ( empty( $currency ) ) {
            return $price;
        }

        return self::convert( $price, $currency );
    }

    /**
     * 获取语言对应的货币代码
     *
     * @param string $lang 语言代码
     * @return string 货币代码
     */
    public static function get_currency_for_language( $lang ) {
        $map = get_option( 'woo_huilv_language_currency_map', array() );

        $currency = '';

        // 完整匹配
        if ( isset( $map[ $lang ] ) ) {
            $currency = $map[ $lang ];
        }

        // 两位代码匹配 (如 'ja_JP' -> 'ja')
        if ( empty( $currency ) ) {
            $short_lang = substr( $lang, 0, 2 );
            if ( isset( $map[ $short_lang ] ) ) {
                $currency = $map[ $short_lang ];
            }
        }

        // 确保返回标准大写格式
        return ! empty( $currency ) ? strtoupper( trim( $currency ) ) : '';
    }

    /**
     * 获取当前目标货币
     *
     * @return string 目标货币代码，为空表示不需要转换
     */
    public static function get_target_currency() {
        if ( ! empty( self::$current_target_currency ) ) {
            return self::$current_target_currency;
        }

        // 1. 允许其他插件通过 filter 直接指定货币
        $currency = apply_filters( 'woo_huilv_target_currency', '' );
        if ( ! empty( $currency ) ) {
            $currency = strtoupper( trim( $currency ) );
            self::$current_target_currency = $currency;
            return $currency;
        }

        // 2. 通过翻译桥接获取
        if ( class_exists( 'WOO_Huilv_Translation_Bridge' ) ) {
            $currency = WOO_Huilv_Translation_Bridge::get_current_currency();
            if ( ! empty( $currency ) ) {
                $currency = strtoupper( trim( $currency ) );
                $base = WOO_Huilv_Exchange_Rate_API::get_base_currency();
                // 如果目标货币和基础货币一样则不转换
                if ( $currency !== $base ) {
                    self::$current_target_currency = $currency;
                    return $currency;
                }
            }
        }

        return '';
    }

    /**
     * 重置当前目标货币缓存（在语言切换时调用）
     */
    public static function reset_target_currency() {
        self::$current_target_currency = '';
    }

    /**
     * 手动设置目标货币（供外部插件调用）
     *
     * @param string $currency 货币代码
     */
    public static function set_target_currency( $currency ) {
        self::$current_target_currency = $currency;
    }

    /**
     * 检测当前语言
     *
     * @return string
     */
    private static function detect_current_language() {
        if ( class_exists( 'WOO_Huilv_Translation_Bridge' ) ) {
            return WOO_Huilv_Translation_Bridge::detect_language();
        }
        return '';
    }
}

// 初始化
add_action( 'init', array( 'WOO_Huilv_Currency_Converter', 'init' ), 20 );

// 页面请求重置缓存
add_action( 'wp', function() {
    WOO_Huilv_Currency_Converter::reset_target_currency();
} );
