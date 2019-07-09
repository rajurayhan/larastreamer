# Video Streaming package for laravel

## Installation

Install via Composer

    composer require rajurayhan/larastreamer

Publish Configuration

    php artisan vendor:publish --tag=larastreamer

## Usage

This package shipped with a built in streaming route - 
  
    Route::get('/stream/{filename}', 'Raju\Streamer\Controllers\StreamController@stream')->name('stream'); 
    
Just send filename on route and stream! 

Or just build your own method following this- 
    
    /* Controller */ 
    
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
    }

## Note 

Default file base path is set to - 

    storage_path('app/uploads/')
    
 To change, edit config/larastreamer.php 
 
## Find Me
	Email: devraju.bd@gmail.com 
