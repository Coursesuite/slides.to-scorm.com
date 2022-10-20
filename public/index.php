<?php
require_once('../vendor/autoload.php');

session_start();

// header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
// header("Cache-Control: post-check=0, pre-check=0", false);
// header("Pragma: no-cache");
// header("Vary: *");

use \CloudConvert\CloudConvert;
use \CloudConvert\Models\Job;
use \CloudConvert\Models\Task;
use Mhor\MediaInfo\MediaInfo;


define('DEFAULT_DURATION', 20);
$step =  0;
$error = 0;
$feedback = "";
$type = "none";
$SlideArray = array();
$id = "";
$CurrentSlide = null;
$action = "";
$page = 1;
$downloadable = false;

$ALLOWED_ACTIONS = ['convert','next','previous','delete','reset','download','upload media','upload recording','reload'];
$ALLOWED_EXTENSIONS = ['ppt','pptx','odp','keynote'];

// verify the page action
if (isset($_POST['action'])) {
    $action = strtolower($_POST['action']);
    if (!in_array($action, $ALLOWED_ACTIONS)) {
        $action = '';
    }
}

// may need to override the step
if (isset($_POST['step'])) {
    $step = intval($_POST['step']);
    if ($step < 0) {
        $step = 0;
    }
    if ($step > 3) {
        $step = 3;
    }
}

// start over with a new project
if (isset($_POST['reset']) || isset($_GET['reset'])) {
    $action = "reset";
}

// create a new project when required
if (!isset($_SESSION['workingdir'])) {
    $id = md5(time() . rand(0, 9999));
    $_SESSION['workingdir'] = $id;
}

// current project id is persisted through querystring
if (isset($_GET['id'])) {
    if (preg_match('/^[a-f0-9]{32}$/', $_GET['id'])) {
        $id = $_GET['id'];
        $_SESSION['workingdir'] = $id;
    }
}

if (empty($id)) {
    $id = $_SESSION['workingdir'];
}

// job directory
$WORKING_DIR = 'jobs/' . $_SESSION['workingdir'];

function createWorkingDir() {
    global $WORKING_DIR;
    if (!file_exists($WORKING_DIR)) {
        mkdir($WORKING_DIR, 0774, true);
    }
}

// make a filename safe 
function safename($str) {
    mb_regex_encoding("UTF-8");
    $filename = mb_ereg_replace('^[\s]+|[^\P{C}]|[\\\\\/\%\$\#\*\:\?\"\>\<\|]+|[\s\.]+$','', $str);
    $filename = str_replace(" ", "_", $filename);
    return $filename;
}

function get_acceptable_extensions() {
    global $ALLOWED_EXTENSIONS;
    $types = "";
    foreach ($ALLOWED_EXTENSIONS as $ext) {
        $types .= "." . $ext . ",";
    }
    return trim($types,',');
}

function TrackEvent($source,$message) {
global $id;
    $apisecret = file_get_contents("../analytics.key");
    $measurementId = "G-4DT44QT3YY";
    $clientId = preg_replace("/^.+\.(.+?\..+?)$/", "\\1", @$_COOKIE['_ga']);
    $timestamp = round(microtime(true) * 1000);
    if (!empty($clientId)) {
        $payload = new stdClass();
        $payload->client_id = $clientId;
        $payload->user_id = $id;
        $payload->non_personalized_ads = true;
        $payload->timestamp = $timestamp;
        $payload->events = [];
        $event = new stdClass();
        $event->name = "server";
        $event->params = new stdClass();
        $event->params->$source = $message;
        $payload->events[] = $event;
        $req = curl_init("https://www.google-analytics.com/mp/collect?api_secret={$apisecret}&measurement_id={$measurementId}");
        curl_setopt_array($req, array(
            CURLOPT_POST => TRUE,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => array(
                "Content-Type: application/json",
            )
        ));
        $response = curl_exec($req);
        // file_put_contents('log.txt', $timestamp.PHP_EOL.json_encode($payload).PHP_EOL.$response.PHP_EOL , FILE_APPEND | LOCK_EX);
        curl_close($req);
    }

}

function removeDir($dirname) {
    if (is_dir($dirname)) {
        $dir = new RecursiveDirectoryIterator($dirname, RecursiveDirectoryIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($dir, RecursiveIteratorIterator::CHILD_FIRST) as $object) {
            if ($object->isFile()) {
                unlink($object);
            } elseif($object->isDir()) {
                rmdir($object);
            } else {
                throw new Exception('Unknown object type: '. $object->getFileName());
            }
        }
        rmdir($dirname); // Now remove myfolder
    } else {
        throw new Exception('This is not a directory');
    }
}

// converts a google slide sharing link to a series of images
//  https://docs.google.com/presentation/d/1mEqRIc8nzIj_iEtLcNBM0oHEQXdmEkWqf_okT_46xLo/edit?usp=sharing
function downloadGoogleSlides($google, $folder) {

    // track source as google slides
    TrackEvent('source','googleslides');

    // ensure the folder exists
    createWorkingDir();

    $path = realpath($folder);
    $slides = [];
    $count = 0;
    $parts = explode('/', $google); 
    $id = $parts[5];
    $url = "https://docs.google.com/presentation/d/" . $id . "/export/jpeg?pageid=";

    // scrape the sharing url itself
    $contents = file_get_contents($google);

    $title = preg_match('/<title[^>]*>(.*?)<\/title>/ims', $contents, $match) ? $match[1] : null;
    $title = safename($title);

    // first page seems different format
    $jpeg = file_get_contents($url . "p");
    file_put_contents("{$folder}/gslide-{$count}.jpg", $jpeg);
    $slides[] = [
        "image" => "gslide-{$count}.jpg",
        "kind" => null,
        "media" => null,
        "duration" => 0
    ];
    $count++;

    // loop through remaning pages
    $parts = explode('DOCS_modelChunkParseStart = new Date().getTime();', $contents);
    foreach ($parts as $part) {

        // each page has an id which we need to append to the url
        if (($id_start = strpos($part, 'DOCS_modelChunk = [[12,"')) !== false) {
            $id_end = strpos($part, '",', $id_start);
            $pageid = substr($part, $id_start + 24, $id_end - $id_start - 24);

            // some pages may error so we need to check for that, hide it using @ because we don't care about the error itself
            $jpeg = @file_get_contents($url . $pageid);

            // store the downloaded jpegs
            if (!empty($jpeg)) {
                if (!file_exists("{$path}/gslide-{$count}.jpg")) {
                    file_put_contents("{$path}/gslide-{$count}.jpg", $jpeg);
                }
                $slides[] = [
                    "image" => "gslide-{$count}.jpg",
                    "kind" => null,
                    "media" => null,
                    "duration" => 0
                ];
                $count++;
            }
        }
    }

    // slides now contains an ordered array of the images
    return $slides;
}

function convertWebmToMp4($input, $folder) {
    $path = realpath($folder);

    // create a new cloudconvert instance
    $cloudconvert = new CloudConvert([
        'api_key' => file_get_contents("../api.key"),
        'sandbox' => false
    ]);

    $job = (new Job())
    ->addTask(
        (new Task('import/upload', 's2s-import-1'))
        )
    ->addTask(
        (new Task('convert', 's2s-task-1'))
            ->set('input_format', 'webm')
            ->set('output_format', 'mp4')
            ->set('engine', 'ffmpeg')
            ->set('input', ["s2s-import-1"])
            ->set('video_codec', 'x264')
            ->set('crf', 23)
            ->set('preset', 'fast')
            ->set('subtitles_mode', 'none')
            ->set('audio_codec', 'aac')
            ->set('audio_bitrate', 128)
        )
    ->addTask(
        (new Task('export/url', 's2s-export-1'))
            ->set('input', ["s2s-task-1"])
            ->set('inline', false)
            ->set('archive_multiple_files', false)
        ); 

    $cloudconvert->jobs()->create($job);

    // upload the file
    $uploadTask = $job->getTasks()->whereName("s2s-import-1")[0];
    $name = basename($input, ".webm");
    $cloudconvert->tasks()->upload($uploadTask, fopen($input, 'r'), basename($input));

    // wait for the job to finish
    $cloudconvert->jobs()->wait($job);

    // save each of the converted pages as separate files
    foreach ($job->getExportUrls() as $file) {
        $source = $cloudconvert->getHttpTransport()->download($file->url)->detach();
        $dest = fopen($path . '/' . $file->filename, 'w');
        stream_copy_to_stream($source, $dest);
        fclose($dest);
        $output = $file->filename;
    }

    return $output;

}

// converts a compatible presentation to a series of images
function convertSlides($upload, $folder) {


    // ensure the folder exists
    createWorkingDir();

    $slides = [];
    $path = realpath($folder);

    // create a new cloudconvert instance
    $cloudconvert = new CloudConvert([
        'api_key' => file_get_contents("../api.key"),
        'sandbox' => false
    ]);

    $extn = pathinfo($upload['name'], PATHINFO_EXTENSION);

    // track conversion extension, implies source as upload
    TrackEvent('source',$extn);

    if ($extn === "ppt" || $extn === "odp") {
        $TASK = (new Task('convert', 's2s-task-1'))
            ->set('output_format','jpg')
            ->set('input_format',$extn)
            ->set('engine','libreoffice')
            ->set('pixel_density', 300)
            ->set('input',["s2s-import-1"]);
    } else {
        $TASK = (new Task('convert', 's2s-task-1'))
            ->set('output_format','jpg')
            ->set('pixel_density', 300)
            ->set('input',["s2s-import-1"]);
    }

    // create a new job with its import, convert and export steps
    $job = (new Job())
    ->addTask(
        (new Task('import/upload', 's2s-import-1'))
        )
    ->addTask(
        $TASK
        )
    ->addTask(
        (new Task('export/url', 's2s-export-1'))
            ->set('input', ["s2s-task-1"])
            ->set('inline', false)
            ->set('archive_multiple_files', false)
        ); 

    // create job on the server
    $cloudconvert->jobs()->create($job);

    // upload the file
    $uploadTask = $job->getTasks()->whereName("s2s-import-1")[0];
    $cloudconvert->tasks()->upload($uploadTask, fopen($upload['tmp_name'], 'r'), $upload['name']);

    // wait for the job to finish
    $cloudconvert->jobs()->wait($job);

    // save each of the converted pages as separate files
    foreach ($job->getExportUrls() as $file) {
        $source = $cloudconvert->getHttpTransport()->download($file->url)->detach();
        $dest = fopen($path . '/' . $file->filename, 'w');
        stream_copy_to_stream($source, $dest);
        $slides[] = [
            "image" => $file->filename,
            "kind" => null,
            "media" => null,
            "duration" => 0
        ];
    }

    // slides now contains an ordered array of the images
    return $slides;

}

// write the slides json to a file (caching / reloading)
function StoreSlides($slides) {
    global $WORKING_DIR;
    file_put_contents($WORKING_DIR . '/slides.json', json_encode($slides));
}

function LoadSlides() {
    global $WORKING_DIR;
    if (file_exists($WORKING_DIR . '/slides.json')) {
        return json_decode(file_get_contents($WORKING_DIR . '/slides.json'), true);
    }
    return false;
}

function IfSrc($type) {
    global $CurrentSlide, $id;
    if ($CurrentSlide['kind'] == $type) {
        return " src='jobs/{$id}/{$CurrentSlide['media']}'";
    }
}

function ShowFileSize($path) {
    if (file_exists($path)) {
        return human_filesize(filesize($path));
    }
    return '(file missing)';
}

function human_filesize($size, $precision = 2) {
    $units = array('B','kB','MB','GB','TB','PB','EB','ZB','YB');
    $step = 1024;
    $i = 0;
    while (($size / $step) > 0.9) {
        $size = $size / $step;
        $i++;
    }
    return round($size, $precision).$units[$i];
}

function GetDuration($path) {
    $mediaInfo = new MediaInfo();
    $mediaInfoContainer = $mediaInfo->getInfo($path, false);
    $general = $mediaInfoContainer->getGeneral();
    $duration = $general->get('duration')->getMilliseconds();
    return $duration;
}


// decide what to do
switch ($action) {

    case "reload":
        $SlideArray = LoadSlides();
        $page = isset($_POST['slide']) ? intval($_POST['slide']) : 1;
        $CurrentSlide = $SlideArray[$page - 1];
        break;

    case "convert":
        $google = isset($_POST['google']) ? trim($_POST['google']) : "";
        $upload = isset($_FILES['file']) ? $_FILES['file'] : null;

        if (!empty($google)) {

            if (strpos($google, "/presentation/") === false) {
                $error = 1;
                $feedback = "Must be a Google Slides presentation";
            } elseif (strpos($google, "/edit?usp=") === false) {
                $error = 1;
                $feedback = "Must use Google Slides sharing link";
            } else {
                $SlideArray = downloadGoogleSlides($google, $WORKING_DIR);
                $step = 1;
                StoreSlides($SlideArray);
                $CurrentSlide = $SlideArray[0];
            }

        } else if (!is_null($upload)) {

            if ($upload['error'] == 0) {
                $type = "file";
                $ext = pathinfo($upload['name'], PATHINFO_EXTENSION);
                if (!in_array($ext, $ALLOWED_EXTENSIONS)) {
                    $error = 1;
                    $feedback = "File must be a .pptx, .ppt, .keynote or .odp file";
                } else {
                    $SlideArray = convertSlides($upload, $WORKING_DIR);
                    $step = 1;
                    StoreSlides($SlideArray);
                    $CurrentSlide = $SlideArray[0];
                }
            } else {
                $error = 1;
                $feedback = "Error uploading file";
            }

        } else {
            $error = 1;
            $feedback = "No file or url specified";
        }
        break;

    case "next":
        $SlideArray = LoadSlides();
        $page = isset($_POST['slide']) ? intval($_POST['slide']) : 1;
        if ($page < count($SlideArray)) $page = $page + 1;
        $CurrentSlide = $SlideArray[$page - 1];
        break;

    case "previous":
        $SlideArray = LoadSlides();
        $page = isset($_POST['slide']) ? intval($_POST['slide']) : 1;
        if ($page > 1) $page = $page - 1;
        if ($page < 1) $page = 1;
        $CurrentSlide = $SlideArray[$page - 1];
        break;

    case "delete":
        $page = isset($_POST['slide']) ? intval($_POST['slide']) : 0;
        if ($page > 0) {
            $SlideArray = LoadSlides();
            $media = $SlideArray[$page - 1]['media'];
            if (!empty($media)) {
                @unlink($WORKING_DIR . '/' . $media);
            }
            $SlideArray[$page - 1]['media'] = "";
            $SlideArray[$page - 1]['kind'] = null;
            StoreSlides($SlideArray, false);
        }
        $CurrentSlide = $SlideArray[$page - 1];
        break;

    case "reset":
        if (file_exists($WORKING_DIR)) {
            removeDir($WORKING_DIR);
        }
        $id = md5(time() . rand(0, 9999));
        $_SESSION['workingdir'] = $id;
        $WORKING_DIR = $id;
        header("location: ?id=" . $id);
        die();
        break;

    case "upload recording":
    case "upload media":
        $ok = false;
        $file = $_FILES['media'];
        $SlideArray = LoadSlides();
        $page = isset($_POST['slide']) ? intval($_POST['slide']) : 1;
        $duration = isset($_POST['duration']) ? intval($_POST['duration']) : $defaultduration * 1000;
        if ($file['error'] == 0) {
            if ($file['type'] == "audio/mp3" || $file['type'] == "audio/mpeg" || $file['type'] == "video/mp4" || $file['type'] == "video/webm") {
                $ok = true;
                move_uploaded_file($file['tmp_name'], $WORKING_DIR . '/' . $file['name']);
                $type = substr($file['type'], 0, 5);

                // safari as of 15.4 / 2022 doesn't do webm, so we have to convert webm to mp4
                if ($file['type'] == 'video/webm') {
                    $name = ConvertToMp4($file['name'], true);
                } else {
                    $name = $file['name'];
                }
                $SlideArray[$page - 1]['media'] = $name;
                $SlideArray[$page - 1]['kind'] = $type;
                if (intval($duration) < 1) { // e.g. uploaded a file rather than recorded a MediaStream
                    $duration = GetDuration($WORKING_DIR . '/' . $name);
                }
                $SlideArray[$page - 1]['duration'] = $duration;
                StoreSlides($SlideArray);
            }
        }
        if ($action === "uploadrecording") {
            if ($ok) die();
            else die("error");
        }
        $CurrentSlide = $SlideArray[$page - 1];
        break;


    case "download":

        // content name
        $name = isset($_POST['name']) ? trim($_POST['name']) : $id;
        $defaultduration = (isset($_POST['defaultduration'])) ? intval($_POST['defaultduration']) : DEFAULT_DURATION;

        // set up default duration on slides with missing media
        PrepareSlidesForDownload($defaultduration);

        // files for manifest
        $files = glob($WORKING_DIR . '/*');
        $files_array = [];
        foreach ($files as $file) {
            if (basename($file) === "slides.json") continue;
            $files_array[] = "<file href='" . basename($file) . "' />";
        }

        // copy in template
        $source = glob('../template/*');
        foreach (glob('../template/*') as $file) {
            copy($file, $WORKING_DIR . '/' . basename($file));
        }

        // update manifest
        $manifest = file_get_contents($WORKING_DIR . '/imsmanifest.xml');
        $manifest = str_replace('{{timestamp}}', time(), $manifest);
        $manifest = str_replace('{{name}}', $name, $manifest);
        $manifest = str_replace('{{files}}', implode(PHP_EOL, $files_array), $manifest);
        file_put_contents($WORKING_DIR . '/imsmanifest.xml', $manifest);

        // update index
        $html = file_get_contents($WORKING_DIR . '/index.html');
        $html = str_replace('{{name}}', $name, $html);
        $html = str_replace('{{slides}}', file_get_contents($WORKING_DIR . '/slides.json'), $html);
        file_put_contents($WORKING_DIR . '/index.html', $html);

        // get a download name
        $safename = safename($name);

        // track download as the number of pages
        TrackEvent('download',count($files_array));

        // package up the zip file
        $zip = new ZipArchive();
        $zipname = "{$safename}.zip";
        $zip->open("jobs/{$zipname}", ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $files = glob($WORKING_DIR . '/*');
        foreach ($files as $file) {
            if (basename($file) !== 'slides.json') {
                $zip->addFile($file, basename($file));
            }
        }
        $zip->close();
        header('Content-Type: application/zip');
        header("Content-Disposition: attachment; filename='{$zipname}'");
        header('Content-Length: ' . filesize("jobs/{$zipname}"));
        header("Location: jobs/{$zipname}");
        exit;
        break;

    default:
        $SlideArray = LoadSlides();
        if (is_array($SlideArray)) {
            $CurrentSlide = $SlideArray[0];
            $step = 1;
        }
        break;

}

function PrepareSlidesForDownload($duration) {
    $SlideArray = LoadSlides();
    if (is_array($SlideArray)) {
        foreach ($SlideArray as &$slide) {
            if (is_null($slide['kind'])) {
                $slide['kind'] = 'audio';
                $slide['media'] = CreateBlankMp3($duration);
                $slide['duration'] = $duration * 1000;
            }
        }
        StoreSlides($SlideArray);
    }
}

function CreateBlankMp3($duration) {
global $id;
    $filename = md5($duration) . '.mp3';
    $location = "./jobs/{$id}/{$filename}"; // NOT realpath()
    if (!file_exists($location)) {
        // fast
        $command = "ffmpeg -ar 48000 -t {$duration} -f s16le -acodec pcm_s16le -ac 2 -i /dev/zero -acodec libmp3lame -aq 4 {$location} 2>&1";
        shell_exec($command);
        // slow
        $command2 = "ffmpeg -f lavfi -i anullsrc=r=44100:cl=mono -t {$duration} -q:a 9 -acodec libmp3lame {$location}";
    }
    return $filename;
}

function ConvertToMp4($input, $delete = false) {
global $id;
    $filename = basename($input,'.webm');
    $location = "./jobs/{$id}/{$filename}";
    $command = "ffmpeg -i {$location}.webm -fflags +genpts -r 25 {$location}.mp4";
    shell_exec($command);
    if ($delete) {
        unlink($location . '.webm');
    }
    return $filename . '.mp4';
}

/*
Convert mp4 video to webm format with ffmpeg:
ffmpeg -i input-file.mp4 -c:v libvpx -crf 10 -b:v 1M -c:a libvorbis output-file.webm

convert webm to mp4:
ffmpeg -i video.webm -movflags faststart -preset veryfast video.mp4
// see https://blog.addpipe.com/converting-webm-to-mp4-with-ffmpeg/

*/

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Slides to Scorm</title>
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta http-equiv="content-type" content="text/html; charset=utf-8">
    <meta name="description" content="A free tool to let you convert PPTX, KEYNOTE, GOOGLE SLIDES to an automatic web-based slideshow, with audio or video per slide. SCORM compatible.">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,300italic,700,700italic">
    <link rel="stylesheet" href="app.css" type="text/css">
    <!-- Global site tag (gtag.js) - Google Analytics -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-4DT44QT3YY"></script>
    <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', 'G-4DT44QT3YY');
    </script>
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-8055550271725539" crossorigin="anonymous"></script>
</head>
<body>

<div class="container">
  <div class="header">
    <div class="title">Slides to Scorm</div>
    <div class="subtitle">Automate slides with video or audio, get a completion</div>
    <div class="nav"><a href="https://pdf.to-scorm.com/">PDF 2 Scorm</a> | <a href="https://youtube.to-scorm.com/">YouTube 2 Scorm</a> | <a href="https://vimeo.to-scorm.com/">Vimeo 2 Scorm</a> | <a href="https://soundcloud.to-scorm.com/">SoundCloud 2 Scorm</a> | <a href="https://video.to-scorm.com/">Video 2 Scorm</a> | <a href="https://slides.to-scorm.com/">Slides 2 Scorm</a></div>
    <div class="actions"><?php if ($step > 0) { ?><button onclick="document.getElementById('download').showModal();" data-track="Popup Modal">Download</button> <?php } ?><form method="get"><button name="reset" value="1" data-track="Reset">Reset</button></form></div>
  </div>
  <div class="step"><?php if ($step === 0) { ?>
    <div class="flex">
    <div class="intro">
        <h3>How to use this tool</h3>
        <p>Upload a presentation (ppt, pptx, keynote or ods) or google slides. Then upload or record audio or video to each slide. You can download a SCORM-compatible zip file. When played, the slides will automatically change when the video or audio finishes playing (or after <?php echo DEFAULT_DURATION; ?> seconds if no audio/video was recorded - you can change this). A SCORM completion occurs when the last slide finishes playing.</p>
        <p>Once a file is converted, a <b>Download</b> button will appear next to <b>Reset</b>. You can download your package even if you haven't recorded media for each slide yet.</p>
        <h3>Known issues</h3>
        <ul>
        <li>Sometimes Google Slides don't convert properly. If this happens, export them as PPTX or PDF and upload as a file instead.</li>
        <li>Video uses <i>webm</i> format by default, which is not suported in Safari, and so gets converted to mp4.</li>
        <li>Safari 15+ has a bug recording video (audio seems fine) - wait until a new Safari comes out and 🤞, or use Chrome/Firefox.</li>
        <li>You can't (yet) click on the timeline to skip ahead in a slide.</li>
        </ul>
        <h3>Privacy</h3>
        <p>We store your slides and media in a temporary folder. You can delete this folder at any time by pressing the <b>Reset</b> button. Temporary data will be removed from our server automatically as required. Google analytics is used to track basic details about your use of this tool. We don't capture or store any personal information.</p>
    </div>
    <div class="example">
        <div class="ratio"><iframe src="/sample_course_1/index.html" width="100%" height="100%" frameborder="0"></iframe></div>
    </div>
    </div>
  <?php } else if ($step === 1) { ?>
        <h2>Record audio or video for each slides</h2>
  <?php } ?></div>
  <div class="slides"><?php if ($step === 1) { ?>
    <form method="post" action="?id=<?php echo $id; ?>" class="slides">
        <input type="hidden" name="step" value="1">
        <input type="hidden" name="action" value="reload">
        <ol>
<?php foreach ($SlideArray as $index => $slide) { ?>
        <li<?php if ($slide === $CurrentSlide) echo " class='selected'"; ?>>
            <?php if ($slide['kind'] === 'video') { ?>
            <span class="icon" title='Video recording'>🎥</span>
            <?php } else if ($slide['kind'] === 'audio') { ?>
            <span class="icon" title='Audio recording'>🎤</span>
            <?php } ?>
            <button type="submit" name="slide" value="<?php echo $index + 1; ?>"><img src="<?php echo "/jobs/", $id, "/", $slide['image']; ?>" alt="Select slide <?php echo $index + 1; ?>"></button>
        </li>
<?php } ?>
        </ol>
    </form>
  <?php } ?></div>
  <div class="canvas"><?php if ($step === 0) { ?>

        <h2>Select a presentation to load</h2>
        <form method="post" enctype="multipart/form-data" action="?id=<?php echo $id; ?>">
            <input type="hidden" name="action" value="convert">
            <input type="text" name="google" placeholder="Paste in Google Slides 'sharing' url" size="40">
            <span>or</span>
            <input type="file" name="file" accept="<?php echo get_acceptable_extensions(); ?>">
            <input type="submit" value="Upload" data-track="Upload content">
            <output class="feedback"><?php echo $feedback; ?></output>
        </form>
        </section>

<?php } else if ($step === 1) { ?>

        <div class="slide-container" data-index="<?php echo $page; ?>">
            <div class="slide-image">
                <img src="<?php echo "jobs/{$id}/{$CurrentSlide['image']}"; ?>" alt="Slide Image">
                <?php if ($CurrentSlide['kind'] === 'audio') { ?>
                <audio id="media" controls src='<?php echo "jobs/{$id}/{$CurrentSlide['media']}"; ?>'></audio>
                <?php } else if ($CurrentSlide['kind'] === 'video') { ?>
                <video id="media" controls src='<?php echo "jobs/{$id}/{$CurrentSlide['media']}"; ?>'></video>
                <?php } ?>
                <span class="timer"></span>
            </div>
            <nav>
                <form method="post" action="?id=<?php echo $id; ?>">
                    <input type="hidden" name="step" value="1">
                    <input type="hidden" name="slide" value="<?php echo $page; ?>">
                    <input type="submit" name="action" value="Previous">
                    <output class="slide-index">Slide <?php echo $page; ?> of <?php echo count($SlideArray); ?></output>
                    <input type="submit" name="action" value="Next">
                </form>
            </nav>
            <div class="media">
                <h4>Page media</h4>
                <?php if (is_null($CurrentSlide['kind'])) { ?>
                <button class="btn-action" data-action="upload">Upload</button>
                <button class="btn-action" data-action="record">Record video</button>
                <button class="btn-action" data-action="audio">Record audio</button>
                <canvas class="visualiser" width="150" height="39"></canvas>
                <button class="video-control" id="start-record" hidden>Start recording</button>
                <button class="video-control" id="stop-record" hidden>Stop recording</button>
                <form method="post" action="?id=<?php echo $id; ?>" class="upload" hidden enctype="multipart/form-data">
                    <input type="hidden" name="step" value="1">
                    <input type="hidden" name="slide" value="<?php echo $page; ?>">
                    <input type="hidden" name="duration" value="0">
                    <output id="video_feedback"></output>
                    <input type="file" name="media" accept="audio/mp3,video/mp4,audio/mpeg,video/webm">
                    <input type="submit" name="action" value="Upload media" hidden>
                </form>
                <?php } else { ?>
                <?php echo ShowFileSize("jobs/{$id}/{$CurrentSlide['media']}"); ?>
                <form method="post" action="?id=<?php echo $id; ?>">
                    <input type="hidden" name="step" value="1">
                    <input type="hidden" name="slide" value="<?php echo $page; ?>">
                    <input type="submit" name="action" value="Delete" data-track="Delete page">
                </form>
                <?php } ?>
            </div>
        </div>

<?php } ?></div>
  <div class="footer">Need something more comprehensive? Try <a href="https://www.courseassembler.com/" data-track="Upsell link">Course Assembler</a>.</div>
</div>

<dialog id="download">
    <h3>Enter a name for your course</h3>
    <form method="post" action="?id=<?php echo $id; ?>">
        <input type="hidden" name="slide" value="<?php echo $page; ?>">
        <label>Name: <input type="text" size="40" name="name" placeholder="Type in here ..."></label>
        <br>
        <label>Default slide duration: <input type="number" name="defaultduration" value="<?php echo DEFAULT_DURATION; ?>" min="1" max="300" step="1"> seconds</label>
        <p class="center"><input type="submit" name="action" value="Download" data-track="Download"></p>
    </form>
    <a href='' onclick="document.getElementById('download').close();return false;">✖️ Close</a>
</dialog>


    <form method="post" action="?id=<?php echo $id; ?>" id="reload">
        <input type="hidden" name="step" value="1">
        <input type="hidden" name="slide" value="<?php echo $page; ?>">
        <input type="hidden" name="action" value="reload">
    </form>

    <script src="https://unpkg.com/mic-recorder-to-mp3"></script>
    <script src="https://unpkg.com/fix-webm-duration"></script>
    <script src="app.js"></script>

</body>
</html>