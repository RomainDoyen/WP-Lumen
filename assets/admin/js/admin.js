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
        if (data.has_backup) {
          box.attr('data-has-backup', '1');
          $('#lumen-wp-restore').prop('hidden', false);
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

  $(document).on('click', '#lumen-wp-restore', function (e) {
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
            ' traitées — ' +
            (entry.ok || 0) +
            ' OK · ' +
            (entry.err || 0) +
            ' erreur(s)'
        )
      );
      $li.append($('<p class="lumen-wp-history__opts"/>').text(opts.join(' · ')));

      var errors = entry.errors || [];
      if (errors.length) {
        var $err = $('<ul class="lumen-wp-history__errors"/>');
        errors.forEach(function (line) {
          $err.append($('<li/>').text(line));
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
    var pct = total ? Math.min(100, Math.round((processed / total) * 100)) : status === 'done' ? 100 : 0;

    $('#lumen-wp-bulk-progress').prop('hidden', status === 'idle');
    $('#lumen-wp-progress-bar').val(pct);
    $('#lumen-wp-progress-label').text(
      processed + ' / ' + total + ' — OK ' + (job.ok || 0) + ' / err ' + (job.err || 0)
    );
    $('#lumen-wp-bulk-status-text').text(job.last_message || status);
    renderBulkLog(job.log || []);

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
    }
  });

  $(document).on('click', '#lumen-wp-bulk-start', function (e) {
    e.preventDefault();
    ajax('lumen_wp_bulk_start', {
      force: $('#lumen-wp-force').is(':checked') ? 1 : 0,
      use_ai: $('#lumen-wp-use-ai').is(':checked') ? 1 : 0
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
        window.lumenWpModal.success(lumenWp.i18n.tickForced || 'Une image a été traitée.');
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

  function refreshAiProviderUi() {
    var $provider = $('#lumen-wp-ai-provider');
    var $model = $('#lumen-wp-ai-model');
    if (!$provider.length) return;

    var provider = $provider.val() || 'none';

    $('#lumen-wp-api-key-row').prop('hidden', provider === 'none');
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

    var current = $model.val() || '';
    var models = catalog[provider] || { '': 'Choisir d’abord un fournisseur' };
    $model.empty();
    Object.keys(models).forEach(function (value) {
      var opt = $('<option/>').attr('value', value).text(models[value]);
      if (value === current) opt.prop('selected', true);
      $model.append(opt);
    });
    if (current && !Object.prototype.hasOwnProperty.call(models, current)) {
      $model.append($('<option/>').attr('value', current).text(current).prop('selected', true));
    }
    $model.prop('disabled', provider === 'none');
  }

  $(document).on('change', '#lumen-wp-ai-provider', refreshAiProviderUi);
  $(function () {
    refreshAiProviderUi();
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

    var li = $root.data('label-images') || 'Images';
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
        window.lumenWpModal.success(lumenWp.i18n.tickForced || 'Une image a été traitée.');
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
})(jQuery);
