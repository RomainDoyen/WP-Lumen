(function ($) {
  'use strict';

  function ajax(action, data, opts) {
    opts = opts || {};
    return $.ajax({
      url: lumenWp.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      timeout: opts.timeout || 60000,
      data: Object.assign({ action: action, nonce: lumenWp.nonce }, data || {})
    });
  }

  function ajaxErrorMessage(xhr, fallback) {
    if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
      return xhr.responseJSON.data.message;
    }
    if (xhr && xhr.statusText === 'timeout') {
      return 'Délai dépassé — réessayez (bibliothèque volumineuse).';
    }
    if (xhr && xhr.status === 0) {
      return 'Requête interrompue — vérifiez la connexion.';
    }
    if (xhr && xhr.status) {
      return (fallback || (lumenWp.i18n && lumenWp.i18n.error) || 'Erreur') + ' (HTTP ' + xhr.status + ')';
    }
    return fallback || (lumenWp.i18n && lumenWp.i18n.error) || 'Erreur';
  }

  function showModalError(message) {
    if (window.lumenWpModal && typeof window.lumenWpModal.error === 'function') {
      window.lumenWpModal.error(message);
      return;
    }
    window.alert(message);
  }

  function showModalSuccess(message) {
    if (window.lumenWpModal && typeof window.lumenWpModal.success === 'function') {
      window.lumenWpModal.success(message);
      return;
    }
    window.alert(message);
  }

  // —— Custom select (options au thème Lumen) ——
  function closeAllCustomSelects(except) {
    $('.lumen-wp-csel.is-open').each(function () {
      if (except && this === except) return;
      var $wrap = $(this);
      $wrap.removeClass('is-open');
      $wrap.find('.lumen-wp-csel__menu').prop('hidden', true);
      $wrap.find('.lumen-wp-csel__trigger').attr('aria-expanded', 'false');
    });
  }

  function syncCustomSelect($select) {
    var $wrap = $select.closest('.lumen-wp-csel');
    if (!$wrap.length) return;

    var disabled = !!$select.prop('disabled');
    $wrap.toggleClass('is-disabled', disabled);
    $wrap.find('.lumen-wp-csel__trigger').prop('disabled', disabled);

    var $menu = $wrap.find('.lumen-wp-csel__menu').empty();
    var current = String($select.val() == null ? '' : $select.val());
    var label = '';

    $select.find('option').each(function () {
      var value = String(this.value);
      var text = $(this).text();
      var selected = value === current || (!!$select.find('option:selected').length === false && this.selected);
      if (this.selected) {
        current = value;
        label = text;
      }
      var $btn = $('<button type="button" class="lumen-wp-csel__option" role="option"/>')
        .attr('data-value', value)
        .attr('aria-selected', this.selected ? 'true' : 'false')
        .toggleClass('is-selected', !!this.selected)
        .text(text);
      $menu.append($('<li role="none"/>').append($btn));
    });

    if (!label) {
      var $sel = $select.find('option:selected').first();
      label = $sel.length ? $sel.text() : '';
    }
    $wrap.find('.lumen-wp-csel__value').text(label || '—');
  }

  function enhanceCustomSelect(selectEl) {
    var $select = $(selectEl);
    if (!$select.length || $select.data('lumenCsel')) return;

    $select.addClass('lumen-wp-select lumen-wp-csel__native');
    var $wrap = $('<div class="lumen-wp-csel"/>');
    $select.before($wrap);
    $wrap.append($select);
    $wrap.append(
      $('<button type="button" class="lumen-wp-csel__trigger" aria-haspopup="listbox" aria-expanded="false"/>')
        .append($('<span class="lumen-wp-csel__value"/>'))
        .append($('<span class="lumen-wp-csel__chevron" aria-hidden="true"/>'))
    );
    $wrap.append($('<ul class="lumen-wp-csel__menu" role="listbox" hidden/>'));
    $select.data('lumenCsel', true);
    syncCustomSelect($select);
  }

  function enhanceAllCustomSelects(root) {
    $(root || document)
      .find('select.lumen-wp-select')
      .each(function () {
        enhanceCustomSelect(this);
      });
  }

  $(document).on('click', '.lumen-wp-csel__trigger', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $wrap = $(this).closest('.lumen-wp-csel');
    if ($wrap.hasClass('is-disabled')) return;
    var open = !$wrap.hasClass('is-open');
    closeAllCustomSelects(open ? $wrap[0] : null);
    $wrap.toggleClass('is-open', open);
    $wrap.find('.lumen-wp-csel__menu').prop('hidden', !open);
    $(this).attr('aria-expanded', open ? 'true' : 'false');
  });

  $(document).on('click', '.lumen-wp-csel__option', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $btn = $(this);
    var $wrap = $btn.closest('.lumen-wp-csel');
    var $select = $wrap.find('select');
    var value = String($btn.data('value'));
    $select.val(value).trigger('change');
    syncCustomSelect($select);
    closeAllCustomSelects();
  });

  $(document).on('click', function () {
    closeAllCustomSelects();
  });

  $(document).on('keydown', function (e) {
    if (e.key === 'Escape') closeAllCustomSelects();
  });

  // —— Number steppers (flèches au thème Lumen) ——
  function stepNumberInput($input, direction) {
    var el = $input.get(0);
    if (!el || $input.prop('disabled') || $input.prop('readonly')) return;

    var step = parseFloat($input.attr('step') || '1');
    if (!isFinite(step) || step <= 0) step = 1;
    var minAttr = $input.attr('min');
    var maxAttr = $input.attr('max');
    var min = minAttr !== undefined && minAttr !== '' ? parseFloat(minAttr) : null;
    var max = maxAttr !== undefined && maxAttr !== '' ? parseFloat(maxAttr) : null;
    var raw = String($input.val() || '').trim();
    var value = raw === '' ? 0 : parseFloat(raw);
    if (!isFinite(value)) value = 0;

    value = direction > 0 ? value + step : value - step;
    // Évite les flottants bizarres (ex. 0.1 + 0.2).
    var decimals = 0;
    var stepStr = String(step);
    if (stepStr.indexOf('.') !== -1) {
      decimals = stepStr.split('.')[1].length;
    }
    value = parseFloat(value.toFixed(decimals));

    if (min !== null && isFinite(min) && value < min) value = min;
    if (max !== null && isFinite(max) && value > max) value = max;

    $input.val(String(value)).trigger('change').trigger('input');
  }

  function enhanceNumberStepper(inputEl) {
    var $input = $(inputEl);
    if (!$input.length || $input.data('lumenNstep')) return;

    $input.addClass('lumen-wp-nstep__input');
    var $wrap = $('<div class="lumen-wp-nstep"/>');
    $input.before($wrap);
    $wrap.append($input);
    $wrap.append(
      $('<div class="lumen-wp-nstep__btns"/>')
        .append(
          $('<button type="button" class="lumen-wp-nstep__btn lumen-wp-nstep__btn--up" tabindex="-1"/>')
            .attr('aria-label', 'Augmenter')
            .html('&#9650;')
        )
        .append(
          $('<button type="button" class="lumen-wp-nstep__btn lumen-wp-nstep__btn--down" tabindex="-1"/>')
            .attr('aria-label', 'Diminuer')
            .html('&#9660;')
        )
    );
    $input.data('lumenNstep', true);
    $wrap.toggleClass('is-disabled', !!$input.prop('disabled'));
  }

  function enhanceAllNumberSteppers(root) {
    var $scope = root ? $(root) : $(document);
    // Si on passe déjà .lumen-wp-wrap, ne pas re-chercher .lumen-wp-wrap à l’intérieur.
    var $inputs = $scope.is('.lumen-wp-wrap')
      ? $scope.find('input[type="number"]')
      : $scope.find('.lumen-wp-wrap input[type="number"]');
    $inputs.each(function () {
      enhanceNumberStepper(this);
    });
  }

  $(document).on('click', '.lumen-wp-nstep__btn--up', function (e) {
    e.preventDefault();
    stepNumberInput($(this).closest('.lumen-wp-nstep').find('input[type="number"]'), 1);
  });

  $(document).on('click', '.lumen-wp-nstep__btn--down', function (e) {
    e.preventDefault();
    stepNumberInput($(this).closest('.lumen-wp-nstep').find('input[type="number"]'), -1);
  });

  // —— Modale feedback globale ——
  var modalEl = null;
  var lastFocus = null;

  function ensureModal() {
    if (!modalEl) {
      modalEl = document.getElementById('lumen-wp-modal');
    }
    return modalEl;
  }

  function closeModal() {
    var el = ensureModal();
    if (!el) return;
    el.hidden = true;
    el.setAttribute('aria-hidden', 'true');
    el.classList.remove('is-success', 'is-error', 'is-info', 'is-open', 'is-confirm');
    document.documentElement.classList.remove('lumen-wp-modal-open');
    var action = el.querySelector('#lumen-wp-modal-action');
    if (action) {
      hideModalAction(action);
    }
    resetModalConfirmUi(el);
    if (lastFocus && typeof lastFocus.focus === 'function') {
      lastFocus.focus();
    }
    lastFocus = null;
    confirmCallback = null;
  }

  var confirmCallback = null;

  function hideModalAction(action) {
    if (!action) return;
    action.hidden = true;
    action.classList.remove('is-visible');
    action.setAttribute('aria-hidden', 'true');
    action.setAttribute('tabindex', '-1');
    action.removeAttribute('href');
    action.textContent = '';
  }

  function showModalAction(action, url, label) {
    if (!action || !url) {
      hideModalAction(action);
      return;
    }
    action.hidden = false;
    action.classList.add('is-visible');
    action.setAttribute('aria-hidden', 'false');
    action.removeAttribute('tabindex');
    action.setAttribute('href', url);
    action.textContent = label || '';
  }

  function resetModalConfirmUi(el) {
    var cancel = el.querySelector('#lumen-wp-modal-cancel');
    var ok = el.querySelector('#lumen-wp-modal-ok');
    var i18n = lumenWp.i18n || {};
    if (cancel) {
      cancel.hidden = true;
      cancel.setAttribute('aria-hidden', 'true');
    }
    if (ok) {
      ok.textContent = i18n.okLabel || 'OK';
      ok.setAttribute('data-lumen-modal-close', '');
      ok.classList.remove('lumen-wp-modal__confirm');
    }
  }

  function openModal(type, title, message, opts) {
    opts = opts || {};
    var el = ensureModal();
    if (!el) {
      window.alert(message || title || '');
      return;
    }

    lastFocus = document.activeElement;
    var kind = type === 'error' ? 'error' : type === 'info' ? 'info' : type === 'confirm' ? 'info' : 'success';
    var i18n = lumenWp.i18n || {};
    var defaultTitle =
      kind === 'error' ? i18n.errorTitle : kind === 'info' ? i18n.infoTitle : i18n.successTitle;

    resetModalConfirmUi(el);
    el.classList.remove('is-success', 'is-error', 'is-info', 'is-confirm');
    el.classList.add('is-' + kind, 'is-open');
    if (type === 'confirm') {
      el.classList.add('is-confirm');
    }
    el.querySelector('#lumen-wp-modal-title').textContent = title || defaultTitle || '';
    el.querySelector('#lumen-wp-modal-message').textContent = message || '';

    var action = el.querySelector('#lumen-wp-modal-action');
    if (action) {
      if (opts.actionUrl) {
        showModalAction(action, opts.actionUrl, opts.actionLabel || i18n.openSettings || '');
      } else {
        hideModalAction(action);
      }
    }

    if (type === 'confirm') {
      var cancel = el.querySelector('#lumen-wp-modal-cancel');
      var ok = el.querySelector('#lumen-wp-modal-ok');
      if (cancel) {
        cancel.hidden = false;
        cancel.setAttribute('aria-hidden', 'false');
        cancel.textContent = opts.cancelLabel || i18n.cancelLabel || 'Annuler';
      }
      if (ok) {
        ok.textContent = opts.confirmLabel || i18n.confirmLabel || 'Confirmer';
        ok.removeAttribute('data-lumen-modal-close');
        ok.classList.add('lumen-wp-modal__confirm');
      }
      confirmCallback = typeof opts.onConfirm === 'function' ? opts.onConfirm : null;
    }

    el.hidden = false;
    el.setAttribute('aria-hidden', 'false');
    document.documentElement.classList.add('lumen-wp-modal-open');

    var btn = el.querySelector(
      type === 'confirm'
        ? '#lumen-wp-modal-ok'
        : '#lumen-wp-modal-action.is-visible, #lumen-wp-modal-ok, [data-lumen-modal-close].button-primary, .lumen-wp-modal__actions .button-primary'
    );
    if (btn) {
      setTimeout(function () {
        btn.focus();
      }, 10);
    }
  }

  window.lumenWpModal = {
    show: function (opts) {
      opts = opts || {};
      openModal(opts.type || 'success', opts.title || '', opts.message || '', opts);
    },
    success: function (message, title) {
      openModal('success', title || (lumenWp.i18n && lumenWp.i18n.successTitle), message);
    },
    error: function (message, title) {
      openModal('error', title || (lumenWp.i18n && lumenWp.i18n.errorTitle), message);
    },
    info: function (message, title, opts) {
      openModal(
        'info',
        title || (lumenWp.i18n && lumenWp.i18n.infoTitle),
        message,
        opts || {}
      );
    },
    confirm: function (opts) {
      opts = opts || {};
      openModal('confirm', opts.title || (lumenWp.i18n && lumenWp.i18n.confirmTitle) || 'Confirmer', opts.message || '', opts);
    },
    close: closeModal
  };

  $(document).on('click', '#lumen-wp-modal-ok.lumen-wp-modal__confirm', function (e) {
    e.preventDefault();
    var cb = confirmCallback;
    closeModal();
    if (cb) {
      cb();
    }
  });

  $(document).on('click', '[data-lumen-modal-close]', function (e) {
    e.preventDefault();
    closeModal();
  });

  $(document).on('keydown', function (e) {
    if (e.key === 'Escape' && ensureModal() && !ensureModal().hidden) {
      closeModal();
    }
  });

  $(function () {
    if (lumenWp.flash && lumenWp.flash.message) {
      openModal(lumenWp.flash.type || 'success', lumenWp.flash.title || '', lumenWp.flash.message);
    }
  });

  // Audit / forms: replace native confirm()
  $(document).on('submit', 'form[data-lumen-confirm]', function (e) {
    var form = this;
    if (form.getAttribute('data-lumen-confirmed') === '1') {
      form.removeAttribute('data-lumen-confirmed');
      return true;
    }
    e.preventDefault();
    var msg = form.getAttribute('data-lumen-confirm') || '';
    var title = form.getAttribute('data-lumen-confirm-title') || '';
    if (!window.lumenWpModal || typeof window.lumenWpModal.confirm !== 'function') {
      if (window.confirm(msg)) {
        form.setAttribute('data-lumen-confirmed', '1');
        form.submit();
      }
      return false;
    }
    window.lumenWpModal.confirm({
      type: 'confirm',
      title: title || (lumenWp.i18n && lumenWp.i18n.confirmTitle) || 'Confirmer',
      message: msg,
      confirmLabel: (lumenWp.i18n && lumenWp.i18n.confirmLabel) || 'Confirmer',
      cancelLabel: (lumenWp.i18n && lumenWp.i18n.cancelLabel) || 'Annuler',
      onConfirm: function () {
        form.setAttribute('data-lumen-confirmed', '1');
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      }
    });
    return false;
  });

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    var ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    try {
      document.execCommand('copy');
      return Promise.resolve();
    } catch (e) {
      return Promise.reject(e);
    } finally {
      document.body.removeChild(ta);
    }
  }

  function fillSeoFields(seo, root) {
    if (!seo) return;

    var alt =
      typeof seo.alt_text_wcag === 'string' && seo.alt_text_wcag !== ''
        ? seo.alt_text_wcag
        : typeof seo.alt_text === 'string'
          ? seo.alt_text
          : typeof seo.alt_text_seo === 'string'
            ? seo.alt_text_seo
            : '';

    // 1) Modèle Backbone d’abord → la sidebar WP se re-render avec les nouvelles valeurs.
    syncWpMediaAttachment(root, seo, alt);

    // 2) Champs Lumen + natifs tout de suite (sans attendre le re-render).
    applyLumenSeoInputs(seo, root);
    setNativeMediaField('title', typeof seo.title === 'string' ? seo.title : null);
    setNativeMediaField('caption', typeof seo.caption === 'string' ? seo.caption : null);
    setNativeMediaField('description', typeof seo.description === 'string' ? seo.description : null);
    setNativeMediaField('alt', alt !== '' ? alt : null);

    // 3) Après le re-render WP (compat HTML mis en cache), réappliquer la carte Lumen.
    var boxEl = resolveMetaboxEl(root);
    window.setTimeout(function () {
      var live = boxEl && document.contains(boxEl) ? boxEl : findMetaboxByAttachment(root);
      applyLumenSeoInputs(seo, live || document);
      setNativeMediaField('title', typeof seo.title === 'string' ? seo.title : null);
      setNativeMediaField('caption', typeof seo.caption === 'string' ? seo.caption : null);
      setNativeMediaField('description', typeof seo.description === 'string' ? seo.description : null);
      setNativeMediaField('alt', alt !== '' ? alt : null);
    }, 0);
    window.setTimeout(function () {
      var live = boxEl && document.contains(boxEl) ? boxEl : findMetaboxByAttachment(root);
      applyLumenSeoInputs(seo, live || document);
    }, 50);
  }

  function resolveMetaboxEl(root) {
    if (!root) return null;
    if (root.classList && root.classList.contains('lumen-wp-metabox')) return root;
    if (root.closest) return root.closest('.lumen-wp-metabox');
    return null;
  }

  function findMetaboxByAttachment(root) {
    var id = attachmentIdFrom(root);
    if (!id) return null;
    return document.querySelector('.lumen-wp-metabox[data-attachment-id="' + id + '"]');
  }

  function attachmentIdFrom(root) {
    var box = resolveMetaboxEl(root);
    if (box) return parseInt(box.getAttribute('data-attachment-id') || '0', 10) || 0;
    if (root && root.getAttribute) {
      return parseInt(root.getAttribute('data-attachment-id') || '0', 10) || 0;
    }
    return 0;
  }

  function applyLumenSeoInputs(seo, root) {
    var scope = root && root.querySelector ? root : document;
    var keys = [
      'title',
      'alt_text_seo',
      'alt_text_wcag',
      'alt_text_short',
      'caption',
      'description'
    ];
    keys.forEach(function (key) {
      if (typeof seo[key] !== 'string') return;
      var byData = scope.querySelector('[data-lumen-seo="' + key + '"]');
      if (byData) {
        byData.value = seo[key];
        return;
      }
      var byName = document.querySelector('[name="lumen_seo[' + key + ']"]');
      if (byName) byName.value = seo[key];
    });
  }

  function setNativeMediaField(setting, value) {
    if (value === null || typeof value !== 'string') return;
    var nodes = document.querySelectorAll(
      '.attachment-details [data-setting="' +
        setting +
        '"], .media-sidebar [data-setting="' +
        setting +
        '"], .attachment-details [data-setting="' +
        setting +
        '"] input, .attachment-details [data-setting="' +
        setting +
        '"] textarea, .media-sidebar [data-setting="' +
        setting +
        '"] input, .media-sidebar [data-setting="' +
        setting +
        '"] textarea'
    );
    var list = Array.prototype.slice.call(nodes);
    if (!list.length) {
      var fallback = null;
      if (setting === 'title') {
        fallback =
          document.getElementById('attachment-details-two-column-title') ||
          document.getElementById('title');
      } else if (setting === 'caption') {
        fallback = document.getElementById('attachment_caption');
      } else if (setting === 'description') {
        fallback = document.getElementById('attachment_content');
      } else if (setting === 'alt') {
        fallback = document.getElementById('attachment_alt');
      }
      if (fallback) list = [fallback];
    }
    list.forEach(function (el) {
      if (!el || el.value === undefined) return;
      if (el.value === value) return;
      el.value = value;
      if (window.jQuery) {
        window.jQuery(el).trigger('input').trigger('change');
      } else {
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
    if (setting === 'description' && window.tinymce && typeof window.tinymce.get === 'function') {
      var ed = window.tinymce.get('attachment_content');
      if (ed) ed.setContent(value);
    }
  }

  function syncWpMediaAttachment(root, seo, alt) {
    if (!window.wp || !wp.media || typeof wp.media.attachment !== 'function') return;
    var id = attachmentIdFrom(root);
    if (!id) return;
    var att = wp.media.attachment(id);
    if (!att || typeof att.set !== 'function') return;
    var patch = {};
    if (typeof seo.title === 'string') patch.title = seo.title;
    if (typeof seo.caption === 'string') patch.caption = seo.caption;
    if (typeof seo.description === 'string') patch.description = seo.description;
    if (typeof alt === 'string' && alt !== '') patch.alt = alt;
    if (!Object.keys(patch).length) return;
    att.set(patch);
    if (typeof att.trigger === 'function') {
      att.trigger('change', att);
    }
  }

  $(document).on('click', '.lumen-wp-copy', function (e) {
    e.preventDefault();
    var id = $(this).data('target');
    var el = document.getElementById(id);
    if (!el) return;
    copyText(el.value)
      .then(function () {
        window.lumenWpModal.success(lumenWp.i18n.copied);
      })
      .catch(function () {
        window.lumenWpModal.error(lumenWp.i18n.copyFail);
      });
  });

  $(document).on('click', '.lumen-wp-suggest, #lumen-wp-suggest', function (e) {
    e.preventDefault();
    var box = $(this).closest('.lumen-wp-metabox');
    var id = box.data('attachment-id');
    var banner = box.find('.lumen-wp-mistral-banner, #lumen-wp-mistral-banner').first();
    banner.prop('hidden', true).text('');
    var btn = $(this).data('label', $(this).text()).prop('disabled', true).text(lumenWp.i18n.processing);

    ajax('lumen_wp_suggest', { id: id })
      .done(function (res) {
        if (!res || !res.success) {
          window.lumenWpModal.error(
            (res && res.data && res.data.message) || lumenWp.i18n.error
          );
          return;
        }
        fillSeoFields(res.data.seo, box.get(0));
        box.find('.lumen-wp-status').text('ok');
        box.find('.lumen-wp-error').remove();
        if (res.data.gutenberg) {
          $('#lumen-wp-gutenberg').val(res.data.gutenberg);
        }
        if (res.data.jsonld) {
          $('#lumen-wp-jsonld').val(res.data.jsonld);
        }
        if (res.data.warning) {
          banner.text(res.data.warning).prop('hidden', false);
          window.lumenWpModal.info(res.data.warning);
        } else {
          window.lumenWpModal.success(lumenWp.i18n.suggestDone);
        }
      })
      .fail(function (xhr) {
        var msg =
          (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ||
          lumenWp.i18n.error;
        banner.text(msg).prop('hidden', false);
        window.lumenWpModal.error(msg);
      })
      .always(function () {
        btn.prop('disabled', false).text(btn.data('label'));
      });
  });

  $(document).on('click', '.lumen-wp-reprocess, #lumen-wp-reprocess', function (e) {
    e.preventDefault();
    var $btn = $(this);
    var box = $btn.closest('.lumen-wp-metabox');
    var id = box.data('attachment-id');
    var run = function () {
      var btn = $btn.data('label', $btn.text()).prop('disabled', true).text(lumenWp.i18n.processing);
      ajax('lumen_wp_reprocess', { id: id, use_mistral: 0 })
        .done(function (res) {
          var data = res && res.data ? res.data : {};
          if (data.seo) fillSeoFields(data.seo, box.get(0));
          if (data.gutenberg) $('#lumen-wp-gutenberg').val(data.gutenberg);
          if (data.jsonld) $('#lumen-wp-jsonld').val(data.jsonld);
          if (data.status) {
            box.find('.lumen-wp-status').text(data.status);
          }
          if (data.has_backup) {
            box.attr('data-has-backup', '1');
            box.find('.lumen-wp-restore, #lumen-wp-restore').prop('hidden', false);
          }
          if (!res.success) {
            window.lumenWpModal.error(data.message || data.error || lumenWp.i18n.error);
          } else {
            window.lumenWpModal.success(lumenWp.i18n.done);
          }
        })
        .fail(function () {
          window.lumenWpModal.error(lumenWp.i18n.error);
        })
        .always(function () {
          btn.prop('disabled', false).text(btn.data('label'));
        });
    };

    if (box.hasClass('lumen-wp-metabox--modal')) {
      var msg =
        (lumenWp.i18n && lumenWp.i18n.reprocessConfirm) ||
        'Re-traiter remplacera les métadonnées SEO par une nouvelle génération. Continuer ?';
      if (
        window.lumenWpModal &&
        typeof window.lumenWpModal.confirm === 'function' &&
        document.getElementById('lumen-wp-modal')
      ) {
        window.lumenWpModal.confirm({
          message: msg,
          onConfirm: run
        });
        return;
      }
      if (!window.confirm(msg)) {
        return;
      }
    }
    run();
  });

  $(document).on('click', '.lumen-wp-restore, #lumen-wp-restore', function (e) {
    e.preventDefault();
    if (!window.confirm(lumenWp.i18n.restoreConfirm || 'Restaurer l’original ?')) {
      return;
    }
    var box = $(this).closest('.lumen-wp-metabox');
    var id = box.data('attachment-id');
    var btn = $(this).prop('disabled', true).text(lumenWp.i18n.processing);

    ajax('lumen_wp_restore_original', { id: id })
      .done(function (res) {
        var data = res && res.data ? res.data : {};
        if (!res.success) {
          window.lumenWpModal.error(data.message || lumenWp.i18n.error);
          return;
        }
        if (data.status) box.find('.lumen-wp-status').text(data.status);
        $('#lumen-wp-gutenberg').val(data.gutenberg || '');
        $('#lumen-wp-jsonld').val(data.jsonld || '');
        window.lumenWpModal.success(data.message || lumenWp.i18n.restored);
      })
      .fail(function (xhr) {
        window.lumenWpModal.error(
          (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ||
            lumenWp.i18n.error
        );
      })
      .always(function () {
        btn.prop('disabled', false).text('Restaurer l’original');
      });
  });

  // Bulk page — job WP-Cron
  var bulkPollTimer = null;
  var bulkLastStatus = '';
  var bulkStatusReady = false; // évite de rejouer la modale au rechargement d’un job déjà « done »

  function renderBulkLog(log) {
    var $ul = $('#lumen-wp-bulk-log');
    var $empty = $('#lumen-wp-bulk-log-empty');
    var $meta = $('#lumen-wp-bulk-log-meta');
    if (!$ul.length) return;

    $ul.empty();
    var rows = log || [];
    if ($meta.length) {
      $meta.text(rows.length ? rows.length + ' message(s)' : '30 derniers messages');
    }
    if ($empty.length) {
      $empty.prop('hidden', rows.length > 0);
    }

    rows.forEach(function (row) {
      var text = row.t || '';
      var ok = row.ok;
      var id = '';
      var msg = text;
      var m = text.match(/^#(\d+)\s*[—\-–]\s*(.*)$/);
      if (m) {
        id = '#' + m[1];
        msg = m[2] || '';
      }

      var stateClass = 'is-info';
      var badge = 'info';
      if (ok === true) {
        stateClass = 'is-ok';
        badge = 'ok';
      } else if (ok === false) {
        stateClass = 'is-error';
        badge = 'err';
      }

      var li = $('<li class="lumen-wp-log__row"/>').addClass(stateClass);
      li.append($('<span class="lumen-wp-log__badge"/>').text(badge));
      li.append($('<span class="lumen-wp-log__id"/>').text(id || '—'));
      li.append($('<span class="lumen-wp-log__msg"/>').text(msg || text));
      $ul.append(li);
    });
  }

  function appendErrorListItem($ul, err) {
    var title = (err && err.title) || (err && err.id ? '#' + err.id : 'Média');
    var message = (err && err.message) || '';
    var url = (err && err.edit_url) || '';
    var $li = $('<li class="lumen-wp-error-list__item"/>');
    if (url) {
      $li.append(
        $('<a class="lumen-wp-error-list__title" target="_blank" rel="noopener noreferrer"/>')
          .attr('href', url)
          .text(title)
      );
    } else {
      $li.append($('<span class="lumen-wp-error-list__title"/>').text(title));
    }
    if (message) {
      $li.append($('<span class="lumen-wp-error-list__msg"/>').text(message));
    }
    $ul.append($li);
  }

  function renderBulkErrors(errors) {
    var $shell = $('#lumen-wp-bulk-errors-shell');
    var $ul = $('#lumen-wp-bulk-errors');
    var $meta = $('#lumen-wp-bulk-errors-meta');
    if (!$shell.length || !$ul.length) return;

    var rows = errors || [];
    $ul.empty();
    if ($meta.length) {
      $meta.text(rows.length ? rows.length + ' erreur(s)' : '0');
    }
    $shell.prop('hidden', rows.length === 0);
    rows.forEach(function (err) {
      appendErrorListItem($ul, err);
    });
  }

  function formatHistoryWhen(startedAt, endedAt) {
    if (!startedAt) return '—';
    var start = new Date(startedAt);
    if (isNaN(start.getTime())) return '—';
    var dateOpts = { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' };
    var timeOpts = { hour: '2-digit', minute: '2-digit' };
    var out = start.toLocaleString(undefined, dateOpts);
    if (endedAt) {
      var end = new Date(endedAt);
      if (!isNaN(end.getTime())) {
        out += ' → ' + end.toLocaleTimeString(undefined, timeOpts);
      }
    }
    return out;
  }

  function renderBulkHistory(history) {
    var $ul = $('#lumen-wp-bulk-history');
    if (!$ul.length) return;

    var rows = history || [];
    $ul.empty();
    if (!rows.length) {
      $ul.append(
        $('<li class="lumen-wp-history__empty" id="lumen-wp-bulk-history-empty"/>').text(
          'Aucun traitement terminé pour le moment.'
        )
      );
      return;
    }

    rows.forEach(function (entry) {
      var ended = entry.ended === 'done' ? 'done' : 'stopped';
      var badge = ended === 'done' ? 'Terminé' : 'Arrêté';
      var opts = [];
      if (entry.types && entry.types.length) {
        var typeLabels = { image: 'Images', pdf: 'PDF', svg: 'SVG', video: 'Vidéos' };
        opts.push(
          entry.types
            .map(function (t) {
              return typeLabels[t] || t;
            })
            .join(', ')
        );
      }
      if (entry.force) opts.push('Déjà OK repris');
      if (entry.use_ai) {
        opts.push(entry.ai_label ? 'IA (' + entry.ai_label + ')' : 'IA');
      } else {
        opts.push('Sans IA');
      }

      var $li = $('<li class="lumen-wp-history__item"/>').addClass('is-' + ended);
      var $top = $('<div class="lumen-wp-history__top"/>');
      $top.append($('<span class="lumen-wp-history__badge"/>').text(badge));
      $top.append(
        $('<span class="lumen-wp-history__when"/>').text(
          formatHistoryWhen(entry.started_at, entry.ended_at)
        )
      );
      if (entry.user_name) {
        $top.append($('<span class="lumen-wp-history__user"/>').text(entry.user_name));
      }
      $li.append($top);
      $li.append(
        $('<p class="lumen-wp-history__stats"/>').text(
          (entry.processed || 0) +
            ' / ' +
            (entry.total_estimate || 0) +
            ' traités — ' +
            (entry.ok || 0) +
            ' OK · ' +
            (entry.err || 0) +
            ' erreur(s)'
        )
      );
      $li.append($('<p class="lumen-wp-history__opts"/>').text(opts.join(' · ')));

      var errors = entry.errors || [];
      if (errors.length) {
        var $err = $('<ul class="lumen-wp-error-list lumen-wp-error-list--compact"/>');
        errors.forEach(function (err) {
          if (typeof err === 'string') {
            appendErrorListItem($err, { title: err, message: '', edit_url: '' });
          } else {
            appendErrorListItem($err, err);
          }
        });
        $li.append($err);
      }
      $ul.append($li);
    });
  }

  function applyBulkJob(job, meta) {
    if (!job) return;
    var status = job.status || 'idle';
    var processed = parseInt(job.processed, 10) || 0;
    var total = parseInt(job.total_estimate, 10) || 0;
    var batch = parseInt(job.batch_size, 10) || 0;
    var pct =
      status === 'done'
        ? 100
        : total
          ? Math.min(99, Math.round((processed / total) * 100))
          : Math.min(95, processed > 0 ? Math.max(3, Math.round(Math.log10(processed + 1) * 20)) : 0);

    $('#lumen-wp-bulk-progress').prop('hidden', status === 'idle');
    $('#lumen-wp-progress-bar').val(pct);
    var progressLabel = total
      ? processed + ' / ~' + total
      : processed + ' traités';
    progressLabel += ' — OK ' + (job.ok || 0) + ' / err ' + (job.err || 0);
    if (batch) {
      progressLabel += ' · ×' + batch;
    }
    $('#lumen-wp-progress-label').text(progressLabel);
    $('#lumen-wp-bulk-status-text').text(job.last_message || status);
    renderBulkLog(job.log || []);
    renderBulkErrors(job.errors || []);

    $('#lumen-wp-bulk-start').prop('disabled', status === 'running' || status === 'paused');
    $('#lumen-wp-bulk-pause').prop('disabled', status !== 'running');
    $('#lumen-wp-bulk-resume').prop('disabled', status !== 'paused');
    $('#lumen-wp-bulk-stop').prop('disabled', status !== 'running' && status !== 'paused');

    if (meta && meta.ai && meta.ai.usage) {
      var budget = meta.ai.budget > 0 ? meta.ai.budget : '∞';
      $('#lumen-wp-bulk-ai-meta').text(
        'Usage IA ce mois : ' + (meta.ai.usage.calls_month || 0) + ' / ' + budget
      );
    }

    if (meta && meta.health && $('#lumen-wp-bulk-health-text').length) {
      var h = meta.health;
      var healthMsg = lumenWp.i18n.cronOk || 'Tout va bien.';
      if (h.cron_disabled) {
        healthMsg = lumenWp.i18n.cronDisabled || 'Traitement automatique désactivé.';
      } else if (h.stale) {
        healthMsg = lumenWp.i18n.cronStale || 'Traitement bloqué — avancez maintenant.';
      }
      $('#lumen-wp-bulk-health-text').text(healthMsg);
    }

    if (meta && meta.history) {
      renderBulkHistory(meta.history);
    }

    // Modale uniquement sur une vraie transition vers « done » pendant la session.
    if (bulkStatusReady && status === 'done' && bulkLastStatus !== 'done') {
      var msg =
        (lumenWp.i18n.bulkDone || 'Terminé.') +
        ' ' +
        (job.ok || 0) +
        ' OK / ' +
        (job.err || 0) +
        ' erreur(s).';
      if ((job.err || 0) > 0 && (job.ok || 0) === 0) {
        window.lumenWpModal.error(msg);
      } else if ((job.err || 0) > 0) {
        window.lumenWpModal.show({ type: 'error', title: lumenWp.i18n.done, message: msg });
      } else {
        window.lumenWpModal.success(msg);
      }
      if (meta && meta.history) {
        renderBulkHistory(meta.history);
      } else {
        // Relit le status pour récupérer l’historique archivé côté serveur.
        ajax('lumen_wp_bulk_status').done(function (res) {
          if (res && res.success && res.data && res.data.history) {
            renderBulkHistory(res.data.history);
          }
        });
      }
    }
    bulkLastStatus = status;
    bulkStatusReady = true;

    if (status === 'running') {
      startBulkPoll();
    } else {
      stopBulkPoll();
    }
  }

  function pollBulkStatus() {
    if (!$('#lumen-wp-bulk-start').length) return;
    ajax('lumen_wp_bulk_status')
      .done(function (res) {
        if (res && res.success && res.data) {
          applyBulkJob(res.data.job, res.data);
        }
      });
  }

  function startBulkPoll() {
    if (bulkPollTimer) return;
    bulkPollTimer = setInterval(pollBulkStatus, 2000);
  }

  function stopBulkPoll() {
    if (bulkPollTimer) {
      clearInterval(bulkPollTimer);
      bulkPollTimer = null;
    }
  }

  $(function () {
    if ($('#lumen-wp-bulk-start').length) {
      pollBulkStatus();
      refreshBulkEstimate();
    }
  });

  $(document).on('click', '#lumen-wp-use-ai-label[data-ai-locked="1"]', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var i18n = lumenWp.i18n || {};
    window.lumenWpModal.info(i18n.aiNeededMessage || '', i18n.aiNeededTitle || '', {
      actionUrl: lumenWp.settingsUrl || '',
      actionLabel: i18n.openSettings || ''
    });
  });

  function bulkEstimatePayload() {
    var types = $('.lumen-wp-bulk-type:checked')
      .map(function () {
        return $(this).val();
      })
      .get();
    return {
      force: $('#lumen-wp-force').is(':checked') ? 1 : 0,
      use_ai: $('#lumen-wp-use-ai').is(':checked') ? 1 : 0,
      types: types,
    };
  }

  var bulkEstimateTimer = null;
  function refreshBulkEstimate() {
    if (!$('#lumen-wp-bulk-estimate').length) return;
    clearTimeout(bulkEstimateTimer);
    bulkEstimateTimer = setTimeout(function () {
      var payload = bulkEstimatePayload();
      if (!payload.types.length) {
        $('#lumen-wp-bulk-estimate')
          .prop('hidden', false)
          .removeClass('lumen-wp-urls-error')
          .text('Sélectionnez au moins un type.');
        return;
      }
      ajax('lumen_wp_bulk_estimate', payload)
        .done(function (res) {
          if (!res || !res.success || !res.data) return;
          $('#lumen-wp-bulk-estimate')
            .prop('hidden', false)
            .toggleClass('lumen-wp-urls-error', res.data.within_budget === false)
            .text(res.data.message || '');
        })
        .fail(function () {
          $('#lumen-wp-bulk-estimate').prop('hidden', true).text('');
        });
    }, 250);
  }

  $(document).on(
    'change',
    '#lumen-wp-force, #lumen-wp-use-ai, .lumen-wp-bulk-type',
    refreshBulkEstimate
  );

  $(document).on('click', '#lumen-wp-bulk-start', function (e) {
    e.preventDefault();
    var types = $('.lumen-wp-bulk-type:checked')
      .map(function () {
        return $(this).val();
      })
      .get();
    if (!types.length) {
      window.alert('Sélectionnez au moins un type de média.');
      return;
    }
    ajax('lumen_wp_bulk_start', {
      force: $('#lumen-wp-force').is(':checked') ? 1 : 0,
      use_ai: $('#lumen-wp-use-ai').is(':checked') ? 1 : 0,
      types: types
    })
      .done(function (res) {
        if (!res || !res.success) {
          window.lumenWpModal.error(
            (res && res.data && res.data.message) || lumenWp.i18n.error
          );
          return;
        }
        applyBulkJob(res.data.job, res.data);
        startBulkPoll();
      })
      .fail(function (xhr) {
        window.lumenWpModal.error(
          (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ||
            lumenWp.i18n.error
        );
      });
  });

  $(document).on('click', '#lumen-wp-bulk-force-tick', function (e) {
    e.preventDefault();
    var btn = $(this).prop('disabled', true);
    ajax('lumen_wp_bulk_force_tick')
      .done(function (res) {
        if (!res || !res.success) {
          window.lumenWpModal.error(
            (res && res.data && res.data.message) || lumenWp.i18n.error
          );
          return;
        }
        applyBulkJob(res.data.job, res.data);
        window.lumenWpModal.success(lumenWp.i18n.tickForced || 'Un média a été traité.');
      })
      .fail(function (xhr) {
        window.lumenWpModal.error(
          (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ||
            lumenWp.i18n.error
        );
      })
      .always(function () {
        btn.prop('disabled', false);
      });
  });

  $(document).on('click', '#lumen-wp-bulk-pause', function (e) {
    e.preventDefault();
    ajax('lumen_wp_bulk_pause').done(function (res) {
      if (res && res.success) applyBulkJob(res.data.job);
    });
  });

  $(document).on('click', '#lumen-wp-bulk-resume', function (e) {
    e.preventDefault();
    ajax('lumen_wp_bulk_resume').done(function (res) {
      if (res && res.success) {
        applyBulkJob(res.data.job);
        startBulkPoll();
      }
    });
  });

  $(document).on('click', '#lumen-wp-bulk-stop', function (e) {
    e.preventDefault();
    ajax('lumen_wp_bulk_stop').done(function (res) {
      if (res && res.success) applyBulkJob(res.data.job, res.data);
      stopBulkPoll();
    });
  });

  function getVisibleApiKey(provider) {
    var $input = $('.lumen-wp-api-key[data-provider="' + provider + '"] .lumen-wp-api-key__input');
    return $input.length ? String($input.val() || '').trim() : '';
  }

  function hasStoredApiKey(provider) {
    var $wrap = $('.lumen-wp-api-key[data-provider="' + provider + '"]');
    return $wrap.attr('data-has-key') === '1';
  }

  function fillAiModelSelect(models, keepValue) {
    var $model = $('#lumen-wp-ai-model');
    if (!$model.length) return;
    var current = typeof keepValue === 'string' ? keepValue : $model.val() || '';
    var list = models || { '': 'Choisir d’abord un fournisseur' };
    $model.empty();
    Object.keys(list).forEach(function (value) {
      var opt = $('<option/>').attr('value', value).text(list[value]);
      if (value === current) opt.prop('selected', true);
      $model.append(opt);
    });
    if (current && !Object.prototype.hasOwnProperty.call(list, current)) {
      $model.val('');
    }
    enhanceCustomSelect($model[0]);
    syncCustomSelect($model);
  }

  function setAiModelsMeta(text) {
    var $meta = $('#lumen-wp-ai-models-meta');
    if ($meta.length) $meta.text(text);
  }

  function refreshAiProviderUi(opts) {
    opts = opts || {};
    var $provider = $('#lumen-wp-ai-provider');
    var $model = $('#lumen-wp-ai-model');
    if (!$provider.length) return;

    var provider = $provider.val() || 'none';
    var hideAiFields = provider === 'none';

    $('#lumen-wp-api-key-row').prop('hidden', hideAiFields);
    $('#lumen-wp-ai-model-row').prop('hidden', hideAiFields);
    $('#lumen-wp-ai-budget-row').prop('hidden', hideAiFields);
    $('#lumen-wp-ai-validation-row').prop('hidden', hideAiFields);
    $('.lumen-wp-api-key').each(function () {
      var match = $(this).data('provider') === provider;
      $(this).prop('hidden', !match);
      if (!match) {
        var $input = $(this).find('.lumen-wp-api-key__input');
        var $btn = $(this).find('.lumen-wp-api-key__toggle');
        $input.attr('type', 'password');
        $btn.attr('aria-pressed', 'false').text($btn.data('label-show') || 'Afficher');
      }
    });

    if (!$model.length) return;

    var catalog = {};
    try {
      catalog = JSON.parse($model.attr('data-catalog') || '{}') || {};
    } catch (e) {
      catalog = {};
    }

    var models = catalog[provider] || { '': 'Choisir d’abord un fournisseur' };
    fillAiModelSelect(models);
    $model.prop('disabled', provider === 'none');
    syncCustomSelect($model);
    syncCustomSelect($provider);

    if (hideAiFields || opts.skipFetch) return;

    var apiKey = getVisibleApiKey(provider);
    if (!apiKey && !hasStoredApiKey(provider)) {
      setAiModelsMeta(
        'Catalogue Lumen uniquement — renseignez la clé API puis actualisez pour charger les modèles Vision du fournisseur.'
      );
      return;
    }

    ajax('lumen_wp_ai_models_refresh', {
      provider: provider,
      api_key: apiKey,
      force: opts.force ? 1 : 0
    }).done(function (res) {
      if (!res || !res.success || !res.data) return;
      fillAiModelSelect(res.data.models || models);
      var msg = res.data.message || '';
      if (res.data.fetched_at) {
        var d = new Date(res.data.fetched_at);
        if (!isNaN(d.getTime())) {
          msg +=
            (msg ? ' — ' : '') +
            'Dernière synchro API : ' +
            d.toLocaleString();
        }
      }
      if (msg) setAiModelsMeta(msg);
      if (!res.data.ok && !opts.silentFail) {
        // Catalogue local déjà affiché ; on signale sans bloquer.
      }
    });
  }

  $(document).on('change', '#lumen-wp-ai-provider', function () {
    refreshAiProviderUi({ skipFetch: false, force: false });
  });

  $(document).on('click', '#lumen-wp-ai-models-refresh', function (e) {
    e.preventDefault();
    var provider = $('#lumen-wp-ai-provider').val() || 'none';
    if (provider === 'none') return;

    var $btn = $(this).prop('disabled', true);
    var label = $btn.text();
    var apiKey = getVisibleApiKey(provider);
    $btn.text(lumenWp.i18n.processing || 'Traitement…');

    if (!apiKey && !hasStoredApiKey(provider)) {
      window.lumenWpModal.error('Renseignez d’abord la clé API du fournisseur.');
      $btn.prop('disabled', false).text(label);
      return;
    }

    ajax('lumen_wp_ai_models_refresh', {
      provider: provider,
      api_key: apiKey,
      force: 1
    })
      .done(function (res) {
        if (res && res.success && res.data) {
          fillAiModelSelect(res.data.models || {});
          var msg = res.data.message || '';
          if (res.data.fetched_at) {
            var d = new Date(res.data.fetched_at);
            if (!isNaN(d.getTime())) {
              msg += (msg ? ' — ' : '') + 'Dernière synchro API : ' + d.toLocaleString();
            }
          }
          if (msg) setAiModelsMeta(msg);
          if (res.data.ok) {
            window.lumenWpModal.success(msg || 'Modèles actualisés.');
          } else {
            window.lumenWpModal.error(msg || lumenWp.i18n.error);
          }
        } else {
          window.lumenWpModal.error(
            (res && res.data && res.data.message) || lumenWp.i18n.error
          );
        }
      })
      .fail(function (xhr) {
        window.lumenWpModal.error(
          (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ||
            lumenWp.i18n.error
        );
      })
      .always(function () {
        $btn.prop('disabled', false).text(label);
      });
  });

  function applyUiThemePreview(theme) {
    var next = theme === 'light' ? 'light' : 'dark';
    var $body = $(document.body);
    $body.removeClass('lumen-wp-theme-dark lumen-wp-theme-light').addClass('lumen-wp-theme-' + next);
    $('.lumen-wp-metabox')
      .removeClass('lumen-wp-theme-dark lumen-wp-theme-light')
      .addClass('lumen-wp-theme-' + next);
    var bg = next === 'light' ? '#f5f5f4' : '#0c0a09';
    var $crit = $('#lumen-wp-critical');
    if ($crit.length) {
      $crit.text(
        $crit
          .text()
          .replace(/background:\s*#[0-9a-fA-F]{3,8}\s*!important/g, 'background: ' + bg + ' !important')
      );
    }
  }

  $(document).on('change', '#lumen-wp-ui-theme', function () {
    applyUiThemePreview($(this).val());
  });

  $(function () {
    enhanceAllCustomSelects('.lumen-wp-wrap');
    enhanceAllNumberSteppers('.lumen-wp-wrap');
    refreshAiProviderUi({ skipFetch: false, force: false });
  });

  $(document).on('click', '.lumen-wp-api-key__toggle', function (e) {
    e.preventDefault();
    var $btn = $(this);
    var $input = $('#' + $btn.attr('aria-controls'));
    if (!$input.length) return;
    var show = $input.attr('type') === 'password';
    $input.attr('type', show ? 'text' : 'password');
    $btn
      .attr('aria-pressed', show ? 'true' : 'false')
      .text(show ? $btn.data('label-hide') || 'Masquer' : $btn.data('label-show') || 'Afficher');
  });

  $(document).on('click', '#lumen-wp-ai-usage-reset', function (e) {
    e.preventDefault();
    var btn = $(this).prop('disabled', true);
    ajax('lumen_wp_ai_usage_reset')
      .done(function (res) {
        if (res && res.success) {
          window.lumenWpModal.success('Compteur IA réinitialisé.');
          window.location.reload();
        } else {
          window.lumenWpModal.error(lumenWp.i18n.error);
        }
      })
      .fail(function () {
        window.lumenWpModal.error(lumenWp.i18n.error);
      })
      .always(function () {
        btn.prop('disabled', false);
      });
  });

  // —— Kit d'icônes ——
  var iconsFile = null;

  function iconsStatus(text, isError) {
    var el = $('#lumen-wp-icons-status');
    if (!text) {
      el.prop('hidden', true).text('');
      return;
    }
    el.prop('hidden', false).text(text).toggleClass('is-error', !!isError);
  }

  function formatBytes(bytes) {
    if (!bytes) return '0 B';
    var i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + ['B', 'KB', 'MB', 'GB'][i];
  }

  function renderIconsResult(data) {
    var grid = $('#lumen-wp-icons-grid').empty();
    (data.kit || []).forEach(function (item) {
      var card = $(
        '<article class="lumen-wp-icon-card">' +
          '<div class="lumen-wp-icon-card__preview"><img src="" alt="" width="64" height="64" /></div>' +
          '<div class="lumen-wp-icon-card__meta"><strong></strong><span></span></div>' +
          '<a class="button" href="#" download></a>' +
          '</article>'
      );
      card.find('img').attr({ src: item.url, alt: item.size + 'px' });
      card.find('strong').text(item.size + '×' + item.size + ' px');
      card.find('span').text(formatBytes(item.bytes));
      card.find('a').attr({ href: item.url, download: 'icon-' + item.size + '.png' }).text('PNG');
      grid.append(card);
    });

    var siteBox = $('#lumen-wp-icons-site').empty();
    if (data.site && Object.keys(data.site).length) {
      siteBox.append('<h3 class="lumen-wp-panel__title">Favicons site</h3>');
      var ul = $('<ul class="lumen-wp-icons-site-list"/>');
      Object.keys(data.site).forEach(function (key) {
        var item = data.site[key];
        ul.append(
          $('<li/>').html(
            '<code>' +
              (item.filename || key) +
              '</code> — <a href="' +
              item.url +
              '" target="_blank" rel="noopener noreferrer">ouvrir</a>'
          )
        );
      });
      siteBox.append(ul);
    }

    if (data.zip && data.zip.url) {
      $('#lumen-wp-icons-zip').attr('href', data.zip.url).prop('hidden', false);
    }

    $('#lumen-wp-icons-results').prop('hidden', false);
  }

  function openIconsFilePicker() {
    var input = document.getElementById('lumen-wp-icons-file');
    if (input) {
      input.click();
    }
  }

  $(document).on('click', '#lumen-wp-icons-drop', function (e) {
    if ($(e.target).closest('label[for="lumen-wp-icons-file"]').length) {
      return;
    }
    e.preventDefault();
    openIconsFilePicker();
  });

  $(document).on('keydown', '#lumen-wp-icons-drop', function (e) {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      openIconsFilePicker();
    }
  });

  $(document).on('dragover', '#lumen-wp-icons-drop', function (e) {
    e.preventDefault();
    e.stopPropagation();
    $(this).addClass('is-dragover');
  });

  $(document).on('dragleave drop', '#lumen-wp-icons-drop', function (e) {
    e.preventDefault();
    e.stopPropagation();
    $(this).removeClass('is-dragover');
  });

  $(document).on('drop', '#lumen-wp-icons-drop', function (e) {
    var files = e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files;
    if (files && files[0]) {
      iconsFile = files[0];
      $('#lumen-wp-icons-generate').prop('disabled', false);
      iconsStatus(iconsFile.name);
    }
  });

  $(document).on('change', '#lumen-wp-icons-file', function () {
    var f = this.files && this.files[0];
    if (!f) return;
    iconsFile = f;
    $('#lumen-wp-icons-generate').prop('disabled', false);
    iconsStatus(f.name);
  });

  $(document).on('click', '#lumen-wp-icons-reset', function (e) {
    e.preventDefault();
    iconsFile = null;
    $('#lumen-wp-icons-file').val('');
    $('#lumen-wp-icons-generate').prop('disabled', true);
    iconsStatus('');
  });

  $(document).on('click', '#lumen-wp-icons-generate', function (e) {
    e.preventDefault();
    if (!iconsFile) {
      iconsStatus('Choisissez une image.', true);
      window.lumenWpModal.error('Choisissez une image.');
      return;
    }

    var btn = $(this).prop('disabled', true).text(lumenWp.i18n.processing);
    var fd = new FormData();
    fd.append('action', 'lumen_wp_icons_generate');
    fd.append('nonce', lumenWp.nonce);
    fd.append('file', iconsFile);
    fd.append('apply_site', $('#lumen-wp-icons-apply-site').is(':checked') ? '1' : '0');

    $.ajax({
      url: lumenWp.ajaxUrl,
      method: 'POST',
      data: fd,
      processData: false,
      contentType: false
    })
      .done(function (res) {
        if (!res || !res.success) {
          var err = (res && res.data && res.data.message) || lumenWp.i18n.error;
          iconsStatus(err, true);
          window.lumenWpModal.error(err);
          return;
        }
        renderIconsResult(res.data);
        var okMsg = res.data.applied ? lumenWp.i18n.iconsDoneSite : lumenWp.i18n.iconsDone;
        iconsStatus(okMsg);
        window.lumenWpModal.success(okMsg);
      })
      .fail(function (xhr) {
        var msg =
          (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ||
          lumenWp.i18n.error;
        iconsStatus(msg, true);
        window.lumenWpModal.error(msg);
      })
      .always(function () {
        btn.prop('disabled', false).text('Générer le kit');
      });
  });

  $(document).on('change', '#lumen-wp-icons-apply-site', function () {
    if ($('#lumen-wp-icons-results').prop('hidden')) return;
    var enabled = $(this).is(':checked');
    ajax('lumen_wp_icons_toggle_site', {
      enable: enabled ? 1 : 0
    })
      .done(function (res) {
        if (!res || !res.success) {
          window.lumenWpModal.error(
            (res && res.data && res.data.message) || lumenWp.i18n.error
          );
          return;
        }
        window.lumenWpModal.success(
          enabled ? 'Favicons site activés.' : 'Favicons site désactivés.'
        );
      })
      .fail(function () {
        window.lumenWpModal.error(lumenWp.i18n.error);
      });
  });

  // Outils — santé cron + cleanup
  function formatBytes(n) {
    n = parseInt(n, 10) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
    return (n / (1024 * 1024)).toFixed(2) + ' MB';
  }

  function renderCleanupPreview(p) {
    var $root = $('#lumen-wp-cleanup-preview');
    if (!p || !$root.length) return;

    if ($root.find('[data-preview]').length) {
      $root.find('[data-preview="attachments"]').text(p.attachments || 0);
      $root.find('[data-preview="sidecars"]').text(p.sidecars || 0);
      $root.find('[data-preview="sidecar_bytes"]').text(formatBytes(p.sidecar_bytes));
      $root.find('[data-preview="backups"]').text(p.backups || 0);
      $root.find('[data-preview="backup_bytes"]').text(formatBytes(p.backup_bytes));
      return;
    }

    var li = $root.data('label-images') || 'Médias';
    var lv = $root.data('label-variants') || 'Variantes';
    var lb = $root.data('label-backups') || 'Sauvegardes';
    $root.html(
      '<article class="lumen-wp-stat">' +
        '<span class="lumen-wp-stat__label">' +
        li +
        '</span>' +
        '<strong class="lumen-wp-stat__value" data-preview="attachments">' +
        (p.attachments || 0) +
        '</strong>' +
        '</article>' +
        '<article class="lumen-wp-stat">' +
        '<span class="lumen-wp-stat__label">' +
        lv +
        '</span>' +
        '<strong class="lumen-wp-stat__value" data-preview="sidecars">' +
        (p.sidecars || 0) +
        '</strong>' +
        '<span class="lumen-wp-stat__hint" data-preview="sidecar_bytes">' +
        formatBytes(p.sidecar_bytes) +
        '</span>' +
        '</article>' +
        '<article class="lumen-wp-stat">' +
        '<span class="lumen-wp-stat__label">' +
        lb +
        '</span>' +
        '<strong class="lumen-wp-stat__value" data-preview="backups">' +
        (p.backups || 0) +
        '</strong>' +
        '<span class="lumen-wp-stat__hint" data-preview="backup_bytes">' +
        formatBytes(p.backup_bytes) +
        '</span>' +
        '</article>'
    );
  }

  function jobStatusLabel(status) {
    var map = {
      idle: (lumenWp.i18n && lumenWp.i18n.statusIdle) || 'Inactif',
      running: (lumenWp.i18n && lumenWp.i18n.statusRunning) || 'En cours',
      paused: (lumenWp.i18n && lumenWp.i18n.statusPaused) || 'En pause',
      done: (lumenWp.i18n && lumenWp.i18n.statusDone) || 'Terminé'
    };
    return map[status] || status || 'Inactif';
  }

  function formatHealthTs(isoOrUnix) {
    if (!isoOrUnix) return '—';
    var d =
      typeof isoOrUnix === 'number'
        ? new Date(isoOrUnix * 1000)
        : new Date(isoOrUnix);
    if (isNaN(d.getTime())) return '—';
    return d.toLocaleString();
  }

  function applyToolsHealth(h) {
    if (!h || !$('#lumen-wp-tools-health').length) return;
    $('[data-health="job_status"]').text(jobStatusLabel(h.job_status));
    $('[data-health="cron"]').text(h.cron_disabled ? 'désactivé' : 'actif');
    $('[data-health="next"]').text(formatHealthTs(h.next_scheduled));
    $('[data-health="last"]').text(formatHealthTs(h.last_tick_at));
    $('[data-health="locked"]').text(h.locked ? 'oui' : 'non');
    var state = 'OK';
    var cls = 'is-ok';
    if (h.cron_disabled) {
      state = 'Relance manuelle conseillée';
      cls = 'is-warn';
    } else if (h.stale) {
      state = 'Peut-être bloqué';
      cls = 'is-warn';
    }
    $('[data-health="state"]').text(state).removeClass('is-ok is-warn').addClass(cls);
  }

  $(document).on('click', '#lumen-wp-cron-refresh', function (e) {
    e.preventDefault();
    ajax('lumen_wp_cron_health').done(function (res) {
      if (res && res.success) applyToolsHealth(res.data.health);
    });
  });

  $(document).on('click', '#lumen-wp-cron-force-tick', function (e) {
    e.preventDefault();
    var btn = $(this).prop('disabled', true);
    ajax('lumen_wp_bulk_force_tick')
      .done(function (res) {
        if (!res || !res.success) {
          window.lumenWpModal.error(
            (res && res.data && res.data.message) || lumenWp.i18n.error
          );
          return;
        }
        applyToolsHealth(res.data.health);
        window.lumenWpModal.success(lumenWp.i18n.tickForced || 'Un média a été traité.');
      })
      .fail(function (xhr) {
        window.lumenWpModal.error(
          (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ||
            lumenWp.i18n.error
        );
      })
      .always(function () {
        btn.prop('disabled', false);
      });
  });

  $(document).on('click', '#lumen-wp-cleanup-refresh', function (e) {
    e.preventDefault();
    ajax('lumen_wp_cleanup_preview').done(function (res) {
      if (res && res.success) renderCleanupPreview(res.data.preview);
    });
  });

  $(document).on('click', '#lumen-wp-cleanup-run', function (e) {
    e.preventDefault();
    if (!window.confirm(lumenWp.i18n.cleanupConfirm || 'Lancer le nettoyage ?')) {
      return;
    }
    var btn = $(this).prop('disabled', true).text(lumenWp.i18n.processing);
    ajax('lumen_wp_cleanup_run', {
      sidecars: $('#lumen-wp-cleanup-sidecars').is(':checked') ? 1 : 0,
      backups: $('#lumen-wp-cleanup-backups').is(':checked') ? 1 : 0,
      clear_status: $('#lumen-wp-cleanup-status').is(':checked') ? 1 : 0
    })
      .done(function (res) {
        if (!res || !res.success) {
          window.lumenWpModal.error(
            (res && res.data && res.data.message) || lumenWp.i18n.error
          );
          return;
        }
        renderCleanupPreview(res.data.preview);
        $('#lumen-wp-cleanup-result').prop('hidden', false).text(res.data.message || '');
        window.lumenWpModal.success(res.data.message || lumenWp.i18n.done);
      })
      .fail(function (xhr) {
        window.lumenWpModal.error(
          (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ||
            lumenWp.i18n.error
        );
      })
      .always(function () {
        btn.prop('disabled', false).text('Lancer le nettoyage');
      });
  });

  $(document).on('click', '#lumen-wp-jobs-purge', function (e) {
    e.preventDefault();
    var btn = $(this);
    var msg =
      (lumenWp.i18n && lumenWp.i18n.jobsPurgeConfirm) ||
      'Supprimer l’historique tokens/jobs Lumen (table + cache médias) ? Cette action est irréversible. Les fichiers ne sont pas touchés.';

    var run = function () {
      btn.prop('disabled', true);
      ajax('lumen_wp_jobs_purge')
        .done(function (res) {
          if (!res || !res.success) {
            window.lumenWpModal.error(
              (res && res.data && res.data.message) || lumenWp.i18n.error
            );
            return;
          }
          var jobs = (res.data && res.data.jobs) || 0;
          var metas = (res.data && res.data.metas) || 0;
          window.lumenWpModal.success(
            'Journal vidé — ' + jobs + ' job(s), ' + metas + ' cache(s) médias.'
          );
        })
        .fail(function (xhr) {
          window.lumenWpModal.error(ajaxErrorMessage(xhr, lumenWp.i18n.error));
        })
        .always(function () {
          btn.prop('disabled', false);
        });
    };

    if (
      window.lumenWpModal &&
      typeof window.lumenWpModal.confirm === 'function' &&
      document.getElementById('lumen-wp-modal')
    ) {
      window.lumenWpModal.confirm({
        message: msg,
        onConfirm: run
      });
      return;
    }
    if (!window.confirm(msg)) {
      return;
    }
    run();
  });

  /* —— URLs cassées (file prod-hardened) —— */
  var urlsPollTimer = null;
  var urlsLastStatus = '';
  var urlsStatusReady = false;
  var urlsPollInFlight = false;
  var URLS_AJAX_TIMEOUT = 25000;
  var URLS_POLL_MS = 1800;

  function renderUrlsIssues(issues) {
    var $box = $('#lumen-wp-urls-results');
    if (!$box.length) return;
    issues = issues || [];
    if (!issues.length) {
      $box
        .prop('hidden', false)
        .html(
          '<p class="description">Aucune ancienne URL détectée dans le contenu / Elementor / options.</p>'
        );
      return;
    }
    var html =
      '<table class="widefat striped lumen-wp-urls-table"><thead><tr>' +
      '<th>Média</th><th>Ancienne URL</th><th>Nouvelle URL</th><th>Réfs</th><th></th>' +
      '</tr></thead><tbody>';
    issues.forEach(function (row) {
      var refs = row.refs || {};
      var refTxt =
        (refs.posts || 0) + 'p / ' + (refs.metas || 0) + 'm / ' + (refs.options || 0) + 'o';
      var warn =
        row.old_missing && row.new_exists
          ? ' <span class="lumen-wp-urls-pill">404→webp</span>'
          : '';
      html +=
        '<tr>' +
        '<td><strong>' +
        $('<div/>').text(row.title || '#' + row.id).html() +
        '</strong> <code>#' +
        row.id +
        '</code>' +
        warn +
        '</td>' +
        '<td class="lumen-wp-urls-url"><code>' +
        $('<div/>').text(row.old_url || '').html() +
        '</code></td>' +
        '<td class="lumen-wp-urls-url"><code>' +
        $('<div/>').text(row.new_url || '').html() +
        '</code></td>' +
        '<td>' +
        refTxt +
        '</td>' +
        '<td>' +
        (row.edit_url
          ? '<a class="button button-small" href="' +
            row.edit_url +
            '" target="_blank" rel="noopener">Fiche</a>'
          : '') +
        '</td>' +
        '</tr>';
    });
    html += '</tbody></table>';
    $box.prop('hidden', false).html(html);
  }

  function renderUrlsLog(log) {
    var $ul = $('#lumen-wp-urls-log');
    if (!$ul.length) return;
    log = log || [];
    if (!log.length) {
      $ul.prop('hidden', true).empty();
      return;
    }
    $ul.prop('hidden', false).empty();
    log.slice(0, 16).forEach(function (line) {
      $ul.append($('<li/>').text(line));
    });
  }

  function renderUrlsErrors(errors) {
    var $ul = $('#lumen-wp-urls-errors');
    if (!$ul.length) return;
    errors = errors || [];
    if (!errors.length) {
      $ul.prop('hidden', true).empty();
      return;
    }
    $ul.prop('hidden', false).empty();
    errors.slice(0, 12).forEach(function (err) {
      var msg =
        (err && err.id ? '#' + err.id + ' — ' : '') +
        ((err && err.message) || String(err || ''));
      $ul.append($('<li/>').text(msg));
    });
  }

  function urlsLastErrorIds(lastErrors) {
    var ids = [];
    var seen = {};
    (lastErrors || []).forEach(function (err) {
      var id = err && err.id ? parseInt(err.id, 10) : 0;
      if (!id || seen[id]) return;
      seen[id] = true;
      ids.push(id);
    });
    return ids;
  }

  function applyUrlsJob(job, health, lastErrors) {
    if (!job || !$('#lumen-wp-urls-diagnose').length) return;
    var status = job.status || 'idle';
    var processed = parseInt(job.processed, 10) || 0;
    var total = parseInt(job.total_estimate, 10) || 0;
    var pct =
      status === 'done'
        ? 100
        : total
          ? Math.min(99, Math.round((processed / total) * 100))
          : Math.min(95, processed * 3);
    var running = status === 'running';
    var mode = job.mode || 'diagnose';
    var isRewrite = mode === 'rewrite' || mode === 'retry';
    var errIds = urlsLastErrorIds(lastErrors);

    $('#lumen-wp-urls-progress').prop('hidden', status === 'idle');
    $('#lumen-wp-urls-progress-bar').val(pct);
    var label =
      (total ? processed + ' / ~' + total : processed + ' traités') +
      (isRewrite
        ? ' — rempl. ' +
          (job.replacements || 0) +
          ' / CSS ' +
          (job.css_files || 0)
        : ' — obsolètes ' + (job.issues_found || 0));
    if (job.force_full) {
      label += ' / complet';
    }
    if (mode === 'retry') {
      label += ' / retry';
    }
    if (job.batch_size) {
      label += ' · ×' + job.batch_size;
    }
    if (job.err) {
      label += ' / err ' + job.err;
    }
    $('#lumen-wp-urls-progress-label').text(label);
    $('#lumen-wp-urls-status-text').text(job.last_message || status);
    $('#lumen-wp-urls-job-state').text(status + (health && health.stale ? ' (stale)' : ''));

    if (job.last_error) {
      $('#lumen-wp-urls-error-text').prop('hidden', false).text(job.last_error);
    } else {
      $('#lumen-wp-urls-error-text').prop('hidden', true).text('');
    }

    renderUrlsLog(job.log || []);
    renderUrlsErrors(job.errors || []);

    $('#lumen-wp-urls-diagnose').prop('disabled', running);
    $('#lumen-wp-urls-rewrite').prop('disabled', running);
    $('#lumen-wp-urls-retry')
      .prop('disabled', running || !errIds.length)
      .text('Réessayer les erreurs (' + errIds.length + ')');
    $('#lumen-wp-urls-force-full').prop('disabled', running);
    $('#lumen-wp-urls-force-tick').prop('disabled', !running);
    $('#lumen-wp-urls-stop').prop('disabled', !running);

    if (status === 'done') {
      if (mode === 'diagnose') {
        renderUrlsIssues(job.issues || []);
      } else {
        var summary =
          (job.last_message || 'Réécriture terminée.') +
          ' Posts ' +
          (job.posts || 0) +
          ' / Elementor ' +
          (job.metas || 0) +
          ' / options ' +
          (job.options || 0) +
          ' / CSS ' +
          (job.css_files || 0) +
          '.';
        $('#lumen-wp-urls-results')
          .prop('hidden', false)
          .html('<p class="description">' + $('<div/>').text(summary).html() + '</p>');
      }
    }

    if (urlsStatusReady && status === 'done' && urlsLastStatus !== 'done') {
      if ((job.err || 0) > 0) {
        showModalError(job.last_message || lumenWp.i18n.error);
      } else {
        showModalSuccess(job.last_message || lumenWp.i18n.done);
      }
    }
    urlsLastStatus = status;
    urlsStatusReady = true;

    if (running) {
      startUrlsPoll();
    } else {
      stopUrlsPoll();
    }
  }

  function pollUrlsStatus() {
    if (!$('#lumen-wp-urls-diagnose').length || urlsPollInFlight) return;
    urlsPollInFlight = true;
    ajax('lumen_wp_urls_status', {}, { timeout: URLS_AJAX_TIMEOUT })
      .done(function (res) {
        if (res && res.success && res.data) {
          applyUrlsJob(res.data.job, res.data.health, res.data.last_errors);
        } else if (res && res.data && res.data.message) {
          $('#lumen-wp-urls-error-text').prop('hidden', false).text(res.data.message);
        }
      })
      .fail(function (xhr) {
        var msg = ajaxErrorMessage(xhr, 'Scan interrompu');
        $('#lumen-wp-urls-status-text').text(msg);
        $('#lumen-wp-urls-error-text').prop('hidden', false).text(msg);
        // Keep polling — transient host glitches are common.
      })
      .always(function () {
        urlsPollInFlight = false;
      });
  }

  function startUrlsPoll() {
    if (urlsPollTimer) return;
    urlsPollTimer = setInterval(pollUrlsStatus, URLS_POLL_MS);
  }

  function stopUrlsPoll() {
    if (urlsPollTimer) {
      clearInterval(urlsPollTimer);
      urlsPollTimer = null;
    }
  }

  function startUrlsJob(mode, $btn, forceRestart) {
    var label = $btn && $btn.length ? $btn.text() : '';
    var forceFull =
      mode !== 'retry' && $('#lumen-wp-urls-force-full').is(':checked') ? 1 : 0;
    if ($btn && $btn.length) {
      $btn.prop('disabled', true).text('Démarrage…');
    }
    $('#lumen-wp-urls-progress').prop('hidden', false);
    $('#lumen-wp-urls-status-text').text('Démarrage…');
    $('#lumen-wp-urls-error-text').prop('hidden', true).text('');
    $('#lumen-wp-urls-results').prop('hidden', true).empty();

    ajax(
      'lumen_wp_urls_start',
      {
        mode: mode,
        force_restart: forceRestart ? 1 : 0,
        force_full: forceFull,
      },
      { timeout: URLS_AJAX_TIMEOUT }
    )
      .done(function (res) {
        if (!res || !res.success) {
          var msg = (res && res.data && res.data.message) || lumenWp.i18n.error;
          // Busy job: offer force restart once.
          if (res && res.data && res.data.job && res.data.job.status === 'running' && !forceRestart) {
            if (window.confirm(msg + '\n\nForcer un redémarrage ?')) {
              startUrlsJob(mode, $btn, true);
              return;
            }
          }
          showModalError(msg);
          if ($btn && $btn.length) $btn.prop('disabled', false).text(label);
          if (res && res.data && res.data.job) {
            applyUrlsJob(res.data.job, res.data.health, res.data.last_errors);
          }
          return;
        }
        applyUrlsJob(res.data.job, res.data.health, res.data.last_errors);
        startUrlsPoll();
      })
      .fail(function (xhr) {
        var $form = $('.lumen-wp-urls-form[data-urls-mode="' + mode + '"]');
        if ($form.length && $form[0]) {
          if (forceRestart) {
            $form.find('.lumen-wp-urls-force-restart').val('1');
          }
          $form.find('.lumen-wp-urls-force-full-field').val(forceFull ? '1' : '0');
          $('#lumen-wp-urls-status-text').text('Bascule en mode formulaire…');
          $form[0].submit();
          return;
        }
        showModalError(ajaxErrorMessage(xhr, 'Démarrage impossible'));
        if ($btn && $btn.length) $btn.prop('disabled', false).text(label);
      });
  }

  $(function () {
    if ($('#lumen-wp-urls-diagnose').length) {
      pollUrlsStatus();
    }
  });

  $(document).on('submit', '.lumen-wp-urls-form', function (e) {
    e.preventDefault();
    var $form = $(this);
    var mode = $form.data('urls-mode') || 'diagnose';
    var confirmMsg = $form.data('confirm');
    if (confirmMsg && !window.confirm(String(confirmMsg))) {
      return;
    }
    var forceFull =
      mode !== 'retry' && $('#lumen-wp-urls-force-full').is(':checked') ? 1 : 0;
    $form.find('.lumen-wp-urls-force-full-field').val(forceFull ? '1' : '0');
    startUrlsJob(mode, $form.find('button[type="submit"]'), false);
  });

  $(document).on('submit', '#lumen-wp-urls-tick-form', function (e) {
    e.preventDefault();
    var form = this;
    var btn = $('#lumen-wp-urls-force-tick').prop('disabled', true);
    ajax('lumen_wp_urls_force_tick', {}, { timeout: URLS_AJAX_TIMEOUT })
      .done(function (res) {
        if (!res || !res.success) {
          showModalError((res && res.data && res.data.message) || lumenWp.i18n.error);
          return;
        }
        applyUrlsJob(res.data.job, res.data.health, res.data.last_errors);
      })
      .fail(function () {
        form.submit();
      })
      .always(function () {
        btn.prop('disabled', false);
      });
  });

  $(document).on('submit', '#lumen-wp-urls-stop-form', function (e) {
    e.preventDefault();
    var form = this;
    ajax('lumen_wp_urls_stop', {}, { timeout: URLS_AJAX_TIMEOUT })
      .done(function (res) {
        if (res && res.success) {
          applyUrlsJob(res.data.job, res.data.health, res.data.last_errors);
        }
        stopUrlsPoll();
      })
      .fail(function () {
        form.submit();
      });
  });

  // —— Historique : modale détails ——
  var historyModal = document.getElementById('lumen-wp-history-modal');
  var historyFocus = null;

  function closeHistoryModal() {
    if (!historyModal) return;
    historyModal.hidden = true;
    historyModal.setAttribute('aria-hidden', 'true');
    historyModal.classList.remove('is-open');
    document.documentElement.classList.remove('lumen-wp-modal-open');
    if (historyFocus && typeof historyFocus.focus === 'function') {
      historyFocus.focus();
    }
    historyFocus = null;
  }

  function openHistoryModal() {
    if (!historyModal) return;
    historyFocus = document.activeElement;
    historyModal.hidden = false;
    historyModal.setAttribute('aria-hidden', 'false');
    historyModal.classList.add('is-open', 'is-info');
    document.documentElement.classList.add('lumen-wp-modal-open');
  }

  function escHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function renderHistoryDetail(d) {
    var body = document.getElementById('lumen-wp-history-modal-body');
    var edit = document.getElementById('lumen-wp-history-modal-edit');
    if (!body) return;
    if (edit) {
      edit.href = d.edit_url || '#';
    }

    function row(label, value) {
      return (
        '<div class="lumen-wp-history-fact">' +
        '<span class="lumen-wp-history-fact__k">' +
        escHtml(label) +
        '</span>' +
        '<span class="lumen-wp-history-fact__v">' +
        escHtml(value || '—') +
        '</span>' +
        '</div>'
      );
    }

    var formats = Array.isArray(d.formats) && d.formats.length ? d.formats.join(', ') : '—';
    var thumb = d.large_url
      ? '<img class="lumen-wp-history-detail__thumb" src="' +
        escHtml(d.large_url) +
        '" alt="" width="88" height="88" />'
      : '<span class="lumen-wp-history-detail__ph" aria-hidden="true"></span>';

    var facts =
      row('Statut', d.status_label) +
      row('Compression', d.compression_label) +
      row('Formats', formats) +
      row('IA / SEO', d.ai_label) +
      row('Texte alternatif', d.alt) +
      row('Titre SEO', d.title_seo) +
      row('Date', d.date) +
      row('Tokens', d.tokens_label) +
      row('Source tokens', d.last_job && d.last_job.tokens_source);
    if (d.error) {
      facts += row('Erreur', d.error);
    }

    var validation = d.validation_url
      ? '<p class="lumen-wp-history-detail__extra"><a class="button" href="' +
        escHtml(d.validation_url) +
        '">Ouvrir À valider</a></p>'
      : '';

    var jobsHtml = '';
    if (Array.isArray(d.jobs) && d.jobs.length) {
      jobsHtml =
        '<h3>Derniers runs</h3>' +
        '<ul class="lumen-wp-history-jobs">' +
        d.jobs
          .map(function (job) {
            var parts = [
              job.date_label || job.completed_at || job.created_at || '—',
              job.type || '—',
              job.status || '—',
              job.tokens_label ||
                (job.tokens_total != null && job.tokens_total !== ''
                  ? String(job.tokens_total)
                  : '—'),
              job.provider_used || job.provider || '—'
            ];
            return '<li>' + escHtml(parts.join(' · ')) + '</li>';
          })
          .join('') +
        '</ul>';
    }

    body.innerHTML =
      '<div class="lumen-wp-history-detail">' +
      '<header class="lumen-wp-history-detail__head">' +
      thumb +
      '<div class="lumen-wp-history-detail__intro">' +
      '<h2 id="lumen-wp-history-modal-title" class="lumen-wp-history-detail__title">' +
      escHtml(d.title || '#' + d.id) +
      '</h2>' +
      '<p class="lumen-wp-history-detail__sub">#' +
      escHtml(String(d.id || '')) +
      (d.kind_label ? ' · ' + escHtml(d.kind_label) : '') +
      '</p>' +
      '</div></header>' +
      '<div class="lumen-wp-history-facts">' +
      facts +
      '</div>' +
      jobsHtml +
      validation +
      '</div>';
  }

  $(document).on('click', '.lumen-wp-history-detail', function (e) {
    e.preventDefault();
    if (!historyModal) {
      historyModal = document.getElementById('lumen-wp-history-modal');
    }
    var id = $(this).data('id');
    if (!id) return;
    var body = document.getElementById('lumen-wp-history-modal-body');
    if (body) {
      body.innerHTML = '<p class="description">Chargement…</p>';
    }
    openHistoryModal();
    ajax('lumen_wp_history_detail', { id: id })
      .done(function (res) {
        if (res && res.success && res.data) {
          renderHistoryDetail(res.data);
        } else {
          if (body) {
            body.innerHTML =
              '<p class="description">' +
              escHtml((res && res.data && res.data.message) || 'Impossible de charger les détails.') +
              '</p>';
          }
        }
      })
      .fail(function () {
        if (body) {
          body.innerHTML = '<p class="description">Erreur réseau.</p>';
        }
      });
  });

  $(document).on('click', '[data-lumen-history-close]', function (e) {
    e.preventDefault();
    closeHistoryModal();
  });

  $(document).on('keydown', function (e) {
    if (e.key === 'Escape' && historyModal && !historyModal.hidden) {
      closeHistoryModal();
    }
  });

})(jQuery);
