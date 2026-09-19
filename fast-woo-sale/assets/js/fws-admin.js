/**
 * Fast Woo Predictive Purchase — Admin Panel Script (v2.7)
 * رابط دولایه تنظیمات، جستجوی محصول، مدیریت قوانین دستی و لیست سیاه، بنچمارک و تحلیل
 */
(function ($) {
  'use strict';

  var P = window.fws_admin_params || {};

  function showToast(message, isError) {
    var $toast = $('<div class="fws-admin-toast"></div>').text(message || '');
    if (isError) $toast.addClass('is-error');
    $('body').append($toast);
    setTimeout(function () { $toast.addClass('is-visible'); }, 10);
    setTimeout(function () {
      $toast.removeClass('is-visible');
      setTimeout(function () { $toast.remove(); }, 350);
    }, 3200);
  }

  function api(action, data, done) {
    var payload = $.extend({ action: action, nonce: P.nonce }, data || {});
    $.post(P.ajax_url, payload, function (res) {
      if (res && res.success) {
        if (res.data && res.data.message) showToast(res.data.message, false);
        if (done) done(res);
      } else {
        showToast(res && res.data && res.data.message ? res.data.message : 'خطای نامشخص رخ داد.', true);
      }
    }).fail(function () {
      showToast('خطای ارتباط با سرور؛ لطفاً دوباره تلاش کنید.', true);
    });
  }

  $(document).ready(function () {

    /* ── لایه‌بندی تنظیمات: ساده در برابر تخصصی ─────────────────────── */
    var storedState = null;
    try { storedState = localStorage.getItem('fws_expert_settings'); } catch (e) {}
    if (storedState === '1') {
      $('#fws-expert-panel').show();
      $('#fws-expert-toggle').addClass('is-open');
    }

    $('#fws-expert-toggle').on('click', function () {
      var $panel = $('#fws-expert-panel');
      var open = !$panel.is(':visible');
      $panel.stop(true, true).slideToggle(200);
      $(this).toggleClass('is-open', open);
      try { localStorage.setItem('fws_expert_settings', open ? '1' : '0'); } catch (e) {}
    });

    /* ── کارت‌های استراتژی موتور (انتخاب بصری) ─────────────────────── */
    $(document).on('change', '.fws-mode-card input[type=radio]', function () {
      $('.fws-mode-card').removeClass('is-active');
      $(this).closest('.fws-mode-card').addClass('is-active');
    });

    /* ── جستجوی محصولات (AJAX) ─────────────────────────────────────── */
    var searchTimer = {};
    $(document).on('input', '.fws-ps .fws-ps-input', function () {
      var $box = $(this).closest('.fws-ps');
      var $input = $(this);
      var term = $.trim($input.val());
      var key = $box.data('key');

      if (term.length < 2) {
        $box.find('.fws-ps-results').empty().hide();
        return;
      }
      clearTimeout(searchTimer[key]);
      searchTimer[key] = setTimeout(function () {
        $.post(P.ajax_url, { action: 'fws_admin_search_products', nonce: P.nonce, term: term }, function (res) {
          var $list = $box.find('.fws-ps-results').empty();
          var results = (res && res.success && res.data && res.data.results) ? res.data.results : [];
          if (!results.length) {
            $list.append('<div class="fws-ps-empty">' + (P.i18n && P.i18n.no_results ? P.i18n.no_results : 'یافت نشد') + '</div>');
          } else {
            $.each(results, function (i, item) {
              $list.append(
                $('<button type="button" class="fws-ps-option"></button>')
                  .text(item.text + ' (#' + item.id + ')')
                  .data('id', item.id)
                  .data('text', item.text)
              );
            });
          }
          $list.show();
        });
      }, 350);
    });

    $(document).on('click', '.fws-ps-option', function (e) {
      e.preventDefault();
      var $box = $(this).closest('.fws-ps');
      $box.find('.fws-ps-id').val($(this).data('id'));
      $box.find('.fws-ps-input').val($(this).data('text'));
      $box.find('.fws-ps-results').empty().hide();
    });

    $(document).on('click', function (e) {
      if (!$(e.target).closest('.fws-ps').length) {
        $('.fws-ps-results').hide();
      }
    });

    /* ── قوانین دستی مدیر ─────────────────────────────────────────── */
    $('#fws-add-rule-btn').on('click', function () {
      var source = $('#fws-rule-source-id').val();
      var target = $('#fws-rule-target-id').val();
      var confidence = $('#fws-rule-confidence').val() || 95;
      if (!source || !target) {
        showToast('ابتدا هر دو محصول را از جستجو انتخاب کنید.', true);
        return;
      }
      var $btn = $(this).prop('disabled', true);
      api('fws_add_manual_rule', { source_id: source, target_id: target, confidence: confidence }, function () {
        window.location.reload();
      }).always(function () { $btn.prop('disabled', false); });
    });

    $(document).on('click', '.fws-delete-rule', function () {
      if (!window.confirm('این قانون دستی حذف شود؟')) return;
      api('fws_delete_manual_rule', {
        source_id: $(this).data('source'),
        target_id: $(this).data('target')
      }, function () { window.location.reload(); });
    });

    /* ── لیست سیاه محصولات ────────────────────────────────────────── */
    $('#fws-add-blacklist-btn').on('click', function () {
      var pid = $('#fws-blacklist-id').val();
      if (!pid) {
        showToast('ابتدا محصول را از جستجو انتخاب کنید.', true);
        return;
      }
      api('fws_add_blacklist_product', { product_id: pid }, function () {
        window.location.reload();
      });
    });

    $(document).on('click', '.fws-bl-remove', function () {
      api('fws_remove_blacklist_product', { product_id: $(this).data('product-id') }, function () {
        window.location.reload();
      });
    });

    /* ── اکشن‌های موتور: تحلیل مجدد / بهینه‌سازی / بنچمارک ─────────── */
    $('#fws-recalculate-btn').on('click', function (e) {
      e.preventDefault();
      var $btn = $(this);
      $btn.prop('disabled', true).text('در حال پردازش سفارشات...');
      $('#fws-admin-status').text('در حال اجرای الگوریتم Market Basket به صورت دسته‌ای...').css('color', '#2563eb');

      $.post(P.ajax_url, { action: 'fws_recalculate_rules', nonce: P.nonce }, function (res) {
        $btn.prop('disabled', false).text('🔄 تحلیل مجدد دیتابیس سفارشات');
        if (res.success) {
          $('#fws-admin-status').text('✓ موفقیت: ' + res.data.rules_count + ' رابطه کشف و ذخیره شد.').css('color', '#059669');
          setTimeout(function () { location.reload(); }, 1200);
        } else {
          $('#fws-admin-status').text('خطا: ' + (res.data ? res.data.message : 'نامشخص')).css('color', '#dc2626');
        }
      }).fail(function () {
        $btn.prop('disabled', false).text('🔄 تحلیل مجدد دیتابیس سفارشات');
        $('#fws-admin-status').text('خطای سرور در انجام تحلیل. لطفاً گزارش خطای PHP را بررسی نمایید.').css('color', '#dc2626');
      });
    });

    $('#fws-optimize-db-btn').on('click', function (e) {
      e.preventDefault();
      var $btn = $(this);
      $btn.prop('disabled', true).text('در حال بهینه‌سازی...');

      $.post(P.ajax_url, { action: 'fws_optimize_database', nonce: P.nonce }, function (res) {
        $btn.prop('disabled', false).text('⚡ بهینه‌سازی ایندکس‌ها و پاکسازی کش');
        if (res.success) {
          $('#fws-admin-status').text('✓ ایندکس‌ها مرتب و کش با موفقیت پاکسازی شد.').css('color', '#059669');
        }
      }).fail(function () {
        $btn.prop('disabled', false).text('⚡ بهینه‌سازی ایندکس‌ها و پاکسازی کش');
        $('#fws-admin-status').text('خطا در پاکسازی کش یا بهینه‌سازی جدول.').css('color', '#dc2626');
      });
    });

    $('#fws-benchmark-btn').on('click', function (e) {
      e.preventDefault();
      var $btn = $(this);
      $btn.prop('disabled', true).text('در حال اندازه‌گیری...');
      $('#fws-admin-status').text('اجرای بنچمارک کوئری با و بدون ایندکس...').css('color', '#2563eb');

      $.post(P.ajax_url, { action: 'fws_run_speed_benchmark', nonce: P.nonce }, function (res) {
        $btn.prop('disabled', false).text('🚀 بنچمارک سرعت کوئری');
        if (res.success && res.data && res.data.status !== 'no_data') {
          var msg = '✓ کوئری ایندکس‌شده: ' + res.data.indexed_ms + 'ms — بدون ایندکس: ' + res.data.unindexed_ms + 'ms (اعداد واقعی اندازه‌گیری‌شده)';
          if (res.data.has_redis) msg += ' — Redis فعال';
          $('#fws-admin-status').text(msg).css('color', '#059669');
        } else {
          $('#fws-admin-status').text('هنوز داده‌ای برای بنچمارک وجود ندارد. ابتدا تحلیل مجدد را اجرا کنید.').css('color', '#d97706');
        }
      }).fail(function () {
        $btn.prop('disabled', false).text('🚀 بنچمارک سرعت کوئری');
        $('#fws-admin-status').text('خطا در اجرای بنچمارک.').css('color', '#dc2626');
      });
    });
    /* ── پنل ظاهر و شخصی‌سازی: پیش‌نمایش زنده (v2.8) ───────────────── */
    var $previewRoot = $('#fws-style-preview-root');
    if ($previewRoot.length) {
      var PRESET_VARS = {
        minimal: {
          '--fws-shadow': 'none',
          '--fws-grad-thankyou': 'none',
          '--fws-grad-search': 'none',
          '--fws-progress-fill': 'var(--fws-accent)'
        },
        theme: {
          '--fws-box-bg': 'transparent',
          '--fws-text-main': 'inherit',
          '--fws-text-muted': 'inherit',
          '--fws-price-color': 'inherit',
          '--fws-shadow': 'none',
          '--fws-btn-success-bg': 'var(--fws-accent)',
          '--fws-tag-bg': 'transparent',
          '--fws-tag-text': 'inherit',
          '--fws-search-badge-bg': 'var(--fws-accent)',
          '--fws-progress-fill': 'var(--fws-accent)',
          '--fws-grad-thankyou': 'none',
          '--fws-grad-search': 'none'
        }
      };
      var ALL_PRESET_KEYS = ['--fws-shadow', '--fws-grad-thankyou', '--fws-grad-search', '--fws-progress-fill',
        '--fws-btn-success-bg', '--fws-tag-bg', '--fws-tag-text', '--fws-search-badge-bg',
        '--fws-box-bg', '--fws-text-main', '--fws-text-muted', '--fws-price-color'];

      function darkenHex(hex, pct) {
        var m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex || '');
        if (!m) return hex;
        var out = '#';
        for (var i = 1; i <= 3; i++) {
          var v = Math.round(parseInt(m[i], 16) * (1 - pct));
          out += ('0' + Math.max(0, Math.min(255, v)).toString(16)).slice(-2);
        }
        return out;
      }

      function applyStylePreview() {
        var el = $previewRoot[0];
        if (!el || !el.style) return;

        // ۱) مقادیر پالت رنگ از فرم
        $('#fws-style-card input[type=color]').each(function () {
          var name = $(this).data('fws-var');
          if (name) el.style.setProperty(name, $(this).val());
        });

        // ۲) رنگ hover مشتق‌شده از رنگ اصلی
        var accent = $('#fws-style-card input[data-fws-var="--fws-accent"]').val() || '#f97316';
        el.style.setProperty('--fws-accent-hover', darkenHex(accent, 0.12));

        // ۳) مقادیر عددی (گردی گوشه‌ها و اندازه فونت)
        $('#fws-style-card input[type=number][data-fws-var]').each(function () {
          var v = parseInt($(this).val(), 10);
          if (isNaN(v)) return;
          var suffix = $(this).data('fws-suffix') || '';
          el.style.setProperty($(this).data('fws-var'), v + suffix);
          if ('--fws-radius' === $(this).data('fws-var')) {
            el.style.setProperty('--fws-radius-sm', Math.max(0, v - 6) + 'px');
          }
        });

        // ۴) بازنویسی متغیرها بر اساس پیش‌تنظیم فعال (اولویت نهایی با پیش‌تنظیم)
        var preset = $previewRoot.hasClass('fws-preset-theme') ? 'theme'
          : ($previewRoot.hasClass('fws-preset-minimal') ? 'minimal' : 'default');
        var vars = PRESET_VARS[preset];
        if (vars) {
          $.each(vars, function (k, v) { el.style.setProperty(k, v); });
        }
      }

      $previewRoot.removeClass('fws-preset-default fws-preset-minimal fws-preset-theme');
      applyStylePreview();

      $('#fws-style-card').on('input change', 'input[type=color], input[type=number][data-fws-var]', applyStylePreview);

      $('#fws-style-card').on('change', 'input[name="fws_prediction_settings[style_preset]"]', function () {
        var preset = String($(this).val() || 'default');
        $previewRoot.removeClass('fws-preset-default fws-preset-minimal fws-preset-theme').addClass('fws-preset-' + preset);
        // پاکسازی متغیرهای پیش‌تنظیم قبلی، سپس اعمال مجدد
        $.each(ALL_PRESET_KEYS, function (i, name) { $previewRoot[0].style.removeProperty(name); });
        applyStylePreview();
      });
    }
  });
})(jQuery);
