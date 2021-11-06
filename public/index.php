<?php
require_once('../vendor/autoload.php');

session_start();

use \CloudConvert\CloudConvert;
use \CloudConvert\Models\Job;
use \CloudConvert\Models\Task;
use Mhor\MediaInfo\MediaInfo;

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

// converts a google slide sharing link to a series of images
//  https://docs.google.com/presentation/d/1mEqRIc8nzIj_iEtLcNBM0oHEQXdmEkWqf_okT_46xLo/edit?usp=sharing
function downloadGoogleSlides($google, $folder) {

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
    if ($extn === "ppt" || $extn === "odp") {
        $TASK = (new Task('convert', 'task-1'))
            ->set('output_format','jpg')
            ->set('input_format',$extn)
            ->set('engine','libreoffice')
            ->set('pixel_density', 300)
            ->set('input',["import-1"]);
    } else {
        $TASK = (new Task('convert', 'task-1'))
            ->set('output_format','jpg')
            ->set('pixel_density', 300)
            ->set('input',["import-1"]);
    }

    // create a new job with its import, convert and export steps
    $job = (new Job())
    ->addTask(
        (new Task('import/upload', 'import-1'))
        )
    ->addTask(
        $TASK
        )
    ->addTask(
        (new Task('export/url', 'export-1'))
            ->set('input', ["task-1"])
            ->set('inline', false)
            ->set('archive_multiple_files', false)
        ); 

    // create job on the server
    $cloudconvert->jobs()->create($job);

    // upload the file
    $uploadTask = $job->getTasks()->whereName("import-1")[0];
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
        // if (file_exists($WORKING_DIR)) {
        //     unlink($WORKING_DIR);
        // }
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
        $duration = isset($_POST['duration']) ? intval($_POST['duration']) : 0;
        if ($file['error'] == 0) {
            if ($file['type'] == "audio/mp3" || $file['type'] == "audio/mpeg" || $file['type'] == "video/mp4" || $file['type'] == "video/webm") {
                $ok = true;
                move_uploaded_file($file['tmp_name'], $WORKING_DIR . '/' . $file['name']);
                $type = substr($file['type'], 0, 5);
                $SlideArray[$page - 1]['media'] = $file['name'];
                $SlideArray[$page - 1]['kind'] = $type;
                if (intval($duration) < 1) { // e.g. uploaded a file rather than recorded a MediaStream
                    $duration = GetDuration($WORKING_DIR . '/' . $file['name']);
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

if (is_array($SlideArray)) {
    $downloadable = ($step > 0);
    foreach ($SlideArray as $slide) {
        if (is_null($slide['kind'])) {
            $downloadable = false;
            break;
        }
    }
}


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
</head>
<body>
    
    <header>
        <h1>Slides to Scorm</h1>
        <h3>Automate slides with video or audio, get a completion</h3>
    </header>

    <main>
<?php if ($step === 0) { ?>

        <section class="intro">
            <p>Upload a presentation (ppt, pptx, keynote or ods) or google slides. Then upload or record audio or video to each slide. Then you can download a SCORM-compatible zip file. When played, the slides will automatically change when the video or audio finishes playing. A SCORM completion occurs when the last slide finishes playing.</p>
			<p>Sometimes Google Slides don't convert properly. If this happens, export them as PPTX or PDF and upload as a file instead.</p>
			<p>For slides that have video, you can tap the video to change its position, or press-and-hold to change its size (at runtime, not in this editor).</p>
        </section>

        <section class="convert-slides">
        <h2>Select a presentation to load</h2>
        <form method="post" enctype="multipart/form-data" action="?id=<?php echo $id; ?>">
            <input type="hidden" name="action" value="convert">
            <input type="text" name="google" placeholder="Paste in Google Slides 'sharing' url" size="40">
            <span>or</span>
            <input type="file" name="file" accept="<?php echo get_acceptable_extensions(); ?>">
            <input type="submit" value="Upload">
            <output class="feedback"><?php echo $feedback; ?></output>
        </form>
        </section>

<?php } else if ($step === 1) { ?>

        <section class="record-media">
        <h2>Record audio or video for each slides</h2>
        <div class="slide-container" data-index="<?php echo $page; ?>">
            <div class="slide-image">
                <img src="<?php echo "jobs/{$id}/{$CurrentSlide['image']}"; ?>" alt="Slide Image">
                <?php if ($CurrentSlide['kind'] === 'audio') { ?>
                <audio id="media" controls src='<?php echo "jobs/{$id}/{$CurrentSlide['media']}"; ?>'></audio>
                <?php } else if ($CurrentSlide['kind'] === 'video') { ?>
                <video id="media" controls src='<?php echo "jobs/{$id}/{$CurrentSlide['media']}"; ?>'></video>
                <?php } ?>
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
                    <input type="submit" name="action" value="Delete">
                </form>
                <?php } ?>
            </div>
        </div>
        </section>

<?php }
if ($downloadable) { ?>

        <section class="download-package">
            <h3>Your package is ready to download</h3>
            <form method="post" action="?id=<?php echo $id; ?>">
                <label>Name: <input type="text" size="40" name="name" placeholder="Enter a name for your course"></label>
                <input type="submit" name="action" value="Download">
            </form>
        </section>

<?php } ?>

    </main>

    <form method="post" action="?id=<?php echo $id; ?>" id="reload">
        <input type="hidden" name="step" value="1">
        <input type="hidden" name="slide" value="<?php echo $page; ?>">
        <input type="hidden" name="action" value="reload">
    </form>


    <footer>
    <?php echo "ID: {$id}"; ?> <a href="?reset=1">Reset</a> <a href="https://www.courseassembler.com" style="color:inherit">Try CourseAssembler</a>
    </footer>

    <script src="https://unpkg.com/mic-recorder-to-mp3"></script>
    <script src="https://unpkg.com/fix-webm-duration"></script>
    <script src="app.js"></script>

</body>
</html>
