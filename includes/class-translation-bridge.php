<?php
/**
 * 翻译插件桥接类
 *
 * 自动检测各种主流翻译插件的当前语言，并映射到对应货币
 * 支持: WPML, Polylang, TranslatePress, GTranslate, Weglot, ConveyThis 等
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_Huilv_Translation_Bridge {

    /**
     * 检测当前语言
     *
     * @return string 语言代码（如 'ja', 'zh', 'en'）
     */
    public static function detect_language() {
        $lang = '';

        // -1. 测试模式：直接返回匹配的语言代码
        if ( get_option( 'woo_huilv_test_mode', 'no' ) === 'yes' ) {
            $test_currency = self::get_test_currency();
            if ( ! empty( $test_currency ) ) {
                // 反查语言映射：从货币代码找到语言代码
                $lang_map = get_option( 'woo_huilv_language_currency_map', array() );
                foreach ( $lang_map as $lang_code => $currency_code ) {
                    if ( $currency_code === $test_currency ) {
                        return $lang_code;
                    }
                }
                // 找不到映射也无所谓，get_current_currency 会兜底
                return '';
            }
        }

        // 0. 允许其他插件通过 filter 直接传入语言
        $lang = apply_filters( 'woo_huilv_current_language', $lang );
        if ( ! empty( $lang ) ) {
            return self::normalize_lang( $lang );
        }

        // 1. WPML
        if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
            return self::normalize_lang( ICL_LANGUAGE_CODE );
        }

        // 2. Polylang
        if ( function_exists( 'pll_current_language' ) ) {
            $pll_lang = pll_current_language( 'slug' );
            if ( $pll_lang ) {
                return self::normalize_lang( $pll_lang );
            }
        }

        // 3. TranslatePress
        if ( class_exists( 'TRP_Translate_Press' ) ) {
            global $TRP_LANGUAGE;
            if ( ! empty( $TRP_LANGUAGE ) ) {
                return self::normalize_lang( $TRP_LANGUAGE );
            }
            // 也尝试通过 URL 或 settings 获取
            $trp_lang = self::get_translatepress_language();
            if ( $trp_lang ) {
                return self::normalize_lang( $trp_lang );
            }
        }

        // 4. GTranslate
        if ( ! empty( $_COOKIE['googtrans'] ) ) {
            // 格式: /en/ja or /auto/ja
            $parts = explode( '/', trim( $_COOKIE['googtrans'], '/' ) );
            if ( count( $parts ) >= 2 ) {
                return self::normalize_lang( end( $parts ) );
            }
        }

        // 5. Weglot
        if ( function_exists( 'weglot_get_current_language' ) ) {
            $weglot_lang = weglot_get_current_language();
            if ( $weglot_lang ) {
                return self::normalize_lang( $weglot_lang );
            }
        }

        // 6. ConveyThis
        if ( ! empty( $_COOKIE['conveyThis_language'] ) ) {
            return self::normalize_lang( sanitize_text_field( $_COOKIE['conveyThis_language'] ) );
        }

        // 7. 通过 URL 参数检测 (通用兼容)
        if ( ! empty( $_GET['lang'] ) ) {
            return self::normalize_lang( sanitize_text_field( $_GET['lang'] ) );
        }

        // 8. 通过 HTTP Accept-Language (最后手段，通常不启用)
        // 这里不自动使用浏览器语言，因为只有翻译插件切换后才应该转换

        // 9. WordPress 的 locale
        $locale = get_locale();
        if ( $locale && $locale !== 'en_US' ) {
            return self::normalize_lang( $locale );
        }

        return '';
    }

    /**
     * 获取当前应该使用的货币代码
     *
     * @return string 货币代码（如 'JPY', 'CNY'）或空
     */
    public static function get_current_currency() {
        // 测试模式：直接返回测试货币
        if ( get_option( 'woo_huilv_test_mode', 'no' ) === 'yes' ) {
            $test_currency = self::get_test_currency();
            if ( ! empty( $test_currency ) ) {
                return strtoupper( trim( $test_currency ) );
            }
        }

        // 允许外部插件直接指定货币
        $currency = apply_filters( 'woo_huilv_override_currency', '' );
        if ( ! empty( $currency ) ) {
            return strtoupper( trim( $currency ) );
        }

        $lang = self::detect_language();

        if ( empty( $lang ) ) {
            return '';
        }

        return WOO_Huilv_Currency_Converter::get_currency_for_language( $lang );
    }

    /**
     * 获取测试模式的目标货币
     *
     * 优先读取 cookie（前台工具栏切换），否则使用后台设置
     *
     * @return string 货币代码
     */
    private static function get_test_currency() {
        // 优先 cookie（前台工具栏动态切换）
        if ( ! empty( $_COOKIE['woo_huilv_test_currency'] ) ) {
            $cookie_currency = strtoupper( trim( sanitize_text_field( $_COOKIE['woo_huilv_test_currency'] ) ) );
            if ( ! empty( $cookie_currency ) ) {
                return $cookie_currency;
            }
        }

        // 回退到后台设置（也确保大写）
        $option_currency = get_option( 'woo_huilv_test_currency', 'JPY' );
        return strtoupper( trim( $option_currency ) );
    }

    /**
     * 标准化语言代码
     *
     * 保留 language-region 形式，便于精确映射如 en-gb、zh-hk；
     * 若没有地区信息，则回退为两位语言代码。
     *
     * @param string $lang 原始语言代码
     * @return string 标准化后的语言代码
     */
    private static function normalize_lang( $lang ) {
        if ( empty( $lang ) ) {
            return '';
        }

        $lang = strtolower( trim( $lang ) );
        $lang = str_replace( '_', '-', $lang );
        $lang = preg_replace( '/[^a-z-]/', '', $lang );

        if ( empty( $lang ) ) {
            return '';
        }

        if ( strpos( $lang, '-' ) !== false ) {
            $parts = explode( '-', $lang );
            $language = isset( $parts[0] ) ? substr( $parts[0], 0, 2 ) : '';
            $region   = isset( $parts[1] ) ? substr( $parts[1], 0, 2 ) : '';

            if ( ! empty( $language ) && ! empty( $region ) ) {
                return $language . '-' . $region;
            }

            return $language;
        }

        return substr( $lang, 0, 2 );
    }

    /**
     * 尝试获取 TranslatePress 当前语言
     */
    private static function get_translatepress_language() {
        if ( ! class_exists( 'TRP_Translate_Press' ) ) {
            return '';
        }

        $trp = TRP_Translate_Press::get_trp_instance();
        if ( method_exists( $trp, 'get_component' ) ) {
            $url_converter = $trp->get_component( 'url_converter' );
            if ( $url_converter && method_exists( $url_converter, 'get_lang_from_url_string' ) ) {
                $lang = $url_converter->get_lang_from_url_string( '' );
                if ( $lang ) {
                    return $lang;
                }
            }
        }

        return '';
    }

    /**
     * 获取所有已配置的语言-货币映射
     *
     * @return array
     */
    public static function get_language_currency_map() {
        return get_option( 'woo_huilv_language_currency_map', array() );
    }

    /**
     * 注册自定义翻译插件检测
     *
     * 其他插件可以通过以下 filter 集成:
     *
     * 方法 1 - 直接传入语言代码:
     *   add_filter( 'woo_huilv_current_language', function( $lang ) {
     *       return 'ja'; // 返回语言代码
     *   });
     *
     * 方法 2 - 直接指定目标货币:
     *   add_filter( 'woo_huilv_override_currency', function( $currency ) {
     *       return 'JPY'; // 直接返回货币代码
     *   });
     *
     * 方法 3 - 指定目标货币（在转换器层面）:
     *   add_filter( 'woo_huilv_target_currency', function( $currency ) {
     *       return 'JPY';
     *   });
     *
     * 方法 4 - 使用公共函数:
     *   WOO_Huilv_Currency_Converter::set_target_currency( 'JPY' );
     *
     * 方法 5 - 使用全局函数:
     *   $converted_price = woo_huilv_convert_price( 99.99, 'ja' );
     */
    public static function integration_docs() {
        // 此方法仅作为文档参考，不执行任何操作
    }
}
