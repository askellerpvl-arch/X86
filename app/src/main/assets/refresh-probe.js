(function () {
  // No server contract is present in this repo. A positive opt-in state is
  // required; guessing from a tab name or HTTP status could lose a checkout.
  if (window.__x86NativeSafeRefreshV1 !== true) return 'unknown';
  if (window.scrollY > 0) return 'busy';
  var focused = document.activeElement;
  if (focused && focused.matches('input,textarea,select,[contenteditable]')) return 'busy';
  if (document.querySelector('dialog[open],[role="dialog"],.modal.show,.modal.open,.scanner,video')) return 'busy';
  var fields = document.querySelectorAll('input,textarea,select,[contenteditable]');
  for (var i = 0; i < fields.length; i++) {
    var f = fields[i];
    if (f.matches('[contenteditable],select,input[type="file"]')) return 'unknown';
    if (f.value !== f.defaultValue || f.checked !== f.defaultChecked) return 'busy';
  }
  // The server-owned active tab/checkout/dirty state is deliberately not inferred.
  return 'unknown';
})()
