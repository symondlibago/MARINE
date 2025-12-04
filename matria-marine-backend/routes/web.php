<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/debug-aws', function() {
    return [
        'AWS_ACCESS_KEY_ID' => env('AWS_ACCESS_KEY_ID') ? substr(env('AWS_ACCESS_KEY_ID'), 0, 10) . '...' : 'NOT SET',
        'AWS_SECRET_ACCESS_KEY' => env('AWS_SECRET_ACCESS_KEY') ? 'SET (hidden)' : 'NOT SET',
        'AWS_DEFAULT_REGION' => env('AWS_DEFAULT_REGION'),
        'AWS_BUCKET' => env('AWS_BUCKET'),
        'Config Loaded' => config('filesystems.disks.s3.key') ? 'YES' : 'NO',
    ];
});

Route::get('/test-s3-direct', function() {
    try {
        // Test if we can write to S3
        $testContent = 'Test file created at ' . now();
        $result = Storage::disk('s3')->put('test-connection.txt', $testContent);
        
        if ($result) {
            $url = Storage::disk('s3')->url('test-connection.txt');
            $exists = Storage::disk('s3')->exists('test-connection.txt');
            
            return response()->json([
                'success' => true,
                'message' => 'S3 connection works!',
                'file_url' => $url,
                'exists' => $exists
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Storage::put() returned false'
        ]);
        
    } catch (\Aws\S3\Exception\S3Exception $e) {
        return response()->json([
            'success' => false,
            'error_type' => 'S3 Exception',
            'message' => $e->getMessage(),
            'aws_error_code' => $e->getAwsErrorCode()
        ], 500);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error_type' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ], 500);
    }
});
