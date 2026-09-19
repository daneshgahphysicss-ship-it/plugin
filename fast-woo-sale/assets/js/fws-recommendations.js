/**
 * Fast Woo Predictive Recommendations Frontend Script
 */
(function($) {
  'use strict';

  function showToast(message, isError) {
    $('.fws-toast').remove();
    // امن: متن به‌صورت text-node تزریق می‌شود، نه HTML (محتوای پیام سرور قابل اعتماد نیست)
    var $toast = $('<div class="fws-toast" dir="rtl"></div>').text(message || '');
    if (isError) $toast.addClass('is-error');
    $('body').append($toast);
    setTimeout(function() {
      $toast.addClass('is-visible');
    }, 10);
    setTimeout(function() {
      $toast.removeClass('is-visible');
      setTimeout(function() { $toast.remove(); }, 350);
    }, 3500);
  }

  /**
   * POST helper with one automatic retry on an expired nonce (BUG-10 fix).
   * Pages served from a full-page cache carry a stale nonce; WordPress then answers
   * 403 / -1. We fetch a fresh nonce once and replay the request transparently.
   */
  function fwsPost(data, done) {
    data.nonce = fws_params.nonce;
    var jq = $.post(fws_params.ajax_url, data, done);
    var deferred = $.Deferred();
    jq.done(function(res) { deferred.resolve(res); }).fail(function(xhr) {
      var expired = xhr && (xhr.status === 403 || xhr.status === 400 || (xhr.responseText || '').trim() === '-1');
      if (!expired) { deferred.reject(xhr); return; }
      $.post(fws_params.ajax_url, { action: 'fws_refresh_nonce' }).done(function(r) {
        if (!(r && r.success && r.data && r.data.nonce)) { deferred.reject(xhr); return; }
        fws_params.nonce = r.data.nonce;
        data.nonce = r.data.nonce;
        $.post(fws_params.ajax_url, data, done).done(function(res) { deferred.resolve(res); })
          .fail(function(x) { deferred.reject(x); });
      }).fail(function() { deferred.reject(xhr); });
    });
    return deferred.promise();
  }

  $(document).ready(function() {
    // Checkbox toggle: recalculate bundle price dynamically (scoped to each bundle widget)
    $(document).on('change', '.fws-check-item', function() {
      var $wrap = $(this).closest('.fws-bundle-wrapper');
      var total = 0;
      $wrap.find('.fws-check-item:checked').each(function() {
        total += parseFloat($(this).data('price')) || 0;
      });
      var discountPercent = parseFloat(fws_params.bundle_discount) || 0;
      var discounted = total * ((100 - discountPercent) / 100);
      var symbol = fws_params.currency_symbol || 'تومان';

      $wrap.find('.fws-original-price').text(Math.round(total).toLocaleString('fa-IR') + ' ' + symbol);
      $wrap.find('.fws-discounted-price').text(Math.round(discounted).toLocaleString('fa-IR') + ' ' + symbol);
    });

    // Add Bundle to Cart via Ajax (scoped to the clicked widget + signed payload)
    $(document).on('click', '.fws-add-bundle-btn', function(e) {
      e.preventDefault();
      var $btn = $(this);
      var $wrap = $btn.closest('.fws-bundle-wrapper');
      var productIds = [];

      $wrap.find('.fws-check-item:checked').each(function() {
        productIds.push($(this).val());
      });

      if (productIds.length === 0) {
        showToast('لطفاً حداقل یک محصول از پکیج را انتخاب کنید.', true);
        return;
      }

      $btn.prop('disabled', true).text('در حال افزودن به سبد...');

      fwsPost({
        action: 'fws_add_bundle',
        product_ids: productIds,
        main_id: $btn.data('main-id'),
        bundle_ids: $btn.data('bundle-ids'),
        bundle_sig: $btn.data('bundle-sig')
      }, function(res) {
        $btn.prop('disabled', false).text('⚡ ' + fws_params.added_text);
        if (res.success) {
          showToast('✓ ' + fws_params.added_text, false);
          if (res.data && res.data.fragments) {
            $.each(res.data.fragments, function(key, val) {
              $(key).replaceWith(val);
            });
          }
          $(document.body).trigger('wc_fragment_refresh');
          $(document.body).trigger('added_to_cart', [res.data ? res.data.fragments : null, res.data ? res.data.cart_hash : null, $btn]);
          if (res.data.cart_url) {
            setTimeout(function() {
              window.location.href = res.data.cart_url;
            }, 600);
          }
        } else {
          showToast(res.data && res.data.message ? res.data.message : 'خطا در افزودن پکیج به سبد', true);
        }
      }).fail(function() {
        $btn.prop('disabled', false).text('تلاش مجدد');
        showToast('خطای ارتباط با سرور. لطفاً صفحه را تازه‌سازی نمایید.', true);
      });
    });

    // Quick Add Single Product (Free Shipping Fillers & Cart Recs)
    $(document).on('click', '.fws-quick-add-btn', function(e) {
      e.preventDefault();
      var $btn = $(this);
      var pid = $btn.data('product-id');
      $btn.prop('disabled', true).text('...');

      fwsPost({
        action: 'fws_add_single',
        product_id: pid
      }, function(res) {
        if (res.success) {
          $btn.prop('disabled', false).text('✓ افزوده شد');
          showToast(res.data && res.data.message ? res.data.message : 'کالای مکمل با موفقیت به سبد خرید افزوده شد.', false);
          if (res.data && res.data.fragments) {
            $.each(res.data.fragments, function(key, val) {
              $(key).replaceWith(val);
            });
          }
          $(document.body).trigger('wc_fragment_refresh');
          $(document.body).trigger('added_to_cart', [res.data ? res.data.fragments : null, res.data ? res.data.cart_hash : null, $btn]);
        } else {
          $btn.prop('disabled', false).text('+ افزودن');
          showToast(res.data && res.data.message ? res.data.message : 'خطا در افزودن کالا.', true);
        }
      }).fail(function() {
        $btn.prop('disabled', false).text('+ افزودن');
        showToast('خطا در افزودن کالا.', true);
      });
    });

    // Thank You Page 1-Click Upsell
    $(document).on('click', '.fws-thankyou-claim-btn', function(e) {
      e.preventDefault();
      var $btn = $(this);
      var orderId  = $btn.data('order-id');
      var orderKey = $btn.data('order-key');
      var pid      = $btn.data('product-id');

      $btn.prop('disabled', true).text('در حال ثبت به سفارش...');

      fwsPost({
          action: 'fws_thankyou_upsell',
          order_id: orderId,
          order_key: orderKey,
          product_id: pid
      }).done(function(res) {
          if (res.success) {
            $btn.text('🎉 با موفقیت به سفارش افزوده شد').css({'background': '#047857', 'border-color': '#047857'});
            showToast(res.data && res.data.message ? res.data.message : '🎉 کالا با موفقیت به سفارش شما افزوده شد.', false);
            if (res.data && res.data.pay_url) {
              // Order still needs payment: send the customer to pay the updated total.
              setTimeout(function() { window.location.href = res.data.pay_url; }, 1200);
            }
          } else {
            showToast(res.data && res.data.message ? res.data.message : 'امکان ثبت سفارش وجود ندارد.', true);
            $btn.prop('disabled', false).text('تلاش مجدد');
          }
      }).fail(function() {
          showToast('خطای ارتباط با سرور. لطفاً صفحه را تازه‌سازی کنید.', true);
          $btn.prop('disabled', false).text('تلاش مجدد');
      });
    });

    // Exit-Intent Modal: apply the admin-configured real coupon, then go to checkout
    $(document).on('click', '.fws-modal-coupon-btn', function(e) {
      e.preventDefault();
      var $btn = $(this);
      $btn.prop('disabled', true).text('در حال اعمال تخفیف...');

      fwsPost({
        action: 'fws_apply_exit_coupon'
      }, function(res) {
        if (res.success && res.data && res.data.checkout_url) {
          showToast(res.data.message || 'کد تخفیف اعمال شد.', false);
          setTimeout(function() {
            window.location.href = res.data.checkout_url;
          }, 500);
        } else {
          showToast(res.data && res.data.message ? res.data.message : 'اعمال تخفیف ممکن نشد.', true);
          $btn.prop('disabled', false).text('ادامه خرید بدون تخفیف');
        }
      }).fail(function() {
        showToast('خطای ارتباط با سرور. لطفاً صفحه را تازه‌سازی نمایید.', true);
        $btn.prop('disabled', false).text('تلاش مجدد');
      });
    });

    // Exit Intent Handler (with safe private-browsing storage support)
    var exitShown = false;
    try {
      exitShown = !!sessionStorage.getItem('fws_exit_shown');
    } catch(e) {
      exitShown = false;
    }

    if (!exitShown && $('#fws-exit-intent-modal').length > 0) {
      $(document).on('mouseleave', function(e) {
        var alreadyShown = false;
        try { alreadyShown = !!sessionStorage.getItem('fws_exit_shown'); } catch(err) {}
        if (e.clientY < 20 && !alreadyShown) {
          try { sessionStorage.setItem('fws_exit_shown', '1'); } catch(err) {}
          $('#fws-exit-intent-modal').fadeIn(200);
        }
      });
    }

    // Dismiss modal via close button or dismiss action
    $(document).on('click', '.fws-modal-close, .fws-modal-dismiss-btn', function() {
      $('#fws-exit-intent-modal').fadeOut(200);
    });

    // Dismiss on clicking modal backdrop outside content box
    $(document).on('click', '#fws-exit-intent-modal', function(e) {
      if ($(e.target).is('#fws-exit-intent-modal')) {
        $('#fws-exit-intent-modal').fadeOut(200);
      }
    });

    // Dismiss on pressing ESC key
    $(document).on('keydown', function(e) {
      if (e.key === 'Escape' || e.keyCode === 27) {
        if ($('#fws-exit-intent-modal').is(':visible')) {
          $('#fws-exit-intent-modal').fadeOut(200);
        }
      }
    });

    // Reset button states on bfcache navigation (browser back/forward)
    window.addEventListener('pageshow', function(event) {
      if (event.persisted) {
        $('.fws-add-bundle-btn').prop('disabled', false).text('⚡ ' + (fws_params.added_text || 'افزودن پکیج به سبد خرید'));
        $('.fws-quick-add-btn').prop('disabled', false).text('+ افزودن');
      }
    });
  });
})(jQuery);
