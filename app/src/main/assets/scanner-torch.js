(function () {
  if (window.__x86TorchV1 && window.__x86TorchV1.status === 'pending') return 'pending';
  var state = window.__x86TorchV1 = {status: 'unavailable'};
  var videos = document.querySelectorAll('video');
  for (var i = 0; i < videos.length; i++) {
    var video = videos[i], rect = video.getBoundingClientRect();
    if (!rect.width || !rect.height || getComputedStyle(video).visibility === 'hidden') continue;
    var stream = video.srcObject;
    if (!stream || typeof stream.getVideoTracks !== 'function') continue;
    var tracks = stream.getVideoTracks();
    for (var j = 0; j < tracks.length; j++) {
      var track = tracks[j];
      try {
        if (track.readyState !== 'live' || !track.getCapabilities || !track.getCapabilities().torch) continue;
        var enabled = !track.getSettings().torch;
        state.status = 'pending';
        track.applyConstraints({advanced: [{torch: enabled}]}).then(function () {
          state.status = enabled ? 'on' : 'off';
        }).catch(function () { state.status = 'failed'; });
        return 'pending';
      } catch (error) { state.status = 'failed'; return 'failed'; }
    }
  }
  return 'unavailable';
})()
