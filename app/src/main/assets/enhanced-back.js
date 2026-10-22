(function () {
  // Opt-in: only an explicit dialog close control; no guesses based on "X" text.
  var dialogs = document.querySelectorAll('dialog[open],[role="dialog"]');
  for (var i = dialogs.length - 1; i >= 0; i--) {
    var d = dialogs[i], r = d.getBoundingClientRect();
    if (!r.width || !r.height) continue;
    if (d.querySelector('input,textarea,select,[contenteditable]')) return 'blocked';
    var close = d.querySelector('button[data-close],button[aria-label="Закрыть"],button[aria-label="Close"]');
    if (close) { close.click(); return 'closed'; }
    return 'blocked';
  }
  return 'unknown';
})()
