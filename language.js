(function () {
  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char];
    });
  }

  function iconMarkup(icon) {
    icon = String(icon || '').trim();
    if (!icon) {
      return '<span class="cloud-language-emoji" aria-hidden="true">GL</span>';
    }

    if (/^(https?:\/\/|\/|r2:)/i.test(icon)) {
      if (icon.indexOf('r2:') === 0) {
        icon = 'https://json-worker.tranthienthanh93.workers.dev/get_file?file=' + encodeURIComponent(icon.substring(3));
      }

      return '<img src="' + escapeHtml(icon) + '" alt="" loading="lazy">';
    }

    return '<span class="cloud-language-emoji" aria-hidden="true">' + escapeHtml(icon) + '</span>';
  }

  function languageTemplate(item) {
    if (!item.id || !item.element) {
      return item.text;
    }

    var option = item.element;
    var icon = option.getAttribute('data-icon') || '';
    var name = option.getAttribute('data-name') || item.text || '';
    var code = option.getAttribute('data-lang-country') || option.getAttribute('data-lang-key') || '';

    return window.jQuery(
      '<span class="cloud-language-option">' +
        '<span class="cloud-language-flag">' + iconMarkup(icon) + '</span>' +
        '<span class="cloud-language-name">' + escapeHtml(name) + '</span>' +
        '<span class="cloud-language-code">' + escapeHtml(String(code).toUpperCase()) + '</span>' +
      '</span>'
    );
  }

  function languageMatcher(params, data) {
    var term = String(params.term || '').trim().toLowerCase();
    if (term === '') {
      return data;
    }
    if (!data.element) {
      return null;
    }

    var option = data.element;
    var haystack = [
      option.getAttribute('data-name') || data.text || '',
      option.getAttribute('data-lang-country') || '',
      option.getAttribute('data-lang-key') || ''
    ].join(' ').toLowerCase();

    return haystack.indexOf(term) !== -1 ? data : null;
  }

  document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('.language-form');
    var select = document.querySelector('.js-cloud-language-select');
    if (!form || !select || !window.jQuery || !window.jQuery.fn.select2) {
      if (select && form) {
        select.addEventListener('change', function () {
          form.submit();
        });
      }
      return;
    }

    var changing = false;
    var $select = window.jQuery(select);
    $select.select2({
      width: '100%',
      dropdownParent: window.jQuery(form),
      dropdownCssClass: 'cloud-language-dropdown',
      minimumResultsForSearch: 6,
      templateResult: languageTemplate,
      templateSelection: languageTemplate,
      matcher: languageMatcher,
      escapeMarkup: function (markup) {
        return markup;
      }
    });

    $select.on('select2:select change', function () {
      if (changing) {
        return;
      }

      changing = true;
      form.classList.add('is-changing');

      var hiddenCountry = form.querySelector('input[name="country_id"][type="hidden"]');
      if (!hiddenCountry) {
        hiddenCountry = document.createElement('input');
        hiddenCountry.type = 'hidden';
        hiddenCountry.name = 'country_id';
        form.appendChild(hiddenCountry);
      }
      hiddenCountry.value = select.value || '';

      form.submit();
    });
  });
})();
