const App = {};

App.init = function() {

  const page = document.querySelector(".slide-container") ? document.querySelector(".slide-container").dataset.index : 0;
  const audio_button = document.querySelector("button[data-action='audio']");
  const record_button = document.querySelector("button[data-action='record']");
  const upload_button = document.querySelector("button[data-action='upload']");

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
      document.querySelector("form.upload").removeAttribute("hidden");
      document.querySelector("form.upload input[type='submit']").removeAttribute("hidden");
    });
  }

  if (record_button) {
    record_button.addEventListener('click', async function() {
      record_button.style.display = "none";
      upload_button.style.display = "none";
      audio_button.style.display = "none";
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

      let upl = document.querySelector("form.upload input[type='file']");
      upl.parentNode.removeChild(upl);

      audio_recorder.start().then(() => {
        audio_button.textContent = 'Stop recording';
        div.classList.add("recording");
        audio_button.removeEventListener('click', recordAudio);
        audio_button.addEventListener('click', stopAudio);
      }).catch((e) => {
        console.error(e);
      });
  }

  function stopAudio() {
      audio_recorder.stop().getMp3().then(([buffer, blob]) => {
        const file = new File(buffer, 'recording-' + page + '.mp3', {
          type: blob.type,
          lastModified: Date.now()
        });

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

};

window.addEventListener('DOMContentLoaded', (event) => {
  App.init();
});