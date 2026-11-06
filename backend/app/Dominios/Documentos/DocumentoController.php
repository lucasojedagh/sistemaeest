<?php

namespace App\Dominios\Documentos;

use App\Http\Controllers\Controller;

use App\Dominios\Documentos\Documento;
use App\Support\ActivityLogger;
use App\Support\AuthorizesScope;
use App\Support\LikeSearch;
use App\Support\RoleHierarchy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentoController extends Controller
{
    use AuthorizesScope;
    /** Roles que pueden tener visibilidad de documentos. */
    private const ROLES_VALIDOS = [
        RoleHierarchy::ADMIN,
        RoleHierarchy::DIRECTIVO,
        RoleHierarchy::JEFE_PRECEPTORES,
        RoleHierarchy::PRECEPTOR,
        RoleHierarchy::PROSECRETARIA,
        RoleHierarchy::PROFESOR,
    ];

    /** Visibilidad por defecto: administradores, jefe de preceptores y preceptores. */
    private const ROLES_DEFAULT = [
        RoleHierarchy::ADMIN,
        RoleHierarchy::JEFE_PRECEPTORES,
        RoleHierarchy::PRECEPTOR,
    ];

    /** Descarga de archivos institucionales: el alcance de documentos del rol decide. */
    private function abortIfNotStaff(Request $request): void
    {
        if (\App\Support\Alcances::ver($request->user()->role, 'documentos') === \App\Support\Alcances::NADA) {
            abort(403, 'No tenés permiso para descargar documentos.');
        }
    }

    /** El documento solo es visible para sus roles asignados (null = todos, docs legacy). */
    private function authorizeDoc(Request $request, Documento $doc): void
    {
        if (empty($doc->roles)) return;
        if (!in_array($request->user()->role, $doc->roles, true)) {
            abort(403, 'No tenés permiso para ver este documento.');
        }
    }

    public function index(Request $request): JsonResponse
    {
        $query = Documento::with('user:id,name,email')->orderBy('created_at', 'desc');

        if ($search = $request->input('search')) {
            $query->where('nombre', 'like', LikeSearch::contains($search));
        }

        // Cada rol solo ve los documentos habilitados para el (null = todos).
        $role = $request->user()->role;
        $query->where(function ($q) use ($role) {
            $q->whereNull('roles')->orWhereJsonContains('roles', $role);
        });

        return response()->json($query->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'archivo' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,jpg,jpeg,png,gif,zip,rar', 'max:51200'],
            // Roles que pueden ver el documento; vacio o ausente = visibilidad por defecto.
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'in:' . implode(',', self::ROLES_VALIDOS)],
        ]);

        $file = $request->file('archivo');
        $originalName = $this->sanearNombreArchivo($file->getClientOriginalName());
        $mime = $file->getMimeType();
        $size = $file->getSize();

        $path = $file->store('documentos', 'local');

        $roles = array_values(array_unique(array_map('strval', $data['roles'] ?? [])));
        if (empty($roles)) {
            $roles = self::ROLES_DEFAULT;
        }

        $doc = Documento::create([
            'user_id'        => $request->user()->id,
            'nombre'         => $data['nombre'],
            'nombre_archivo' => $originalName,
            'ruta'           => $path,
            'mime'           => $mime,
            'tamano'         => $size,
            'roles'          => $roles,
        ]);

        ActivityLogger::log($request, 'Subió documento: ' . $doc->nombre, 'documento.store', 'Documento', $doc->id);

        return response()->json($doc->load('user:id,name,email'), 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $doc = Documento::findOrFail($id);

        // El creador siempre puede editar su propio documento,
        // incluso si excluyo su rol de la lista.
        if ((int) $doc->user_id !== (int) $request->user()->id) {
            $this->authorizeDoc($request, $doc);
        }

        $data = $request->validate([
            // Nombre visible del documento (opcional).
            'nombre' => ['sometimes', 'required', 'string', 'max:255'],
            // Roles que pueden ver el documento (opcional); debe quedar al menos uno.
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', 'in:' . implode(',', self::ROLES_VALIDOS)],
        ]);

        if (!isset($data['nombre']) && !isset($data['roles'])) {
            return response()->json(['message' => 'No hay cambios para aplicar.'], 422);
        }

        $renombro = isset($data['nombre']) && $data['nombre'] !== $doc->nombre;

        if ($renombro) {
            $viejoNombre = $doc->nombre;
            $doc->update(['nombre' => $data['nombre']]);
            ActivityLogger::log(
                $request,
                'Renombró documento: ' . $viejoNombre . ' → ' . $data['nombre'],
                'documento.update',
                'Documento',
                $doc->id,
                ['nombre' => $viejoNombre],
                ['nombre' => $data['nombre']]
            );
        }

        if (isset($data['roles'])) {
            $viejosRoles = (array) ($doc->roles ?? []);
            $nuevosRoles = array_values(array_unique(array_map('strval', $data['roles'])));
            $doc->update(['roles' => $nuevosRoles]);
            ActivityLogger::log(
                $request,
                'Editó visibilidad de documento: ' . $doc->nombre,
                'documento.update',
                'Documento',
                $doc->id,
                ['roles' => array_values($viejosRoles)],
                ['roles' => $nuevosRoles]
            );
        }

        return response()->json($doc->load('user:id,name,email'));
    }

    public function download(Request $request, $id)
    {
        $this->abortIfNotStaff($request);

        $doc = Documento::findOrFail($id);
        $this->authorizeDoc($request, $doc);

        if (!Storage::disk('local')->exists($doc->ruta)) {
            return response()->json(['message' => 'Archivo no encontrado en el servidor.'], 404);
        }

        ActivityLogger::log($request, 'Descargó documento: ' . $doc->nombre, 'documento.download', 'Documento', $doc->id);

        return Storage::disk('local')->download($doc->ruta, $doc->nombre_archivo);
    }

    public function print(Request $request, $id)
    {
        $this->abortIfNotStaff($request);

        $doc = Documento::findOrFail($id);
        $this->authorizeDoc($request, $doc);

        if (!Storage::disk('local')->exists($doc->ruta)) {
            return response()->json(['message' => 'Archivo no encontrado en el servidor.'], 404);
        }

        if ($doc->mime !== 'application/pdf') {
            return response()->json(['message' => 'Solo se puede imprimir documentos PDF.'], 422);
        }

        ActivityLogger::log($request, 'Imprimió documento: ' . $doc->nombre, 'documento.print', 'Documento', $doc->id);

        // El nombre va entre comillas dentro de la cabecera: una comilla o un
        // backslash lo rompería (y los registros viejos guardan el nombre tal
        // como lo mandó el cliente).
        $nombreCabecera = str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\"', '', ''], (string) $doc->nombre_archivo);

        return Storage::disk('local')->download($doc->ruta, $doc->nombre_archivo, [
            'Content-Disposition' => 'inline; filename="' . $nombreCabecera . '"',
        ]);
    }

    // El nombre original lo manda el cliente: se guarda sin partes de ruta ni
    // caracteres de control para que no rompa cabeceras ni vistas.
    private function sanearNombreArchivo(string $nombre): string
    {
        $base = str_replace(['\\', '/'], '', $nombre);
        $base = preg_replace('/[\x00-\x1F\x7F]/', '', $base) ?? '';
        $base = trim($base);
        if ($base === '' || $base === '.' || $base === '..') {
            $base = 'documento';
        }

        return mb_substr($base, 0, 200);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        // Borra fila + archivo físico: exige contraseña reciente.
        $this->assertPasswordConfirmed($request);

        $doc = Documento::findOrFail($id);

        // El creador siempre puede eliminar su propio documento,
        // incluso si se excluyo su rol de la visibilidad.
        if ((int) $doc->user_id !== (int) $request->user()->id) {
            $this->authorizeDoc($request, $doc);
        }

        if (Storage::disk('local')->exists($doc->ruta)) {
            Storage::disk('local')->delete($doc->ruta);
        }

        $nombre = $doc->nombre;
        $doc->delete();

        ActivityLogger::log($request, 'Eliminó documento: ' . $nombre, 'documento.destroy', 'Documento', $id);

        return response()->json(['ok' => true]);
    }
}

