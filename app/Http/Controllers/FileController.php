<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers;

use App\Contracts\RecordStore;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Outbox;
use App\Support\PrivateFiles;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;

final class FileController extends Controller
{
    public function __construct(private RecordStore $store, private Access $access, private PrivateFiles $files, private Outbox $outbox, private Audit $audit) {}

    public function upload(Request $request): mixed
    {
        $data = $request->validate(['file' => 'required|file|max:10240|mimes:pdf,txt,docx,png,jpg,jpeg,webp', 'matter_id' => 'nullable|string', 'title' => 'nullable|string|max:160']);
        $user = $request->user();
        $isClient = (bool) array_intersect($user->roles, ['client', 'prospect']);
        $scope = ['owner_id' => $user->id, 'team_ids' => [], 'client_ids' => $isClient ? [$user->id] : [], 'visibility' => $isClient ? 'shared' : 'internal'];
        if (! empty($data['matter_id'])) {
            $matter = $this->store->get('matters', $data['matter_id']);
            abort_unless($matter !== null, 404);
            $this->access->authorize($user, 'matters.read', $matter);
            $scope = array_merge($scope, ['matter_id' => $matter['id'], 'team_ids' => array_values(array_unique(array_merge([$matter['owner_id']], $matter['team_ids'] ?? []))), 'confidentiality' => $matter['confidentiality'] ?? 'standard']);
        } else {
            $this->access->authorize($user, 'documents.write');
        }
        $file = $request->file('file');
        $bytes = $file->getContent();
        $path = $this->files->write($bytes, 'quarantine');
        try {
            $record = $this->store->transaction(function () use ($file, $data, $scope, $path, $bytes, $user) {
                $record = $this->store->create('documents', array_merge($scope, ['title' => $data['title'] ?? $file->getClientOriginalName(), 'name' => basename($file->getClientOriginalName()), 'mime' => $file->getMimeType(), 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'path' => $path, 'kind' => 'upload', 'status' => 'quarantined']));
                $this->outbox->enqueue('scan', ['document_id' => $record['id']], $record['id']);
                $this->audit->log($user->id, 'file.uploaded', 'documents', $record['id']);

                return $record;
            });
        } catch (\Throwable $e) {
            $this->files->delete($path);
            throw $e;
        }

        return response()->json(['data' => array_diff_key($record, ['path' => true])], 201);
    }

    public function download(Request $request, string $id): mixed
    {
        $record = $this->store->get('documents', $id);
        abort_unless($record !== null, 404);
        $this->access->authorize($request->user(), 'documents.read', $record);
        abort_unless(($record['status'] ?? '') === 'clean' && isset($record['path']), 423, 'This file has not been cleared for download.');
        $this->audit->log($request->user()->id, 'file.downloaded', 'documents', $id);
        $bytes = $this->files->read($record['path']);

        return response($bytes)->header('Content-Type', 'application/octet-stream')->header('Content-Disposition', HeaderUtils::makeDisposition('attachment', $record['name'] ?? 'document', 'document'))->header('Cache-Control', 'private, no-store');
    }
}
