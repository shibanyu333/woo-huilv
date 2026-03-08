<?php
/**
 * 商品固定多币种价格
 *
 * 允许在商品编辑页为已配置的目标货币设置固定价格。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_Huilv_Product_Fixed_Prices {

    /**
     * 商品固定价格 Meta Key
     */
    const META_KEY = '_woo_huilv_fixed_prices';

    /**
     * 初始化
     */
    public static function init() {
        if ( is_admin() ) {
            add_action( 'woocommerce_product_options_pricing', array( __CLASS__, 'render_simple_product_fields' ) );
            add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_simple_product_fields' ) );
            add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'render_variation_fields' ), 10, 3 );
            add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_fields' ), 10, 2 );
            add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_list_table_column' ), 25 );
            add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_list_table_column' ), 10, 2 );
            add_action( 'quick_edit_custom_box', array( __CLASS__, 'render_quick_edit_fields' ), 10, 2 );
            add_action( 'bulk_edit_custom_box', array( __CLASS__, 'render_bulk_edit_fields' ), 10, 2 );
            add_action( 'save_post_product', array( __CLASS__, 'handle_list_table_edits' ), 20, 2 );
            add_action( 'admin_footer-edit.php', array( __CLASS__, 'print_list_table_scripts' ) );
            add_action( 'admin_head-edit.php', array( __CLASS__, 'print_list_table_styles' ) );
        }
    }

    /**
     * 商品列表新增列
     *
     * @param array $columns 列定义
     * @return array
     */
    public static function add_list_table_column( $columns ) {
        $new_columns = array();

        foreach ( $columns as $key => $label ) {
            $new_columns[ $key ] = $label;

            if ( 'price' === $key ) {
                $new_columns['woo_huilv_fixed_prices'] = __( '多币种固定价', 'woo-huilv' );
            }
        }

        if ( ! isset( $new_columns['woo_huilv_fixed_prices'] ) ) {
            $new_columns['woo_huilv_fixed_prices'] = __( '多币种固定价', 'woo-huilv' );
        }

        return $new_columns;
    }

    /**
     * 渲染商品列表列
     *
     * @param string $column  列名
     * @param int    $post_id 商品 ID
     */
    public static function render_list_table_column( $column, $post_id ) {
        if ( 'woo_huilv_fixed_prices' !== $column ) {
            return;
        }

        $currencies   = self::get_available_currencies();
        $fixed_prices = self::get_fixed_prices( $post_id );

        if ( empty( $currencies ) ) {
            echo '<span class="description">' . esc_html__( '请先在汇率设置中添加目标货币', 'woo-huilv' ) . '</span>';
            return;
        }

        $summary = array();
        foreach ( $currencies as $currency ) {
            if ( empty( $fixed_prices[ $currency ] ) ) {
                continue;
            }

            $parts = array();
            if ( isset( $fixed_prices[ $currency ]['regular'] ) && '' !== $fixed_prices[ $currency ]['regular'] ) {
                $parts[] = 'R:' . wc_format_localized_price( $fixed_prices[ $currency ]['regular'] );
            }
            if ( isset( $fixed_prices[ $currency ]['sale'] ) && '' !== $fixed_prices[ $currency ]['sale'] ) {
                $parts[] = 'S:' . wc_format_localized_price( $fixed_prices[ $currency ]['sale'] );
            }

            if ( ! empty( $parts ) ) {
                $summary[] = '<div class="woo-huilv-fixed-chip"><strong>' . esc_html( $currency ) . '</strong> ' . esc_html( implode( ' / ', $parts ) ) . '</div>';
            }
        }

        if ( empty( $summary ) ) {
            echo '<span class="description">' . esc_html__( '未设置', 'woo-huilv' ) . '</span>';
        } else {
            echo wp_kses_post( implode( '', $summary ) );
        }

        echo '<div class="hidden woo-huilv-fixed-data" data-prices="' . esc_attr( wp_json_encode( $fixed_prices ) ) . '"></div>';
    }

    /**
     * 渲染快速编辑字段
     *
     * @param string $column_name 列名
     * @param string $post_type   文章类型
     */
    public static function render_quick_edit_fields( $column_name, $post_type ) {
        if ( 'product' !== $post_type || 'woo_huilv_fixed_prices' !== $column_name ) {
            return;
        }

        $currencies = self::get_available_currencies();
        if ( empty( $currencies ) ) {
            return;
        }

        wp_nonce_field( 'woo_huilv_quick_edit', 'woo_huilv_quick_edit_nonce' );

        echo '<fieldset class="inline-edit-col-left woo-huilv-inline-edit">';
        echo '<div class="inline-edit-col">';
        echo '<span class="title">' . esc_html__( '多币种固定价格', 'woo-huilv' ) . '</span>';
        echo '<span class="input-text-wrap woo-huilv-inline-help">' . esc_html__( '留空表示清空该商品在对应货币下的固定价格。', 'woo-huilv' ) . '</span>';

        foreach ( $currencies as $currency ) {
            echo '<div class="woo-huilv-inline-row">';
            echo '<span class="woo-huilv-inline-code">' . esc_html( $currency ) . '</span>';
            echo '<label>';
            echo '<span class="title">' . esc_html__( '原价', 'woo-huilv' ) . '</span>';
            echo '<span class="input-text-wrap"><input type="text" class="text wc_input_price woo-huilv-quick-field" name="woo_huilv_quick_fixed_regular_price[' . esc_attr( $currency ) . ']" data-currency="' . esc_attr( $currency ) . '" data-price-type="regular" value="" /></span>';
            echo '</label>';
            echo '<label>';
            echo '<span class="title">' . esc_html__( '促销价', 'woo-huilv' ) . '</span>';
            echo '<span class="input-text-wrap"><input type="text" class="text wc_input_price woo-huilv-quick-field" name="woo_huilv_quick_fixed_sale_price[' . esc_attr( $currency ) . ']" data-currency="' . esc_attr( $currency ) . '" data-price-type="sale" value="" /></span>';
            echo '</label>';
            echo '</div>';
        }

        echo '</div>';
        echo '</fieldset>';
    }

    /**
     * 渲染批量编辑字段
     *
     * @param string $column_name 列名
     * @param string $post_type   文章类型
     */
    public static function render_bulk_edit_fields( $column_name, $post_type ) {
        if ( 'product' !== $post_type || 'woo_huilv_fixed_prices' !== $column_name ) {
            return;
        }

        $currencies = self::get_available_currencies();
        if ( empty( $currencies ) ) {
            return;
        }

        wp_nonce_field( 'woo_huilv_bulk_edit', 'woo_huilv_bulk_edit_nonce' );

        echo '<fieldset class="inline-edit-col-left woo-huilv-inline-edit woo-huilv-bulk-edit">';
        echo '<div class="inline-edit-col">';
        echo '<span class="title">' . esc_html__( '批量设置多币种固定价格', 'woo-huilv' ) . '</span>';
        echo '<span class="input-text-wrap woo-huilv-inline-help">' . esc_html__( '批量编辑时：留空=不改；勾选清空=删除该货币固定价；填写数值=批量覆盖。', 'woo-huilv' ) . '</span>';

        foreach ( $currencies as $currency ) {
            echo '<div class="woo-huilv-inline-row woo-huilv-inline-row-bulk">';
            echo '<span class="woo-huilv-inline-code">' . esc_html( $currency ) . '</span>';
            echo '<label>';
            echo '<span class="title">' . esc_html__( '原价', 'woo-huilv' ) . '</span>';
            echo '<span class="input-text-wrap"><input type="text" class="text wc_input_price" name="woo_huilv_bulk_fixed_regular_price[' . esc_attr( $currency ) . ']" value="" /></span>';
            echo '</label>';
            echo '<label>';
            echo '<span class="title">' . esc_html__( '促销价', 'woo-huilv' ) . '</span>';
            echo '<span class="input-text-wrap"><input type="text" class="text wc_input_price" name="woo_huilv_bulk_fixed_sale_price[' . esc_attr( $currency ) . ']" value="" /></span>';
            echo '</label>';
            echo '<label class="alignleft woo-huilv-inline-clear">';
            echo '<input type="checkbox" name="woo_huilv_bulk_clear_currency[]" value="' . esc_attr( $currency ) . '" /> ';
            echo '<span class="checkbox-title">' . esc_html__( '清空此货币', 'woo-huilv' ) . '</span>';
            echo '</label>';
            echo '</div>';
        }

        echo '</div>';
        echo '</fieldset>';
    }

    /**
     * 处理列表页快速编辑与批量编辑保存
     *
     * @param int     $post_id 商品 ID
     * @param WP_Post $post    文章对象
     */
    public static function handle_list_table_edits( $post_id, $post ) {
        if ( empty( $post ) || 'product' !== $post->post_type ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';

        if ( 'inline-save' === $action ) {
            self::save_quick_edit( $post_id );
            return;
        }

        if ( isset( $_REQUEST['bulk_edit'] ) ) {
            self::save_bulk_edit( $post_id );
        }
    }

    /**
     * 保存快速编辑
     *
     * @param int $post_id 商品 ID
     */
    private static function save_quick_edit( $post_id ) {
        if ( empty( $_POST['woo_huilv_quick_edit_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woo_huilv_quick_edit_nonce'] ) ), 'woo_huilv_quick_edit' ) ) {
            return;
        }

        $regular_input = isset( $_POST['woo_huilv_quick_fixed_regular_price'] ) ? wp_unslash( $_POST['woo_huilv_quick_fixed_regular_price'] ) : array();
        $sale_input    = isset( $_POST['woo_huilv_quick_fixed_sale_price'] ) ? wp_unslash( $_POST['woo_huilv_quick_fixed_sale_price'] ) : array();

        $fixed_prices = self::collect_posted_prices( $post_id, $regular_input, $sale_input );

        self::persist_fixed_prices( $post_id, $fixed_prices );
    }

    /**
     * 保存批量编辑
     *
     * @param int $post_id 商品 ID
     */
    private static function save_bulk_edit( $post_id ) {
        if ( empty( $_POST['woo_huilv_bulk_edit_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woo_huilv_bulk_edit_nonce'] ) ), 'woo_huilv_bulk_edit' ) ) {
            return;
        }

        $regular_input = isset( $_POST['woo_huilv_bulk_fixed_regular_price'] ) ? wp_unslash( $_POST['woo_huilv_bulk_fixed_regular_price'] ) : array();
        $sale_input    = isset( $_POST['woo_huilv_bulk_fixed_sale_price'] ) ? wp_unslash( $_POST['woo_huilv_bulk_fixed_sale_price'] ) : array();
        $clear_list    = isset( $_POST['woo_huilv_bulk_clear_currency'] ) ? (array) wp_unslash( $_POST['woo_huilv_bulk_clear_currency'] ) : array();
        $clear_list    = array_map( 'strtoupper', array_map( 'sanitize_text_field', $clear_list ) );
        $fixed_prices  = self::get_fixed_prices( $post_id );
        $currencies    = array_unique( array_merge( self::get_available_currencies(), array_keys( $fixed_prices ), array_keys( (array) $regular_input ), array_keys( (array) $sale_input ), $clear_list ) );

        foreach ( $currencies as $currency ) {
            $currency = strtoupper( trim( $currency ) );
            if ( empty( $currency ) ) {
                continue;
            }

            if ( in_array( $currency, $clear_list, true ) ) {
                unset( $fixed_prices[ $currency ] );
                continue;
            }

            $has_regular = isset( $regular_input[ $currency ] ) && '' !== trim( (string) $regular_input[ $currency ] );
            $has_sale    = isset( $sale_input[ $currency ] ) && '' !== trim( (string) $sale_input[ $currency ] );

            if ( ! $has_regular && ! $has_sale ) {
                continue;
            }

            $existing_regular = isset( $fixed_prices[ $currency ]['regular'] ) ? $fixed_prices[ $currency ]['regular'] : '';
            $existing_sale    = isset( $fixed_prices[ $currency ]['sale'] ) ? $fixed_prices[ $currency ]['sale'] : '';

            $regular = $has_regular ? self::normalize_price_value( $regular_input[ $currency ] ) : $existing_regular;
            $sale    = $has_sale ? self::normalize_price_value( $sale_input[ $currency ] ) : $existing_sale;

            if ( '' === $regular && '' === $sale ) {
                unset( $fixed_prices[ $currency ] );
                continue;
            }

            if ( ! self::is_valid_sale_price( $regular, $sale ) ) {
                $sale = '';
            }

            $fixed_prices[ $currency ] = array(
                'regular' => $regular,
                'sale'    => $sale,
            );
        }

        self::persist_fixed_prices( $post_id, $fixed_prices );
    }

    /**
     * 输出商品列表脚本
     */
    public static function print_list_table_scripts() {
        $screen = get_current_screen();
        if ( ! $screen || 'edit-product' !== $screen->id ) {
            return;
        }
        ?>
        <script>
        jQuery(function($) {
            if (typeof inlineEditPost === 'undefined') {
                return;
            }

            var originalEdit = inlineEditPost.edit;
            inlineEditPost.edit = function(id) {
                originalEdit.apply(this, arguments);

                var postId = 0;
                if (typeof id === 'object') {
                    postId = parseInt(this.getId(id), 10);
                } else {
                    postId = parseInt(id, 10);
                }

                if (!postId) {
                    return;
                }

                var $editRow = $('#edit-' + postId);
                var $postRow = $('#post-' + postId);
                var $data = $postRow.find('.woo-huilv-fixed-data');
                var prices = {};

                $editRow.find('.woo-huilv-quick-field').val('');

                if ($data.length) {
                    try {
                        prices = JSON.parse($data.attr('data-prices') || '{}');
                    } catch (err) {
                        prices = {};
                    }
                }

                $.each(prices, function(currency, values) {
                    if (!values) {
                        return;
                    }

                    $editRow.find('input[name="woo_huilv_quick_fixed_regular_price[' + currency + ']"]').val(values.regular || '');
                    $editRow.find('input[name="woo_huilv_quick_fixed_sale_price[' + currency + ']"]').val(values.sale || '');
                });
            };
        });
        </script>
        <?php
    }

    /**
     * 输出商品列表样式
     */
    public static function print_list_table_styles() {
        $screen = get_current_screen();
        if ( ! $screen || 'edit-product' !== $screen->id ) {
            return;
        }
        ?>
        <style>
            .column-woo_huilv_fixed_prices {
                width: 16%;
            }
            .woo-huilv-fixed-chip {
                display: inline-block;
                margin: 0 6px 6px 0;
                padding: 4px 8px;
                border-radius: 999px;
                background: #f3f4f6;
                font-size: 12px;
                line-height: 1.4;
            }
            .woo-huilv-inline-edit .inline-edit-col {
                display: grid;
                gap: 8px;
                min-width: 420px;
            }
            .woo-huilv-inline-help {
                color: #6b7280;
                margin-bottom: 6px;
            }
            .woo-huilv-inline-row {
                display: grid;
                grid-template-columns: 60px 1fr 1fr;
                gap: 8px;
                align-items: end;
                margin-bottom: 4px;
            }
            .woo-huilv-inline-row-bulk {
                grid-template-columns: 60px 1fr 1fr auto;
            }
            .woo-huilv-inline-row label {
                display: block;
            }
            .woo-huilv-inline-row .title,
            .woo-huilv-inline-clear .checkbox-title {
                display: block;
                margin-bottom: 2px;
                color: #4b5563;
                font-size: 12px;
            }
            .woo-huilv-inline-code {
                font-weight: 700;
                color: #111827;
                padding-top: 18px;
            }
            .woo-huilv-inline-clear {
                padding-top: 18px;
            }
        </style>
        <?php
    }

    /**
     * 获取商品固定价格数组
     *
     * @param WC_Product|int $product 商品对象或 ID
     * @return array
     */
    public static function get_fixed_prices( $product ) {
        $product_id = self::get_product_id( $product );
        if ( ! $product_id ) {
            return array();
        }

        $prices = get_post_meta( $product_id, self::META_KEY, true );

        return self::sanitize_price_rows( is_array( $prices ) ? $prices : array() );
    }

    /**
     * 获取指定货币的固定价格
     *
     * @param WC_Product|int $product  商品对象或 ID
     * @param string         $currency 货币代码
     * @param string         $type     price|regular|sale
     * @return float|string
     */
    public static function get_fixed_price( $product, $currency, $type = 'price' ) {
        $currency = strtoupper( trim( $currency ) );
        if ( empty( $currency ) ) {
            return '';
        }

        $prices = self::get_fixed_prices( $product );
        if ( empty( $prices[ $currency ] ) || ! is_array( $prices[ $currency ] ) ) {
            return '';
        }

        $regular = isset( $prices[ $currency ]['regular'] ) ? $prices[ $currency ]['regular'] : '';
        $sale    = isset( $prices[ $currency ]['sale'] ) ? $prices[ $currency ]['sale'] : '';

        if ( 'regular' === $type ) {
            return '' !== $regular ? floatval( $regular ) : '';
        }

        if ( 'sale' === $type ) {
            return self::is_valid_sale_price( $regular, $sale ) ? floatval( $sale ) : '';
        }

        if ( self::is_valid_sale_price( $regular, $sale ) ) {
            return floatval( $sale );
        }

        return '' !== $regular ? floatval( $regular ) : '';
    }

    /**
     * 渲染简单商品固定价格字段
     */
    public static function render_simple_product_fields() {
        global $post;

        if ( ! $post ) {
            return;
        }

        $currencies = self::get_available_currencies();
        if ( empty( $currencies ) ) {
            return;
        }

        $fixed_prices = self::get_fixed_prices( $post->ID );

        echo '<p class="form-field show_if_simple show_if_external"><strong>' . esc_html__( '多币种固定价格', 'woo-huilv' ) . '</strong><br />';
        echo '<span class="description">' . esc_html__( '为已配置的目标货币设置固定价格。填写后，前台切换到对应货币时将直接显示这里的价格，不再按汇率换算。', 'woo-huilv' ) . '</span></p>';

        foreach ( $currencies as $currency ) {
            $regular = isset( $fixed_prices[ $currency ]['regular'] ) ? $fixed_prices[ $currency ]['regular'] : '';
            $sale    = isset( $fixed_prices[ $currency ]['sale'] ) ? $fixed_prices[ $currency ]['sale'] : '';

            woocommerce_wp_text_input( array(
                'id'                => 'woo_huilv_fixed_regular_' . $currency,
                'name'              => 'woo_huilv_fixed_regular_price[' . $currency . ']',
                'label'             => sprintf( __( '%s 固定原价', 'woo-huilv' ), $currency ),
                'value'             => $regular,
                'desc_tip'          => true,
                'description'       => sprintf( __( '用户切换到 %s 时优先显示此原价。留空则继续按汇率换算。', 'woo-huilv' ), $currency ),
                'type'              => 'text',
                'data_type'         => 'price',
                'wrapper_class'     => 'show_if_simple show_if_external',
                'custom_attributes' => array(
                    'inputmode' => 'decimal',
                ),
            ) );

            woocommerce_wp_text_input( array(
                'id'                => 'woo_huilv_fixed_sale_' . $currency,
                'name'              => 'woo_huilv_fixed_sale_price[' . $currency . ']',
                'label'             => sprintf( __( '%s 固定促销价', 'woo-huilv' ), $currency ),
                'value'             => $sale,
                'desc_tip'          => true,
                'description'       => sprintf( __( '可选。填写后，%s 会使用此促销价；需小于固定原价才会生效。', 'woo-huilv' ), $currency ),
                'type'              => 'text',
                'data_type'         => 'price',
                'wrapper_class'     => 'show_if_simple show_if_external',
                'custom_attributes' => array(
                    'inputmode' => 'decimal',
                ),
            ) );
        }
    }

    /**
     * 保存简单商品固定价格
     *
     * @param int $product_id 商品 ID
     */
    public static function save_simple_product_fields( $product_id ) {
        $fixed_prices = self::collect_posted_prices(
            $product_id,
            isset( $_POST['woo_huilv_fixed_regular_price'] ) ? wp_unslash( $_POST['woo_huilv_fixed_regular_price'] ) : array(),
            isset( $_POST['woo_huilv_fixed_sale_price'] ) ? wp_unslash( $_POST['woo_huilv_fixed_sale_price'] ) : array()
        );

        self::persist_fixed_prices( $product_id, $fixed_prices );
    }

    /**
     * 渲染变体固定价格字段
     *
     * @param int     $loop           变体循环序号
     * @param array   $variation_data 变体数据
     * @param WP_Post $variation      变体对象
     */
    public static function render_variation_fields( $loop, $variation_data, $variation ) {
        $currencies = self::get_available_currencies();
        if ( empty( $currencies ) ) {
            return;
        }

        $fixed_prices = self::get_fixed_prices( $variation->ID );

        echo '<div class="form-row form-row-full">';
        echo '<p><strong>' . esc_html__( '多币种固定价格', 'woo-huilv' ) . '</strong><br />';
        echo '<span class="description">' . esc_html__( '该变体可单独设置固定价格。留空则继续按基础货币和汇率换算。', 'woo-huilv' ) . '</span></p>';
        echo '</div>';

        foreach ( $currencies as $currency ) {
            $regular = isset( $fixed_prices[ $currency ]['regular'] ) ? $fixed_prices[ $currency ]['regular'] : '';
            $sale    = isset( $fixed_prices[ $currency ]['sale'] ) ? $fixed_prices[ $currency ]['sale'] : '';

            woocommerce_wp_text_input( array(
                'id'                => 'woo_huilv_variation_fixed_regular_' . $currency . '_' . $loop,
                'name'              => 'woo_huilv_variation_fixed_regular_price[' . $currency . '][' . $loop . ']',
                'label'             => sprintf( __( '%s 固定原价', 'woo-huilv' ), $currency ),
                'value'             => $regular,
                'desc_tip'          => true,
                'description'       => sprintf( __( '该变体在 %s 下的固定原价。', 'woo-huilv' ), $currency ),
                'type'              => 'text',
                'data_type'         => 'price',
                'wrapper_class'     => 'form-row form-row-first',
                'custom_attributes' => array(
                    'inputmode' => 'decimal',
                ),
            ) );

            woocommerce_wp_text_input( array(
                'id'                => 'woo_huilv_variation_fixed_sale_' . $currency . '_' . $loop,
                'name'              => 'woo_huilv_variation_fixed_sale_price[' . $currency . '][' . $loop . ']',
                'label'             => sprintf( __( '%s 固定促销价', 'woo-huilv' ), $currency ),
                'value'             => $sale,
                'desc_tip'          => true,
                'description'       => sprintf( __( '该变体在 %s 下的固定促销价。', 'woo-huilv' ), $currency ),
                'type'              => 'text',
                'data_type'         => 'price',
                'wrapper_class'     => 'form-row form-row-last',
                'custom_attributes' => array(
                    'inputmode' => 'decimal',
                ),
            ) );
        }
    }

    /**
     * 保存变体固定价格
     *
     * @param int $variation_id 变体 ID
     * @param int $index        变体序号
     */
    public static function save_variation_fields( $variation_id, $index ) {
        $regular_input = isset( $_POST['woo_huilv_variation_fixed_regular_price'] ) ? wp_unslash( $_POST['woo_huilv_variation_fixed_regular_price'] ) : array();
        $sale_input    = isset( $_POST['woo_huilv_variation_fixed_sale_price'] ) ? wp_unslash( $_POST['woo_huilv_variation_fixed_sale_price'] ) : array();
        $currencies    = array_unique( array_merge( self::get_available_currencies(), array_keys( self::get_fixed_prices( $variation_id ) ) ) );
        $fixed_prices  = array();

        foreach ( $currencies as $currency ) {
            $currency = strtoupper( trim( $currency ) );

            $regular = isset( $regular_input[ $currency ][ $index ] ) ? self::normalize_price_value( $regular_input[ $currency ][ $index ] ) : '';
            $sale    = isset( $sale_input[ $currency ][ $index ] ) ? self::normalize_price_value( $sale_input[ $currency ][ $index ] ) : '';

            if ( '' === $regular && '' === $sale ) {
                continue;
            }

            if ( ! self::is_valid_sale_price( $regular, $sale ) ) {
                $sale = '';
            }

            $fixed_prices[ $currency ] = array(
                'regular' => $regular,
                'sale'    => $sale,
            );
        }

        self::persist_fixed_prices( $variation_id, $fixed_prices );
    }

    /**
     * 获取当前应显示的固定价格货币列表
     *
     * @return array
     */
    public static function get_available_currencies() {
        $base_currency = WOO_Huilv_Exchange_Rate_API::get_base_currency();
        $lang_map      = get_option( 'woo_huilv_language_currency_map', array() );
        $manual_rates  = get_option( 'woo_huilv_manual_rates', array() );

        $currencies = array_unique( array_merge( array_values( $lang_map ), array_keys( $manual_rates ) ) );
        $currencies = array_filter( array_map( 'strtoupper', $currencies ) );
        $currencies = array_values( array_diff( $currencies, array( strtoupper( $base_currency ) ) ) );

        /**
         * 过滤可设置固定价的货币列表。
         *
         * @param array $currencies 当前货币列表
         */
        $currencies = apply_filters( 'woo_huilv_fixed_price_currencies', $currencies );

        sort( $currencies );

        return $currencies;
    }

    /**
     * 收集提交的固定价格
     *
     * @param int   $product_id     商品 ID
     * @param array $regular_input  原价输入
     * @param array $sale_input     促销价输入
     * @return array
     */
    private static function collect_posted_prices( $product_id, $regular_input, $sale_input ) {
        $existing   = self::get_fixed_prices( $product_id );
        $currencies = array_unique( array_merge( self::get_available_currencies(), array_keys( $existing ), array_keys( (array) $regular_input ), array_keys( (array) $sale_input ) ) );
        $prices     = array();

        foreach ( $currencies as $currency ) {
            $currency = strtoupper( trim( $currency ) );
            if ( empty( $currency ) ) {
                continue;
            }

            $regular = isset( $regular_input[ $currency ] ) ? self::normalize_price_value( $regular_input[ $currency ] ) : '';
            $sale    = isset( $sale_input[ $currency ] ) ? self::normalize_price_value( $sale_input[ $currency ] ) : '';

            if ( '' === $regular && '' === $sale ) {
                continue;
            }

            if ( ! self::is_valid_sale_price( $regular, $sale ) ) {
                $sale = '';
            }

            $prices[ $currency ] = array(
                'regular' => $regular,
                'sale'    => $sale,
            );
        }

        return $prices;
    }

    /**
     * 持久化固定价格
     *
     * @param int   $product_id 商品 ID
     * @param array $fixed_prices 固定价格
     */
    private static function persist_fixed_prices( $product_id, $fixed_prices ) {
        $fixed_prices = self::sanitize_price_rows( $fixed_prices );

        if ( empty( $fixed_prices ) ) {
            delete_post_meta( $product_id, self::META_KEY );
            return;
        }

        update_post_meta( $product_id, self::META_KEY, $fixed_prices );
    }

    /**
     * 清洗价格结构
     *
     * @param array $rows 原始数据
     * @return array
     */
    private static function sanitize_price_rows( $rows ) {
        $clean = array();

        foreach ( $rows as $currency => $values ) {
            $currency = strtoupper( sanitize_text_field( $currency ) );
            if ( empty( $currency ) || ! is_array( $values ) ) {
                continue;
            }

            $regular = isset( $values['regular'] ) ? self::normalize_price_value( $values['regular'] ) : '';
            $sale    = isset( $values['sale'] ) ? self::normalize_price_value( $values['sale'] ) : '';

            if ( '' === $regular && '' === $sale ) {
                continue;
            }

            if ( ! self::is_valid_sale_price( $regular, $sale ) ) {
                $sale = '';
            }

            $clean[ $currency ] = array(
                'regular' => $regular,
                'sale'    => $sale,
            );
        }

        return $clean;
    }

    /**
     * 标准化价格值
     *
     * @param mixed $value 原始输入
     * @return string
     */
    private static function normalize_price_value( $value ) {
        if ( is_array( $value ) ) {
            return '';
        }

        $value = wc_clean( $value );
        $value = '' !== $value ? wc_format_decimal( $value, false ) : '';

        return '' === $value ? '' : (string) $value;
    }

    /**
     * 判断促销价是否有效
     *
     * @param string $regular 原价
     * @param string $sale    促销价
     * @return bool
     */
    private static function is_valid_sale_price( $regular, $sale ) {
        if ( '' === $sale || ! is_numeric( $sale ) ) {
            return false;
        }

        if ( '' === $regular || ! is_numeric( $regular ) ) {
            return true;
        }

        return floatval( $sale ) < floatval( $regular );
    }

    /**
     * 获取商品 ID
     *
     * @param WC_Product|int $product 商品对象或 ID
     * @return int
     */
    private static function get_product_id( $product ) {
        if ( is_numeric( $product ) ) {
            return absint( $product );
        }

        if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
            return absint( $product->get_id() );
        }

        return 0;
    }
}

WOO_Huilv_Product_Fixed_Prices::init();