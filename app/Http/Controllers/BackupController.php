<?php

namespace App\Http\Controllers;

use App\Actions\Data\ExportAllData;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    public function download(string $name): StreamedResponse
    {
        $path = ExportAllData::DIRECTORY.'/'.$name;

        // Only plain backup names are accepted, so no other file can be reached.
        abort_unless(ExportAllData::isValidName($name) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $name);
    }
}
