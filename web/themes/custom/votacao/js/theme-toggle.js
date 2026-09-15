/**
 * @file
 * Light/dark mode toggle.
 *
 * The choice is stored in localStorage and applied as data-theme on <html>;
 * html.html.twig applies it again before the first paint. Without a stored
 * choice the site follows the operating system preference.
 */

(function () {
  'use strict';

  var STORAGE_KEY = 'votacao-theme';
  var root = document.documentElement;
  var media = window.matchMedia('(prefers-color-scheme: dark)');

  function current() {
    var forced = root.getAttribute('data-theme');
    if (forced === 'light' || forced === 'dark') {
      return forced;
    }
    return media.matches ? 'dark' : 'light';
  }

  function updateButton(button) {
    var next = current() === 'dark' ? 'light' : 'dark';
    button.setAttribute('aria-label', button.getAttribute('data-label-' + next));
    button.setAttribute('title', button.getAttribute('data-label-' + next));
  }

  function init() {
    var buttons = document.querySelectorAll('[data-theme-toggle]');
    if (!buttons.length) {
      return;
    }
    buttons.forEach(function (button) {
      updateButton(button);
      button.addEventListener('click', function () {
        var next = current() === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try {
          localStorage.setItem(STORAGE_KEY, next);
        }
        catch (e) {
          // Storage unavailable (private mode): the choice lasts for this page only.
        }
        buttons.forEach(updateButton);
      });
    });
    media.addEventListener('change', function () {
      buttons.forEach(updateButton);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  }
  else {
    init();
  }
})();
