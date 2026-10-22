(function () {
  if (typeof X86Trusted === 'undefined') return false;
  var sequence = 0;
  window.X86Android = {
    saveBase64: function (dataUrl, fileName, mime) {
      X86Trusted.postMessage(JSON.stringify({version: 1, type: 'download',
        id: String(++sequence), dataUrl: dataUrl, fileName: fileName || ''}));
    }
  };
  return true;
})()
