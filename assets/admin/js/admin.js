(function ($) {
  'use strict';

  function ajax(action, data) {
    return $.post(lumenWp.ajaxUrl, Object.assign({ action: action, nonce: lumenWp.nonce }, data || {}));
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
    el.classList.remove('is-success', 'is-error', 'is-info', 'is-open');
    document.documentElement.classList.remove('lumen-wp-modal-open');
    var action = el.querySelector('#lumen-wp-modal-action');
    if (action) {
      hideModalAction(action);
    }
    if (lastFocus && typeof lastFocus.focus === 'function') {
      lastFocus.focus();
    }
    lastFocus = null;
  }

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

  function openModal(type, title, message, opts) {
    opts = opts || {};
    var el = ensureModal();
    if (!el) {
      window.alert(message || title || '');
      return;
    }

    lastFocus = document.activeElement;
    var kind = type === 'error' ? 'error' : type === 'info' ? 'info' : 'success';
    var i18n = lumenWp.i18n || {};
    var defaultTitle =
      kind === 'error' ? i18n.errorTitle : kind === 'info' ? i18n.infoTitle : i18n.successTitle;

    el.classList.remove('is-success', 'is-error', 'is-info');
    el.classList.add('is-' + kind, 'is-open');
    el.querySelector('#lumen-wp-modal-title').textContent = title || defaultTitle || '';
    el.querySelector('#lumen-wp-modal-message').textContent = message || '';

    var action = el.querySelector('#lumen-wp-modal-action');
    if (action) {
      // Lien « Ouvrir les réglages » uniquement pour la modale IA non configurée.
      if (opts.actionUrl) {
        showModalAction(action, opts.actionUrl, opts.actionLabel || i18n.openSettings || '');
      } else {
        hideModalAction(action);
      }
    }

    el.hidden = false;
    el.setAttribute('aria-hidden', 'false');
    document.documentElement.classList.add('lumen-wp-modal-open');

    var btn = el.querySelector(
      '#lumen-wp-modal-action.is-visible, [data-lumen-modal-close].button-primary, .lumen-wp-modal__actions .button-primary'
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
    var pct = total ? Math.min(100, Math.round((processed / total) * 100)) : status === 'done' ? 100 : 0;

    $('#lumen-wp-bulk-progress').prop('hidden', status === 'idle');
    $('#lumen-wp-progress-bar').val(pct);
    $('#lumen-wp-progress-label').text(
      processed + ' / ' + total + ' — OK ' + (job.ok || 0) + ' / err ' + (job.err || 0)
    );
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
    if (!apiKey) {
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

    if (!apiKey) {
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

  function renderUrlsReport(report) {
    var $summary = $('#lumen-wp-urls-summary');
    var $box = $('#lumen-wp-urls-results');
    if (!$summary.length || !$box.length || !report) return;

    var totals = report.totals || {};
    var issues = report.issues || [];
    $summary
      .prop('hidden', false)
      .text(
        'Scan : ' +
          (report.scanned || 0) +
          ' média(s) — ' +
          (totals.issues || 0) +
          ' URL(s) obsolète(s) (posts ' +
          (totals.posts || 0) +
          ', Elementor ' +
          (totals.metas || 0) +
          ', options ' +
          (totals.options || 0) +
          ').'
      );

    if (!issues.length) {
      $box.prop('hidden', false).html(
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
      var warn = row.old_missing && row.new_exists ? ' <span class="lumen-wp-urls-pill">404→webp</span>' : '';
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

  $(document).on('click', '#lumen-wp-urls-diagnose', function (e) {
    e.preventDefault();
    var btn = $(this).prop('disabled', true);
    ajax('lumen_wp_urls_diagnose')
      .done(function (res) {
        if (!res || !res.success) {
          window.lumenWpModal.error(
            (res && res.data && res.data.message) || lumenWp.i18n.error
          );
          return;
        }
        renderUrlsReport(res.data.report);
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

  $(document).on('click', '#lumen-wp-urls-rewrite', function (e) {
    e.preventDefault();
    if (
      !window.confirm(
        'Réécrire globalement les anciennes URLs (.jpg/.png → .webp) dans le contenu, Elementor et les options ?'
      )
    ) {
      return;
    }
    var btn = $(this).prop('disabled', true).text(lumenWp.i18n.processing || '…');
    ajax('lumen_wp_urls_rewrite')
      .done(function (res) {
        if (!res || !res.success) {
          window.lumenWpModal.error(
            (res && res.data && res.data.message) || lumenWp.i18n.error
          );
          return;
        }
        renderUrlsReport(res.data.report);
        window.lumenWpModal.success(res.data.message || lumenWp.i18n.done);
      })
      .fail(function (xhr) {
        window.lumenWpModal.error(
          (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ||
            lumenWp.i18n.error
        );
      })
      .always(function () {
        btn.prop('disabled', false).text('Réécrire globalement');
      });
  });
})(jQuery);
