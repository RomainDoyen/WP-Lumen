(function ($) {
  'use strict';

  function ajax(action, data) {
    return $.post(lumenWp.ajaxUrl, Object.assign({ action: action, nonce: lumenWp.nonce }, data || {}));
  }

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
    el.classList.remove('is-success', 'is-error', 'is-open');
    document.documentElement.classList.remove('lumen-wp-modal-open');
    if (lastFocus && typeof lastFocus.focus === 'function') {
      lastFocus.focus();
    }
    lastFocus = null;
  }

  function openModal(type, title, message) {
    var el = ensureModal();
    if (!el) {
      window.alert(message || title || '');
      return;
    }

    lastFocus = document.activeElement;
    var kind = type === 'error' ? 'error' : 'success';
    var i18n = lumenWp.i18n || {};

    el.classList.remove('is-success', 'is-error');
    el.classList.add(kind === 'error' ? 'is-error' : 'is-success', 'is-open');
    el.querySelector('#lumen-wp-modal-title').textContent =
      title || (kind === 'error' ? i18n.errorTitle : i18n.successTitle) || '';
    el.querySelector('#lumen-wp-modal-message').textContent = message || '';

    el.hidden = false;
    el.setAttribute('aria-hidden', 'false');
    document.documentElement.classList.add('lumen-wp-modal-open');

    var btn = el.querySelector('[data-lumen-modal-close].button, .lumen-wp-modal__actions .button');
    if (btn) {
      setTimeout(function () {
        btn.focus();
      }, 10);
    }
  }

  window.lumenWpModal = {
    show: function (opts) {
      opts = opts || {};
      openModal(opts.type || 'success', opts.title || '', opts.message || '');
    },
    success: function (message, title) {
      openModal('success', title || (lumenWp.i18n && lumenWp.i18n.successTitle), message);
    },
    error: function (message, title) {
      openModal('error', title || (lumenWp.i18n && lumenWp.i18n.errorTitle), message);
    },
    close: closeModal
  };

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

  function fillSeoFields(seo) {
    if (!seo) return;
    var map = {
      title: 'lumen_seo[title]',
      alt_text_seo: 'lumen_seo[alt_text_seo]',
      alt_text_wcag: 'lumen_seo[alt_text_wcag]',
      alt_text_short: 'lumen_seo[alt_text_short]',
      caption: 'lumen_seo[caption]',
      description: 'lumen_seo[description]'
    };
    Object.keys(map).forEach(function (key) {
      var el = document.querySelector('[name="' + map[key] + '"]');
      if (el && typeof seo[key] === 'string') {
        el.value = seo[key];
      }
    });
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

  $(document).on('click', '#lumen-wp-suggest', function (e) {
    e.preventDefault();
    var box = $(this).closest('.lumen-wp-metabox');
    var id = box.data('attachment-id');
    var banner = $('#lumen-wp-mistral-banner');
    banner.prop('hidden', true).text('');
    var btn = $(this).prop('disabled', true).text(lumenWp.i18n.processing);

    ajax('lumen_wp_suggest', { id: id })
      .done(function (res) {
        if (!res || !res.success) {
          window.lumenWpModal.error(
            (res && res.data && res.data.message) || lumenWp.i18n.error
          );
          return;
        }
        fillSeoFields(res.data.seo);
        if (res.data.gutenberg) {
          $('#lumen-wp-gutenberg').val(res.data.gutenberg);
        }
        if (res.data.jsonld) {
          $('#lumen-wp-jsonld').val(res.data.jsonld);
        }
        if (res.data.rate_limited) {
          banner.text(res.data.error || 'Rate limit Mistral').prop('hidden', false);
          window.lumenWpModal.error(res.data.error || lumenWp.i18n.error);
        } else if (res.data.error) {
          banner.text(res.data.error).prop('hidden', false);
          window.lumenWpModal.error(res.data.error);
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
        btn.prop('disabled', false).text('Suggérer (IA)');
      });
  });

  $(document).on('click', '#lumen-wp-reprocess', function (e) {
    e.preventDefault();
    var box = $(this).closest('.lumen-wp-metabox');
    var id = box.data('attachment-id');
    var btn = $(this).prop('disabled', true).text(lumenWp.i18n.processing);

    ajax('lumen_wp_reprocess', { id: id, use_mistral: 0 })
      .done(function (res) {
        var data = res && res.data ? res.data : {};
        if (data.seo) fillSeoFields(data.seo);
        if (data.gutenberg) $('#lumen-wp-gutenberg').val(data.gutenberg);
        if (data.jsonld) $('#lumen-wp-jsonld').val(data.jsonld);
        if (data.status) {
          box.find('.lumen-wp-status').text(data.status);
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
        btn.prop('disabled', false).text('Re-traiter (optimiser + pack)');
      });
  });

  // Bulk page
  var bulkState = {
    ids: [],
    index: 0,
    stop: false,
    force: false,
    useMistral: false,
    okCount: 0,
    errCount: 0
  };

  function logLine(text, ok) {
    var li = $('<li/>').text(text);
    if (ok === true) li.addClass('is-ok');
    if (ok === false) li.addClass('is-error');
    $('#lumen-wp-bulk-log').prepend(li);
  }

  function setProgress(done, total) {
    var pct = total ? Math.round((done / total) * 100) : 0;
    $('#lumen-wp-progress-bar').val(pct);
    $('#lumen-wp-progress-label').text(done + ' / ' + total);
  }

  function finishBulk() {
    $('#lumen-wp-bulk-start').prop('disabled', false);
    $('#lumen-wp-bulk-stop').prop('disabled', true);
    logLine(lumenWp.i18n.done, true);

    var total = bulkState.ids.length;
    var msg =
      lumenWp.i18n.bulkDone +
      ' ' +
      bulkState.okCount +
      ' OK / ' +
      bulkState.errCount +
      ' erreur(s) sur ' +
      total +
      '.';

    if (bulkState.errCount > 0 && bulkState.okCount === 0) {
      window.lumenWpModal.error(msg);
    } else if (bulkState.errCount > 0) {
      window.lumenWpModal.show({
        type: 'error',
        title: lumenWp.i18n.done,
        message: msg
      });
    } else {
      window.lumenWpModal.success(msg);
    }
  }

  function processNext() {
    if (bulkState.stop || bulkState.index >= bulkState.ids.length) {
      finishBulk();
      return;
    }

    var id = bulkState.ids[bulkState.index];
    ajax('lumen_wp_bulk_process', {
      id: id,
      force: bulkState.force ? 1 : 0,
      use_mistral: bulkState.useMistral ? 1 : 0
    })
      .done(function (res) {
        var ok = !!(res && res.success);
        var status = res && res.data && res.data.status ? res.data.status : '';
        var msg = res && res.data && res.data.message ? res.data.message : status;
        if (ok) bulkState.okCount += 1;
        else bulkState.errCount += 1;
        logLine('#' + id + ' — ' + (msg || (ok ? 'ok' : 'error')), ok);
      })
      .fail(function (xhr) {
        var msg =
          (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ||
          lumenWp.i18n.error;
        bulkState.errCount += 1;
        logLine('#' + id + ' — ' + msg, false);
      })
      .always(function () {
        bulkState.index += 1;
        setProgress(bulkState.index, bulkState.ids.length);
        processNext();
      });
  }

  $(document).on('click', '#lumen-wp-bulk-start', function (e) {
    e.preventDefault();
    bulkState.stop = false;
    bulkState.force = $('#lumen-wp-force').is(':checked');
    bulkState.useMistral = $('#lumen-wp-use-mistral').is(':checked');
    bulkState.index = 0;
    bulkState.ids = [];
    bulkState.okCount = 0;
    bulkState.errCount = 0;

    $('#lumen-wp-bulk-log').empty();
    $('.lumen-wp-progress').prop('hidden', false);
    $('#lumen-wp-bulk-start').prop('disabled', true);
    $('#lumen-wp-bulk-stop').prop('disabled', false);
    setProgress(0, 0);

    ajax('lumen_wp_bulk_ids', { force: bulkState.force ? 1 : 0 })
      .done(function (res) {
        if (!res || !res.success) {
          logLine(lumenWp.i18n.error, false);
          $('#lumen-wp-bulk-start').prop('disabled', false);
          $('#lumen-wp-bulk-stop').prop('disabled', true);
          window.lumenWpModal.error(lumenWp.i18n.error);
          return;
        }
        bulkState.ids = res.data.ids || [];
        setProgress(0, bulkState.ids.length);
        if (!bulkState.ids.length) {
          logLine(lumenWp.i18n.bulkEmpty, true);
          $('#lumen-wp-bulk-start').prop('disabled', false);
          $('#lumen-wp-bulk-stop').prop('disabled', true);
          window.lumenWpModal.success(lumenWp.i18n.bulkEmpty);
          return;
        }
        processNext();
      })
      .fail(function () {
        logLine(lumenWp.i18n.error, false);
        $('#lumen-wp-bulk-start').prop('disabled', false);
        $('#lumen-wp-bulk-stop').prop('disabled', true);
        window.lumenWpModal.error(lumenWp.i18n.error);
      });
  });

  $(document).on('click', '#lumen-wp-bulk-stop', function (e) {
    e.preventDefault();
    bulkState.stop = true;
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
})(jQuery);
