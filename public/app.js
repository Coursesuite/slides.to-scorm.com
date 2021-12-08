const App = {};

App.init = function() {

  const page = document.querySelector(".slide-container") ? document.querySelector(".slide-container").dataset.index : 0;
  const audio_button = document.querySelector("button[data-action='audio']");
  const record_button = document.querySelector("button[data-action='record']");
  const upload_button = document.querySelector("button[data-action='upload']");
  const canvas = document.querySelector("canvas.visualiser");
  const timer = document.querySelector("span.timer");

  const div = document.querySelector(".record-media");

  const start_button = document.querySelector("#start-record");
  const stop_button = document.querySelector("#stop-record");
  const upload_form = document.querySelector("form.upload");

  let camera_stream = null;
  let media_recorder = null;
  let blobs_recorded = [];
  let video = null;
  let audio_recorder = null;
  let start_time = null;
  let duration = 0;
  let timer_interval = null;

  let mediaType = "video/webm",
      mediaExtn = "webm",
    cameraOptions = {}

  if (typeof MediaRecorder.isTypeSupported == 'function'){
    if (MediaRecorder.isTypeSupported('video/webm;codecs=h264')) {
      cameraOptions = {mimeType: 'video/webm;codecs=h264'};
      mediaType = "video/webm;codecs=h264";
    } else  if (MediaRecorder.isTypeSupported('video/webm')) {
      cameraOptions = {mimeType: 'video/webm'};
    } else  if (MediaRecorder.isTypeSupported('video/mp4')) {
      cameraOptions = {mimeType: 'video/mp4', videoBitsPerSecond : 2500000};
      mediaExtn = "mp4";
      mediaType = "video/mp4";
    }
  }

  if (audio_button) {
    audio_recorder = new MicRecorder({
      bitRate: 192
    });
    audio_button.addEventListener('click', recordAudio);
  }

  if (upload_button) {
    upload_button.addEventListener('click', function() {
      record_button.style.display = "none";
      upload_button.style.display = "none";
      audio_button.style.display = "none";
      canvas.style.display = "none";
      document.querySelector("form.upload").removeAttribute("hidden");
      document.querySelector("form.upload input[type='submit']").removeAttribute("hidden");
    });
  }

  if (record_button) {
    record_button.addEventListener('click', async function() {
      record_button.style.display = "none";
      upload_button.style.display = "none";
      audio_button.style.display = "none";
      canvas.style.display = "none";
      document.querySelector("form.upload").removeAttribute("hidden");

      let upl = document.querySelector("form.upload input[type='file']");
      upl.parentNode.removeChild(upl);

      try {
        camera_stream = await navigator.mediaDevices.getUserMedia({
          audio: true,
          video: { width: 854, height: 480 }
        });
      }
      catch(error) {
        alert(error.message);
        return;
      }

      video = document.createElement("video");
      video.setAttribute("autoplay", "true");
      video.setAttribute("playsinline", "true");
      video.setAttribute("width","854");
      video.id = "media";
      document.querySelector(".slide-image").appendChild(video);
      video.srcObject = camera_stream;
      start_button.style.display = 'inline-block';

    });
  }

  if (start_button) {

    start_button.addEventListener('click', function() {
      media_recorder = new MediaRecorder(camera_stream, cameraOptions);
      blobs_recorded = [];
      start_time = Date.now();

      media_recorder.addEventListener('dataavailable', function(e) {
        // var data = event.data;
        // if (data && data.size > 0) {
        //     mediaParts.push(data);
        // }
        blobs_recorded.push(e.data);
      });

      media_recorder.addEventListener('stop', function() {
        duration = Date.now() - start_time;
        document.querySelector("form.upload input[type='submit']").removeAttribute("hidden");
        stop_button.style.display = 'none';
        start_button.style.display = 'inline-block';
      });

      media_recorder.start(300);
      div.classList.add("recording");
      document.querySelector("#video_feedback").textContent = "";

      start_button.style.display = 'none';
      stop_button.style.display = 'inline-block';
    });
  }

  if (stop_button) {
    stop_button.addEventListener('click', function() {
      media_recorder.stop();
      div.classList.remove("recording");

      document.querySelector("#video_feedback").textContent = "Video recorded - ready to upload";
      upload_form.querySelector("input[name='duration']").value = duration;
      upload_form.querySelector("input[type='submit']").value = "Upload recording";
      upload_form.removeAttribute("action");
      upload_form.querySelector("input[type='submit']").addEventListener('click', async function (event) {
        event.preventDefault();
        const fd = new FormData(upload_form);
        let blob = new Blob(blobs_recorded, { type: mediaType });
        const fixedBlob = await ysFixWebmDuration(blob, duration, {logger: false});
        blob = null; // early gc
        fd.append("media", fixedBlob, "recording-" + page + "." + mediaExtn);
        fd.append("action","upload recording");
        let result = await fetch("index.php" + location.search, {
          method: "POST",
          body: fd
        });
        result = await result.text();
        if (result === "error") {
          alert("An error occurred uploading the recording.");
        } else {
          document.getElementById("reload").submit();
        }
      })
    });
  }

  function recordAudio() {
      record_button.style.display = "none";
      upload_button.style.display = "none";
      document.querySelector("#video_feedback").innerHTML = "";

      let upl = document.querySelector("form.upload input[type='file']");
      if (upl) upl.parentNode.removeChild(upl);
      startTimer();

      audio_recorder.start().then(() => {
        audio_button.textContent = 'Stop recording';
        div.classList.add("recording");

        canvas.style.display = 'inline-block';
        visualize(canvas, audio_recorder.activeStream);

        // swap the button listener to stop recording action
        audio_button.removeEventListener('click', recordAudio);
        audio_button.addEventListener('click', stopAudio);
      }).catch((e) => {
        console.dir(e);
        alert('Could not start recording.');
        stopTimer();
      });
  }

  function stopAudio() {
      stopTimer();
      audio_recorder.stop().getMp3().then(([buffer, blob]) => {
        const file = new File(buffer, 'recording-' + page + '.mp3', {
          type: blob.type,
          lastModified: Date.now()
        });

        // swap the button listener back to start recording action
        audio_button.removeEventListener('click', stopAudio);
        audio_button.addEventListener('click', recordAudio);

        const player = new Audio(URL.createObjectURL(file));
        player.controls = true;
        document.querySelector("#video_feedback").textContent = "";
        document.querySelector("#video_feedback").appendChild(player);

        audio_button.textContent = 'Record audio';
        div.classList.remove("recording");

        upload_form.removeAttribute("hidden");
        upload_form.querySelector("input[type='submit']").value = "Upload recording";
        upload_form.removeAttribute("action");
        upload_form.querySelector("input[type='submit']").addEventListener('click', async function (event) {
          event.preventDefault();
          const fd = new FormData(upload_form);
          fd.append("media", file);
          fd.append("action","upload recording");
          let result = await fetch("index.php" + location.search, {
            method: "POST",
            body: fd
          });
          result = await result.text();
          if (result === "error") {
            alert("An error occurred uploading the recording.");
          } else {
            document.getElementById("reload").submit();
          }
        });
      }).catch((e) => {
        console.error(e);
      });

  }

  function startTimer() {
    timer.textContent = HHMMSS(0);
    timer.style.padding = "1rem";
    timer_interval = setInterval(function() {
      let t = ~~timer.dataset.seconds;
      t++;
      timer.dataset.seconds = t;
      timer.textContent = HHMMSS(t);
    }, 1000);
  }

  function stopTimer() {
    if (timer_interval) clearInterval(timer_interval);
    timer.style.padding = "0";
    timer.textContent = "";
    timer.dataset.seconds = 0;
  }

};



function visualize(canvas, stream) {
  var audioCtx = new (window.AudioContext || webkitAudioContext)();
  var source = audioCtx.createMediaStreamSource(stream);
  var canvasCtx = canvas.getContext("2d");
  var analyser = audioCtx.createAnalyser();
  analyser.fftSize = 2048;
  var bufferLength = analyser.frequencyBinCount;
  var dataArray = new Uint8Array(bufferLength);

  source.connect(analyser);
  //analyser.connect(audioCtx.destination);

  draw()

  function draw() {
    WIDTH = canvas.width
    HEIGHT = canvas.height;

    requestAnimationFrame(draw);

    analyser.getByteTimeDomainData(dataArray);

    canvasCtx.fillStyle = 'rgb(255, 255, 255)';
    canvasCtx.fillRect(0, 0, WIDTH, HEIGHT);

    canvasCtx.lineWidth = 2;
    canvasCtx.strokeStyle = 'rgb(127, 127, 127)';

    canvasCtx.beginPath();

    var sliceWidth = WIDTH * 1.0 / bufferLength;
    var x = 0;


    for(var i = 0; i < bufferLength; i++) {

      var v = dataArray[i] / 128.0;
      var y = v * HEIGHT/2;

      if(i === 0) {
        canvasCtx.moveTo(x, y);
      } else {
        canvasCtx.lineTo(x, y);
      }

      x += sliceWidth;
    }

    canvasCtx.lineTo(canvas.width, canvas.height/2);
    canvasCtx.stroke();

  }
}

function HHMMSS(seconds) {
    var date = new Date(1970,0,1), result;
    date.setSeconds(seconds);
    result = (date.toTimeString().replace(/.*(\d{2}:\d{2}:\d{2}).*/, "$1"));
    return result.replace(/^00:/,'');
}

window.addEventListener('DOMContentLoaded', (event) => {
  App.init();
});