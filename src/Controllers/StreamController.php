<?php

namespace Raju\Streamer\Controllers;

use App\Http\Controllers\Controller;
use Raju\Streamer\Helpers\VideoStream;

class StreamController extends Controller
{
    public function stream($filename)
    {

        $videosDir      = config('larastreamer.basepath');
        if (file_exists($filePath = $videosDir."/".$filename)) {
            $stream = new VideoStream($filePath);
            return response()->stream(function() use ($stream) {
                $stream->start();
            });
        }
        return response("File doesn't exists", 404);
    }

    public function streamer()
    {
        return 'Hello from Streamer Package!';
    }
}
