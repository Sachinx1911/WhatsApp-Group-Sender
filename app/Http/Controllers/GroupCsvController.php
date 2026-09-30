<?php

namespace App\Http\Controllers;

use App\Actions\Groups\GroupCsv;
use App\Models\Group;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GroupCsvController extends Controller
{
    /** Export groups matching the Group Manager filters currently in the URL. */
    public function export(Request $request, GroupCsv $csv): StreamedResponse
    {
        $filters = $request->only(['search', 'category', 'status', 'min_members', 'max_members']);

        return response()->streamDownload(function () use ($csv, $filters) {
            $csv->write(fopen('php://output', 'w'), Group::with('category')->filter($filters)->orderBy('name')->lazy());
        }, 'education-hub-groups-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function sample(): StreamedResponse
    {
        $examples = [
            ['MPSC Batch 11', 'MPSC', 250, 'active'],
            ['Police Batch 07', 'Police Bharti', 230, 'active'],
            ['Talathi Batch 01', 'Talathi Bharti', '', 'active'],
            ['Free Batch 06', 'Free', 600, 'inactive'],
        ];

        return response()->streamDownload(function () use ($examples) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, GroupCsv::COLUMNS, escape: '');
            foreach ($examples as $row) {
                fputcsv($out, $row, escape: '');
            }
        }, 'groups-sample.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
