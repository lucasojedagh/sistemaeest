@php
    use App\Support\CourseLabel;

    // Datos del encabezado (configurables en config/planilla.php).
    $cfg = config('planilla');

    $anioCorto     = CourseLabel::anioCorto($anio);
    $divisionCorta = CourseLabel::divisionCorta($division);

    // Celda de la planilla. Hay tres casos:
    //  - Vista de sola lectura (!editable): solo el texto, no existe input.
    //  - Edición con la columna habilitada: texto + input editable.
    //  - Edición con la columna bloqueada: texto + input disabled. El input
    //    tiene que estar aunque esté bloqueado, porque en modo edición el CSS
    //    esconde .p-print-val y la celda quedaría vacía.
    // El texto de cada celda lo calcula el controlador (prepararPlanilla), así
    // la planilla que se ve y la que se descarga en Excel dicen lo mismo.
    $celda = function (string $clase, $txt, $permiteEditar = false) use ($editable) {
        $span = '<span class="p-print-val">' . e($txt) . '</span>';
        if (!$editable) return $span;
        if ($permiteEditar) {
            return $span . '<input type="text" class="p-celda-input ' . e($clase) . '" value="' . e($txt) . '">';
        }
        return $span . '<input type="text" class="p-celda-input ' . e($clase) . '" value="' . e($txt) . '" disabled>';
    };

    // Pie: cuántos aprobaron, desaprobaron y sin evaluar en cada columna.
    $filasResumen = [];
    foreach ([['APROBADOS', 'apro'], ['DESAPROBADOS', 'desa'], ['SIN EVALUAR', 'sin'], ['TOTAL', 'total']] as [$rotulo, $clave]) {
        $filasResumen[] = array_merge([$rotulo], $resumen[$clave]);
    }

    // Filas del formulario: las calcula el controlador con la misma regla que
    // usa el Excel (los 35 renglones de la plantilla, o más si el curso tiene más
    // alumnos).
    $filas = $filas ?? \App\Support\PlanillaExcel::filasPara(count($alumnos));
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Planilla de Calificaciones {{ $ciclo }}</title>
    <link rel="stylesheet" href="{{ asset('css/planilla-calificaciones.css') }}?v={{ filemtime(public_path('css/planilla-calificaciones.css')) }}">
</head>
<body class="{{ $editable ? 'editable-mode' : '' }}">

    {{-- ==================== BARRA DE HERRAMIENTAS ==================== --}}
    <div class="toolbar no-print">
        @php
            // Botón de Excel: siempre disponible. El archivo sale con el diseño
            // oficial y se imprime desde Excel (nítido y editable a mano).
            $excelQs = 'anio=' . $anio . '&division=' . urlencode($division)
                . '&materia_id=' . $materia->id . '&ciclo=' . $ciclo;
        @endphp
        <a href="/calificaciones/planilla/excel?{{ $excelQs }}"
           id="btn-excel"
           class="tb-btn tb-excel"
           onclick="descargarExcel(event)">Descargar Excel</a>
        @if ($editable)
            <button class="tb-btn tb-save" onclick="handleGuardarCambios()">Guardar cambios</button>
            <button class="tb-btn tb-print" onclick="window.print()">Imprimir</button>
            <span id="save-status" class="save-status"></span>
        @endif
    </div>

    <div class="p-hoja">

        {{-- ==================== ENCABEZADO ==================== --}}
        <div class="p-cab">
            <img class="p-logo" src="{{ asset('LogoInstitucional.png') }}" alt="">

            <div class="p-renglon p-r1">{{ $cfg['encabezado_1'] }}</div>
            <div class="p-renglon p-r2">{{ $cfg['encabezado_2'] }}</div>
            <div class="p-renglon p-r3">
                {{ $cfg['escuela'] }}{!! $cfg['escuela_bajada'] !== '' ? ' &nbsp;" ' . e($cfg['escuela_bajada']) . ' "' : '' !!}
            </div>

            <div class="p-bloque">
                <div class="p-titulo">{{ $cfg['titulo'] }} {{ $ciclo }}</div>
                <div class="p-datos">
                    <div class="p-dato"><span>CURSO:</span><b>{{ $anioCorto }}</b></div>
                    <div class="p-dato"><span>DIVISIÓN:</span><b>{{ $divisionCorta }}</b></div>
                </div>
            </div>

            <div class="p-lineas">
                <div class="p-linea">ASIGNATURA:&nbsp;&nbsp;<span class="p-valor">{{ $materia->nombre }}</span></div>
                <div class="p-linea">PROFESOR:&nbsp;&nbsp;<span class="p-valor">{{ $profesor ?? '' }}</span></div>
            </div>
        </div>

        {{-- ==================== TABLA ==================== --}}
        <table class="p-tabla" id="planilla-table">
            <colgroup>
                <col class="c1">
                <col class="cn" span="6">
                <col class="cn2" span="12">
                <col class="cint">
                <col class="cn2" span="3">
                <col class="cfin">
            </colgroup>
            <thead>
                <tr class="p-alto">
                    <th class="p-cabecera-nro" rowspan="4">Nº</th>
                    <th class="p-cabecera-nombre" colspan="6" rowspan="4">Apellido/s y Nombre/s</th>
                    <th class="p-grupo" colspan="6">1° CUATRIMESTRE</th>
                    <th class="p-grupo" colspan="6">2° CUATRIMESTRE</th>
                    <th rowspan="4"><div class="p-giro p-med">INTENSIFICACIÓN 1°CUATRIMESTRE</div></th>
                    <th class="p-grupo p-intens" colspan="3" rowspan="2">INTENSIFICACIÓN</th>
                    <th class="p-cabecera-final" rowspan="4"><div class="p-giro p-gde">CALIFICACIÓN FINAL</div></th>
                </tr>
                <tr>
                    <th colspan="4" rowspan="3"><div class="p-vp"><span>CALIFICACIONES</span><span>VALORACIONES PARCIALES</span></div></th>
                    <th rowspan="3" class="p-doble-izq"><div class="p-giro p-peq">1º VALORACIÓN PRELIMINAR</div></th>
                    <th rowspan="3" class="p-doble-der"><div class="p-giro p-peq">CALIFICACIÓN 1°CUATRIMESTRE</div></th>
                    <th colspan="4" rowspan="3"><div class="p-vp"><span>CALIFICACIONES</span><span>VALORACIONES PARCIALES</span></div></th>
                    <th rowspan="3" class="p-doble-izq"><div class="p-giro p-peq">2º VALORACIÓN PRELIMINAR</div></th>
                    <th rowspan="3" class="p-doble-der"><div class="p-giro p-peq">CALIFICACIÓN 2°CUATRIMESTRE</div></th>
                </tr>
                <tr>
                    <th rowspan="2"><div class="p-giro p-peq">DICIEMBRE</div></th>
                    <th rowspan="2"><div class="p-giro p-peq">FEBRERO</div></th>
                    <th rowspan="2"><div class="p-giro p-peq">MARZO ABRIL</div></th>
                </tr>
            </thead>
            <tbody>
                @for ($i = 1; $i <= $filas; $i++)
                    @php
                        $alumno = $i <= count($alumnos) ? $alumnos[$i - 1] : null;
                        $aid    = $alumno ? $alumno['alumno_id'] : null;
                        $c      = $alumno['celdas'] ?? [];
                    @endphp
                    <tr data-alumno-id="{{ $aid ?? '' }}">
                        <td class="p-nro">{{ $i }}</td>
                        <td class="p-alumno" colspan="6">{{ $alumno ? ($alumno['apellido'] . ', ' . $alumno['nombre']) : '' }}</td>

                        {{-- 1° cuatrimestre: 4 valoraciones parciales --}}
                        @for ($j = 1; $j <= 4; $j++)
                            <td class="p-nota">
                                <span class="p-print-val">{{ $c["p1_$j"] ?? '' }}</span>
                                @if ($editable && $aid)
                                    <input type="text" class="p-celda-input p1-nota" data-idx="{{ $j - 1 }}" value="{{ $c["p1_$j"] ?? '' }}" maxlength="3">
                                @endif
                            </td>
                        @endfor

                        <td class="p-nota p-doble-izq">
                            <span class="p-print-val">{{ $c['p1_prelim'] ?? '' }}</span>
                            @if ($editable && $aid)
                                <input type="text" class="p-celda-input p1-val-prelim" value="{{ $c['p1_prelim'] ?? '' }}" maxlength="3">
                            @endif
                        </td>
                        <td class="p-nota p-doble-der">
                            {!! $celda('p1-num', $c['p1_cuatri'] ?? '', $aid && ($ventanaP1 ?? $ventanaAbierta ?? false)) !!}
                        </td>

                        {{-- 2° cuatrimestre: 4 valoraciones parciales --}}
                        @for ($j = 1; $j <= 4; $j++)
                            <td class="p-nota">
                                <span class="p-print-val">{{ $c["p2_$j"] ?? '' }}</span>
                                @if ($editable && $aid)
                                    <input type="text" class="p-celda-input p2-nota" data-idx="{{ $j - 1 }}" value="{{ $c["p2_$j"] ?? '' }}" maxlength="3">
                                @endif
                            </td>
                        @endfor

                        <td class="p-nota p-doble-izq">
                            <span class="p-print-val">{{ $c['p2_prelim'] ?? '' }}</span>
                            @if ($editable && $aid)
                                <input type="text" class="p-celda-input p2-val-prelim" value="{{ $c['p2_prelim'] ?? '' }}" maxlength="3">
                            @endif
                        </td>
                        <td class="p-nota p-doble-der">
                            {!! $celda('p2-num', $c['p2_cuatri'] ?? '', $aid && ($ventanaP2 ?? $ventanaAbierta ?? false)) !!}
                        </td>

                        {{-- Intensificación --}}
                        <td class="p-nota">{!! $celda('int-1c', $c['int_1c'] ?? '', (bool) $aid) !!}</td>
                        <td class="p-nota">{!! $celda('int-dic', $c['int_dic'] ?? '', (bool) $aid) !!}</td>
                        <td class="p-nota">{!! $celda('int-feb', $c['int_feb'] ?? '', (bool) $aid) !!}</td>
                        <td class="p-nota">{!! $celda('int-mab', $c['int_mab'] ?? '', (bool) $aid) !!}</td>

                        {{-- Calificación final: la calcula el sistema, el guardado de la
                             planilla no admite este campo, así que va bloqueada. --}}
                        <td class="p-nota p-final">{!! $celda('nota-final', $c['final'] ?? '', false) !!}</td>
                    </tr>
                @endfor
            </tbody>
        </table>

        {{-- ==================== AVISO ==================== --}}
        <div class="p-aviso">IMPORTANTE: LEER LAS SIGUIENTES CONSIDERACIONES ANTES DE COMPLETAR LA PLANILLA.</div>

        {{-- ==================== RESUMEN Y CONSIDERACIONES ==================== --}}
        <div class="p-abajo">
            <table class="p-resumen">
                <colgroup>
                    <col class="p-c1"><col class="p-c2"><col class="p-c3"><col class="p-c4">
                    <col class="p-c5"><col class="p-c6"><col class="p-c7"><col class="p-c8">
                </colgroup>
                <tbody>
                    <tr>
                        <td colspan="2"></td>
                        <th class="p-1c">1°C</th>
                        <th class="p-2c">2°C</th>
                        <th class="p-chico">TOTAL</th>
                        <th class="p-chico">DIC</th>
                        <th class="p-chico-b">FEB MAR</th>
                        <th class="p-chico-b">MAR ABRIL</th>
                    </tr>
                    @foreach ($filasResumen as $fila)
                        <tr>
                            <td class="p-rotulo" colspan="2">{{ $fila[0] }}</td>
                            @for ($c = 1; $c <= 6; $c++)
                                <td>{{ $fila[$c] }}</td>
                            @endfor
                        </tr>
                    @endforeach
                    <tr>
                        <td colspan="5"></td>
                        <th>1°C</th>
                        <th>2°C</th>
                        <th>TOTAL</th>
                    </tr>
                    <tr>
                        <td class="p-rotulo-medio" colspan="5">TOTAL DE CLASES DE LA MATERIA</td>
                        <td></td><td></td><td></td>
                    </tr>
                    <tr>
                        <td class="p-rotulo-medio" colspan="5">CLASES EFECTIVAMENTE DADAS</td>
                        <td></td><td></td><td></td>
                    </tr>
                </tbody>
            </table>

            <div class="p-notas">
                <p>1 - La Valoración Preliminar refiere a una valoración cualitativa que se entrega a las/os estudiantes y sus familias, y que comunica los avances en los aprendizajes de los primeros dos meses de cada cuatrimestre. La misma se expresa de acuerdo a las categorías: TEA-TEP-TED.</p>
                <p>2- La Calificación del cuatrimestre resulta de la ponderación de las valoraciones parciales cualitativas y cuantitativas obtenidas por la/el estudiante, dando cuenta de su proceso de aprendizaje durante ese período, a través de diferentes instrumentos y/o instancias evaluativas. Se podrá expresar con números enteros, en la escala de 1 (uno) a 10 (diez).</p>
                <p>3- Intensificación 1º cuatrimestre: debe completarse sólo si la/el estudiante obtuvo originalmente una calificación menor a 7 (siete) en el 1° cuatrimestre y, durante el 2º cuatrimestre intensifica los saberes pendientes logrando una calificación de 7 (siete) o más.</p>
                <p>4- Considerar SIN EVALUAR a aquellas/os estudiantes que no concurrieron a ninguna clase del período.</p>
                <p>5- La Calificación final de cada materia se registrará al momento en que la misma haya sido aprobada y acreditada o al cierre del ciclo lectivo.</p>
                <p>6 - Las/os docentes deben firmar al pie de las calificaciones consignadas.</p>
                <p>7 - No se debe sacar de lista (tachar, borrar, rayar) a ningún/a estudiante.</p>
            </div>
        </div>
    </div>

    <script>
    // Token de autenticación: el modal padre lo envía por postMessage (ver el
    // mensaje 'auth' más abajo). Se declara acá porque lo usan tanto el guardado
    // como la descarga del Excel, y el guardado solo existe en modo edición.
    // El api_token real nunca viaja en la URL: la página se abre con ?ticket=
    // (AuthApiController@printTicket), que dura 60 s y se puede renovar.
    let __authToken = null;
    </script>

    @if ($editable)
    <script>
    const __FRONTEND_ORIGINS = @json(config('cors.allowed_origins', []));
    const __PARENT_ORIGIN = __FRONTEND_ORIGINS.length ? __FRONTEND_ORIGINS[0] : window.location.origin;
    let __motivoResolve = null;

    function solicitarMotivo() {
        return new Promise((resolve) => {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({ type: 'solicitar-motivo' }, __PARENT_ORIGIN);
                __motivoResolve = resolve;
            } else {
                resolve(null);
            }
        });
    }

    async function handleGuardarCambios() {
        const detalles = await solicitarMotivo();
        if (detalles === '__CANCEL__') return;
        guardarPlanilla(detalles);
    }

    async function guardarPlanilla(detalles) {
        const btn = document.querySelector('.tb-save');
        const status = document.getElementById('save-status');
        btn.disabled = true;
        status.textContent = 'Guardando...';
        status.className = 'save-status saving';

        const rows = document.querySelectorAll('#planilla-table tbody tr[data-alumno-id]');
        const alumnos = [];

        for (const row of rows) {
            const alumnoId = row.dataset.alumnoId;
            if (!alumnoId) continue;

            const p1_vp = [];
            row.querySelectorAll('.p1-nota').forEach(inp => p1_vp.push(inp.value));
            const p1_preliminar = row.querySelector('.p1-val-prelim')?.value || '';
            const p1FinalInput = row.querySelector('.p1-num');
            const p1_final_cuatri = p1FinalInput?.value || '';

            const p2_vp = [];
            row.querySelectorAll('.p2-nota').forEach(inp => p2_vp.push(inp.value));
            const p2_preliminar = row.querySelector('.p2-val-prelim')?.value || '';
            const p2FinalInput = row.querySelector('.p2-num');
            const p2_final_cuatri = p2FinalInput?.value || '';

            const val = (sel) => {
                const v = row.querySelector(sel)?.value || '';
                return v === '' ? null : parseFloat(v);
            };

            const a = { alumno_id: parseInt(alumnoId) };
            a.p1_vp = p1_vp;
            a.p1_preliminar = p1_preliminar;
            a.p2_vp = p2_vp;
            a.p2_preliminar = p2_preliminar;
            // Solo se envían si el campo está habilitado (ventana abierta); si está
            // deshabilitado el backend preserva los valores existentes.
            if (p1FinalInput && !p1FinalInput.disabled && p1_final_cuatri !== '') a.p1_final_cuatri = parseFloat(p1_final_cuatri);
            if (p2FinalInput && !p2FinalInput.disabled && p2_final_cuatri !== '') a.p2_final_cuatri = parseFloat(p2_final_cuatri);

            const int1c = val('.int-1c'), intDic = val('.int-dic');
            const intFeb = val('.int-feb'), intMab = val('.int-mab');
            if (int1c !== null) a.int_1cuatri = int1c;
            if (intDic !== null) a.int_dic = intDic;
            if (intFeb !== null) a.int_feb = intFeb;
            if (intMab !== null) a.int_mar_abr = intMab;

            alumnos.push(a);
        }

        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
            };
            if (__authToken) headers['Authorization'] = 'Bearer ' + __authToken;

            const payload = {
                anio: {{ $anio }},
                division: '{{ $division }}',
                materia_id: {{ $materia->id }},
                ciclo: {{ $ciclo }},
                alumnos,
            };
            if (detalles) payload._detalles = detalles;

            const res = await fetch('/calificaciones/planilla/guardar', {
                method: 'POST',
                headers,
                body: JSON.stringify(payload),
            });
            const result = await res.json();
            if (result.ok) {
                status.textContent = 'Guardado correctamente';
                status.className = 'save-status success';
            } else {
                const msgs = result.errores ? result.errores.join('; ') : (result.message || 'Error');
                status.textContent = 'Error: ' + msgs;
                status.className = 'save-status error';
            }
        } catch (e) {
            status.textContent = 'Error de conexion: ' + e.message;
            status.className = 'save-status error';
        }

        btn.disabled = false;
    }

    // Solo en modo edición se ven los campos; en el papel se imprime el valor.
    window.addEventListener('message', (e) => {
        if (!e.data) return;
        if (__FRONTEND_ORIGINS.length && !__FRONTEND_ORIGINS.includes(e.origin)) return;
        if (e.data.type === 'auth' && e.data.token) {
            __authToken = e.data.token;
        } else if (e.data.type === 'guardar-planilla') {
            if (e.data.token) __authToken = e.data.token;
            if (typeof guardarPlanilla === 'function') guardarPlanilla(e.data.detalles || null);
        } else if (e.data.type === 'motivo-confirmado') {
            if (e.data.token) __authToken = e.data.token;
            if (__motivoResolve) { __motivoResolve(e.data.detalles || null); __motivoResolve = null; }
        } else if (e.data.type === 'motivo-cancelado') {
            if (__motivoResolve) { __motivoResolve('__CANCEL__'); __motivoResolve = null; }
        }
    });
    </script>
    @endif

    <script>
    // Descarga del Excel. Sirve en los dos modos (mirar / editar).
    // Se descarga con fetch (no navegando el iframe) para poder avisar si
    // falló en vez de dejar la planilla tapada con un JSON de error.
    // (__authToken se declara en el script de arriba, antes del bloque de edición.)

    // El ticket de la URL dura 60 s. Si la planilla estuvo abierta más tiempo
    // se pide uno nuevo con el token que manda el modal padre; si no hay token
    // (se abrió la URL directo) se reusa el de la URL.
    async function ticketParaDescargar() {
        const deLaUrl = new URLSearchParams(window.location.search).get('ticket');
        if (!__authToken) return deLaUrl;
        try {
            const resp = await fetch('/api/print/ticket', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': 'Bearer ' + __authToken,
                },
                body: JSON.stringify({}),
            });
            const datos = await resp.json();
            return (datos && datos.ticket) || deLaUrl;
        } catch {
            return deLaUrl;
        }
    }

    // Aviso en la barra de herramientas (reusa el estilo del guardado).
    function avisarExcel(texto, tipo) {
        let el = document.getElementById('excel-aviso');
        if (!el) {
            el = document.createElement('span');
            el.id = 'excel-aviso';
            document.querySelector('.toolbar').appendChild(el);
        }
        el.className = 'save-status ' + tipo;
        el.textContent = texto;
        if (tipo === 'success') setTimeout(() => { el.textContent = ''; }, 4000);
    }

    async function descargarExcel(ev) {
        ev.preventDefault();
        const btn = ev.currentTarget;
        // Los enlaces no tienen atributo disabled real: se bloquea con aria-disabled
        // para que un doble clic no dispare dos generaciones.
        if (btn.getAttribute('aria-disabled') === 'true') return;
        btn.setAttribute('aria-disabled', 'true');
        avisarExcel('Generando la planilla...', 'saving');

        try {
            const ticket = await ticketParaDescargar();
            const url = btn.href + (ticket ? '&ticket=' + encodeURIComponent(ticket) : '');
            const resp = await fetch(url, {
                headers: { 'Accept': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' },
            });

            if (!resp.ok) {
                let mensaje = 'No se pudo descargar la planilla (' + resp.status + ')';
                try {
                    const datos = await resp.json();
                    if (datos && datos.message) mensaje = datos.message;
                } catch { /* respondió sin JSON */ }
                avisarExcel(mensaje, 'error');
                return;
            }

            const utf8 = (resp.headers.get('Content-Disposition') || '')
                .match(/filename\*=UTF-8''([^;]+)/i);
            const blobUrl = URL.createObjectURL(await resp.blob());
            const enlace = document.createElement('a');
            enlace.href = blobUrl;
            enlace.download = utf8 ? decodeURIComponent(utf8[1]) : 'Planilla.xlsx';
            document.body.appendChild(enlace);
            enlace.click();
            enlace.remove();
            // Se libera recién ahora: si se hace antes, el navegador no llega a leer el archivo.
            setTimeout(() => URL.revokeObjectURL(blobUrl), 30000);
            avisarExcel('Planilla descargada', 'success');
        } catch {
            avisarExcel('Se cortó la conexión: intentá de nuevo', 'error');
        } finally {
            btn.removeAttribute('aria-disabled');
        }
    }

    window.addEventListener('message', (e) => {
        if (e.data && e.data.type === 'auth' && e.data.token) {
            __authToken = e.data.token;
        }
    });
    </script>
</body>
</html>