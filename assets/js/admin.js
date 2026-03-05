/**
 * WOO 汇率转换 - 后台管理 JS
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // =============================
        // 自动刷新开关 → 控制刷新天数行显隐
        // =============================
        $('#woo_huilv_auto_refresh').on('change', function() {
            if ($(this).is(':checked')) {
                $('.auto-refresh-row').slideDown(200);
            } else {
                $('.auto-refresh-row').slideUp(200);
            }
        });

        // =============================
        // 测试模式开关 → 控制测试货币行显隐
        // =============================
        $('#woo_huilv_test_mode').on('change', function() {
            if ($(this).is(':checked')) {
                $('.test-mode-row').slideDown(200);
            } else {
                $('.test-mode-row').slideUp(200);
            }
        });

        // =============================
        // 手动刷新汇率
        // =============================
        $('#btn-refresh-rates').on('click', function() {
            var $btn = $(this);

            if ($btn.hasClass('refreshing')) {
                return;
            }

            $btn.addClass('refreshing').prop('disabled', true);
            $btn.find('span:last').text(wooHuilv.i18n.refreshing);

            $.ajax({
                url: wooHuilv.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'woo_huilv_manual_refresh',
                    nonce: wooHuilv.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('success', response.data.message);
                        $('#last-update-time').text(response.data.last_update);
                        $('#rates-count').text(response.data.rates_count + ' 种货币');

                        // 2秒后刷新页面以更新汇率显示
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotice('error', response.data || wooHuilv.i18n.refresh_error);
                    }
                },
                error: function() {
                    showNotice('error', wooHuilv.i18n.refresh_error);
                },
                complete: function() {
                    $btn.removeClass('refreshing').prop('disabled', false);
                    $btn.find('span:last').text('手动刷新汇率');
                }
            });
        });

        // =============================
        // 添加手动汇率
        // =============================
        $('#btn-add-manual-rate').on('click', function() {
            var currency = $('#new-manual-currency').val();
            var rate = parseFloat($('#new-manual-rate').val());

            if (!currency) {
                alert('请选择货币');
                return;
            }
            if (isNaN(rate) || rate <= 0) {
                alert('请输入有效的汇率值（大于0）');
                return;
            }

            // 检查是否已存在
            if ($('#manual-rates-table tbody tr[data-currency="' + currency + '"]').length > 0) {
                // 更新已有行的值
                $('#manual-rates-table tbody tr[data-currency="' + currency + '"] .manual-rate-input').val(rate);
                showNotice('success', currency + ' 汇率已更新为 ' + rate + '，请保存设置');
                $('#new-manual-currency').val('');
                $('#new-manual-rate').val('');
                return;
            }

            // 获取货币名称
            var currencyName = $('#new-manual-currency option:selected').text();

            var row = '<tr class="map-row" data-currency="' + currency + '">' +
                '<td><strong>' + currency + '</strong><br><small class="description">' + currencyName.split(' - ')[1] + '</small></td>' +
                '<td><input type="number" class="manual-rate-input" name="woo_huilv_manual_rates[' + currency + ']" value="' + rate + '" step="any" min="0" style="width: 120px;" /></td>' +
                '<td class="rate-display">-</td>' +
                '<td><span class="manual-rate-badge">手动</span></td>' +
                '<td><button type="button" class="button btn-remove-manual-rate" data-currency="' + currency + '" title="删除"><span class="dashicons dashicons-trash"></span></button></td>' +
                '</tr>';

            $('#manual-rates-table tbody').append(row);
            showNotice('success', '已添加 ' + currency + ' 手动汇率，请保存设置');

            $('#new-manual-currency').val('');
            $('#new-manual-rate').val('');
        });

        // =============================
        // 删除手动汇率
        // =============================
        $(document).on('click', '.btn-remove-manual-rate', function() {
            var $row = $(this).closest('tr');
            var currency = $(this).data('currency');

            if (confirm('确定要删除 ' + currency + ' 的手动汇率吗？删除后将使用 API 汇率。')) {
                $row.fadeOut(300, function() {
                    $(this).remove();
                });
                showNotice('success', currency + ' 手动汇率已删除，请保存设置');
            }
        });

        // =============================
        // 添加语言-货币映射行
        // =============================
        $('#btn-add-mapping').on('click', function() {
            var template = $('#tmpl-lang-currency-row').html();
            $('#lang-currency-map-table tbody').append(template);
        });

        // =============================
        // 添加小数映射行
        // =============================
        $('#btn-add-decimal').on('click', function() {
            var template = $('#tmpl-decimal-row').html();
            $('#decimal-map-table tbody').append(template);
        });

        // =============================
        // 删除行
        // =============================
        $(document).on('click', '.btn-remove-row', function() {
            if (confirm(wooHuilv.i18n.confirm_delete)) {
                $(this).closest('tr').fadeOut(300, function() {
                    $(this).remove();
                });
            }
        });

        // =============================
        // 显示通知
        // =============================
        function showNotice(type, message) {
            // 移除旧通知
            $('.woo-huilv-notice').remove();

            var $notice = $('<div class="woo-huilv-notice woo-huilv-notice-' + type + '">' + message + '</div>');
            $('.woo-huilv-status-cards').after($notice);
            $notice.fadeIn(300);

            // 5秒后自动消失
            setTimeout(function() {
                $notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
        }

        // =============================
        // 表单验证
        // =============================
        $('form').on('submit', function() {
            var autoRefresh = $('#woo_huilv_auto_refresh').is(':checked');

            if (autoRefresh) {
                var apiKey = $('#woo_huilv_api_key').val();
                if (!apiKey || apiKey.trim() === '') {
                    if (!confirm('自动刷新已启用但 API Key 为空，汇率将无法自动更新。确定要保存吗？')) {
                        return false;
                    }
                }

                var refreshDays = parseInt($('#woo_huilv_refresh_days').val());
                if (isNaN(refreshDays) || refreshDays < 1) {
                    alert('刷新频率至少为 1 天');
                    return false;
                }
            }

            return true;
        });

    });

})(jQuery);
