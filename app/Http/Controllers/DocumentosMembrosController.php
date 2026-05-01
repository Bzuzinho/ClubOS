<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DocumentosMembrosController extends Controller
{
    /**
     * Display a listing of the member's documents.
     */
    public function index(User $member): JsonResponse
    {
        $documents = $member->documents()->latest()->get();
        
        return response()->json([
            'documents' => $documents,
        ]);
    }

    /**
     * Store a newly created document.
     */
    public function store(Request $request, User $member): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['cc', 'atestado', 'autorizacao', 'rgpd', 'consentimento', 'afiliacao', 'declaracao_transporte', 'outro'])],
            'name' => 'nullable|string|max:255',
            'file' => 'required|file|max:5120', // 5MB max
            'expiry_date' => 'nullable|date',
        ]);

        // Store the file
        $path = $request->file('file')->store('private/user-documents', 'local');

        $document = $member->documents()->create([
            'type' => $validated['type'],
            'name' => $validated['name'] ?? null,
            'file_path' => $path,
            'expiry_date' => $validated['expiry_date'] ?? null,
        ]);

        return response()->json([
            'message' => 'Documento carregado com sucesso!',
            'document' => $document,
        ], 201);
    }

    /**
     * Remove the specified document.
     */
    public function destroy(User $member, UserDocument $document): JsonResponse
    {
        // Verify document belongs to member
        if ($document->user_id !== $member->id) {
            return response()->json([
                'message' => 'Documento não pertence a este membro.',
            ], 403);
        }

        // Delete file from storage
        if ($document->file_path) {
            $this->userDocumentDisk($document)->delete($document->file_path);
        }

        $document->delete();

        return response()->json([
            'message' => 'Documento eliminado com sucesso!',
        ]);
    }

    private function userDocumentDisk(UserDocument $document): FilesystemAdapter
    {
        if (Storage::disk('local')->exists($document->file_path)) {
            return Storage::disk('local');
        }

        return Storage::disk('public');
    }
}
