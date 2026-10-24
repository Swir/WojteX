<?php

namespace App\Http\Controllers;

use App\Services\MybbImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MybbImportController extends Controller
{
    public function preview(Request $request, MybbImporter $importer): JsonResponse
    {
        $this->authorizeAdmin($request);
        $request->validate(['file' => ['required', 'file', 'max:16384']]);
        return response()->json($importer->preview($importer->readFile($request->file('file')->getRealPath())));
    }

    public function store(Request $request, MybbImporter $importer): JsonResponse
    {
        $this->authorizeAdmin($request);
        $request->validate([
            'file' => ['required', 'file', 'max:16384'],
            'confirm_private' => ['required', 'accepted'],
        ], ['confirm_private.accepted' => __('server.import.confirm_private')]);
        $report = $importer->import($importer->readFile($request->file('file')->getRealPath()), $request->user()->id);
        return response()->json($report, $report['status'] === 'already_imported' ? 200 : 201);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user() && $request->user()->role === 'admin' && !$request->user()->is_banned, 403, __('server.import.admin_only'));
    }
}
